"""Exercise the workspace API and its PostgreSQL state inside test.ps1's isolated network."""
import concurrent.futures
from dataclasses import dataclass
from http.client import HTTPConnection
from http.cookies import SimpleCookie
import json
import os
from pathlib import Path
import re
import runpy
import sys
import threading
from urllib.parse import parse_qs, quote, urlsplit

import psycopg
from psycopg import sql

PASSWORD = os.environ['QA_PASSWORD']
PROMPT = 'QA-PROMPT-never-log-b628a9'
KEY = 'qa-no-provider-key-never-log-c95c37'
LOG_EXPECTATIONS = Path('/tmp/workspace-api-log-expectations.json')
JSON, XML, YAML = ('application/' + fmt for fmt in ('json', 'xml', 'yaml'))
correlations = {}
sensitive = {PASSWORD, KEY, PROMPT}
exchanges = 0
lock = threading.Lock()


def passed(label):
    print('PASS', label, flush=True)


@dataclass
class Reply:
    status: int
    headers: dict
    data: object
    rid: str


class Client:
    def __init__(self, host='api:8080'):
        self.host = host
        self.cookie = self.csrf = ''

    def req(self, method, path, status=200, data=None, *, raw=None, media=JSON,
            accept=JSON, headers=None, html=False):
        global exchanges
        assert path.startswith('/') and not path.startswith('//'), 'Link must stay site-relative'
        outgoing = {'Accept': accept}
        if self.cookie:
            outgoing['Cookie'] = self.cookie
        if self.csrf and method not in ('GET', 'HEAD'):
            outgoing['X-CSRF-Token'] = self.csrf
        if data is not None:
            assert raw is None
            raw = json.dumps(data).encode()
        if isinstance(raw, str):
            raw = raw.encode()
        if raw is not None:
            outgoing['Content-Type'] = media
        for k, v in (headers or {}).items():
            if v is None:
                outgoing.pop(k, None)
            else:
                outgoing[k] = v
        conn = HTTPConnection(self.host, timeout=70)
        try:
            conn.request(method, path, raw, outgoing)
            response = conn.getresponse()
            hs = {k.lower(): v for k, v in response.getheaders()}
            body = response.read()
            code = response.status
        finally:
            conn.close()
        rid = hs.get('x-request-id', '')
        label = f'{method} {path} [{rid}]'
        assert re.fullmatch('[0-9a-f]{16}', rid), label + ': missing request correlation'
        accepted = (status,) if isinstance(status, int) else status
        # Do not include raw request/response bodies in CI failures.
        assert code in accepted, f'{label}: expected {accepted}, got {code}'
        if html:
            assert hs['content-type'].split(';')[0] == 'text/html', label
            decoded = body.decode()
        else:
            decoded = contract.exchange(method, path, raw, outgoing.get('Content-Type',''), code, hs, body)
            if code == 204:
                assert not body, label
            if code >= 400:
                assert decoded['requestId'] == rid and decoded['error'] and decoded['code'], label
            assert KEY.encode() not in body and PASSWORD.encode() not in body and PROMPT.encode() not in body, label
        with lock:
            exchanges += 1
            if self.host == 'api:8080':
                assert rid not in correlations, 'Request ID reused'
                correlations[rid] = (method, urlsplit(path).path, code)
        return Reply(code, hs, decoded, rid)

    def login(self, name, role):
        result = self.req('POST','/auth/login',200,{'username':name,'password':PASSWORD})
        value = result.data
        assert value['user']['username'] == name and value['user']['role'] == role
        assert set(value['user']) == {'id','username','role'}
        cookie = SimpleCookie(result.headers['set-cookie'])['osiris_session']
        assert cookie['httponly'] and cookie['samesite'].lower() in ('lax','strict') and cookie['path'] == '/'
        self.cookie = 'osiris_session=' + cookie.value
        self.csrf = value['csrfToken']
        assert self.csrf and self.csrf != cookie.value
        sensitive.update((cookie.value, self.csrf))
        me = self.req('GET','/auth/me').data
        assert me['user'] == value['user'] and me['csrfToken'] == self.csrf
        return self

    def follow(self, obj, rel, method='GET', **kwargs):
        link = obj['_links'][rel]
        assert link['method'] == method, rel
        return self.req(method, link['href'], **kwargs)


def rows(query, *params):
    return db.execute(query, params or None).fetchall()


def one(query, *params):
    [(value,)] = rows(query, *params)
    return value


def scope_query(user_id):
    return [r[0] for r in rows('''SELECT json_build_object('id',s.id,'projectId',s.project_id,
        'title',s.title,'status',s.status,'createdAt',to_char(s.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'),
        'updatedAt',to_char(s.updated_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'))
        FROM sessions s JOIN projects p ON p.id=s.project_id WHERE p.user_id=%s ORDER BY s.created_at,s.id''', user_id)]


