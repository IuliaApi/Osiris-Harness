<?php

declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message, public readonly string $codeName = 'invalid_request')
    {
        parent::__construct($message);
    }
}

function respond(mixed $value, int $status = 200): never
{
    try {
        sendRepresentation($value, $status);
    } catch (ApiError $error) {
        if ($error->httpStatus !== 406) { throw $error; }
        http_response_code(406);
        header('Vary: Accept');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error->getMessage(), 'code' => $error->codeName, 'requestId' => $GLOBALS['requestId']]);
        exit;
    }
}

function readRequestBody(): array
{
    $type = requestMediaType($_SERVER['CONTENT_TYPE'] ?? '');
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > REPRESENTATION_MAX_BYTES) {
        throw new ApiError(413, 'Request body exceeds 64 KiB', 'payload_too_large');
    }
    $raw = file_get_contents('php://input', false, null, 0, REPRESENTATION_MAX_BYTES + 1);
    if ($raw === false) { throw new ApiError(400, 'Unreadable request body'); }
    return decodeRepresentation($raw, $type);
}

function queryParams(): array
{
    $params = [];
    foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $pair) {
        if ($pair === '') { continue; }
        $split = explode('=', $pair, 2);
        $key = urldecode($split[0]);
        if (array_key_exists($key, $params)) { throw new ApiError(400, "Repeated query parameter: $key"); }
        $params[$key] = urldecode($split[1] ?? '');
    }
    return $params;
}

function requireHttpMethod(string $method, array $allowed): void
{
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ApiError(405, 'Method not allowed', 'method_not_allowed');
    }
}

function requiredResourceVersion(): int
{
    $header = $_SERVER['HTTP_IF_MATCH'] ?? null;
    if ($header === null) { throw new ApiError(428, 'If-Match with the quoted current version is required', 'precondition_required'); }
    if (!preg_match('/\A"([1-9][0-9]{0,9})"\z/D', trim($header), $m) || (int) $m[1] > 2147483647) {
        throw new ApiError(400, 'If-Match must be one strong, quoted positive integer version', 'invalid_precondition');
    }
    return (int) $m[1];
}

function rejectStaleVersion(): never
{
    throw new ApiError(412, 'Resource version changed; reload before retrying', 'stale_version');
}
