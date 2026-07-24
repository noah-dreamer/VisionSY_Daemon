<?php

use App\Jobs\Media\DiscoverMediaGalleries;
use App\Models\MediaStorageSource;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    \App\Models\TransferToken::where('expires_at', '<', now()->subDay())->delete();
})->daily()->name('purge-expired-tokens');

Schedule::call(function () {
    $sources = MediaStorageSource::enabled()->get();
    foreach ($sources as $source) {
        DiscoverMediaGalleries::dispatch($source->id);
    }
})->dailyAt('03:00')
    ->name('media-scan-daily')
    ->withoutOverlapping()
    ->onOneServer();
