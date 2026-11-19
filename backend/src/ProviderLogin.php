<?php

declare(strict_types=1);

final class ProviderLogin
{
    private const BASE = '/api/provider-logins';

    private static function invalidService(): ApiError
    {
        return new ApiError(503, 'Provider authentication service unavailable', 'provider_login_unavailable');
    }

    private static function envelope(string $provider, #[SensitiveParameter] mixed $credential): string
    {
        $data = is_string($credential) && strlen($credential) <= 32768 ? json_decode($credential,true,16) : null;
        if (!is_array($data) || ($data['provider'] ?? null) !== $provider || !in_array($data['type'] ?? null,['api_key','oauth','keyless'],true)) { throw self::invalidService(); }
        return ProviderCredentials::LOGIN_PREFIX . $credential;
    }

    private static function needsRefresh(#[SensitiveParameter] string $value): bool
    {
        if (!str_starts_with($value,ProviderCredentials::LOGIN_PREFIX)) { return false; }
        $data = json_decode(substr($value,strlen(ProviderCredentials::LOGIN_PREFIX)),true,16,JSON_THROW_ON_ERROR);
        return isset($data['expires']) && $data['expires'] <= (time()+60)*1000;
    }

    public static function refreshIfNeeded(string $user, string $provider, #[SensitiveParameter] string $value): string
    {
        if (!self::needsRefresh($value)) { return $value; }
        // Rotating grants must commit independently of dispatch reservations, including a later quota refusal.
        $vault = connectWorkspaceDatabase();
        return inDatabaseTransaction($vault,static function () use ($vault,$user,$provider) {
            $sealed = executeSql($vault,'SELECT sealed_key FROM provider_credentials WHERE user_id=? AND provider=? FOR UPDATE',[$user,$provider])->fetchColumn();
            if ($sealed === false) { throw new ApiError(503, 'This provider was disconnected', 'ai_not_configured'); }
            $current = ProviderCredentials::unseal($user,$provider,$sealed);
            if (!self::needsRefresh($current)) { return $current; }
            $resolved = RouterConnection::request('auth/resolve',['provider'=>$provider,'credential'=>substr($current,strlen(ProviderCredentials::LOGIN_PREFIX))]);
            $value = self::envelope($provider,$resolved['credential'] ?? null);
            executeSql($vault,'UPDATE provider_credentials SET sealed_key=? WHERE user_id=? AND provider=?',[ProviderCredentials::seal($user,$provider,$value),$user,$provider]);
            return $value;
        });
    }

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        if ($path !== self::BASE && !preg_match('~\A/api/provider-logins/([a-f0-9]{64})/(status|answer|cancel)\z~D',$path,$m)) { return; }
        if (!RouterConnection::enabled()) { throw new ApiError(404,'Provider sign-in unavailable','not_found'); }
        $actor = $auth->user();
        if (queryParams()) { throw new ApiError(400,'Sign-in does not take query parameters'); }
        if ($path === self::BASE && $method === 'GET') {
            $rows = RouterConnection::request('logins')['providers'] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) { throw self::invalidService(); }
            $methods = [];
            foreach ($rows as $row) {
                if (!ProviderCredentials::validProvider($row['id'] ?? null) || !ProviderCredentials::validProvider($row['provider'] ?? null) || !is_string($row['name'] ?? null) || !is_bool($row['available'] ?? null)) { throw self::invalidService(); }
                $methods[] = array_intersect_key($row,array_flip(['id','provider','name','available','reason']));
            }
            $links = ['self'=>apiLink(self::BASE)];
            if ($actor['role'] !== 'viewer') { $links['startLogin'] = apiLink(self::BASE,'POST'); }
            respond(['providers'=>$methods,'_links'=>$links]);
        }
        requireHttpMethod($method,$path === self::BASE ? ['GET','POST'] : ['POST']);
        if ($actor['role'] === 'viewer') { throw new ApiError(403,'Sign-in requires a writable account','forbidden'); }
        $action = $m[2] ?? 'start';
        $payload = readRequestBody();
        $params = ['actor'=>hash('sha256',$actor['id']."\0".Auth::cookieToken())];
        if ($action !== 'start') { $params['id'] = $m[1]; }
        $valid = match ($action) {
            'start' => count($payload) === 1 && ProviderCredentials::validProvider($payload['provider'] ?? null),
            'answer' => count($payload) === 2 && is_string($payload['promptId'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D',$payload['promptId']) && is_string($payload['answer'] ?? null) && strlen($payload['answer']) <= 16384,
            default => $payload === [],
        };
        if (!$valid) { throw new ApiError(422,'Invalid provider sign-in request','login_input_invalid'); }
        $reply = RouterConnection::request('auth/'.$action,$params+$payload);
        $id = $reply['id'] ?? null; $provider = $reply['provider'] ?? null; $state = $reply['state'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D',$id) || (isset($m[1]) && $m[1] !== $id) || !ProviderCredentials::validProvider($provider)) { throw self::invalidService(); }
        if ($state === 'failed') { throw new ApiError(422,'Provider sign-in did not finish','provider_login_failed'); }
        if (!in_array($state,['completed','pending','cancelled'],true)) { throw self::invalidService(); }
        if ($state === 'completed') {
            $value = self::envelope($provider,$reply['credential'] ?? null);
            inDatabaseTransaction($db,static function () use ($db,$auth,$actor,$provider,$value) {
                $auth->authenticate(Auth::cookieToken());
                if ($auth->user()['role'] === 'viewer') { throw new ApiError(403,'Provider sign-in no longer allowed','forbidden'); }
                executeSql($db,"INSERT INTO provider_credentials(user_id,provider,sealed_key,auth_type) VALUES (?,?,?,'login') ON CONFLICT (user_id,provider) DO UPDATE SET sealed_key=EXCLUDED.sealed_key, auth_type='login'",[$actor['id'],$provider,ProviderCredentials::seal($actor['id'],$provider,$value)]);
                $auth->audit('credential.set',$provider);
            });
            RouterConnection::request('auth/cancel',['actor'=>$params['actor'],'id'=>$id]);
        }
        $public = array_intersect_key($reply,array_flip(['id','provider','loginId','state','expiresAt','url','message']));
        if (isset($reply['prompt']) && is_array($reply['prompt'])) { $public['prompt'] = array_intersect_key($reply['prompt'],array_flip(['id','message','secret','allowEmpty','placeholder'])); }
        if ($state === 'pending') {
            foreach (['status','answer','cancel'] as $next) { $public['_links'][$next] = apiLink(self::BASE.'/'.$id.'/'.$next,'POST'); }
        }
        respond($public);
    }
}
