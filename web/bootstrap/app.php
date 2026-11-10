<?php

use App\Http\Middleware\EnsureSystemAdmin;
use App\Http\Middleware\InternalApiAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\ManagementCors::class);
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, \App\Http\Middleware\ManagementApiAuth::class);
        $middleware->alias([
            'system_admin' => EnsureSystemAdmin::class,
            'internal_api' => InternalApiAuth::class,
            'management_api' => \App\Http\Middleware\ManagementApiAuth::class,
            'legacy_admin' => \App\Http\Middleware\LegacyAdminAccess::class,
        ]);

        // Trusted proxies：信任前置反向代理（FrankenPHP/Caddy 等）。
        // TRUSTED_PROXY_IPS：逗号分隔的代理 IP 列表，默认信任 127.0.0.1（容器内回环）。
        $proxyIps = array_filter(
            explode(',', (string) env('TRUSTED_PROXY_IPS', '127.0.0.1'))
        );
        if (! empty($proxyIps)) {
            $middleware->trustProxies(at: $proxyIps);
        }
        // trusted headers 使用 Symfony 默认集合：
        //   X-Forwarded-For / X-Forwarded-Host / X-Forwarded-Proto / X-Forwarded-Port
        //   X-Forwarded-Prefix / Forwarded（RFC 7239）
        // 生产反向代理必须设置这些头；FrankenPHP/Caddy 通过 header_down 传递。
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
