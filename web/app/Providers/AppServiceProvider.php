<?php

namespace App\Providers;

use App\Services\Media\FileTypeDetector;
use App\Services\Media\GalleryService;
use App\Services\Media\MediaScannerService;
use App\Services\Media\PathResolverService;
use App\Services\Media\StorageSourceService;
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
        $this->app->singleton(PathResolverService::class, fn () => new PathResolverService());
        $this->app->singleton(StorageSourceService::class, fn () => new StorageSourceService(
            $this->app->make(PathResolverService::class)
        ));
        $this->app->singleton(GalleryService::class, fn () => new GalleryService(
            $this->app->make(PathResolverService::class)
        ));
        $this->app->singleton(FileTypeDetector::class, fn () => new FileTypeDetector());
        $this->app->singleton(MediaScannerService::class, fn ($app) => new MediaScannerService(
            $app->make(PathResolverService::class),
            $app->make(FileTypeDetector::class),
            $app->make(\App\Services\Media\MediaMetadataService::class)
        ));
        $this->app->bind(
            \App\Services\Media\RawConverterInterface::class,
            \App\Services\Media\ExifToolRawConverter::class
        );
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