def fields(item):
    return {k:v for k,v in item.items() if k != '_links'}


def expire(client, name):
    import hashlib
    token_hash = hashlib.sha256(client.cookie.partition('=')[2].encode()).hexdigest()
    assert one('SELECT user_id FROM auth_sessions WHERE token_hash=%s',token_hash) == name
    db.execute("UPDATE auth_sessions SET expires_at=now()-interval '1 second' WHERE token_hash=%s",(token_hash,))
    client.req('GET','/auth/me',401)
    return token_hash


def pages_and_identity():
    anon = Client()
    for path in ('/','/login','/dashboard','/workspace','/chat','/manage','/api-checks'):
        page = anon.req('GET',path,accept='text/html',html=True).data
        assert '<input' in page and '<button' in page and 'src="/assets/workspace.js"' in page, path
    assert 'Page not found' in anon.req('GET','/dashboard/no-such-page',404,accept='text/html',html=True).data
    anon.req('GET','/qa-no-such-route',404)
    for path in ('/auth/me','/api','/api/runtime','/projects','/sessions','/sessions/ses_101',
                 '/projects/prj_ai/sessions','/sessions/ses_101/context','/sessions/ses_101/tool-runs'):
        anon.req('GET',path,401)
    anon.req('POST','/sessions',401,{'projectId':'prj_ai','title':'No'})
    anon.req('GET','/sessions',401,headers={'Cookie':'osiris_session=invalid','X-User-Role':'admin'})
    for user, pw in [('demo_one','wrong-password'),('qa_not_registered',PASSWORD)]:
        result = anon.req('POST','/auth/login',401,{'username':user,'password':pw})
        assert 'osiris_session=' not in result.headers.get('set-cookie','')
    a = Client().login('demo_one','owner'); b = Client().login('demo_two','owner')
    member = Client().login('qa_member','member'); viewer = Client().login('qa_viewer','viewer')
    admin = Client().login('qa_admin','admin')
    empty = Client('empty:8080').login('qa_empty','member')
    assert empty.req('GET','/sessions').data == []
    for client, uid in [(a,'usr_demo_one'),(b,'usr_demo_two')]:
        expected = scope_query(uid)
        actual = client.req('GET','/sessions').data
        assert isinstance(actual,list) and [fields(r) for r in actual] == expected and expected
        for actual_item, seed in zip(actual, expected):
            assert fields(client.follow(actual_item,'self').data) == seed
    assert len(scope_query('usr_demo_one')) == 3 and len(scope_query('usr_demo_two')) == 2
    assert fields(a.req('GET','/sessions/ses_101').data)['title'] == 'Investigate crash'
    assert all(client.req('GET','/sessions').data == [] for client in [member, viewer, admin])
    hashed = one('SELECT password_hash FROM users WHERE id=%s','usr_demo_one')
    assert hashed and hashed.startswith(('$argon2','$2')) and PASSWORD not in hashed
    assert one('SELECT password_hash != %s FROM users WHERE id=%s',hashed,'usr_demo_two'), 'Password salts must differ'
    dead = Client().login('qa_member','member')
    dead.req('POST','/auth/logout',403,headers={'X-CSRF-Token':None})
    dead.req('POST','/auth/logout',204)
    dead.req('GET','/auth/me',401)  # Deliberately replay the old cookie.
    stale = Client().login('qa_member','member')
    token_hash = expire(stale,'usr_qa_member')
    db.execute("INSERT INTO auth_audit(request_id,event,created_at) VALUES ('qa-expired','fixture',now()-interval '31 days')")
    db.execute("INSERT INTO auth_rate_limits(bucket,hits,expires_at) VALUES ('qa-expired',1,now()-interval '1 second')")
    admin_root = admin.req('GET','/api').data
    a.req('POST','/api/maintenance/prune',403)
    admin.follow(admin_root,'prune','POST',status=403,headers={'X-CSRF-Token':None})
    admin.follow(admin_root,'prune','POST',status=204)
    assert one('SELECT count(*) FROM auth_sessions WHERE token_hash=%s',token_hash) == 0
    assert one("SELECT count(*) FROM auth_rate_limits WHERE bucket='qa-expired'") == 0
    assert one("SELECT count(*) FROM auth_audit WHERE request_id='qa-expired'") == 0
    assert a.req('GET','/auth/me').data['user']['id'] == 'usr_demo_one'
    passed('public pages, password hashing, session invalidation and account-scoped session lists vs SQL')
    return a,b,member,viewer,admin


