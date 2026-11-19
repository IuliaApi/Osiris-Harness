#!/usr/bin/env python3
"""Validate real HTTP entities using docs/openapi.yaml (OpenAPI 3.1 / JSON Schema 2020-12).

Install: python -m pip install -r scripts/contract-requirements.txt
Live: python scripts/validate-contract.py --base-url http://127.0.0.1:8080 --username qa_owner
      Uses OSIRIS_QA_PASSWORD or a hidden prompt. Use a disposable, provisioned owner account.
Offline: python scripts/validate-contract.py --base-url http://127.0.0.1:8080 --har capture.har
      Export a HAR *with content*. Treat it as secret: do not commit or share unredacted HARs.
No HAR is written by the live probe; no cookies, credentials, request data or responses are printed.
"""
import argparse
import base64
import getpass
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, ProxyHandler, Request
from uuid import uuid4
import xml.etree.ElementTree as ET
from xml.sax.saxutils import escape

from defusedxml.ElementTree import fromstring
from jsonschema import Draft202012Validator, FormatChecker
import yaml

ROOT = Path(__file__).resolve().parents[1]
MEDIA = ('application/json', 'application/xml', 'application/yaml')
ALIASES = {'text/xml': MEDIA[1], 'text/yaml': MEDIA[2]}
NIL = '{http://www.w3.org/2001/XMLSchema-instance}nil'


class Invalid(ValueError):
    """Safe diagnostic; never includes an instance value or raw header value."""


def require(condition, message):
    if not condition:
        raise Invalid(message)


def unique_fields(pairs):
    obj = {}
    for key, value in pairs:
        require(isinstance(key, str) and key not in obj, 'duplicate/non-string field')
        obj[key] = value
    return obj


class DataLoader(yaml.SafeLoader):
    def compose_node(self, parent, index):
        require(not self.check_event(yaml.AliasEvent), 'YAML aliases are not data fields')
        return super().compose_node(parent, index)

    def construct_mapping(self, node, deep=False):
        return unique_fields(self.construct_pairs(node, deep=deep))


def xml_value(node):
    require(set(node.attrib) <= {'kind', 'type', NIL}, 'unexpected XML attribute')
    if NIL in node.attrib:
        require(node.attrib == {NIL: 'true'} and not list(node) and not node.text, 'invalid XML null')
        return None
    kind = node.get('kind')
    if kind in ('array', 'object') or (kind is None and node.tag == 'request'):
        require('type' not in node.attrib, 'typed XML container')
        require(not (node.text or '').strip() and all(not (c.tail or '').strip() for c in node), 'mixed XML content')
        if kind == 'array':
            require(all(c.tag == 'item' for c in node), 'non-item XML array entry')
            return [xml_value(c) for c in node]
        require(len({c.tag for c in node}) == len(node), 'duplicate XML field')
        return {c.tag: xml_value(c) for c in node}
    require(kind is None and not list(node), 'invalid XML container kind')
    value = node.text or ''
    scalar = node.get('type', 'string')
    if scalar == 'string':
        return value
    if scalar == 'boolean':
        require(value in ('true', 'false'), 'invalid XML boolean')
        return value == 'true'
    if scalar == 'integer':
        require(re.fullmatch(r'-?(?:0|[1-9][0-9]*)', value), 'invalid XML integer')
        return int(value)
    if scalar == 'number':
        require(re.fullmatch(r'-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?', value), 'invalid XML number')
        return float(value)
    raise Invalid('invalid XML scalar type')


def decode(body, media, request):
    try:
        text = body.decode('utf-8')
        if media == MEDIA[0]:
            def not_json(_):
                raise Invalid('non-finite JSON number')
            return json.loads(text, object_pairs_hook=unique_fields, parse_constant=not_json)
        if media == MEDIA[1]:
            node = fromstring(text, forbid_dtd=True)
            require(node.tag == ('request' if request else 'response'), 'wrong XML root')
            if not request:
                require(node.get('kind') in ('object', 'array'), 'missing XML root kind')
            return xml_value(node)
        if media == MEDIA[2]:
            return yaml.load(text, Loader=DataLoader)
    except Invalid:
        raise
    except (ValueError, yaml.YAMLError, ET.ParseError):
        raise Invalid('invalid encoded HTTP entity') from None
    raise Invalid('unsupported media type')


