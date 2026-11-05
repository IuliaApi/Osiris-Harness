<?php

declare(strict_types=1);

/** Representation policy: GET/POST/PATCH produce JSON, XML or YAML; writes consume JSON only. */
function responseMediaType(): string
{
    $accept = trim($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept === '') { return 'application/json'; }
    $supported = ['application/json', 'application/xml', 'text/xml', 'application/yaml', 'text/yaml'];
    $best = null;
    $bestScore = -1.0;
    foreach (explode(',', $accept) as $position => $entry) {
        $parts = array_map('trim', explode(';', strtolower(trim($entry))));
        $media = array_shift($parts);
        $quality = 1.0;
        foreach ($parts as $param) {
            if (preg_match('/^q=(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $param, $m)) { $quality = (float) $m[1]; }
            elseif (str_starts_with($param, 'q=')) { $quality = 0.0; }
        }
        if ($quality <= 0) { continue; }
        $specificity = in_array($media, $supported, true) ? 2 : ($media === 'application/*' || $media === 'text/*' ? 1 : ($media === '*/*' ? 0 : -1));
        if ($specificity < 0) { continue; }
        $selected = in_array($media, $supported, true) ? $media : ($media === 'text/*' ? 'application/xml' : 'application/json');
        if ($selected === 'text/xml') { $selected = 'application/xml'; }
        if ($selected === 'text/yaml') { $selected = 'application/yaml'; }
        $score = $quality * 10 + $specificity;
        if ($score > $bestScore) { $bestScore = $score; $best = $selected; }
    }
    if ($best === null) { throw new ApiError(406, 'Available representations: application/json, application/xml, application/yaml', 'not_acceptable'); }
    return $best;
}

function toXmlNode(string $name, mixed $value): string
{
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) { throw new RuntimeException('Invalid XML field name'); }
    if ($value === null) { return "<$name xsi:nil=\"true\"/>"; }
    if (is_array($value)) {
        $kind = array_is_list($value) ? 'array' : 'object';
        $out = "<$name kind=\"$kind\">";
        foreach ($value as $key => $child) {
            $out .= toXmlNode(is_int($key) ? 'item' : (string) $key, $child);
        }
        return $out . "</$name>";
    }
    $scalar = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    $scalar = htmlspecialchars($scalar, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'number' : null));
    $attribute = $type === null ? '' : ' type="' . $type . '"';
    return "<$name$attribute>$scalar</$name>";
}

function yamlScalar(mixed $value): string
{
    if ($value === null) { return 'null'; }
    if (is_bool($value)) { return $value ? 'true' : 'false'; }
    if (is_int($value) || is_float($value)) { return (string) $value; }
    return json_encode((string) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function toYaml(mixed $value, int $level = 0): string
{
    if (!is_array($value)) { return str_repeat(' ', $level) . yamlScalar($value) . "\n"; }
    if ($value === []) { return str_repeat(' ', $level) . "[]\n"; }
    $list = array_is_list($value);
    $output = '';
    foreach ($value as $key => $child) {
        $indent = str_repeat(' ', $level);
        $prefix = $list ? '- ' : (string) $key . ': ';
        if (is_array($child) && $child !== []) {
            $output .= $indent . rtrim($prefix) . "\n" . toYaml($child, $level + 2);
        } else {
            $output .= $indent . $prefix . (is_array($child) ? '[]' : yamlScalar($child)) . "\n";
        }
    }
    return $output;
}

function sendRepresentation(mixed $value, int $status = 200): never
{
    $media = responseMediaType();
    http_response_code($status);
    header('Vary: Accept');
    header('Content-Type: ' . $media . '; charset=utf-8');
    if ($media === 'application/xml') {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" kind="'
            . (is_array($value) && array_is_list($value) ? 'array' : 'object') . '">';
        if (is_array($value)) {
            foreach ($value as $key => $child) { echo toXmlNode(is_int($key) ? 'item' : (string) $key, $child); }
        } else { echo toXmlNode('value', $value); }
        echo '</response>';
    } elseif ($media === 'application/yaml') {
        echo "---\n" . toYaml($value);
    } else {
        echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}
