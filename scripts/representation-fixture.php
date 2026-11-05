<?php

declare(strict_types=1);

/** CLI fixture consumed by scripts/test-semantic-formats.py; no database calls. */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message, public readonly string $codeName = '') { parent::__construct($message); }
}
require __DIR__ . '/../backend/public/Representation.php';

$_SERVER['HTTP_ACCEPT'] = $argv[1] ?? 'application/json';
sendRepresentation([
    'session' => ['id' => 'ses_fixture', 'title' => 'Quoted <test> & review', 'status' => 'active',
        'createdAt' => '2026-10-14T12:30:00Z', 'enabled' => true, 'nullable' => null,
        'zero' => 0, 'fraction' => 1.5],
    'messages' => [['id' => 'msg_fixture', 'role' => 'user', 'content' => "A: \"one\"\nB: \\two"]],
    'page' => ['total' => 1, 'limit' => 1, 'offset' => 0, 'hasMore' => false],
    'empty' => [],
]);
