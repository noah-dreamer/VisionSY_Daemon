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
use Illuminate\Support\Facades\View;
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
        $this->app->bind(
            \App\Services\Media\MediaPreviewConverter::class,
            \App\Services\Media\ExifToolPreviewConverter::class
        );
        $this->app->bind(
            \App\Services\Media\MediaMountManager::class,
            \App\Services\Media\ExistingPathMountDriver::class
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

        View::composer('layouts.settings', function ($view) {
            $view->with('navItems', [
                [
                    'label' => '账户设置',
                    'items' => [
                        ['route' => 'settings.index',   'label' => '个人资料', 'match' => 'settings.index',    'icon' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z'],
                        ['route' => 'settings.password', 'label' => '修改密码', 'match' => 'settings.password', 'icon' => 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z'],
                    ],
                ],
                [
                    'label' => '系统管理',
                    'adminOnly' => true,
                    'items' => [
                        ['route' => 'admin.users.index',           'label' => '用户管理',       'match' => 'admin.users.*',           'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
                        ['route' => 'admin.user-groups.index',     'label' => '用户组',         'match' => 'admin.user-groups.*',     'icon' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z'],
                        ['route' => 'admin.storage-sources.index', 'label' => '存储源',         'match' => 'admin.storage-sources.*', 'icon' => 'M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3m-19.5 0a4.5 4.5 0 0 1 .9-2.7L5.737 5.1a3.375 3.375 0 0 1 2.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 0 1 .9 2.7m0 0a3 3 0 0 1-3 3m0 3h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Zm-3 6h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Z'],
                        ['route' => 'admin.galleries.index',       'label' => '图库管理',       'match' => 'admin.galleries.*',       'icon' => 'M2.25 15.75l5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z'],
                        ['route' => 'admin.audit-logs.index',      'label' => '审计日志',       'match' => 'admin.audit-logs.*',      'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
                        ['route' => 'admin.dashboard',             'label' => '管理后台概览',   'match' => 'admin.dashboard',         'icon' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 8.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25Zm9.75-9.75A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z'],
                    ],
                ],
            ]);
        });
    }
}
