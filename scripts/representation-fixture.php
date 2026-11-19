<?php

declare(strict_types=1);

/** CLI fixture consumed by scripts/test-semantic-formats.py; no database calls. */
require_once dirname(__DIR__) . '/backend/bootstrap.php';

if (($argv[1] ?? '') === 'decode') {
    $data = decodeRepresentation(stream_get_contents(STDIN), requestMediaType($argv[2]));
    sendRepresentation($data);
}
$_SERVER['HTTP_ACCEPT'] = $argv[1] ?? 'application/json';
sendRepresentation([
    'session' => ['id' => 'ses_fixture', 'title' => 'Quoted <test> & review', 'status' => 'active',
        'createdAt' => '2026-10-14T12:30:00Z', 'enabled' => true, 'nullable' => null,
        'zero' => 0, 'fraction' => 1.5],
    'messages' => [['id' => 'msg_fixture', 'role' => 'user', 'content' => "A: \"one\"\nB: \\two"]],
    'page' => ['total' => 1, 'limit' => 1, 'offset' => 0, 'hasMore' => false],
    'empty' => [],
    'emptyMap' => (object) [],
    '_links' => ['self' => ['href' => '/sessions/ses_fixture?view=full&include=tasks', 'method' => 'GET'],
        'update' => ['href' => '/sessions/ses_fixture', 'method' => 'PATCH']],
]);
