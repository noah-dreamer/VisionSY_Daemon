<?php

namespace App\Services\Media;

interface MediaMountManager
{
    public function mount(\App\Models\MediaStorageSource $source): array;

    public function unmount(\App\Models\MediaStorageSource $source): array;

    public function status(\App\Models\MediaStorageSource $source): array;

    public function healthCheck(\App\Models\MediaStorageSource $source): bool;
}
