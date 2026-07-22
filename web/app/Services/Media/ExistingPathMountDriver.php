<?php

namespace App\Services\Media;

use App\Enums\StorageSourceType;
use App\Models\MediaStorageSource;
use RuntimeException;

class ExistingPathMountDriver implements MediaMountManager
{
    public function mount(MediaStorageSource $source): array
    {
        if (! $source->isNetworkMount()) {
            return ['mounted' => is_dir($source->root_path), 'message' => '本地存储源无需挂载。'];
        }

        if (is_dir($source->root_path) && is_readable($source->root_path)) {
            return ['mounted' => true, 'message' => '路径已可用（宿主机预先挂载）。'];
        }

        return ['mounted' => false, 'message' => '宿主机挂载路径不可用，请联系管理员预先挂载。'];
    }

    public function unmount(MediaStorageSource $source): array
    {
        if (! $source->isNetworkMount()) {
            return ['mounted' => true, 'message' => '本地存储源无需卸载。'];
        }

        return ['mounted' => true, 'message' => '现有挂载驱动不支持主动卸载，请由宿主机管理员操作。'];
    }

    public function status(MediaStorageSource $source): array
    {
        $isDir = is_dir($source->root_path);
        $readable = $isDir && is_readable($source->root_path);
        $writable = $isDir && is_writable($source->root_path);

        return [
            'mounted' => $isDir && $readable,
            'path' => $source->root_path,
            'exists' => $isDir,
            'readable' => $readable,
            'writable' => $writable,
            'driver' => 'existing_path',
        ];
    }

    public function healthCheck(MediaStorageSource $source): bool
    {
        $s = $this->status($source);

        return $s['exists'] && $s['readable'];
    }
}
