<?php

namespace App\Services\Media;

use App\Enums\StorageSourceType;
use App\Models\MediaStorageSource;
use App\Models\User;
use RuntimeException;

class StorageSourceService
{
    public function __construct(
        private PathResolverService $pathResolver,
    ) {
    }

    public function create(array $data, User $creator): MediaStorageSource
    {
        $rootPath = $this->validateAndNormalizeRootPath($data['root_path'], $data['driver'] ?? 'local');

        return MediaStorageSource::create([
            'name' => $data['name'],
            'driver' => $data['driver'] ?? 'local',
            'root_path' => $rootPath,
            'is_enabled' => $data['is_enabled'] ?? true,
            'is_read_only' => ! ($data['is_writable'] ?? false),
            'is_managed' => $data['is_managed'] ?? false,
            'scan_recursive' => $data['scan_recursive'] ?? true,
            'created_by' => $creator->id,
        ]);
    }

    public function update(MediaStorageSource $source, array $data): MediaStorageSource
    {
        if (isset($data['root_path'])) {
            $data['root_path'] = $this->validateAndNormalizeRootPath($data['root_path'], $data['driver'] ?? $source->driver->value);
        }

        if (isset($data['is_writable'])) {
            $data['is_read_only'] = ! $data['is_writable'];
            unset($data['is_writable']);
        }

        $source->update($data);

        return $source;
    }

    public function validateAndNormalizeRootPath(string $path, string $driver): string
    {
        $path = trim($path);
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            throw new RuntimeException('根路径不能为 /。');
        }

        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("目录不存在或无法访问: {$path}");
        }

        if (! is_readable($real)) {
            throw new RuntimeException("目录不可读: {$path}");
        }

        $allowedRoots = config('visionsy.media.allowed_roots', []);
        if ($allowedRoots !== []) {
            $allowed = false;
            foreach ($allowedRoots as $allowedRoot) {
                $allowedReal = realpath($allowedRoot) ?: $allowedRoot;
                $allowedRootNorm = rtrim($allowedReal, '/').'/';
                if (str_starts_with(rtrim($real, '/').'/', $allowedRootNorm)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                throw new RuntimeException("路径不在允许的根路径范围内。允许: ".implode(', ', $allowedRoots));
            }
        }

        $forbidden = [
            storage_path(),
            base_path(),
            app_path(),
            '/etc',
            '/boot',
            '/sys',
            '/proc',
            '/dev',
        ];
        foreach ($forbidden as $forbid) {
            $forbidReal = realpath($forbid) ?: $forbid;
            $forbid = rtrim($forbidReal, '/').'/';
            if (str_starts_with(rtrim($real, '/').'/', $forbid)) {
                throw new RuntimeException('不允许使用系统关键目录作为存储源根路径。');
            }
        }

        return $real;
    }

    public function verifyReadable(MediaStorageSource $source): bool
    {
        return is_dir($source->root_path) && is_readable($source->root_path);
    }

    public function verifyWritable(MediaStorageSource $source): bool
    {
        if ($source->is_read_only) {
            return false;
        }

        return is_writable($source->root_path);
    }

    public function delete(MediaStorageSource $source): void
    {
        if ($source->galleries()->count() > 0) {
            throw new RuntimeException('存储源下存在图库，请先删除所有图库。');
        }

        if ($source->mediaResources()->count() > 0) {
            throw new RuntimeException('存储源下存在媒体资源，请先清理。');
        }

        $source->delete();
    }
}
