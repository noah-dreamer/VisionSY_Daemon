<?php

namespace App\Services\Media;

use App\Models\MediaStorageSource;

class FakeMountDriver implements MediaMountManager
{
    private bool $mountShouldFail = false;
    private bool $mounted = false;

    public function failNextMount(): self
    {
        $this->mountShouldFail = true;

        return $this;
    }

    public function isMounted(): bool
    {
        return $this->mounted;
    }

    public function mount(MediaStorageSource $source): array
    {
        if ($this->mountShouldFail) {
            $this->mountShouldFail = false;

            return ['mounted' => false, 'error' => '挂载失败：模拟错误。'];
        }

        $this->mounted = true;

        return ['mounted' => true, 'message' => '已挂载。'];
    }

    public function unmount(MediaStorageSource $source): array
    {
        if ($source->galleries()->whereIn('scan_status', ['scanning'])->exists()) {
            return ['mounted' => true, 'error' => '存在运行中的扫描任务，无法卸载。'];
        }

        $this->mounted = false;

        return ['mounted' => false, 'message' => '已卸载。'];
    }

    public function status(MediaStorageSource $source): array
    {
        return [
            'mounted' => $this->mounted,
            'path' => $source->root_path,
            'exists' => $this->mounted,
            'readable' => $this->mounted,
            'writable' => $this->mounted && ! $source->is_read_only,
            'driver' => 'fake',
        ];
    }

    public function healthCheck(MediaStorageSource $source): bool
    {
        return $this->mounted;
    }
}
