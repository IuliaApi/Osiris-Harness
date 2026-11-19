<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

const REPRESENTATION_TYPES = ['application/json', 'application/xml', 'application/yaml', 'text/xml', 'text/yaml'];
const REPRESENTATION_MAX_BYTES = 65536;
const REPRESENTATION_MAX_DEPTH = 32;
const REPRESENTATION_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

function responseMediaType(): string
{
    return preferredMediaType($_SERVER['HTTP_ACCEPT'] ?? '', REPRESENTATION_TYPES)
        ?? throw new ApiError(406, 'Available representations: application/json, application/xml, application/yaml', 'not_acceptable');
}

/** The most specific matching range determines each offered type's q, including exclusions. */
function preferredMediaType(string $accept, array $supported): ?string
{
    if (trim($accept) === '') { return $supported[0]; }
    $offers = [];
    // A quoted parameter may contain separators; it must never create another offer.
    foreach (preg_split('/"(?:[^"\\\\]++|\\\\.)*"(*SKIP)(*F)|,/', strtolower($accept)) as $order => $entry) {
        $params = array_map('trim', preg_split('/"(?:[^"\\\\]++|\\\\.)*"(*SKIP)(*F)|;/', $entry));
        $range = array_shift($params);
        $q = 1.0;
        $weighted = false;
        $charset = false;
        foreach ($params as $param) {
            if (preg_match('/^q\s*=\s*(.*)$/D', $param, $m)) {
                $q = !$weighted && preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $m[1]) ? (float) $m[1] : 0.0;
                $weighted = true;
            } elseif (!$weighted) {
                if ($charset || !preg_match('/^charset\s*=\s*(?:utf-8|"utf-8")$/D', $param)) { continue 2; }
                $charset = true;
            }
        }
        foreach ($supported as $offer) {
            $group = explode('/', $offer)[0] . '/*';
            $specificity = $range === $offer ? 4 : ($range === $group ? 2 : ($range === '*/*' ? 0 : -1));
            if ($specificity < 0) { continue; }
            $specificity += (int) $charset;
            if (!isset($offers[$offer]) || $specificity > $offers[$offer][1]
                || ($specificity === $offers[$offer][1] && $q > $offers[$offer][0])) {
                $offers[$offer] = [$q, $specificity, -$order];
            }
        }
    }
    $best = null;
    foreach ($supported as $offer) {
        if (isset($offers[$offer]) && $offers[$offer][0] > 0 && ($best === null || $offers[$offer] > $offers[$best])) {
            $best = $offer;
        }
    }
    return $best;
}

function requestMediaType(string $contentType): string
{
    if (preg_match('/^\s*([^;\s]+)\s*(?:;\s*charset\s*=\s*(?:"utf-8"|utf-8)\s*)?$/iD', $contentType, $m)) {
        $type = strtolower($m[1]);
        if (in_array($type, REPRESENTATION_TYPES, true)) { return $type; }
    }
    throw new ApiError(415, 'Use application/json, application/xml or application/yaml with UTF-8 encoding', 'unsupported_media_type');
}

function malformedDocument(string $format): ApiError
{
    return new ApiError(400, 'Malformed or unsupported ' . $format . ' document', 'invalid_request');
}

/** json_decode validates syntax, but would silently overwrite duplicate (even escaped) keys. */
function checkJsonKeys(string $raw): void
{
    $objects = [];
    $cursor = 0;
    while (preg_match('/[{}"]/', $raw, $match, PREG_OFFSET_CAPTURE, $cursor)) {
        [$token, $start] = $match[0];
        $cursor = $start + 1;
        if ($token === '{') { $objects[] = []; continue; }
        if ($token === '}') { array_pop($objects); continue; }
        while ($raw[$cursor] !== '"') {
            if ($raw[$cursor] === '\\') { ++$cursor; }
            ++$cursor;
        }
        ++$cursor;
        $next = $cursor + strspn($raw, " \t\r\n", $cursor);
        if (($raw[$next] ?? '') !== ':') { continue; }
        $key = json_decode(substr($raw, $start, $cursor - $start), flags: JSON_THROW_ON_ERROR);
        $index = array_key_last($objects);
        if (isset($objects[$index][$key])) { throw malformedDocument('JSON'); }
        $objects[$index][$key] = true;
    }
}

function utf8Length(string $text): int
{
    $length = preg_match_all('/./us', $text);
    if ($length === false) { throw new ApiError(400, 'Expected UTF-8 text'); }
    return $length;
}

/** Keep text representable in every supported format, including XML 1.0. */
function isRepresentableText(string $text): bool
{
    return preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text) === 0;
}