def collections(a):
    path = '/sessions?projectId=prj_ai&sort=title&order=desc&limit=1&offset=0'
    first = a.req('GET',path).data
    assert first['page'] == {'limit':1,'offset':0,'total':3,'hasMore':True}
    nxt = first['links']['next']
    assert parse_qs(urlsplit(nxt).query) == {'projectId':['prj_ai'],'sort':['title'],'order':['desc'],'limit':['1'],'offset':['1']}
    second = a.req('GET',nxt).data
    assert first['items'][0]['id'] != second['items'][0]['id'] and second['links']['previous']
    found = a.req('GET','/sessions?status=active&projectId=prj_ai&q=crash&limit=1').data
    assert found['page']['total'] == 1 and found['items'][0]['id'] == 'ses_101'
    # The search limit is in Unicode characters, not UTF-8 bytes.
    boundary = '/sessions?q=' + quote('é' * 200)
    assert a.req('GET', boundary).data['items'] == []
    a.req('GET', boundary + quote('é'), 400)
    summary = a.req('GET','/sessions/ses_101?view=summary').data
    assert summary['id'] == 'ses_101' and 'updatedAt' not in summary and 'self' in summary['_links']
    for query in ['status=active&projectId=prj_web','offset=99']:
        result = a.req('GET','/sessions?'+query).data
        assert result['items'] == [] and result['links']['next'] is None
        if query == 'offset=99':
            assert result['links']['previous'] is not None
    for query in ('sort=oops','sort=title&sort=id','limit=0','limit=101','offset=-1','unknown=1'):
        a.req('GET','/sessions?'+query,400)
    for m,path in [('POST','/sessions?unexpected=1'),('DELETE','/projects/prj_ai?deleteContents=1'),('DELETE','/sessions/ses_101?deleteContents=no')]:
        a.req(m,path,400)
    assert set(a.req('POST','/sessions/ses_101',405).headers['allow'].replace(' ','').split(',')) == {'GET','PATCH','DELETE'}
    assert a.req('GET','/sessions/ses_101/chat',405).headers['allow'] == 'POST'
    passed('bounded collections, total/empty/overrun, followed preserved sort/filter links, invalid methods and controls')


