<?php

use Illuminate\Support\Facades\Schedule;

// 定期清理过期的授权码 / 令牌 / 传输 token（保留审计日志）
Schedule::call(function () {
    \App\Models\OAuthAuthorizationCode::where('expires_at', '<', now()->subDay())->delete();
    \App\Models\OAuthAccessToken::where('expires_at', '<', now()->subWeek())->delete();
    \App\Models\OAuthRefreshToken::where('expires_at', '<', now()->subWeek())->delete();
    \App\Models\TransferToken::where('expires_at', '<', now()->subDay())->delete();
})->daily()->name('purge-expired-tokens');
