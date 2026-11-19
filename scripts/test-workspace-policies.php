<?php
// Offline synthetic values only. No network, real keys or database required.
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/bootstrap.php';

function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException("FAIL $label"); } echo "PASS $label\n"; }
function rejected(callable $operation, int $expected, string $label): void {
    try { $operation(); } catch (ApiError $e) { check($e->httpStatus === $expected, $label); return; }
    throw new RuntimeException("FAIL accepted: $label");
}
putenv('OSIRIS_VAULT_KEY=' . base64_encode(random_bytes(32)));
$secret = 'fixture-key-only';
$a = ProviderCredentials::seal('one','openai',$secret); $b = ProviderCredentials::seal('one','openai',$secret);
check($a !== $b && !str_contains($a,$secret) && ProviderCredentials::unseal('one','openai',$a) === $secret, 'Random nonce, encrypted round trip');
rejected(fn() => ProviderCredentials::unseal('two','openai',$a),503,'Ciphertext bound to identity');
rejected(fn() => ProviderCredentials::unseal('one','anthropic',$a),503,'Ciphertext bound to provider');
$blob = base64_decode($a); $blob[12] = chr(ord($blob[12]) ^ 1);
rejected(fn() => ProviderCredentials::unseal('one','openai',base64_encode($blob)),503,'Modified GCM tag rejected');
putenv('OSIRIS_VAULT_KEY=bad');
rejected(fn() => ProviderCredentials::seal('one','openai',$secret),503,'Invalid vault key fails closed');
foreach (['../outside','nested/name','C:\\Windows','/absolute','.hidden','user://x',"a\0b",str_repeat('a',65)] as $path) {
    rejected(fn() => WorkspaceToolRuns::parseToolRequest(['operation'=>'workspace.read','path'=>$path]),422,'Non-basename rejected');
}
foreach ([['operation'=>'command','path'=>'note'],['operation'=>'workspace.read','path'=>'note','content'=>'no'],['operation'=>'workspace.write','path'=>'note','content'=>str_repeat('x',8193)],['operation'=>'workspace.write','path'=>'note','content'=>"bad\0text"],['operation'=>'workspace.read','path'=>'note','approvedBy'=>'other']] as $body) {
    rejected(fn() => WorkspaceToolRuns::parseToolRequest($body),422,'Invalid tool arguments rejected');
}
check(WorkspaceToolRuns::parseToolRequest(['operation'=>'workspace.write','path'=>'note.txt','content'=>'']) === ['workspace.write','note.txt',''], 'Empty synthetic file supported');
check(WorkspaceToolRuns::parseToolRequest(['operation'=>'workspace.write','path'=>'note.txt','content'=>'éλ']) === ['workspace.write','note.txt','éλ'], 'Unicode file content preserved');
check(WorkspaceRepository::validateTaskTransition('failed',['status'=>'pending']) === ['status'=>'pending','result'=>null], 'Retry clears failed result');
rejected(fn() => WorkspaceRepository::validateTaskTransition('pending',['status'=>'completed']),409,'Cannot skip running state');
rejected(fn() => WorkspaceRepository::validateTaskTransition('completed',['title'=>'late']),409,'Completed task immutable');
rejected(fn() => WorkspaceRepository::validateTaskTransition('pending',['result'=>'done']),422,'Premature result rejected');
unset($_SERVER['HTTP_IF_MATCH']);
rejected(fn() => requiredResourceVersion(),428,'Version precondition required');
foreach (['2','*','W/"1"','"1", "2"','"2147483648"'] as $bad) {
    $_SERVER['HTTP_IF_MATCH'] = $bad; rejected(fn() => requiredResourceVersion(),400,'Invalid version precondition');
}
$_SERVER['HTTP_IF_MATCH'] = '"2"'; check(requiredResourceVersion() === 2,'Strong version accepted');