/** Only data representable in all three encodings enters application validation. */
function normalizeInput(mixed $value, int $depth = 1): mixed
{
    if ($depth > REPRESENTATION_MAX_DEPTH) { throw malformedDocument('request'); }
    if ($value instanceof stdClass || is_array($value)) {
        $result = [];
        foreach ($value as $key => $child) {
            if ($value instanceof stdClass && !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', (string) $key)) {
                throw malformedDocument('mapping');
            }
            $result[$key] = normalizeInput($child, $depth + 1);
        }
        return $depth > 1 && $value instanceof stdClass && $result === [] ? $value : $result;
    }
    if ((is_string($value) && !isRepresentableText($value))
        || (is_float($value) && !is_finite($value)) || is_object($value)) {
        throw malformedDocument('scalar');
    }
    return $value;
}

function readXmlValue(DOMElement $element, int $depth): mixed
{
    if ($depth > REPRESENTATION_MAX_DEPTH || $element->namespaceURI !== null
        || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $element->tagName)) { throw malformedDocument('XML'); }
    $type = $element->hasAttribute('type') ? $element->getAttribute('type') : null;
    $nil = $element->hasAttributeNS(REPRESENTATION_XSI, 'nil');
    $kind = $element->hasAttribute('kind') ? $element->getAttribute('kind') :
        ($depth === 1 && $type === null && !$nil ? 'object' : null);
    foreach ($element->attributes as $attribute) {
        if ($attribute->namespaceURI === REPRESENTATION_XSI && $attribute->localName === 'nil') { continue; }
        if ($attribute->namespaceURI !== null || !in_array($attribute->name, ['kind', 'type'], true)) { throw malformedDocument('XML'); }
    }
    if ($kind !== null && (!in_array($kind, ['array', 'object'], true) || $type !== null || $nil)) { throw malformedDocument('XML'); }
    if ($nil && ($type !== null || $element->getAttributeNS(REPRESENTATION_XSI, 'nil') !== 'true' || $element->hasChildNodes())) { throw malformedDocument('XML'); }
    if ($nil) { return null; }
    $children = [];
    $text = '';
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMText) {
            if ($kind === null) { $text .= $child->data; }
            elseif (trim($child->data) !== '') { throw malformedDocument('XML'); }
            continue;
        }
        if (!$child instanceof DOMElement || $kind === null) { throw malformedDocument('XML'); }
        if ($kind === 'array') {
            if ($child->tagName !== 'item') { throw malformedDocument('XML'); }
            $children[] = readXmlValue($child, $depth + 1);
        } else {
            if (array_key_exists($child->tagName, $children)) { throw malformedDocument('XML'); }
            $children[$child->tagName] = readXmlValue($child, $depth + 1);
        }
    }
    if ($kind !== null) { return $kind === 'object' ? (object) $children : $children; }
    if ($type === null || $type === 'string') { return $text; }
    if ($type === 'boolean' && in_array($text, ['true', 'false'], true)) { return $text === 'true'; }
    if ($type === 'integer' || $type === 'number') {
        try { $number = json_decode($text, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw malformedDocument('XML'); }
        if (is_int($number) || ($type === 'number' && is_float($number) && is_finite($number))) { return $number; }
    }
    throw malformedDocument('XML');
}

function parseXmlRequest(string $raw): mixed
{
    // Refuse non-UTF-8 byte encodings and DTDs *before* invoking libxml. Never enable
    // entity substitution, DTD loading/validation, XInclude, or the HUGE parser flag.
    if (str_contains($raw, "\0") || str_contains($raw, '<!DOCTYPE') || preg_match('//u', $raw) !== 1
        || (preg_match('/<\?xml\s+[^?]*\bencoding\s*=\s*["\']([^"\']+)/i', $raw, $m) && strcasecmp($m[1], 'utf-8') !== 0)) {
        throw malformedDocument('XML');
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        libxml_clear_errors();
        $options = LIBXML_NONET | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0);
        if (!$doc->loadXML($raw, $options) || libxml_get_last_error() !== false || $doc->doctype !== null || !$doc->documentElement
            || $doc->documentElement->tagName !== 'request' || $doc->childNodes->length !== 1) { throw malformedDocument('XML'); }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return readXmlValue($doc->documentElement, 1);
}