def crud_and_formats(a,b,member,viewer,admin):
    root = a.req('GET','/api').data
    assert a.follow(root,'projects').data['page']['total'] == 1
    assert len(a.follow(root,'sessions').data) == 3
    for csrf in (None,'wrong-token',b.csrf):
        a.req('POST','/projects',403,{'name':'not authorized'},headers={'X-CSRF-Token':csrf})
    a.req('POST','/projects',403,{'name':'cross origin'},headers={'Origin':'https://example.test'})
    a.req('POST','/projects',422,{'userId':'usr_demo_two','name':'wrong owner'})
    assert one('SELECT count(*) FROM projects') == 2
    assert 'createProject' not in viewer.req('GET','/api').data['_links']
    viewer.req('POST','/projects',403,{'name':'no'})
    project = a.follow(root,'createProject','POST',status=201,
        data={'id':'prj_qa','name':'Fixture project','repositoryUrl':'https://github.com/php/php-src'})
    assert project.headers['location'] == '/projects/prj_qa' and project.data['userId'] == 'usr_demo_one'
    create = project.data['_links']['createSession']; assert create['method'] == 'POST'
    session = a.req('POST',create['href'],201,raw='<request><id>ses_qa</id><title>Markup &amp; quotes</title></request>',media='text/xml',accept=YAML)
    assert session.headers['location'] == '/sessions/ses_qa' and session.data['title'] == 'Markup & quotes'
    s = session.data
    message = a.follow(s,'createMessage','POST',status=201,raw='id: msg_qa\nrole: user\ncontent: "Quotation: <&>"\n',media='text/yaml',accept=XML)
    assert message.headers['location'] == '/sessions/ses_qa/messages/msg_qa'
    task = a.follow(s,'createTask','POST',status=201,data={'id':'tsk_qa','title':'Concurrent task','status':'pending'})
    assert task.headers['location'] == '/sessions/ses_qa/tasks/tsk_qa'
    a.req('POST',create['href'],409,{'id':'ses_qa','title':'duplicate'})
    assert one("SELECT count(*) FROM sessions WHERE id='ses_qa' AND title='Markup & quotes'") == 1
    a.req('PATCH','/projects/prj_qa',422,{'userId':'usr_demo_two'})
    a.req('PATCH','/sessions/ses_qa',422,{'projectId':'prj_web'})
    a.req('PATCH',task.headers['location'],422,{'sessionId':'ses_201'},headers=versions(task.data))
    assert one("SELECT user_id FROM projects WHERE id='prj_qa'") == 'usr_demo_one'
    assert one("SELECT project_id FROM sessions WHERE id='ses_qa'") == 'prj_qa'
    path = '/sessions/ses_qa?include=project,messages,tasks'
    expected = a.req('GET',path).data
    assert expected['project']['id'] == 'prj_qa' and expected['messages'][0]['content'] == 'Quotation: <&>'
    assert expected['tasks'][0]['id'] == 'tsk_qa' and expected['messagesPage']['total'] == 1
    for accept, chosen in [(XML,XML),(YAML,YAML),('text/xml','text/xml'),('text/yaml','text/yaml'),('*/*',JSON),
                           ('application/json;q=0.2, application/xml;q=0.9',XML),
                           ('application/json;q=0, application/yaml;q=0.8, */*;q=0.1',YAML)]:
        response = a.req('GET',path,accept=accept)
        assert response.headers['content-type'].split(';')[0] == chosen and response.data == expected
    for fmt in [JSON,XML,YAML]:
        assert a.req('GET','/sessions/not_found',404,accept=fmt).headers['content-type'].split(';')[0] == fmt
    a.req('GET',path,406,accept='image/png')
    a.req('GET',path,406,accept='*/*;q=0')
    p = '/projects/prj_qa/sessions'
    a.req('POST',p,415,raw='text',media='text/plain')
    a.req('POST',p,413,raw='a'*65537)
    for raw,media in [('{}',JSON),('<request/>',XML),('{}',YAML)]:
        a.req('POST',p,422,raw=raw,media=media)
    for raw,media in [('{',JSON),('{"title":"a","title":"b"}',JSON),('<request>',XML),
                      ('<request><title>a</title><title>b</title></request>',XML),('title: [',YAML),('title: a\ntitle: b\n',YAML)]:
        a.req('POST',p,400,raw=raw,media=media)
    for cl in (b,admin,member,viewer):
        for url in ['/projects/prj_qa','/projects/prj_qa/sessions','/sessions/ses_qa','/sessions/ses_qa/context',
                    '/sessions/ses_qa/messages','/sessions/ses_qa/messages/msg_qa','/sessions/ses_qa/tasks/tsk_qa']:
            cl.req('GET',url,404)
        assert cl.req('GET','/sessions?projectId=prj_qa').data['page']['total'] == 0
        assert 'prj_qa' not in {r['id'] for r in cl.req('GET','/projects').data['items']}
    b.req('DELETE','/sessions/ses_qa?deleteContents=1',404)
    b.req('POST','/sessions/ses_qa/messages',404,{'role':'user','content':'no'})
    a.req('GET','/sessions/ses_101/messages/msg_qa',404)
    members = '/projects/prj_qa/members'
    members_doc = a.req('GET',members).data
    a.follow(members_doc,'setMember','POST',data={'userId':'usr_qa_member','role':'member'})
    [membership] = a.req('GET',members).data['items']
    assert fields(membership) == {'userId':'usr_qa_member','role':'member'}
    assert member.req('GET','/sessions/ses_qa').data['id'] == 'ses_qa'
    member.req('PATCH','/sessions/ses_qa',200,{'title':'Shared session'})
    member.req('POST',members,403,{'userId':'usr_demo_two','role':'member'})
    member.req('DELETE','/projects/prj_qa',403)
    a.follow(members_doc,'setMember','POST',data={'userId':'usr_qa_member','role':'viewer'})
    assert 'update' not in member.req('GET','/sessions/ses_qa').data['_links']
    member.req('PATCH','/sessions/ses_qa',403,{'title':'denied after downgrade'})
    a.follow(membership,'delete','DELETE',status=204)
    member.req('GET','/sessions/ses_qa',404)
    a.req('PATCH','/projects/prj_qa',200,{'visibility':'public'})
    public = viewer.req('GET','/sessions/ses_qa').data
    assert not set(public['_links']).intersection({'update','delete','chat','createTask','createMessage'})
    viewer.req('PATCH','/sessions/ses_qa',403,{'title':'no'})
    viewer.req('POST','/sessions/ses_qa/chat',403,{'message':'no'})
    a.req('PATCH','/projects/prj_qa',200,{'visibility':'private'})
    viewer.req('GET','/sessions/ses_qa',404)
    assert one("SELECT title FROM sessions WHERE id='ses_qa'") == 'Shared session'
    passed('negotiated real JSON/XML/YAML inputs, semantic outputs/errors, duplicate keys and IDs, role-aware discovery, tenant/membership scoping and revocation')
    return task.data


