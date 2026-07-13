<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 仅 system_admin 可进入后台。 */
class EnsureSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSystemAdmin() || $user->isDisabled()) {
            abort(403, '仅系统管理员可访问后台。');
        }

        return $next($request);
    }
}
