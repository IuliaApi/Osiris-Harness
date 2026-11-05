<?php

declare(strict_types=1);

class ApiError extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message, public readonly string $codeName = '') { parent::__construct($message); }
}
require __DIR__ . '/../backend/public/Representation.php';
require __DIR__ . '/../backend/public/GitHubContext.php';

function check(bool $condition, string $name): void
{
    if (!$condition) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
$case = [
    'items' => [['id' => 'msg_A', 'content' => '<echo> & "quotes"', 'result' => null, 'ok' => true]],
    'page' => ['total' => 1, 'limit' => 1, 'offset' => 0, 'hasMore' => false],
];
$_SERVER['HTTP_ACCEPT'] = 'application/xml';
check(responseMediaType() === 'application/xml', 'XML negotiation');
$xml = '<response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
foreach ($case as $key => $value) { $xml .= toXmlNode($key, $value); }
$xml .= '</response>';
check(str_contains($xml, '&lt;echo&gt; &amp; &quot;quotes&quot;'), 'XML escaping');
check(str_contains($xml, 'xsi:nil="true"'), 'XML null handling');
check(str_contains($xml, 'type="integer">1</total>'), 'XML numeric typing');
$yaml = toYaml($case);
check(str_contains($yaml, 'content: "<echo> & \\"quotes\\""'), 'YAML quoting');
check(str_contains($yaml, 'hasMore: false'), 'YAML boolean');
$_SERVER['HTTP_ACCEPT'] = 'application/yaml;q=0.9, application/json;q=0.5';
check(responseMediaType() === 'application/yaml', 'weighted YAML negotiation');
$_SERVER['HTTP_ACCEPT'] = 'image/jpeg';
try { responseMediaType(); check(false, '406'); }
catch (ApiError $e) { check($e->httpStatus === 406, 'Unsupported Accept yields 406'); }
check(fetchGitHubRepository(null)['status'] === 'not_configured', 'No configured repository is explicit');
check(fetchGitHubRepository('http://127.0.0.1/private')['reason'] === 'unsupported_repository_url', 'No SSRF to arbitrary upstream');
putenv('OSIRIS_GITHUB_MODE=offline');
check(fetchGitHubRepository('https://github.com/php/php-src')['reason'] === 'upstream_disabled_for_demo', 'Deterministic upstream-failure scenario');
