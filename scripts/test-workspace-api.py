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
POLICY_PROMPT = 'Exercise scoped file permissions'
THREAD_CASE = 'QA-thread-command: '
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

    def login(self, name, role, password=PASSWORD):
        result = self.req('POST','/auth/login',200,{'username':name,'password':password})
        return self.accept_session(result, name, role)

    def accept_session(self, result, name, role):
        value = result.data
        assert set(value) == {'user','csrfToken'}
        assert value['user']['username'] == name and value['user']['role'] == role
        assert set(value['user']) == {'id','username','role'}
        cookie = SimpleCookie(result.headers['set-cookie'])['osiris_session']
        assert cookie['httponly'] and cookie['samesite'].lower() == 'strict'
        assert cookie['path'] == '/' and not cookie['domain']
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
        FROM sessions s LEFT JOIN projects p ON p.id=s.project_id
        WHERE p.user_id=%s OR s.owner_id=%s ORDER BY s.created_at,s.id''', user_id, user_id)]


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
    for path in ('/','/login','/register','/dashboard','/workspace','/chat','/manage','/api-checks'):
        page = anon.req('GET',path,accept='text/html',html=True).data
        assert '<input' in page and '<button' in page and 'src="/assets/workspace.js"' in page, path
    assert 'Page not found' in anon.req('GET','/dashboard/no-such-page',404,accept='text/html',html=True).data
    anon.req('GET','/qa-no-such-route',404)
    for path in ('/auth/me','/api','/api/runtime','/projects','/sessions','/sessions/ses_101',
                 '/projects/prj_ai/sessions','/sessions/ses_101/context','/sessions/ses_101/tool-runs','/sessions/ses_101/permissions',
                 '/sessions/ses_101/goal','/sessions/ses_101/asides'):
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
    catalog = member.follow(member.req('GET','/api').data,'models').data['providers']
    assert {p['id']: (p['status'],p['models']) for p in catalog} == {id_: ('not_configured',[]) for id_ in ('ollama','openai','anthropic')}
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


def registration():
    anon, member = Client(), Client()
    path = '/auth/register'
    name = 'Qa-Self1_'.ljust(100, 'a')
    # Use both UTF-8 byte limits, not just strings with 12 or 72 characters.
    short = PASSWORD[:10] + 'é'
    long = PASSWORD.ljust(70, '-') + 'é'
    sensitive.update((short, long, json.dumps(short)[1:-1], json.dumps(long)[1:-1]))
    credentials = {'username':name, 'password':short}
    db.execute("INSERT INTO users(id,username,email) VALUES ('usr_qa_reserved',%s,'qa-reserved@example.test')",
               (name.lower(),))
    created = anon.req('POST',path,201,credentials)
    member.accept_session(created, name, 'member')
    uid = created.data['user']['id']
    assert created.headers['location'] == '/auth/me'
    [(stored_id, role, hashed)] = rows('SELECT id,role,password_hash FROM users WHERE username=%s',name)
    assert (stored_id, role) == (uid, 'member') and hashed.startswith(('$argon2','$2')) and short not in hashed

    def account_state():
        return rows('SELECT id,username,email,password_hash,role FROM users ORDER BY id'), rows(
            'SELECT token_hash,user_id,expires_at FROM auth_sessions ORDER BY token_hash')

    before = account_state()
    invalid = {'username':'qa_invalid', 'password':PASSWORD}
    for body in (
        {'username':name}, {'password':short}, {**invalid,'username':''}, {**invalid,'username':name+'a'},
        {**invalid,'username':'bad name'}, {**invalid,'username':'café'}, {**invalid,'password':123},
        {**invalid,'password':short[:-1]+'x'}, {**invalid,'password':long+'x'},
        {**invalid,'role':'admin'}, {**invalid,'id':'usr_demo_one'},
    ):
        assert 'set-cookie' not in anon.req('POST',path,422,body).headers
    # A NUL is rejected by the shared wire-format decoder before credential validation.
    assert 'set-cookie' not in anon.req('POST',path,400,{**invalid,'password':PASSWORD+'\0'}).headers
    assert 'set-cookie' not in anon.req('POST',path,403,invalid,headers={'Origin':'https://example.test'}).headers
    for taken in (name, name.lower(), 'demo_one'):
        # Use a different password from the owner of each enabled account.
        password = long if taken == 'demo_one' else PASSWORD
        assert 'set-cookie' not in anon.req('POST',path,409,{'username':taken,'password':password}).headers
    assert account_state() == before, 'Rejected registration changed accounts or sessions'
    anon.req('GET','/auth/me',401)

    page = member.req('GET','/projects').data
    assert page['items'] == [] and page['page']['total'] == 0
    assert member.req('GET','/sessions').data == []
    for private in ('/projects/prj_ai','/projects/prj_web/sessions','/sessions/ses_101'):
        member.req('GET',private,404)
    member.req('POST','/api/maintenance/prune',403)
    member.req('POST','/auth/logout',403,headers={'X-CSRF-Token':None})
    member.req('POST','/auth/logout',204)
    member.req('GET','/auth/me',401)
    again = Client().login(name,'member',short)
    assert again.req('GET','/auth/me').data['user']['id'] == uid and again.cookie != member.cookie

    maximum = anon.req('POST',path,201,{'username':'Q','password':long},accept=XML)
    anon.accept_session(maximum,'Q','member')
    passed('public member registration, byte limits, disabled/provisioned/duplicate username protection, persisted login and private project isolation')


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
    linked = a.follow(root,'createSession','POST',status=201,data={'projectId':'prj_qa','title':'Linked at creation'}).data
    assert a.follow(linked,'project').data['id'] == linked['projectId'] == 'prj_qa'
    assert rows('SELECT project_id,owner_id FROM sessions WHERE id=%s',linked['id']) == [('prj_qa',None)]
    a.follow(linked,'delete','DELETE',status=204)
    member.req('POST','/sessions',404,{'projectId':'prj_qa','title':'Not my project'})
    for wrong_parent in ('prj_web',None):
        a.req('POST',create['href'],422,{'projectId':wrong_parent,'title':'Wrong parent'})
    assert one("SELECT count(*) FROM sessions WHERE id='ses_qa' AND title='Markup & quotes'") == 1
    a.req('PATCH','/projects/prj_qa',422,{'userId':'usr_demo_two'})
    a.req('PATCH','/sessions/ses_qa',422,{'projectId':None})
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
    assert a.follow(s,'permissions').data == member.follow(s,'permissions').data == {'mode':'ask'}
    for invalid in ({}, {'mode':'always'}, {'mode':True}, {'mode':'yolo','userId':'usr_demo_one'}):
        member.follow(s,'setPermissions','PUT',status=422,data=invalid)
    member.follow(s,'setPermissions','PUT',status=403,data={'mode':'yolo'},headers={'X-CSRF-Token':None})
    permissions = s['_links']['permissions']['href']
    member.req('GET',permissions+'?all=1',400)
    member.req('PUT',permissions+'?all=1',400,{'mode':'yolo'})
    assert member.req('PATCH',permissions,405,{}).headers['allow'] == 'GET, PUT'
    assert member.follow(s,'setPermissions','PUT',data={'mode':'yolo'}).data == {'mode':'yolo'}
    assert member.follow(s,'permissions').data == {'mode':'yolo'} and a.follow(s,'permissions').data == {'mode':'ask'}
    b.follow(s,'permissions',status=404)
    b.follow(s,'setPermissions','PUT',status=404,data={'mode':'allow'})
    assert member.req('GET','/sessions/ses_qa').data['id'] == 'ses_qa'
    member.req('PATCH','/sessions/ses_qa',200,{'title':'Shared session'})
    member.req('POST',members,403,{'userId':'usr_demo_two','role':'member'})
    member.req('DELETE','/projects/prj_qa',403)
    a.follow(members_doc,'setMember','POST',data={'userId':'usr_qa_member','role':'viewer'})
    member.req('PATCH','/sessions/ses_qa',403,{'title':'denied after downgrade'})
    read_only = member.req('GET','/sessions/ses_qa').data
    assert not {'update','setPermissions','startAgent'} & read_only['_links'].keys()
    assert member.follow(read_only,'permissions').data == {'mode':'yolo'}
    member.follow(s,'setPermissions','PUT',status=403,data={'mode':'ask'})
    member.follow(s,'startAgent','POST',status=403,data={'message':'No tools for viewers','tools':True})
    a.follow(membership,'delete','DELETE',status=204)
    member.req('GET','/sessions/ses_qa',404)
    a.req('PATCH','/projects/prj_qa',200,{'visibility':'public'})
    public = viewer.req('GET','/sessions/ses_qa').data
    assert not set(public['_links']).intersection({'update','delete','chat','createTask','createMessage'})
    viewer.req('POST','/sessions',403,{'projectId':'prj_qa','title':'Read only'})
    viewer.req('PATCH','/sessions/ses_qa',403,{'title':'no'})
    viewer.req('POST','/sessions/ses_qa/chat',403,{'message':'no'})
    for rel in ('setGoal','askAside'):
        assert rel not in public['_links']
    assert viewer.follow(public,'goal').data == {'goal':None,'status':'none'}
    assert viewer.follow(public,'asides').data == {'items':[]}
    viewer.follow(s,'setGoal','PUT',status=403,data={'goal':'read only'})
    viewer.follow(s,'askAside','POST',status=403,data={'message':'read only','provider':'ollama','model':'qa:agent'})
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
    assert not {'chat','setGoal','askAside'} & archived['_links'].keys()
    assert a.req('PUT','/sessions/ses_qa/goal',409,{'goal':'archived'}).data['code'] == 'invalid_session_state'
    assert a.req('POST','/sessions/ses_qa/asides',409,payload).data['code'] == 'invalid_session_state'
    a.req('POST',path,409,payload)
    a.req('PATCH','/sessions/ses_qa',data={'status':'active'})
    passed('actor-specific encrypted key CRUD, missing-key/archived/offline failures, competing last budget unit, zero saved chat turns')


def standalone_sessions(member, other, viewer, admin):
    root = member.req('GET','/api').data
    assert member.follow(root,'projects').data['page']['total'] == 0
    projects_before = rows('SELECT id FROM projects ORDER BY id')
    create = {'href':'/sessions','method':'POST'}
    assert root['_links']['createSession'] == create
    assert 'createSession' not in viewer.req('GET','/api').data['_links']
    assert 'create' not in viewer.req('GET','/sessions?limit=1').data['_links']
    viewer.req('POST','/sessions',403,{'title':'Viewer cannot create'})
    member.follow(root,'createSession','POST',status=403,data={'title':'Missing CSRF'},headers={'X-CSRF-Token':None})
    member.follow(root,'createSession','POST',status=422,data={'title':'Not mine','ownerId':'usr_demo_two'})

    personal = member.follow(root,'createSession','POST',status=201,data={'title':'Personal chat'}).data
    sid = personal['id']
    path = personal['_links']['self']['href']
    assert path == '/sessions/'+sid and personal['projectId'] is None and 'project' not in personal['_links']
    assert rows('SELECT project_id,owner_id FROM sessions WHERE id=%s',sid) == [(None,'usr_qa_member')]
    member.follow(personal,'update','PATCH',data={'title':'Private conversation'})
    for fields_to_move in ({'projectId':'prj_qa'},{'ownerId':'usr_demo_two'}):
        member.req('PATCH',path,422,fields_to_move)
    [owned] = member.follow(root,'sessions').data
    assert owned['id'] == sid and owned['title'] == 'Private conversation' and owned['projectId'] is None
    page = member.req('GET','/sessions?view=summary&include=project').data
    [summary] = page['items']
    assert page['page']['total'] == 1 and page['_links']['create'] == create
    assert summary['id'] == sid and 'updatedAt' not in summary and 'project' not in summary['_links']
    assert summary['projectId'] is None and summary['project'] is None
    assert member.req('GET','/sessions?projectId=prj_qa').data['page']['total'] == 0
    second = member.follow(page,'create','POST',status=201,data={'title':'Also private','projectId':None},accept=XML).data
    assert second['id'] != sid and second['projectId'] is None and 'project' not in second['_links']
    assert member.follow(personal,'permissions').data == {'mode':'ask'}
    assert member.follow(second,'setPermissions','PUT',data={'mode':'allow'}).data == {'mode':'allow'}
    assert member.follow(personal,'permissions').data == {'mode':'ask'}
    assert rows('SELECT id FROM projects ORDER BY id') == projects_before
    assert member.follow(root,'projects').data['items'] == []

    msg = member.follow(personal,'createMessage','POST',status=201,data={'role':'user','content':'A private note'}).data
    task = member.follow(personal,'createTask','POST',status=201,data={'title':'A private task'}).data
    task = member.follow(task,'update','PATCH',data={'status':'running'},headers=versions(task)).data
    run = member.follow(personal,'requestTool','POST',status=201,data={'operation':'workspace.read','path':'notes.txt'}).data
    assert member.follow(msg,'self').data == msg and member.follow(task,'self').data == task
    assert member.follow(run,'self').data == run
    member.req('GET',second['_links']['self']['href']+'/messages/'+msg['id'],404)
    member.follow(second,'delete','DELETE',status=204)
    assert not rows('SELECT mode FROM chat_permissions WHERE session_id=%s',second['id'])

    for media in (JSON,XML,YAML):
        detail = member.req('GET',path+'?include=project,messages,tasks',accept=media)
        assert detail.headers['content-type'].split(';')[0] == media
        assert detail.data['project'] is None and detail.data['projectId'] is None
        assert detail.data['messages'] == [msg] and detail.data['tasks'] == [task]
        context = member.follow(personal,'context',accept=media).data
        assert context['project'] is None and context['session'] == owned
        assert context['messages']['items'] == [msg] and context['tasks']['items'] == [task]
        assert context['external']['status'] == 'not_configured' and context['external']['repository'] is None

    for outsider in (other,viewer,admin):
        assert sid not in {item['id'] for item in outsider.req('GET','/sessions').data}
        assert outsider.req('GET','/sessions?q=Private%20conversation&include=project').data['page']['total'] == 0
        for obj,rel in [(personal,'self'),(personal,'messages'),(personal,'tasks'),(personal,'toolRuns'),
                        (personal,'context'),(msg,'self'),(task,'self'),(run,'self')]:
            outsider.follow(obj,rel,status=404)
        outsider.follow(personal,'update','PATCH',status=404,data={'title':'Not mine'})
        outsider.follow(personal,'delete','DELETE',status=404)
        outsider.follow(personal,'chat','POST',status=404,data={'message':'Not mine'})
        outsider.follow(personal,'createMessage','POST',status=404,data={'role':'user','content':'Not mine'})
        outsider.follow(msg,'delete','DELETE',status=404)
        outsider.follow(task,'update','PATCH',status=404,data={'status':'failed'},headers=versions(task))
        outsider.follow(run,'approve','POST',status=404,data={},headers=versions(run))

    member.follow(msg,'delete','DELETE',status=204)
    assert member.follow(personal,'messages').data['page']['total'] == 0
    passed('projectless member sessions: root and page creation, persisted ownership, formats, private children and role isolation')
    return personal


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
    for table in ['messages','tasks','workspace_files','tool_runs','chat_permissions']:
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


def registration_limit():
    # This API uses a separate database; exhausting its IP bucket cannot block browser signup.
    anon = Client('empty:8080')
    for attempt in range(20):
        anon.req('POST','/auth/register',422,{'username':f'qa_limit_{attempt}'})
    credentials = {'username':'qa_limit','password':PASSWORD}
    limited = anon.req('POST','/auth/register',429,credentials)
    assert int(limited.headers['retry-after']) > 0 and 'set-cookie' not in limited.headers
    anon.req('POST','/auth/login',401,credentials)
    passed('registration IP limit returns Retry-After without creating the account')


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


def agent_workflow(client, outsider, session):
    payload = {'message':'Use the marker file', 'provider':'ollama', 'model':'qa:agent', 'tools':True}
    assert client.follow(session,'agent').data == {'run':None}
    client.follow(session,'startAgent','POST',status=422,data={**payload,'mode':'yolo'})
    opened = client.follow(session,'startAgent','POST',status=201,data=payload).data
    client.follow(session,'startAgent','POST',status=409,data=payload)
    outsider.follow(opened,'self',status=404)
    outsider.follow(opened,'step','POST',status=404,data={})
    before = client.req('GET','/api/budget').data['spent']
    review = client.follow(opened,'step','POST',data={}).data
    assert review['status'] == 'review'
    [proposal] = review['events']
    assert (proposal['name'],proposal['path']) == ('write','marker.txt')
    assert not rows('SELECT content FROM workspace_files WHERE session_id=%s AND path=%s',session['id'],'marker.txt')
    client.follow(session,'setPermissions','PUT',data={'mode':'yolo'})
    client.follow(opened,'step','POST',status=409,data={})  # consumed round URL
    client.follow(review,'step','POST',status=409,data={})  # no premature apply or dispatch
    assert client.req('GET','/api/budget').data['spent'] == before + 1
    assert client.follow(session,'agent').data['run'] == review
    request = client.follow(proposal,'toolRun').data
    assert request['id'] == proposal['runId'] and request['status'] == 'requested'
    outsider.follow(proposal,'toolRun',status=404)
    approved = client.follow(request,'approve','POST',data={},headers=versions(request)).data
    client.follow(approved,'execute','POST',data={},headers=versions(approved))
    read = client.follow(review,'step','POST',data={}).data
    assert read['status'] == 'ready' and read['events'][0]['output'] == 'Wrote 6 bytes'
    assert read['events'][1]['name'] == 'read' and 'canyon' in read['events'][1]['output']
    complete = client.follow(read,'step','POST',data={}).data
    assert complete['status'] == 'completed' and 'step' not in complete['_links']
    assert client.req('GET','/api/budget').data['spent'] == before + 3
    last = next(m for m in client.follow(session,'messages').data['items'] if m.get('agentEvents'))
    trace = [fields(event) for event in complete['events']]
    assert last['content'] == 'The stored marker is canyon.' and last['agentEvents'] == trace
    assert one('SELECT agent_events FROM messages WHERE id=%s',last['id']) == trace
    second = client.follow(session,'startAgent','POST',status=201,data=payload).data
    cancelled = client.follow(second,'cancel','POST',data={}).data
    assert cancelled['status'] == 'cancelled'
    client.follow(second,'step','POST',status=409,data={})
    assert client.req('GET','/api/budget').data['spent'] == before + 3
    assert client.follow(last,'self').data['agentEvents'] == trace
    client.follow(session,'setPermissions','PUT',data={'mode':'allow'})
    replacement = client.follow(session,'startAgent','POST',status=201,data=payload).data
    pending = client.follow(replacement,'step','POST',data={}).data
    assert pending['status'] == 'review'
    manual = client.follow(session,'requestTool','POST',status=201,data={'operation':'workspace.write','path':'marker.txt','content':'newer human edit'}).data
    manual = client.follow(manual,'approve','POST',data={},headers=versions(manual)).data
    client.follow(manual,'execute','POST',data={},headers=versions(manual))
    queued = client.follow(pending['events'][0],'toolRun').data
    queued = client.follow(queued,'approve','POST',data={},headers=versions(queued)).data
    stale = client.follow(queued,'execute','POST',status=409,data={},headers=versions(queued))
    assert stale.data['code'] == 'workspace_base_changed'
    assert one('SELECT content FROM workspace_files WHERE session_id=%s AND path=%s',session['id'],'marker.txt') == 'newer human edit'
    cancelled = client.follow(pending,'cancel','POST',data={}).data
    [denied] = cancelled['events']
    assert denied['output'] == 'Cancelled without applying' and 'runId' not in denied and '_links' not in denied
    client.follow(queued,'self',status=404)
    client.follow(queued,'execute','POST',status=404,data={},headers=versions(queued))
    assert one('SELECT content FROM workspace_files WHERE session_id=%s AND path=%s',session['id'],'marker.txt') == 'newer human edit'
    passed('native agent: structured Ollama tools, approval barrier, replay protection, real tool results in context, persisted transcript, cancellation and metering')


def policy_workflow(client, control, session, handler):
    payload = {'message':POLICY_PROMPT,'provider':'ollama','model':'qa:agent','tools':True}
    def start():
        return client.follow(session,'startAgent','POST',status=201,data=payload).data
    def assert_applied(event, mode):
        run = client.follow(event,'toolRun').data
        assert (run['id'],run['status'],run['version'],run['result']) == (event['runId'],'completed',3,event['output'])
        assert run['requestedBy'] == run['approvedBy'] == run['executedBy'] == 'usr_qa_member'
        assert set(run['_links']) == {'self'}
        assert rows('SELECT event,actor_id FROM auth_audit WHERE target_id=%s ORDER BY id',run['id']) == [
            (name,'usr_qa_member') for name in ('tool.request','tool.approve.policy.'+mode,'tool.execute.policy.'+mode)]

    # Downgrade while inference is outstanding on another HTTP worker. The
    # arriving batch must observe Allow, not the YOLO setting at dispatch.
    control.follow(session,'setPermissions','PUT',data={'mode':'yolo'})
    handler.entered, handler.release = threading.Event(), threading.Event()
    turn = start()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending = pool.submit(client.follow,turn,'step','POST',data={})
        try:
            assert handler.entered.wait(10), 'Permission fixture was not dispatched'
            control.follow(session,'setPermissions','PUT',data={'mode':'allow'})
        finally:
            handler.release.set()
        mixed = pending.result().data
    assert mixed['status'] == 'review'
    create, edit, replace, invalid = mixed['events']
    assert_applied(create,'allow')
    assert invalid['output'].startswith('Error:') and 'runId' not in invalid
    assert rows('SELECT path,content FROM workspace_files WHERE session_id=%s ORDER BY path',session['id']) == [
        ('draft.txt','one'), ('marker.txt','newer human edit')]
    reviews = [client.follow(e,'toolRun').data for e in (edit,replace)]
    assert all(r['status'] == 'requested' and r['version'] == 1 for r in reviews)
    cancelled = client.follow(mixed,'cancel','POST',data={}).data
    assert cancelled['events'][0] == create and cancelled['events'][3] == invalid
    for event, run in zip(cancelled['events'][1:3], reviews):
        assert event['output'] == 'Cancelled without applying' and 'runId' not in event and '_links' not in event
        client.follow(run,'approve','POST',status=404,data={},headers=versions(run))
        assert rows('SELECT event FROM auth_audit WHERE target_id=%s ORDER BY id',run['id']) == [('tool.request',),('tool.cancel',)]
    assert_applied(create,'allow')

    control.follow(session,'setPermissions','PUT',data={'mode':'yolo'})
    ready = client.follow(start(),'step','POST',data={}).data
    assert ready['status'] == 'ready'
    for event in ready['events'][:3]:
        assert_applied(event,'yolo')
    assert ready['events'][3] == invalid
    assert rows('SELECT path,content FROM workspace_files WHERE session_id=%s ORDER BY path',session['id']) == [
        ('draft.txt','edited'), ('marker.txt','replaced')]
    complete = client.follow(ready,'step','POST',data={}).data
    assert complete['status'] == 'completed' and complete['events'] == ready['events']
    passed('live policy downgrade, guarded Allow creates, mixed-batch cancellation, YOLO writes and edits, validation and audit attribution')


def thread_commands(owner, control, member, handler):
    session = owner.req('POST','/projects/prj_web/sessions',201,{'title':'Thread command fixture'}).data
    sid = session['id']
    none = {'goal':None,'status':'none'}
    # A full multibyte goal must reach the provider as user data, never as system text.
    goal = '界' * 1999 + 'Z'
    sensitive.update((goal, THREAD_CASE))
    def set_goal(**body):
        return owner.follow(session,'setGoal','PUT',data=body).data
    def payload(case):
        return {'message':THREAD_CASE + case, 'provider':'ollama', 'model':'qa:agent'}
    def ask(case, **extra):
        return owner.follow(session,'askAside','POST',data={**payload(case),**extra}).data
    def context(case):
        request = handler.thread_requests[THREAD_CASE + case]
        assert 'tools' not in request
        system, *history, prompt = request['messages']
        assert system['role'] == 'system' and goal not in system['content'] and THREAD_CASE not in system['content']
        assert prompt == {'role':'user','content':THREAD_CASE + case}
        return [(m['role'],m['content']) for m in history]

    for rel in ('goal','asides'):
        member.follow(session,rel,status=404)
    member.follow(session,'setGoal','PUT',status=404,data={'goal':'private'})
    member.follow(session,'askAside','POST',status=404,data=payload('private'))
    owner.follow(session,'setGoal','PUT',status=403,data={'goal':'no CSRF'},headers={'X-CSRF-Token':None})
    owner.follow(session,'askAside','POST',status=403,data=payload('no CSRF'),headers={'X-CSRF-Token':None})
    for invalid in ({'goal':' '},{'goal':goal+'x'},{'goal':'text','action':'pause'},{'action':'skip'}):
        owner.follow(session,'setGoal','PUT',status=422,data=invalid)
    assert owner.follow(session,'setGoal','PUT',status=409,data={'action':'pause'}).data['code'] == 'invalid_goal_state'
    assert owner.follow(session,'goal').data == none
    assert set_goal(goal=goal) == control.follow(session,'goal').data == {'goal':goal,'status':'active'}
    def stored_goal():
        return rows('SELECT goal,status FROM thread_goals WHERE session_id=%s AND user_id=%s',sid,'usr_demo_two')
    assert stored_goal() == [(goal,'active')]
    grant = control.req('POST','/projects/prj_web/members',data={'userId':'usr_qa_member','role':'member'}).data
    assert member.follow(session,'goal').data == none
    assert member.follow(session,'setGoal','PUT',data={'goal':'Other actor objective'}).data['status'] == 'active'

    marker = 'Visible main transcript marker'
    owner.follow(session,'createMessage','POST',status=201,data={'role':'user','content':marker})
    marker_history = [('user',marker)]
    before = owner.req('GET','/api/budget').data
    turn = owner.follow(session,'startAgent','POST',status=201,data={**payload('active main'),'tools':False}).data
    handler.entered, handler.release = threading.Event(), threading.Event()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending = pool.submit(owner.follow,turn,'step','POST',data={})
        try:
            assert handler.entered.wait(10), 'Main dispatch did not reach fixture'
            working = control.follow(session,'agent').data
            assert working['run']['status'] == 'working'
            first = ask('first aside')
            second = ask('independent aside')
            followup = ask('selected follow-up',replyTo=first['id'])
            assert context('first aside') == context('independent aside') == marker_history
            assert context('selected follow-up') == marker_history + [('user',first['question']),('assistant',first['answer'])]
            assert control.follow(session,'agent').data == working
            assert [(m['role'],m['content']) for m in control.follow(session,'messages').data['items']] == marker_history
        finally:
            handler.release.set()
        assert pending.result().data['status'] == 'completed'
    assert context('active main') == marker_history + [('user','Active goal:\n' + goal)]
    assert first == {'id':first['id'],'question':THREAD_CASE+'first aside','answer':'Answer to '+THREAD_CASE+'first aside','provider':'ollama','model':'qa:agent'}
    assert control.follow(session,'asides').data == {'items':[followup,second,first]}
    assert rows('SELECT id,question,answer FROM thread_asides WHERE session_id=%s ORDER BY created_at DESC,id DESC',sid) == [
        (item['id'],item['question'],item['answer']) for item in (followup,second,first)]
    assert member.follow(session,'asides').data == {'items':[]}
    for client, target in ((member,session),(owner,owner.req('GET','/sessions/ses_201').data)):
        client.follow(target,'askAside','POST',status=404,data={**payload('foreign parent'),'replyTo':first['id']})
    for extra in ({'tools':True},{'replyTo':None},{'replyTo':'bad'},{'url':'http://other.invalid'}):
        owner.follow(session,'askAside','POST',status=422,data={**payload('invalid'),**extra})
    assert set_goal(action='pause') == {'goal':goal,'status':'paused'}
    turn = owner.follow(session,'startAgent','POST',status=201,data={**payload('paused main'),'tools':False}).data
    assert owner.follow(turn,'step','POST',data={}).data['status'] == 'completed'
    assert context('paused main') == marker_history + [('user',THREAD_CASE+'active main'),('assistant','Answer to '+THREAD_CASE+'active main')]
    assert set_goal(action='resume')['status'] == 'active'
    assert set_goal(action='done') == {'goal':goal,'status':'done'}
    owner.follow(session,'setGoal','PUT',status=409,data={'action':'resume'})
    assert set_goal(action='clear') == none and stored_goal() == []

    owner.follow(session,'askAside','POST',status=502,data=payload('unrequested tool'))
    assert not rows('SELECT id FROM tool_runs WHERE session_id=%s',sid)
    # Revoke the browser session during a real side dispatch. Charge it without saving its answer.
    late = Client('api:8095').login('demo_two','owner')
    handler.entered, handler.release = threading.Event(), threading.Event()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending = pool.submit(late.follow,session,'askAside','POST',data=payload('expired caller'),status=401)
        try:
            assert handler.entered.wait(10), 'Side dispatch did not reach fixture'
            expire(late,'usr_demo_two')
        finally:
            handler.release.set()
        pending.result()
    budget = owner.req('GET','/api/budget').data
    assert budget['spent'] == before['spent'] + 7 and budget['reserved'] == 0
    assert owner.follow(session,'askAside','POST',status=429,data=payload('over budget')).data['code'] == 'budget_exhausted'
    assert rows('''SELECT outcome,count(*) FROM chat_dispatches WHERE session_id=%s
                    AND user_message_id IS NULL AND assistant_message_id IS NULL GROUP BY outcome ORDER BY outcome''',sid) == [('answered',3),('failed',2)]
    assert control.follow(session,'asides').data == {'items':[followup,second,first]}
    # Exercise the history window without spending artificial provider units.
    db.execute("""INSERT INTO thread_asides(id,session_id,user_id,question,answer,provider,model,created_at)
        SELECT 'aside_'||lpad(to_hex(i),24,'0'),%s,'usr_demo_two','old','old','ollama','qa:agent',now()-make_interval(days=>i)
        FROM generate_series(1,20) i""",(sid,))
    latest = control.follow(session,'asides').data['items']
    assert len(latest) == 20 and latest[:3] == [followup,second,first] and latest[-1]['id'] == f'aside_{17:024x}'
    control.follow(grant,'delete','DELETE',status=204)
    control.follow(session,'delete','DELETE',status=204)
    assert not rows('SELECT id FROM thread_asides WHERE session_id=%s',sid)
    assert not rows('SELECT goal FROM thread_goals WHERE session_id=%s',sid)
    passed('actor-scoped durable goals and user-role dispatch, isolated concurrent side questions, explicit replies, replay boundaries, reauthentication and shared quota')


def synthetic_chat(b, member, personal):
    from http.server import ThreadingHTTPServer
    base_handler = runpy.run_path('scripts/test-ollama-transport.py')['OllamaFixture']
    class Handler(base_handler):
        thread_requests = {}

        def agent_reply(self, data):
            messages = data['messages']
            prompt = next(m['content'] for m in reversed(messages) if m['role'] == 'user')
            if prompt.startswith(THREAD_CASE):
                self.thread_requests[prompt] = data
                if prompt in (THREAD_CASE+'active main',THREAD_CASE+'expired caller'):
                    self.entered.set()
                    if not self.release.wait(30):
                        self.send_error(504)
                        return
                message = {'role':'assistant','content':'Answer to '+prompt}
                if prompt == THREAD_CASE+'unrequested tool':
                    message['tool_calls'] = [{'function':{'name':'write','arguments':{'path':'side.txt','content':'must not be written'}}}]
            elif prompt != POLICY_PROMPT:
                return super().agent_reply(data)
            elif messages[-1]['role'] == 'tool':
                results = [m['content'] for m in messages if m['role'] == 'tool']
                if len(results) != 4 or results[:3] != ['Wrote 3 bytes','Wrote 6 bytes','Wrote 8 bytes'] or not results[3].startswith('Error:'):
                    self.send_error(400)
                    return
                message = {'role':'assistant','content':'Checked the tool results.'}
            else:
                self.entered.set()
                if not self.release.wait(10):
                    self.send_error(504)
                    return
                calls = [('write',{'path':'draft.txt','content':'one'}),
                         ('edit',{'path':'draft.txt','oldText':'one','newText':'edited'}),
                         ('write',{'path':'marker.txt','content':'replaced'}),
                         ('write',{'path':'../escape','content':'invalid'})]
                message = {'role':'assistant','content':'','tool_calls':[{'function':{'name':n,'arguments':args}} for n,args in calls]}
            body = json.dumps({'done':True,'message':message}).encode()
            self.send_response(200)
            self.send_header('Content-Length',str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    # Bind only inside the internal QA network. This fixture cannot forward requests.
    with ThreadingHTTPServer(('0.0.0.0',11435),Handler) as server:
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
            thread_commands(cl,b,member,Handler)

            # Same transport, different actor and no project. Read the committed
            # turn pair back through the ordinary API as well as PostgreSQL.
            private = Client('api:8095'); private.cookie = member.cookie; private.csrf = member.csrf
            answer = private.follow(personal,'chat','POST',data=message).data
            assert (answer['sessionId'],answer['provider'],answer['model']) == (personal['id'],'ollama','qa:model')
            turns = member.follow(personal,'messages').data['items']
            expected = sorted([(answer['userMessage']['id'],'user','success'),
                               (answer['assistantMessage']['id'],'assistant','fixture result')])
            assert sorted((r['id'],r['role'],r['content']) for r in turns) == expected
            assert rows('SELECT id,role,content FROM messages WHERE session_id=%s ORDER BY id',personal['id']) == expected
            assert rows('SELECT user_id,outcome FROM chat_dispatches WHERE session_id=%s',personal['id']) == [('usr_qa_member','answered')]
            for turn in turns:
                b.follow(turn,'self',status=404)
            agent_workflow(private, b, personal)
            # Release a model-issued write only after a second HTTP worker cancels.
            Handler.entered, Handler.release = threading.Event(), threading.Event()
            late = private.follow(personal,'startAgent','POST',status=201,data={
                'message':'Interrupt this turn','model':'qa:interrupt','provider':'ollama','tools':True}).data
            spent = private.req('GET','/api/budget').data['spent']
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                pending = pool.submit(private.follow,late,'step','POST',data={})
                try:
                    assert Handler.entered.wait(10), 'Provider request was not dispatched'
                    assert member.follow(late,'self').data['status'] == 'working'
                    assert member.follow(late,'cancel','POST',data={}).data['status'] == 'cancelled'
                finally:
                    Handler.release.set()
                assert pending.result().data['status'] == 'cancelled'
            assert private.req('GET','/api/budget').data['spent'] == spent + 1
            assert not rows("SELECT id FROM tool_runs WHERE session_id=%s AND path='late.txt'",personal['id'])
            passed('interrupt during inference discards a late tool call without refunding the model dispatch')
            policy_workflow(private,member,personal,Handler)
        finally:
            server.shutdown()
            thread.join()
    passed('synthetic Ollama HTTP: project and standalone chat turns persisted, atomic turn-pair rollback and retry; no live inference claimed')


def sdk_login_isolation(owner, other, viewer):
    from time import monotonic, sleep

    def routed(source):
        client = Client('api:8096')
        client.cookie, client.csrf = source.cookie, source.csrf
        return client

    owner, other, viewer = map(routed, (owner, other, viewer))
    catalog = owner.req('GET', '/api/provider-logins').data
    methods = {m['id']: m for m in catalog['providers']}
    assert 'openai-codex' in methods and methods['ollama']['available']
    start = {'provider': 'ollama'}
    viewer.req('POST', '/api/provider-logins', 403, start)
    owner.req('POST', '/api/provider-logins', 403, start, headers={'X-CSRF-Token': None})
    state = owner.follow(catalog, 'startLogin', 'POST', data=start).data
    other.follow(state, 'status', 'POST', status=404, data={})
    deadline = monotonic() + 20
    while state['state'] == 'pending':
        assert monotonic() < deadline, 'Local SDK login did not complete'
        if 'prompt' in state:
            assert state['prompt']['allowEmpty'], 'Expected the optional local-provider key prompt'
            state = owner.follow(state, 'answer', 'POST', data={'promptId':state['prompt']['id'], 'answer':''}).data
        else:
            sleep(.1)
            state = owner.follow(state, 'status', 'POST', data={}).data
    assert state['state'] == 'completed' and state['provider'] == 'ollama' and 'credential' not in state
    def local(client):
        return next(p for p in client.req('GET','/api/runtime').data['ai']['providers'] if p['id'] == 'ollama')
    assert local(owner)['authType'] == 'login' and 'authType' not in local(other)
    owner.follow(local(owner),'deleteCredential','DELETE',status=204)
    assert 'authType' not in local(owner)
    passed('SDK sign-in requires CSRF and a writable account; transactions and saved grants are account-isolated')


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
            registration()
            seeds = scope_query('usr_demo_one'),scope_query('usr_demo_two')
            collections(a)
            task = crud_and_formats(a,b,member,viewer,admin)
            matrix(a)
            task_versions(a,task)
            tools(a,b,viewer)
            composition(a)
            chat(a,b,viewer)
            sdk_login_isolation(a,b,viewer)
            personal = standalone_sessions(member,b,viewer,admin)
            synthetic_chat(b,member,personal)
            check_migration_replay()
            member.follow(personal,'delete','DELETE',status=204)
            member.follow(personal,'self',status=404)
            assert one('SELECT count(*) FROM messages WHERE session_id=%s',personal['id']) == 0
            rollback_delete(a)
            assert seeds == (scope_query('usr_demo_one'),scope_query('usr_demo_two'))
            login_limit()
            registration_limit()
            audit = one("SELECT jsonb_agg(to_jsonb(t))::text FROM auth_audit t")
            assert audit and not any(s in audit for s in sensitive), 'Private data in persistent audit history'
        with os.fdopen(os.open(LOG_EXPECTATIONS, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600), 'w', encoding='utf-8') as out:
            json.dump({'requests': correlations, 'secrets': list(sensitive)}, out)
        passed(f'{exchanges} black-box HTTP exchanges; non-HTML representations checked against OpenAPI')