def matrix(a):
    # Independent parsers validate the real bodies on every cross-product cell.
    for i, media in enumerate([JSON, XML, YAML]):
        for j, accept in enumerate([JSON, XML, YAML]):
            title = f'format-{i}-{j}'
            def payload(title):
                return {JSON: json.dumps({'title':title}), XML:f'<request><title>{title}</title></request>',
                        YAML: f'title: "{title}"\n'}[media]
            result = a.req('POST','/projects/prj_qa/sessions',201,raw=payload(title),media=media,accept=accept)
            assert result.data['title'] == title and result.headers['content-type'].split(';')[0] == accept
            location = result.headers['location']
            result = a.req('PATCH',location,raw=payload(title+'-patched'),media=media,accept=accept)
            assert result.data['title'] == title+'-patched' and result.headers['content-type'].split(';')[0] == accept
            a.req('DELETE',location,204)
    passed('POST and PATCH × three input formats × three negotiated response formats')


def versions(row):
    return {'If-Match':f'"{row["version"]}"'}


def compete(action):
    gate = threading.Barrier(2, timeout=10)
    def attempt(n):
        gate.wait()
        return action(n)
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        return list(pool.map(attempt, range(2)))


def task_versions(a, task):
    path = task['_links']['update']['href']
    a.req('PATCH',path,428,{'status':'running'})
    a.req('PATCH',path,409,{'status':'completed'},headers=versions(task))
    responses = compete(lambda n: a.req('PATCH',path,(200,412),
        {'status':'running','title':f'worker-{n}'},headers=versions(task)))
    assert sorted(r.status for r in responses) == [200,412]
    winner = next(r.data for r in responses if r.status == 200)
    assert winner['version'] == task['version'] + 1 and winner['status'] == 'running'
    assert rows('SELECT title,version,status FROM tasks WHERE id=%s',task['id']) == [(winner['title'],winner['version'],'running')]
    current = a.req('GET',path).data
    for status in ('failed','pending','running','completed'):
        current = a.req('PATCH',path,200,{'status':status},headers=versions(current)).data
    assert 'update' not in current['_links']
    assert rows('SELECT version,status FROM tasks WHERE id=%s',task['id']) == [(current['version'],'completed')]
    passed('two competing task writers: one commits, one 412; illegal transition, failed-task retry and terminal state vs SQL')


def tools(a,b,viewer):
    s = a.req('GET','/sessions/ses_qa').data
    root = a.follow(s,'toolRuns').data
    assert not root['items']
    request = root['_links']['create']['href']
    for bad in [{'operation':'unknown.operation','path':'notes.txt'},
                {'operation':'workspace.read','path':'nested/name'},
                {'operation':'workspace.write','path':'notes.txt'}]:
        a.req('POST',request,422,bad)
    assert one('SELECT count(*) FROM tool_runs') == 0
    def start(op, **extra):
        reply = a.req('POST',request,201,{'operation':'workspace.'+op,'path':'notes.txt',**extra})
        row = reply.data
        assert row['status'] == 'requested' and row['version'] == 1 and 'execute' not in row['_links']
        assert reply.headers['location'] == row['_links']['self']['href']
        return row
    def step(row, rel, expected=200):
        return a.follow(row,rel,'POST',data={},headers=versions(row),status=expected).data
    read = start('read')
    href = read['_links']['self']['href']
    b.req('POST',href+'/approve',404,{},headers=versions(read))
    a.req('POST',href+'/execute',409,{},headers=versions(read))
    a.req('POST',href+'/approve',428,{})
    a.req('PATCH','/projects/prj_qa',200,{'visibility':'public'})
    viewed = viewer.req('GET',href).data
    assert set(viewed['_links']) == {'self'}
    viewer.req('POST',href+'/approve',403,{},headers=versions(read))
    viewer.req('POST',request,403,{'operation':'workspace.read','path':'notes.txt'})
    a.req('PATCH','/projects/prj_qa',200,{'visibility':'private'})
    read = step(read,'approve')
    step(read,'execute',409)
    assert rows('SELECT status,version,result FROM tool_runs WHERE id=%s',read['id']) == [('approved',read['version'],None)]
    assert one('SELECT count(*) FROM workspace_files') == 0
    write = step(start('write',content='Persisted in PostgreSQL'),'approve')
    first = compete(lambda _: a.follow(write,'execute','POST',status=(200,412),data={},headers=versions(write)))
    assert sorted(r.status for r in first) == [200,412]
    assert rows('SELECT session_id,path,content FROM workspace_files') == [('ses_qa','notes.txt','Persisted in PostgreSQL')]
    done = step(read,'execute')
    assert done['status'] == 'completed' and done['result'] == 'Persisted in PostgreSQL'
    assert set(done['_links']) == {'self'} and done['executedBy'] == 'usr_demo_one'
    page = a.req('GET',request+'?limit=1').data
    assert page['page']['total'] == 2 and a.follow(page,'next').data['items'][0]['id'] == read['id']
    passed('allowlist, read-only actor, private run, explicit approval, single execution, rolled-back missing file and successful retry')


