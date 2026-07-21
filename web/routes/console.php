<?php

use App\Jobs\Media\DiscoverMediaGalleries;
use App\Models\MediaStorageSource;
use Illuminate\Support\Facades\Schedule;

// 定期清理过期的授权码 / 令牌 / 传输 token（保留审计日志）
Schedule::call(function () {
    \App\Models\OAuthAuthorizationCode::where('expires_at', '<', now()->subDay())->delete();
    \App\Models\OAuthAccessToken::where('expires_at', '<', now()->subWeek())->delete();
    \App\Models\OAuthRefreshToken::where('expires_at', '<', now()->subWeek())->delete();
    \App\Models\TransferToken::where('expires_at', '<', now()->subDay())->delete();
})->daily()->name('purge-expired-tokens');

// 每天凌晨扫描所有启用的存储源
Schedule::call(function () {
    $sources = MediaStorageSource::enabled()->get();
    foreach ($sources as $source) {
        DiscoverMediaGalleries::dispatch($source->id);
    }
})->dailyAt('03:00')->name('media-scan-daily');
