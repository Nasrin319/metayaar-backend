<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum accepts tokens of both website users and staff, so routes say which kind they serve:
 * `account:user` for the website API, `account:admin` for the admin panel API.
 */
class EnsureAccountType
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $class = $type === 'admin' ? Admin::class : User::class;

        abort_unless($request->user() instanceof $class, 403, 'This account cannot use this endpoint.');

        return $next($request);
    }
}