def composition(a):
    path = '/sessions/ses_qa/context'
    local = a.req('GET',path).data
    assert local['session']['title'] == one("SELECT title FROM sessions WHERE id='ses_qa'")
    assert local['project']['id'] == 'prj_qa' and local['messages']['items'][0]['id'] == 'msg_qa'
    assert local['sources']['local'] == 'PostgreSQL'
    assert local['external']['reason'] == 'upstream_disabled_for_demo'
    a.req('GET',path+'?simulateUpstreamFailure=1',400)
    for port,reason in enumerate([None,'upstream_timeout','invalid_upstream_response','upstream_rate_limited','upstream_http_503'],8090):
        fixture = Client(f'api:{port}')
        fixture.cookie = a.cookie
        doc = fixture.req('GET',path).data
        for part in ('session','project','messages','tasks'):
            assert doc[part] == local[part]
        ext = doc['external']
        assert ext['provenance'] == 'fixture' and ext['source'] == doc['sources']['external']
        if reason:
            assert ext['status'] == 'unavailable' and ext['repository'] is None and ext['reason'] == reason
        else:
            assert ext['status'] == 'ok' and ext['repository']['fullName'] == 'php/php-src'
    passed('real local DB composite with explicitly labelled transport fixtures: success, timeout, malformed, quota, HTTP 503 and offline')


def chat(a,b,viewer):
    path = '/sessions/ses_qa/chat'
    payload = {'message':PROMPT,'provider':'openai','model':'gpt-4.1-mini'}
    def provider(client):
        runtime = client.req('GET','/api/runtime').data
        assert runtime['capabilities']['shell_execution'] is False
        providers = {p['id']:p for p in runtime['ai']['providers']}
        assert set(providers) == {'openai','anthropic','ollama'}
        return providers['openai']
    assert provider(a)['configured'] is False
    assert 'setCredential' not in provider(viewer).get('_links', {})
    for invalid in [{'message':''},{'message':'ok','provider':'unknown'},{'message':'ok','model':'bad value'}]:
        a.req('POST',path,422,invalid)
    missing = a.req('POST',path,503,payload)
    assert missing.data['code'] == 'ai_not_configured'
    budget = a.req('GET','/api/budget').data
    assert budget['limit'] == 2 and budget['remaining'] == 2 and budget['reserved'] == budget['spent'] == 0
    cref = '/api/credentials/openai'
    viewer.req('PUT',cref,403,{'apiKey':KEY})
    a.req('PUT',cref,403,{'apiKey':KEY},headers={'X-CSRF-Token':None})
    saved = a.follow(provider(a),'setCredential','PUT',data={'apiKey':KEY}).data
    assert saved['configured'] and provider(a)['configured'] and not provider(b)['configured']
    sealed = one("SELECT sealed_key FROM provider_credentials WHERE user_id='usr_demo_one' AND provider='openai'")
    assert sealed and KEY not in sealed
    # No live cloud endpoint is reachable in the internal Docker network. Give
    # this tenant one unit left, then send two HTTP requests to independent PHP workers.
    db.execute("""INSERT INTO chat_budgets (user_id,day,spent) VALUES ('usr_demo_one',(now() AT TIME ZONE 'UTC')::date,1)
        ON CONFLICT(user_id,day) DO UPDATE SET spent=1""")
    attempts = compete(lambda _: a.req('POST',path,(429,502,504),payload))
    assert sum(r.status == 429 for r in attempts) == 1
    transport = next(r for r in attempts if r.status != 429)
    assert transport.data['code'] in ('ai_upstream_unavailable','ai_upstream_timeout')
    after = a.req('GET','/api/budget').data
    assert after['remaining'] == after['reserved'] == 0 and after['spent'] == 2
    assert rows("SELECT spent,reserved FROM chat_budgets WHERE user_id='usr_demo_one'") == [(2,0)]
    assert rows("SELECT outcome,completed_at IS NOT NULL FROM chat_dispatches WHERE user_id='usr_demo_one'") == [('failed', True)]
    assert one("SELECT count(*) FROM messages WHERE session_id='ses_qa'") == 1
    assert a.req('GET','/sessions/ses_qa/messages').data['page']['total'] == 1
    assert b.req('GET','/api/budget').data['remaining'] == 2
    a.follow(saved,'deleteCredential','DELETE',status=204)
    assert not provider(a)['configured'] and one('SELECT count(*) FROM provider_credentials') == 0
    archived = a.req('PATCH','/sessions/ses_qa',data={'status':'archived'}).data
    assert 'chat' not in archived['_links']
    a.req('POST',path,409,payload)
    a.req('PATCH','/sessions/ses_qa',data={'status':'active'})
    passed('actor-specific encrypted key CRUD, missing-key/archived/offline failures, competing last budget unit, zero saved chat turns')