function decodeRepresentation(string $raw, string $media): array
{
    if (strlen($raw) > REPRESENTATION_MAX_BYTES) { throw new ApiError(413, 'Request body exceeds 64 KiB', 'payload_too_large'); }
    if (trim($raw) === '') { throw malformedDocument('request'); }
    switch ($media) {
        case 'application/json':
            try { $value = json_decode($raw, false, REPRESENTATION_MAX_DEPTH + 1, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw malformedDocument('JSON'); }
            checkJsonKeys($raw);
            break;
        case 'application/xml': case 'text/xml':
            $value = parseXmlRequest($raw);
            break;
        case 'application/yaml': case 'text/yaml':
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            // The parser normally discards properties on a standalone document start
            // marker. Reject them explicitly rather than silently accepting a root tag.
            if (preg_match('/(?:\A(?:\xEF\xBB\xBF)?|[\r\n])---[ \t]+[!&%]/', $raw)) { throw malformedDocument('YAML'); }
            try {
                // PARSE_OBJECT_FOR_MAP distinguishes {} from []; no PARSE_OBJECT,
                // PARSE_CONSTANT, PARSE_DATETIME, or PARSE_CUSTOM_TAGS.
                $flags = Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_OBJECT_FOR_MAP;
                if (str_contains($raw, '<<')) {
                    // Symfony otherwise resolves an inline merge even with aliases disabled,
                    // hiding duplicate fields. Make the token an ordinary (invalid) key
                    // and let the parser distinguish mapping keys from quoted/block text.
                    normalizeInput(Yaml::parse(str_replace('<<', '<<!', $raw), $flags, REPRESENTATION_MAX_DEPTH, 0));
                }
                $value = Yaml::parse($raw, $flags, REPRESENTATION_MAX_DEPTH, 0);
            } catch (ParseException) { throw malformedDocument('YAML'); }
            break;
        default:
            throw new ApiError(415, 'Unsupported Content-Type', 'unsupported_media_type');
    }
    if (!$value instanceof stdClass) { throw new ApiError(422, 'Request body must be an object', 'validation_error'); }
    return normalizeInput($value);
}

function toXmlNode(string $name, mixed $value, bool $root = false): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name)) { throw new RuntimeException('Invalid XML field name'); }
    $open = '<' . $name . ($root ? ' xmlns:xsi="' . REPRESENTATION_XSI . '"' : '');
    if ($value === null) { return "$open xsi:nil=\"true\"/>"; }
    if (is_array($value) || $value instanceof stdClass) {
        $kind = is_array($value) && array_is_list($value) ? 'array' : 'object';
        $out = "$open kind=\"$kind\">";
        foreach ($value as $key => $child) {
            $out .= toXmlNode(is_int($key) ? 'item' : (string) $key, $child);
        }
        return $out . "</$name>";
    }
    $scalar = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $scalar = str_replace("\r", '&#13;', htmlspecialchars($scalar, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8'));
    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'number' : null));
    $attribute = $type === null ? '' : ' type="' . $type . '"';
    return "$open$attribute>$scalar</$name>";
}

function yamlScalar(mixed $value): string
{
    $text = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // C1 controls are legal raw JSON but not printable YAML. U+0085 is also a YAML line break.
    return preg_replace_callback('/[\x7f-\x{9f}]/u', static fn ($m) => sprintf('\\u%04x',
        ord($m[0][strlen($m[0]) - 1])), $text);
}

function toYaml(mixed $value, int $level = 0): string
{
    if (!is_array($value) && !$value instanceof stdClass) { return str_repeat(' ', $level) . yamlScalar($value) . "\n"; }
    $list = is_array($value) && array_is_list($value);
    if (!(array) $value) { return str_repeat(' ', $level) . ($list ? '[]' : '{}') . "\n"; }
    $output = '';
    foreach ($value as $key => $child) {
        $indent = str_repeat(' ', $level);
        $key = (string) $key;
        $plainKey = preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D', $key)
            && !in_array(strtolower($key), ['null', 'true', 'false', 'y', 'n', 'yes', 'no', 'on', 'off'], true);
        $prefix = $list ? '- ' : ($plainKey ? $key : yamlScalar($key)) . ': ';
        if ((is_array($child) || $child instanceof stdClass) && (array) $child !== []) {
            $output .= $indent . rtrim($prefix) . "\n" . toYaml($child, $level + 2);
        } else {
            $output .= $indent . $prefix . yamlScalar($child) . "\n";
        }
    }
    return $output;
}

function sendRepresentation(mixed $value, int $status = 200): never
{
    $media = responseMediaType();
    if (str_ends_with($media, '/xml')) {
        $payload = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . toXmlNode('response', $value, true);
    } elseif (str_ends_with($media, '/yaml')) {
        $payload = "---\n" . toYaml($value);
    } else {
        $payload = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    http_response_code($status);
    header('Vary: Accept');
    header('Content-Type: ' . $media . '; charset=utf-8');
    echo $payload;
    exit;
}
