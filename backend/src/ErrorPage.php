<?php

declare(strict_types=1);

/** Public, stateless recovery pages. Never put a request path, exception text, or credential in these URLs. */
final class ErrorPage
{
    private const STATUS = [
        400 => ['Invalid request', 'Check the parameters and field names, then use the action offered by the workspace.'],
        401 => ['Sign in required', 'Your session may have expired, or your sign-in details were not accepted. Sign in to continue.'],
        403 => ['Access denied', 'Use an account with the required permissions, or ask the project owner for access.'],
        404 => ['Page not found', 'This page or record is unavailable to this account. Open the dashboard to find resources you can access.'],
        405 => ['Action not available', 'This address does not support that action. Follow a link offered by the workspace.'],
        406 => ['Response format not available', 'Request JSON, XML or YAML from the API, or use the dashboard to browse the workspace.'],
        409 => ['Conflicting change', 'Read the current record before deciding what to do next. Do not repeat a write without checking its result.'],
        412 => ['Record changed', 'Reload the record and review its current version before applying a change.'],
        413 => ['Request too large', 'Reduce the request body to 64 KiB or less.'],
        415 => ['Unsupported document type', 'Send a UTF-8 JSON, XML or YAML document with its matching Content-Type.'],
        422 => ['Check your input', 'Correct the indicated fields and use the options offered by the workspace.'],
        428 => ['Resource version required', 'Read the latest resource and supply its quoted version in the If-Match header.'],
        429 => ['Request limit reached', 'Pause before making more requests. Respect the Retry-After header when supplied.'],
        500 => ['Workspace error', 'Check the saved state before repeating an action. Contact the administrator with the request ID if this persists.'],
        502 => ['Upstream service error', 'Check provider availability and the selected model. Read the saved run before continuing.'],
        503 => ['Service unavailable', 'Check the workspace configuration and service availability. Do not replay a pending request.'],
        504 => ['Upstream request timed out', 'The service did not respond in time. Refresh the saved run before considering another model call.'],
    ];
    private const RECOVERY = [
        'connection_interrupted' => ['Workspace connection interrupted', 'The browser did not receive a complete response. Reopen the thread or record and check its saved state before repeating a change or model call.'],
        'login_not_found' => ['Sign-in session not found', 'Open Model settings & credentials and start a new provider sign-in.'],
        'login_input_invalid' => ['Check the sign-in response', 'Check the selected provider and the last field requested by its sign-in prompt.'],
        'login_state_changed' => ['Sign-in prompt changed', 'Return to the latest sign-in prompt rather than submitting an older response.'],
        'login_rate_limit' => ['Too many sign-in attempts', 'Wait for the sign-in limit to reset before starting another provider login.'],
        'provider_login_unavailable' => ['Provider sign-in unavailable', 'Check the provider connection in Manage. Reconnect if the saved login has expired.'],
        'provider_login_failed' => ['Provider sign-in incomplete', 'Return to Manage and explicitly start a new sign-in if you still want to connect.'],
        'csrf_failed' => ['Session check failed', 'Open the dashboard on this origin and reload your session before submitting changes.'],
        'invalid_precondition' => ['Invalid resource version', 'Read the record and use its current version as a single quoted integer in If-Match.'],
        'concurrent_change' => ['Concurrent change', 'Another operation changed this record. Reload it before deciding whether your change is still needed.'],
        'conflict' => ['Record conflict', 'Check for an existing record or dependent records. If registering an account, choose a different username.'],
        'turn_active' => ['A turn is already active', 'Open the thread and refresh its run. Continue or cancel the existing turn rather than resending your prompt.'],
        'review_required' => ['File review required', 'Open the thread and inspect the proposed contents. Allow or deny the change there.'],
        'stale_step' => ['The turn has advanced', 'Refresh the run in chat. Do not replay a step that may already have reached the provider.'],
        'invalid_goal_state' => ['Goal state changed', 'Open /goal in the thread and choose an action for its current state.'],
        'invalid_session_state' => ['Thread is not active', 'Open a writable active thread or create a new one.'],
        'invalid_task_state' => ['Task transition unavailable', 'Read the current task status and choose an available transition.'],
        'invalid_run_state' => ['Tool request changed', 'Read the current tool request before deciding whether it needs approval or execution.'],
        'workspace_base_changed' => ['File changed since review', 'Review the latest workspace contents before allowing a new change.'],
        'workspace_file_missing' => ['Workspace file not found', 'Check the workspace filename and whether the file still exists.'],
        'ai_not_configured' => ['Provider access unavailable', 'Open Manage, then Model settings & credentials. Check this account’s chosen provider and model.'],
        'ai_tools_unsupported' => ['Model does not support tools', 'Use /model to choose a tool-capable model, or use /tools off for a plain answer.'],
        'ai_upstream_auth' => ['Provider rejected authentication', 'Open Model settings & credentials in Manage to renew your sign-in or update the provider key.'],
        'ai_upstream_quota' => ['Provider quota exhausted', 'Check credit, billing or subscription limits for the account that supplies this model.'],
        'ai_upstream_rate_limit' => ['Provider rate limit reached', 'Wait for the provider’s limit to reset and check the saved run before another call.'],
        'ai_upstream_unavailable' => ['Provider unreachable', 'Check provider availability or the local service connection, then refresh the saved run.'],
        'ai_catalog_unavailable' => ['Model catalog unavailable', 'Check provider connectivity and your account settings, then reopen the model picker.'],
        'budget_exhausted' => ['Daily dispatch budget exhausted', 'Open Daily budget in Manage. Wait for the reset or ask the administrator about the limit.'],
        'budget_unavailable' => ['Dispatch budget unavailable', 'Ask the workspace administrator to check the provider dispatch limits.'],
        'vault_unavailable' => ['Credential vault unavailable', 'Ask the workspace administrator to restore the credential vault before changing account access.'],
    ];
    // Codes with no override use the HTTP status guidance. Only application
    // constants may enter a help URL, never an arbitrary upstream error string.
    private const STANDARD_CODES = [
        'invalid_request', 'unauthorized', 'forbidden', 'not_found', 'method_not_allowed', 'not_acceptable',
        'stale_version', 'payload_too_large', 'unsupported_media_type', 'validation_error', 'precondition_required',
        'rate_limited', 'internal_error', 'ai_upstream_failure', 'ai_upstream_timeout', 'request_failed',
    ];