def check_migration_replay():
    def snapshot():
        return {table:one(sql.SQL("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]') FROM {} t")
                          .format(sql.Identifier(table)))
                for table, in rows("SELECT tablename FROM pg_tables WHERE schemaname='public'")}
    before = snapshot()
    migrations = sorted(Path('/fixtures/migrations').glob('*.sql'))
    assert migrations, 'No migrations found for the replay check'
    for path in migrations:
        db.execute(path.read_text(encoding='utf-8'))
    assert snapshot() == before, 'Migrations changed existing synthetic data'
    passed('reapplied additive migrations after real writes; all public table rows identical')


def rollback_delete(a):
    a.req('DELETE','/sessions/ses_qa',409)
    # DELIBERATE database fault after child deletion, before parent deletion.
    # PostgreSQL must roll back children AND transactional audit entries.
    before = rows("SELECT (SELECT count(*) FROM messages WHERE session_id='ses_qa'), "
                  "(SELECT count(*) FROM tasks WHERE session_id='ses_qa'),(SELECT count(*) FROM auth_audit)")
    db.execute("""CREATE FUNCTION qa_abort() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION USING ERRCODE='23503',MESSAGE='synthetic QA rollback'; END $$;
        CREATE TRIGGER qa_reject_parent BEFORE DELETE ON sessions FOR EACH ROW
        WHEN (OLD.id='ses_qa') EXECUTE FUNCTION qa_abort()""")
    try:
        a.req('DELETE','/sessions/ses_qa?deleteContents=1',409)
        actual = rows("SELECT (SELECT count(*) FROM messages WHERE session_id='ses_qa'), "
                      "(SELECT count(*) FROM tasks WHERE session_id='ses_qa'),(SELECT count(*) FROM auth_audit)")
        assert actual == before and actual[0][:2] == (1,1)
        full = a.req('GET','/sessions/ses_qa?include=messages,tasks').data
        assert full['messages'][0]['id'] == 'msg_qa' and full['tasks'][0]['id'] == 'tsk_qa'
    finally:
        db.execute('DROP TRIGGER qa_reject_parent ON sessions; DROP FUNCTION qa_abort()')
    deletion = a.follow(full,'delete','DELETE',status=204)
    assert one("SELECT count(*) FROM auth_audit WHERE request_id=%s AND actor_id='usr_demo_one' AND event='sessions.delete'",deletion.rid) == 1
    for table in ['messages','tasks','workspace_files','tool_runs']:
        assert one(sql.SQL("SELECT count(*) FROM {} WHERE session_id='ses_qa'").format(sql.Identifier(table))) == 0
    for path in ['/sessions/ses_qa','/sessions/ses_qa/messages/msg_qa','/sessions/ses_qa/tasks/tsk_qa']:
        a.req('GET',path,404)
    a.req('DELETE','/projects/prj_qa',204)
    assert one('SELECT count(*) FROM projects') == 2 and one('SELECT count(*) FROM sessions') == 5
    passed('FK conflict, injected rollback including audit rows, retry, cascade cleanup, original seed counts intact')


def login_limit():
    anon = Client()
    for _ in range(10):
        anon.req('POST','/auth/login',401,{'username':'qa_limit','password':'incorrect'})
    limited = anon.req('POST','/auth/login',429,{'username':'qa_limit','password':'incorrect'})
    assert int(limited.headers['retry-after']) > 0
    passed('bounded authentication attempts and Retry-After')


def check_logs():
    expected = json.loads(LOG_EXPECTATIONS.read_text(encoding='utf-8'))
    log = sys.stdin.read()
    assert log and not any(s in log for s in expected['secrets']), 'Unsafe API log (body or credential); content withheld'
    seen = {}
    for line in log.splitlines():
        start = line.find('{')
        if start < 0:
            continue
        try:
            entry = json.loads(line[start:])
        except json.JSONDecodeError:
            continue
        if entry.get('event') == 'http_request':
            assert entry['requestId'] not in seen, 'Duplicate HTTP request log entry'
            seen[entry['requestId']] = entry
    for rid,(method,path,status) in expected['requests'].items():
        entry = seen.get(rid)
        group = path.split('/', 2)[1]
        route = group if group in {'auth', 'api', 'projects', 'sessions'} else 'page'
        assert entry and (entry['method'], entry['route'], entry['status']) == (method, route, status), f'Uncorrelated request {rid} ({method} {path} {status})'
    LOG_EXPECTATIONS.unlink()
    passed(f"{len(expected['requests'])} real request-log correlations; no password, key, cookie, CSRF token or prompt in logs")


