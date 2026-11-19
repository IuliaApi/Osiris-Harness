<?php

declare(strict_types=1);

final class ProviderCredentials
{
    public const KEY_PROVIDERS = ['openai', 'anthropic'];
    public const LOGIN_PREFIX = "\0login:";

    public static function validProvider(mixed $provider): bool
    {
        return is_string($provider) && AIProvider::validProvider($provider);
    }

    private static function encryptionKey(): string
    {
        $encoded = getenv('OSIRIS_VAULT_KEY');
        if ($encoded === false || $encoded === '') {
            $file = getenv('OSIRIS_VAULT_KEY_FILE') ?: '/run/osiris/vault.key';
            $encoded = @file_get_contents($file, false, null, 0, 129);
        }
        $key = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new ApiError(503, 'Credential vault unavailable', 'vault_unavailable');
        }
        return $key;
    }

    /** GCM associated data prevents moving a ciphertext between identities or providers. */
    public static function seal(string $user, string $provider, #[SensitiveParameter] string $secret): string
    {
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, $user . "\0" . $provider);
        if ($ciphertext === false) { throw new ApiError(503, 'Credential vault unavailable', 'vault_unavailable'); }
        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function unseal(string $user, string $provider, #[SensitiveParameter] string $sealed): string
    {
        $blob = base64_decode($sealed, true);
        $value = is_string($blob) && strlen($blob) > 28 ? openssl_decrypt(substr($blob, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), $user . "\0" . $provider) : false;
        if (!is_string($value) || (!str_starts_with($value,self::LOGIN_PREFIX) && !preg_match('/\A[!-~]{1,4096}\z/D', $value))) { throw new ApiError(503, 'Credential vault unavailable', 'vault_unavailable'); }
        return $value;
    }

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        if (!preg_match('~\A/api/credentials/([a-z0-9][a-z0-9.-]{0,79})\z~D', $path, $match)) { return; }
        $provider = $match[1];
        $keyProvider = in_array($provider, self::KEY_PROVIDERS, true);
        if (!$keyProvider && (!RouterConnection::enabled() || $method !== 'DELETE')) { throw new ApiError(404, 'Resource not found', 'not_found'); }
        $actor = $auth->user();
        requireHttpMethod($method, $keyProvider ? ['PUT','DELETE'] : ['DELETE']);
        if ($actor['role'] === 'viewer') { throw new ApiError(403, 'Provider key management requires a writable account', 'forbidden'); }
        if (queryParams()) { throw new ApiError(400, 'Credentials accept no query parameters'); }
        $user = $actor['id'];
        if ($method === 'DELETE') {
            inDatabaseTransaction($db, function () use ($db,$auth,$user,$provider) {
                executeSql($db, 'DELETE FROM provider_credentials WHERE user_id = ? AND provider = ?', [$user,$provider]);
                $auth->audit('credential.delete',$provider);
            });
            http_response_code(204); exit;
        }
        $body = readRequestBody();
        if (array_keys($body) !== ['apiKey'] || !is_string($body['apiKey']) || !preg_match('/\A[!-~]{1,4096}\z/D',$body['apiKey'])) {
            throw new ApiError(422, 'A provider key must be 1–4096 printable non-space ASCII characters', 'validation_error');
        }
        $sealed = self::seal($user,$provider,$body['apiKey']);
        inDatabaseTransaction($db, function () use ($db,$auth,$user,$provider,$sealed) {
            executeSql($db, "INSERT INTO provider_credentials(user_id,provider,sealed_key) VALUES (?,?,?) ON CONFLICT (user_id,provider) DO UPDATE SET sealed_key = EXCLUDED.sealed_key, auth_type='apiKey'", [$user,$provider,$sealed]);
            $auth->audit('credential.set',$provider);
        });
        respond(['provider' => $provider, 'configured' => true, '_links' => ['runtime' => apiLink('/api/runtime'), 'deleteCredential' => apiLink($path,'DELETE')]]);
    }
}
