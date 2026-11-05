<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who is really behind a request. The panel calls the API from its own server, so it passes the
 * visitor's address along. Only a request with the shared key may do that, or anyone calling the API
 * directly could write a false address into the login history.
 */
class ClientInfo
{
    /**
     * @return array{ip_address: string, user_agent: ?string}
     */
    public static function of(Request $request): array
    {
        $key = (string) config('services.internal_api_key');
        $trusted = $key !== '' && hash_equals($key, (string) $request->header('X-Internal-Key'));
        $ip = $trusted ? filter_var($request->header('X-Client-IP'), FILTER_VALIDATE_IP) : false;
        $userAgent = ($trusted ? $request->header('X-Client-User-Agent') : null) ?? $request->userAgent();

        return [
            'ip_address' => $ip ?: (string) $request->ip(),
            'user_agent' => $userAgent ? Str::limit($userAgent, 500, '') : null,
        ];
    }
}
