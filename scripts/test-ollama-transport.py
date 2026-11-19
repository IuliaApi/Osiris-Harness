#!/usr/bin/env python3
"""Actual PHP/cURL requests through a loopback-only synthetic Ollama HTTP proxy; no real model or API key."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
from threading import Thread

ROOT = Path(__file__).resolve().parents[1]


class OllamaFixture(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != 'http://host.docker.internal:11434/api/tags':
            self.send_error(404)
            return
        body = b'{"models":[{"name":"qa:agent"},{"name":"qa:model"}]}'
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if self.path != 'http://host.docker.internal:11434/api/chat':
            self.send_error(400)
            return
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if data['model'] == 'qa:interrupt':
            self.entered.set()
            if not self.release.wait(10):
                self.send_error(504)
                return
            body = json.dumps({'done':True,'message':{'role':'assistant','content':'','tool_calls':[
                {'function':{'name':'write','arguments':{'path':'late.txt','content':'Do not apply'}}}]}}).encode()
            self.send_response(200)
            self.send_header('Content-Length',str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if data['model'] == 'qa:agent':
            self.agent_reply(data)
            return
        if (data['model'] != 'qa:model' or data['stream'] is not False
                or data['options']['num_predict'] != 2048 or self.headers.get('Authorization')):
            self.send_error(400)
            return
        case = data['messages'][-1]['content']
        status, body = {
            'success': (200, b'{"done":true,"model":"qa:model-v2","message":{"content":"fixture result","thinking":"hidden"}}'),
            'missing model': (200, b'{"done":true,"message":{"content":"fixture result"}}'),
            'unsafe model': (200, b'{"done":true,"model":"bad\\nlabel","message":{"content":"fixture result"}}'),
            'invalid': (200, b'bad data with private fixture content'),
            'quota': (429, b'private provider error'),
            'oversize': (200, b'z' * 262145),
        }[case]
        self.send_response(status)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass  # The size bound permits the client to stop receiving early.

    def agent_reply(self, data):
        # A deterministic protocol peer: later rounds only succeed if the real
        # tool result was delivered in the native Ollama message history.
        if {tool['function']['name'] for tool in data['tools']} != {'read','write','glob','grep','edit'}:
            self.send_error(400)
            return
        messages = data['messages']
        results = [m['content'] for m in messages if m['role'] == 'tool']
        content = 'The stored marker is canyon.'
        if results == []:
            name, args = 'write', {'path':'marker.txt','content':'canyon'}
        elif results == ['Wrote 6 bytes']:
            name, args = 'read', {'path':'marker.txt'}
        elif len(results) == 2 and results[0] == 'Wrote 6 bytes' and 'canyon' in results[1]:
            name, args = None, None
        else:
            self.send_error(400)
            return
        message = {'role':'assistant', 'content':'' if name else content}
        if name:
            message['tool_calls'] = [{'function':{'name':name,'arguments':args}}]
        result = json.dumps({'model':'qa:agent-tool' if name else 'qa:agent-final','done':True,'message':message}).encode()
        self.send_response(200)
        self.send_header('Content-Length',str(len(result)))
        self.end_headers()
        self.wfile.write(result)

    def log_message(self, *args):
        pass


php = r'''
require 'backend/bootstrap.php';
foreach (['success'=>200,'missing model'=>200,'unsafe model'=>200,'invalid'=>502,'quota'=>503,'oversize'=>502] as $case=>$expected) {
  try {
    $answer = AIProvider::reply($case, [], ['title'=>'fixture'], null, 'ollama', 'qa:model', null);
    $expectedModel = $case === 'success' ? 'qa:model-v2' : 'qa:model';
    if ($expected!==200 || $answer!==['provider'=>'ollama','model'=>$expectedModel,'text'=>'fixture result']) { throw new RuntimeException('Wrong result'); }
  } catch (ApiError $e) {
    if ($e->httpStatus!==$expected || str_contains($e->getMessage(),'private')) { throw $e; }
  }
  echo "PASS synthetic Ollama transport: $case\n";
}
'''
if __name__ == '__main__':
    with ThreadingHTTPServer(('127.0.0.1', 0), OllamaFixture) as server:
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        # On Windows environment variable names are case insensitive.
        env = {k: v for k, v in os.environ.items() if k.lower() not in {'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy'}}
        env.update(http_proxy=f'http://127.0.0.1:{server.server_port}', OSIRIS_OLLAMA_ENABLED='1')
        try:
            subprocess.run(['php', '-r', php], cwd=ROOT, env=env, check=True, timeout=20)
        finally:
            server.shutdown()
            thread.join()
