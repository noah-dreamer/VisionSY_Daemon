<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 内部 API 鉴权（Nginx / 反向代理网关 / ResourceSpace 服务端调用）。
 * 请求需携带 X-Internal-Secret 头，与 INTERNAL_API_SECRET 一致。
 * 生产真实值来自`<SECRET_MANAGER>`，不写入代码库。
 */
class InternalApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('visionsy.internal_api_secret');
        $provided = (string) $request->headers->get('X-Internal-Secret', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
