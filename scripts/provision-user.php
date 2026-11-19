<?php

declare(strict_types=1);

// Operator-only provisioning and password resets. Never read a password from argv.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/backend/bootstrap.php';

try {
    if ($argc === 2 && $argv[1] === '--prune') {
        Auth::prune(connectWorkspaceDatabase());
        fwrite(STDOUT, "Expired security records pruned.\n");
        exit(0);
    }
    if ($argc !== 4 || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $argv[1])
        || !preg_match('/\A[a-zA-Z0-9_.-]{1,100}\z/D', $argv[2])
        || !in_array($argv[3], ['viewer','member','owner','admin'], true)) {
        fwrite(STDERR, "Usage: php scripts/provision-user.php USER_ID USERNAME viewer|member|owner|admin < password-file\n       php scripts/provision-user.php --prune\n");
        exit(2);
    }
    if (stream_isatty(STDIN)) { throw new RuntimeException('Noninteractive stdin required'); }
    $input = stream_get_contents(STDIN, 75);
    $password = is_string($input) ? preg_replace('/\r?\n\z/', '', $input) : '';
    if (strlen($password) < 12 || strlen($password) > 72 || strpbrk($password, "\0\r\n") !== false) {
        fwrite(STDERR, "Supply one password (12–72 bytes) on standard input.\n");
        exit(2);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
    unset($input, $password);
    [, $id, $username, $role] = $argv;
    $db = connectWorkspaceDatabase(operator: true);
    $db->beginTransaction();
    Auth::lockAccount($db, $username);
    $s = $db->prepare('INSERT INTO users(id,username,email,role,password_hash) VALUES (?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET role=EXCLUDED.role,password_hash=EXCLUDED.password_hash WHERE users.username=EXCLUDED.username RETURNING id');
    $s->execute([$id, $username, hash('sha256', $id) . '@accounts.invalid', $role, $hash]);
    if (!$s->fetchColumn()) { throw new RuntimeException('Account mismatch'); }
    $db->prepare('DELETE FROM auth_sessions WHERE user_id=?')->execute([$id]);
    (new Auth($db, 'local-provision'))->audit('account.provision', $id);
    $db->commit();
    fwrite(STDOUT, "Account provisioned. All previous sessions revoked.\n");
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    // Driver messages can contain connection details or bound IDs. Never echo them.
    fwrite(STDERR, "Provisioning failed: check local database, migrations, matching account and piped input.\n");
    exit(1);
}