    public static function preferred(): bool
    {
        return preferredMediaType($_SERVER['HTTP_ACCEPT'] ?? '', [...REPRESENTATION_TYPES, 'text/html']) === 'text/html';
    }

    public static function url(int $status, string $code): string
    {
        return '/errors/' . $status . '/' . (isset(self::RECOVERY[$code]) || in_array($code, self::STANDARD_CODES, true) ? $code : 'request_failed');
    }

    public static function advertise(int $status, string $code): void
    {
        $url = self::url($status, $code);
        header('X-Error-Page: ' . $url);
        header('Link: <' . $url . '>; rel="help"', false);
    }

    public static function render(int $status, string $code): never
    {
        [$heading, $guidance] = self::RECOVERY[$code] ?? self::STATUS[$status] ?? ['Request failed', 'Open the workspace and check the current state before trying again.'];
        $url = self::url($status, $code);
        $safeCode = htmlspecialchars(basename($url), ENT_QUOTES, 'UTF-8');
        http_response_code($status);
        header('Vary: Accept');
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . $heading . ' — Osiris</title><link rel="canonical" href="' . $url . '"><link rel="stylesheet" href="/assets/workspace.css"></head><body><main class="error-page"><p class="eyebrow">Osiris · HTTP ' . $status . '</p><h1>' . $heading . '</h1><p>' . $guidance . '</p><p><code>' . $safeCode . '</code></p><nav aria-label="Recovery actions">';
        // Only known workspace routes become return links. Never replay a
        // mutation, reflect a referrer URL, or carry private query parameters.
        $return = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
        $label = match ($return) {
            '/register' => 'Back to registration', '/login' => 'Back to sign in',
            '/chat' => 'Back to chat', '/manage' => 'Back to Manage',
            '/workspace' => 'Back to sessions', '/api-checks' => 'Back to API Checks',
            default => null,
        };
        if ($label !== null) echo '<a class="btn-os primary" href="' . $return . '">' . $label . '</a> ';
        if ($status === 401 || $status === 403) echo '<a class="btn-os primary" href="/login">Sign in</a> ';
        if (preg_match('/\\A(?:ai_|budget_|provider_|login_)/', $code) || $code === 'vault_unavailable') echo '<a class="btn-os" href="/manage">Open Manage</a> ';
        if ($status === 409 || $status >= 500) echo '<a class="btn-os" href="/chat">Open chat</a> ';
        echo '<a class="btn-os" href="/dashboard">Open dashboard</a></nav></main></body></html>';
        exit;
    }
}