class Contract:
    def __init__(self):
        self.doc = yaml.safe_load((ROOT / 'docs/openapi.yaml').read_text(encoding='utf-8'))
        self.paths = [(re.compile('^' + '/'.join('[^/]+' if s.startswith('{') else re.escape(s)
                                                 for s in path.split('/')) + '$'), path, item)
                      for path, item in self.doc['paths'].items()]
        self.checker = FormatChecker()
        self.count = 0

    def deref(self, obj):
        if '$ref' not in obj:
            return obj
        ref = obj['$ref']
        require(ref.startswith('#/'), 'external reference not allowed')
        dest = self.doc
        for segment in ref[2:].split('/'):
            dest = dest[segment]
        return dest

    def entity(self, spec, body, content_type, request=False):
        media = content_type.split(';')[0].strip().lower()
        content = self.deref(spec).get('content', {})
        if not content:
            require(not body, 'unexpected response body')
            return None
        require(media in content, 'undocumented Content-Type')
        value = decode(body, ALIASES.get(media, media), request)
        schema = {'components': self.doc['components'], 'allOf': [content[media]['schema']]}
        error = next(Draft202012Validator(schema, format_checker=self.checker).iter_errors(value), None)
        if error:
            # Paths and messages may contain user input or keys; only show the failed schema rule.
            raise Invalid(f'entity failed schema keyword {error.validator}')
        return value

    def exchange(self, method, url, sent, sent_type, status, headers, body):
        split = urlsplit(url)
        match = next(((template, item) for regex, template, item in self.paths if regex.fullmatch(split.path)), None)
        operation = match and match[1].get(method.lower())
        label = f'{method} {match[0] if match else "<unknown route>"} {status}'
        try:
            if operation is None:
                require(status == (405 if match else 404), 'undocumented operation/status')
                spec = self.doc['components']['responses']['Error']
            else:
                spec = operation['responses'].get(str(status))
                require(spec is not None, 'undocumented HTTP status')
                if 200 <= status < 300:
                    req = operation.get('requestBody')
                    if req:
                        require(sent is not None, 'successful request body missing from capture')
                        self.entity(req, sent, sent_type, request=True)
                    else:
                        require(not sent, 'unexpected request payload')
            value = self.entity(spec, body, headers.get('content-type', ''))
            request_id = headers.get('x-request-id', '')
            require(re.fullmatch('[a-f0-9]{16}', request_id), 'missing/invalid X-Request-Id')
            if status >= 400:
                require(value['requestId'] == request_id, 'uncorrelated error body')
            for name, header in self.deref(spec).get('headers', {}).items():
                spec_header = self.deref(header)
                val = headers.get(name.lower())
                if spec_header.get('required'):
                    require(val, f'missing {name} header')
                if val is not None:
                    require(Draft202012Validator(spec_header['schema']).is_valid(val), f'invalid {name} header')
            if status == 405:
                require(headers.get('allow'), 'missing Allow header')
            if 200 <= status < 300 and 'etag' in headers and isinstance(value, dict) and 'version' in value:
                require(headers['etag'] == f'"{value["version"]}"', 'ETag differs from version')
            if status == 201 and '_links' in value:
                require(headers.get('location') == value['_links']['self']['href'], 'Location differs from self link')
            if (method, split.path, status) == ('GET', '/sessions', 200):
                require(isinstance(value, dict if split.query else list), 'wrong legacy/paged list form')
        except Invalid as exc:
            raise Invalid(f'{label}: {exc}') from None
        self.count += 1
        print(f'PASS {label} {headers.get("content-type", "").split(";")[0]}')
        return value


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def encode_flat(obj, media):
    if media == MEDIA[0]:
        return json.dumps(obj).encode()
    if media == MEDIA[1]:
        fields = ''.join(f'<{k}>{escape(v)}</{k}>' for k, v in obj.items())
        return f'<request kind="object">{fields}</request>'.encode()
    # Quoting preserves string values (even passwords that look like YAML scalars).
    return yaml.safe_dump(obj, default_style='"', allow_unicode=True).encode()


