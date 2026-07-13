<?php

namespace App\Providers;

use App\Services\TrustedProxyIpResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TrustedProxyIpResolver::class, fn () => new TrustedProxyIpResolver());
    }

    public function boot(): void
    {
        // 登录失败限速：同一 邮箱+IP 每分钟最多 5 次
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // 内部 API 限速
        RateLimiter::for('internal-api', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });
    }
}
