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
        $middleware->alias([
            'system_admin' => EnsureSystemAdmin::class,
            'internal_api' => InternalApiAuth::class,
        ]);

        // /oauth/token 由外部客户端（ResourceSpace）以表单 POST 调用，无会话、无 CSRF。
        $middleware->validateCsrfTokens(except: [
            'oauth/token',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