def synthetic_chat(b):
    from http.server import ThreadingHTTPServer
    handler = runpy.run_path('scripts/test-ollama-transport.py')['OllamaFixture']
    # Bind only inside the internal QA network. This fixture cannot forward requests.
    with ThreadingHTTPServer(('0.0.0.0',11435),handler) as server:
        thread = threading.Thread(target=server.serve_forever,daemon=True)
        thread.start()
        cl = Client('api:8095'); cl.cookie = b.cookie; cl.csrf = b.csrf
        path = '/sessions/ses_201/chat'
        message = {'provider':'ollama','model':'qa:model','message':'success'}
        try:
            # Abort the second insert after a successful provider response:
            # neither turn nor its audit entry may commit; the dispatch is still charged.
            db.execute("""CREATE FUNCTION qa_reject_turn() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='synthetic QA turn rejection'; END $$;
                CREATE TRIGGER qa_reject_second BEFORE INSERT ON messages FOR EACH ROW
                WHEN (NEW.session_id='ses_201' AND NEW.role='assistant') EXECUTE FUNCTION qa_reject_turn()""")
            try:
                reply = cl.req('POST',path,422,message)
                assert one("SELECT count(*) FROM messages WHERE session_id='ses_201'") == 0
                assert one("SELECT count(*) FROM auth_audit WHERE request_id=%s AND event='messages.insert'",reply.rid) == 0
                assert cl.req('GET','/sessions/ses_201/messages').data['items'] == []
            finally:
                db.execute('DROP TRIGGER qa_reject_second ON messages; DROP FUNCTION qa_reject_turn()')
            answer = cl.req('POST',path,200,message).data
            assert answer['sessionId'] == 'ses_201' and answer['provider'] == 'ollama' and answer['model'] == 'qa:model'
            turns = cl.req('GET','/sessions/ses_201/messages').data['items']
            assert {(r['role'],r['content']) for r in turns} == {('assistant','fixture result'),('user','success')}
            assert sorted((r['id'],r['content']) for r in turns) == rows("SELECT id,content FROM messages WHERE session_id='ses_201' ORDER BY id")
            assert rows("""SELECT outcome,user_message_id,assistant_message_id FROM chat_dispatches
                WHERE user_id='usr_demo_two' AND session_id='ses_201' ORDER BY outcome""") == [
                ('answered',answer['userMessage']['id'],answer['assistantMessage']['id']),('failed',None,None)]
            for turn in turns:
                cl.follow(turn,'delete','DELETE',status=204)
        finally:
            server.shutdown()
            thread.join()
    passed('synthetic Ollama HTTP transport: successful dispatch, atomic turn-pair rollback, retry and SQL/API agreement; no live inference claimed')


if __name__ == '__main__':
    if not __debug__:
        raise SystemExit('Python assertions must be enabled for the integration tests.')
    if sys.argv[1:] == ['--check-logs']:
        check_logs()
    else:
        # Hard guard before any direct SQL: these names are used only in the new disposable cluster.
        if os.environ['DATABASE_URL'] != 'postgres://osiris_dev:lab-local-only@db:5432/osiris_test_populated':
            raise SystemExit('Refusing to run outside the disposable populated fixture database.')
        contract = runpy.run_path('scripts/validate-contract.py')['Contract']()
        with psycopg.connect(os.environ['DATABASE_URL'], autocommit=True) as db:
            a,b,member,viewer,admin = pages_and_identity()
            seeds = scope_query('usr_demo_one'),scope_query('usr_demo_two')
            collections(a)
            task = crud_and_formats(a,b,member,viewer,admin)
            matrix(a)
            task_versions(a,task)
            tools(a,b,viewer)
            composition(a)
            chat(a,b,viewer)
            synthetic_chat(b)
            check_migration_replay()
            rollback_delete(a)
            assert seeds == (scope_query('usr_demo_one'),scope_query('usr_demo_two'))
            login_limit()
            audit = one("SELECT jsonb_agg(to_jsonb(t))::text FROM auth_audit t")
            assert audit and not any(s in audit for s in sensitive), 'Private data in persistent audit history'
        with os.fdopen(os.open(LOG_EXPECTATIONS, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600), 'w', encoding='utf-8') as out:
            json.dump({'requests': correlations, 'secrets': list(sensitive)}, out)
        passed(f'{exchanges} black-box HTTP exchanges; non-HTML representations checked against OpenAPI')