def live(contract, base, username, password):
    client = build_opener(ProxyHandler({}), HTTPCookieProcessor(CookieJar()), NoRedirect())
    csrf = None

    def call(method, path, expected, data=None, media=MEDIA[0], accept=None):
        url = base + path
        accept = accept or media
        headers = {'Accept': accept}
        payload = None
        if data is not None:
            headers['Content-Type'] = media
            payload = encode_flat(data, ALIASES.get(media, media))
        if csrf and method not in ('GET', 'HEAD'):
            headers['X-CSRF-Token'] = csrf
        try:
            response = client.open(Request(url, payload, headers, method=method), timeout=15)
        except HTTPError as exc:
            response = exc
        with response:
            rh = {k.lower(): v for k, v in response.headers.items()}
            status = response.status
            result = contract.exchange(method, url, payload, media, status, rh, response.read())
        require(status == expected, f'expected {expected}, received {status}')
        if status != 204:
            require(rh.get('content-type', '').split(';')[0] == accept, 'server did not negotiate requested representation')
        return result

    call('GET', '/sessions', 401)
    for media in MEDIA:
        auth = call('POST', '/auth/login', 200, {'username': username, 'password': password}, media)
        csrf = auth['csrfToken']
        try:
            call('GET', '/auth/me', 200, media=media)
            call('GET', '/api', 200, media=media)
            call('GET', '/api/runtime', 200, media=media)
            call('GET', '/api/budget', 200, media=media)
            call('GET', '/sessions', 200, media=media)
            call('GET', '/sessions?limit=1', 200, media=media)
            call('GET', '/sessions?limit=0', 400, media=media)
            project = call('POST', '/projects', 201, {'name': 'Contract ' + uuid4().hex}, media)
            p = '/projects/' + project['id']
            try:
                session = call('POST', p + '/sessions', 201, {'title': 'HTTP matrix'}, media)
                s = '/sessions/' + session['id']
                try:
                    for accepted in MEDIA:
                        call('POST', s + '/messages', 201, {'role': 'user', 'content': 'Literal <&> true 001'},
                             media, accept=accepted)
                    if media == MEDIA[0]:
                        for alias in ALIASES:
                            call('PATCH', s, 200, {'title': 'Alias exchange'}, alias)
                    call('POST', s + '/tasks', 201, {'title': 'Schema check'}, media)
                    call('PATCH', s, 200, {'title': 'Updated matrix'}, media)
                    # Compare one actual nested representation across all encodings, not generated fixtures.
                    representations = [call('GET', s + '?include=project,messages,tasks', 200, media=m) for m in MEDIA]
                    require(all(row == representations[0] for row in representations), 'media changed resource semantics')
                    call('GET', s + '/context', 200, media=media)
                finally:
                    call('DELETE', s + '?deleteContents=1', 204)
            finally:
                call('DELETE', p, 204)
        finally:
            call('POST', '/auth/logout', 204)
            csrf = None
    call('GET', '/auth/me', 401)


def har(contract, path, base):
    origin = urlsplit(base)
    for entry in json.loads(path.read_text(encoding='utf-8-sig'))['log']['entries']:
        req, res = entry['request'], entry['response']
        url = urlsplit(req['url'])
        if (url.scheme, url.netloc) != (origin.scheme, origin.netloc):
            continue
        if url.path.split('/')[1:2] not in (['api'], ['sessions'], ['projects'], ['auth']):
            continue
        post = req.get('postData', {})
        sent = post['text'].encode() if 'text' in post else None
        content = res['content']
        require('text' in content or res['status'] == 204, 'HAR missing response body; export with content')
        body = content.get('text', '').encode()
        if content.get('encoding') == 'base64':
            body = base64.b64decode(body, validate=True)
        headers = {h['name'].lower(): h['value'] for h in res['headers']}
        contract.exchange(req['method'], req['url'], sent, post.get('mimeType', ''), res['status'], headers, body)
    require(contract.count > 0, 'no API exchanges for the selected origin')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--base-url', default='http://127.0.0.1:8080')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--username')
    mode.add_argument('--har', type=Path)
    args = parser.parse_args()
    base = args.base_url.rstrip('/')
    parsed = urlsplit(base)
    require(parsed.scheme in ('http', 'https') and parsed.hostname in ('localhost', '127.0.0.1', '::1') and
            not (parsed.username or parsed.password or parsed.path or parsed.query or parsed.fragment),
            'base URL must be a loopback origin')
    contract = Contract()
    if args.har:
        har(contract, args.har, base)
    else:
        live(contract, base, args.username, os.environ.get('OSIRIS_QA_PASSWORD') or getpass.getpass('QA password: '))
    print(f'Validated {contract.count} HTTP exchanges.')


if __name__ == '__main__':
    try:
        main()
    except (Invalid, OSError, KeyError, ValueError, yaml.YAMLError) as exc:
        # Do not expose captures, secret payloads, URLs with credentials or provider keys on parser errors.
        sys.exit(f'FAIL: {exc}' if isinstance(exc, Invalid) else f'FAIL: {type(exc).__name__} reading contract/capture or contacting server')
