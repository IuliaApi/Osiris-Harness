<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

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

foreach ([
    '' => 'application/json',
    'image/png;q=1, text/yaml;q=0.2' => 'text/yaml',
    'application/json;q=0, */*;q=1' => 'application/xml',
    'application/json;q=0.1, application/*;q=0.9' => 'application/xml',
    'application/*;q=0, */*' => 'text/xml',
    'text/yaml;q=0.99, application/json' => 'application/json',
    'text/xml;q=oops, application/yaml;q=0.1' => 'application/yaml',
    'application/json; charset=utf-8;q=0, application/json, text/xml;q=0.3' => 'text/xml',
    'text/plain; profile="x, application/xml", application/json' => 'application/json',
] as $accept => $expected) {
    $_SERVER['HTTP_ACCEPT'] = $accept;
    check(responseMediaType() === $expected, "Accept $accept -> $expected");
}
$_SERVER['HTTP_ACCEPT'] = '*/*;q=0';
try { responseMediaType(); check(false, 'All excluded'); }
catch (ApiError $e) { check($e->httpStatus === 406, 'Wildcard exclusion yields 406'); }
check(requestMediaType(' Text/YAML ; Charset = "UTF-8"') === 'text/yaml', 'Case-insensitive UTF-8 alias');
foreach (['', 'text/plain', 'application/xml; charset=utf-16', 'application/json; charset=utf-8; charset=ascii'] as $bad) {
    try { requestMediaType($bad); check(false, "Bad content type $bad"); }
    catch (ApiError $e) { check($e->httpStatus === 415, "Unsupported Content-Type $bad"); }
}

$expected = ['title' => "a:b\n", 'flag' => true, 'nothing' => null, 'values' => [0, 1.5, 'false']];
$bodies = [
    'application/json' => json_encode($expected, JSON_THROW_ON_ERROR),
    'text/xml' => '<request xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><title>a:b&#10;</title>'
        . '<flag type="boolean">true</flag><nothing xsi:nil="true"/><values kind="array"><item type="integer">0</item>'
        . '<item type="number">1.5</item><item>false</item></values></request>',
    'text/yaml' => "---\ntitle: |\n  a:b\nflag: true\nnothing: null\nvalues: [0, 1.5, 'false']\n",
];
foreach ($bodies as $type => $body) {
    check(decodeRepresentation($body, $type) === $expected, "Canonical typed $type request");
}
foreach ([
    ['application/json', '{broken', 400],
    ['application/json', '{"title":null,"\u0074itle":2}', 400],
    ['application/json', '{"bad":"\u0000"}', 400],
    ['application/json', '{"bad":1e999}', 400],
    ['application/json', '[]', 422],
    ['application/xml', '<request><x/><x/></request>', 400],
    ['application/xml', '<request><x>oops</request>', 400],
    ['application/xml', '<request><x type="boolean">yes</x></request>', 400],
    ['application/xml', '<request><x kind="array"><wrong/></x></request>', 400],
    ['application/xml', '<request xmlns="http://example.test"/>', 400],
    ['application/xml', '<!DOCTYPE request [<!ENTITY demo "disabled">]><request><x>&demo;</x></request>', 400],
    ['application/xml', '<request kind="array"><item>item</item></request>', 422],
    ['application/xml', '<?xml version="1.0" encoding="UTF-16"?><request/>', 400],
    ['application/yaml', 'value: [unfinished', 400],
    ['application/yaml', "value:\nvalue: duplicate\n", 400],
    ['application/yaml', "value: &label [1]\ncopy: *label\n", 400],
    ['application/yaml', "<<: {value: first}\nvalue: overriding\n", 400],
    ['application/yaml', "value: !php/object 'disallowed'\n", 400],
    ['application/yaml', "value: !php/const PHP_VERSION\n", 400],
    ['application/yaml', "--- !unsupported\nfield: value\n", 400],
    ['application/yaml', "---\nx: first\n---\nx: second\n", 400],
    ['application/yaml', 'value: .inf', 400],
    ['application/yaml', '- not a mapping', 422],
    ['application/json', '{"x":' . str_repeat('[', 32) . '0' . str_repeat(']', 32) . '}', 400],
    ['application/xml', '<request>' . str_repeat('<x kind="object">', 32) . str_repeat('</x>', 32) . '</request>', 400],
    ['application/yaml', 'x: ' . str_repeat('[', 32) . '0' . str_repeat(']', 32), 400],
    ['application/yaml', str_repeat('x', 65537), 413],
] as $i => [$type, $body, $status]) {
    try { decodeRepresentation($body, $type); check(false, "Invalid $type case $i accepted"); }
    catch (ApiError $e) { check($e->httpStatus === $status, "Invalid $type case $i -> $status"); }
}
check(fetchGitHubRepository(null)['status'] === 'not_configured', 'No configured repository is explicit');
check(fetchGitHubRepository('http://127.0.0.1/private')['reason'] === 'unsupported_repository_url', 'No SSRF to arbitrary upstream');
putenv('OSIRIS_GITHUB_MODE=offline');
check(fetchGitHubRepository('https://github.com/php/php-src')['reason'] === 'upstream_disabled_for_demo', 'Deterministic upstream-failure scenario');
