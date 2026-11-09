<?php

use App\Jobs\Media\DiscoverMediaGalleries;
use App\Models\MediaStorageSource;
use App\Models\TransferToken;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TransferToken::where('expires_at', '<', now()->subDay())->delete();
})->daily()->name('purge-expired-tokens');

Schedule::call(function () {
    $sources = MediaStorageSource::enabled()->get();
    foreach ($sources as $source) {
        DiscoverMediaGalleries::dispatch($source->id);
    }
})->everyMinute()
    ->name('media-library-scan')
    ->withoutOverlapping()
    ->onOneServer();
