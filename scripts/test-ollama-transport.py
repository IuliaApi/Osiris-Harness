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
    def do_POST(self):
        if self.path != 'http://host.docker.internal:11434/api/chat':
            self.send_error(400)
            return
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if (data['model'] != 'qa:model' or data['stream'] is not False
                or data['options']['num_predict'] != 2048 or self.headers.get('Authorization')):
            self.send_error(400)
            return
        case = data['messages'][-1]['content']
        status, body = {
            'success': (200, b'{"done":true,"message":{"content":"fixture result","thinking":"hidden"}}'),
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

    def log_message(self, *args):
        pass


php = r'''
require 'backend/bootstrap.php';
foreach (['success'=>200,'invalid'=>502,'quota'=>503,'oversize'=>502] as $case=>$expected) {
  try {
    $answer = AIProvider::reply($case, [], ['title'=>'fixture'], null, 'ollama', 'qa:model', null);
    if ($expected!==200 || $answer!==['provider'=>'ollama','model'=>'qa:model','text'=>'fixture result']) { throw new RuntimeException('Wrong result'); }
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
