<?php

namespace App\Services\Media;

use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PathResolverService
{
    public const DERIVATIVE_DIR = '_visionsy';

    private readonly string $managedRoot;
    private readonly string $derivativesRoot;

    public function __construct()
    {
        $this->managedRoot = rtrim(str_replace('\\', '/', (string) config('visionsy.media.managed_root', '/data/visionsy/managed')), '/');
        $this->derivativesRoot = rtrim(str_replace('\\', '/', (string) config('visionsy.media.derivatives_root', '/data/visionsy/derivatives')), '/');
    }

    public function getManagedRoot(): string
    {
        return $this->managedRoot;
    }

    public function getDerivativesRoot(): string
    {
        return $this->derivativesRoot;
    }

    /**
     * 获取原文件的绝对路径。
     */
    public function resolveOriginalAbsolute(MediaResource $resource): string
    {
        if ($resource->isLegacy()) {
            return $this->resolveLegacyOriginalAbsolute($resource);
        }

        $source = $resource->storageSource;
        $gallery = $resource->gallery;

        if ($source === null || $gallery === null) {
            throw new RuntimeException('存储源或图库缺失，无法解析路径。');
        }

        return $this->resolvePath($source->root_path, $gallery->relative_path, $resource->relative_path);
    }

    /**
     * 获取派生文件的绝对路径。
     * 新派生统一写入独立的 derivatives 根目录，使用稳定 ID 层级：
     *   {derivatives_root}/{source_id}/{gallery_id}/{resource_id}/{variant}/{filename}
     *
     * 读取时优先检查新路径，不存在则回退到旧 _visionsy/ 路径，保证兼容。
     */
    public function resolveDerivativeAbsolute(MediaResource $resource, DerivativeVariant $variant): string
    {
        if ($resource->isLegacy()) {
            return $this->resolveLegacyDerivativeAbsolute($resource, $variant);
        }

        if ($resource->storageSource === null || $resource->gallery === null) {
            throw new RuntimeException('存储源或图库缺失，无法解析派生路径。');
        }

        $newPath = $this->resolveNewDerivativePath($resource, $variant);

        if (is_file($newPath)) {
            return $newPath;
        }

        $legacyPath = $this->resolveLegacyDerivativePath($resource, $variant);

        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        return $newPath;
    }

    /**
     * 获取新派生文件的绝对路径（不检查是否存在，用于写入）。
     */
    public function resolveNewDerivativeAbsolute(MediaResource $resource, DerivativeVariant $variant): string
    {
        if ($resource->isLegacy()) {
            return $this->resolveLegacyDerivativeAbsolute($resource, $variant);
        }

        return $this->resolveNewDerivativePath($resource, $variant);
    }

    /**
     * 新派生路径：{derivatives_root}/{source_id}/{gallery_id}/{resource_id}/{subdir}/{filename}
     */
    private function resolveNewDerivativePath(MediaResource $resource, DerivativeVariant $variant): string
    {
        $sourceId = (string) $resource->storageSource->id;
        $galleryId = (string) $resource->gallery->id;
        $resourceId = (string) $resource->id;

        $suffix = match ($variant) {
            DerivativeVariant::Thumb => '.jpg',
            DerivativeVariant::Large => '.jpg',
            DerivativeVariant::WatermarkedJpeg => '.jpg',
            DerivativeVariant::WatermarkedPng => '.png',
        };

        $subDir = match ($variant) {
            DerivativeVariant::Thumb => 'preview/thumb',
            DerivativeVariant::Large => 'preview/large',
            DerivativeVariant::WatermarkedJpeg => 'watermarked/jpeg',
            DerivativeVariant::WatermarkedPng => 'watermarked/png',
        };

        $filename = $resourceId.$suffix;

        return $this->derivativesRoot.'/'.$sourceId.'/'.$galleryId.'/'.$resourceId.'/'.$subDir.'/'.$filename;
    }

    /**
     * 旧派生路径（图库内 _visionsy/）：用于回退兼容。
     */
    private function resolveLegacyDerivativePath(MediaResource $resource, DerivativeVariant $variant): string
    {
        $gallery = $resource->gallery;
        $relPath = $resource->relative_path;
        $source = $resource->storageSource;

        if ($relPath === null || $relPath === '' || $gallery === null || $source === null) {
            throw new RuntimeException('相对路径缺失，无法解析派生路径。');
        }

        $suffix = match ($variant) {
            DerivativeVariant::Thumb => '.jpg',
            DerivativeVariant::Large => '.jpg',
            DerivativeVariant::WatermarkedJpeg => '.jpg',
            DerivativeVariant::WatermarkedPng => '.png',
        };

        $derivativeFile = $relPath.$suffix;

        $subDir = match ($variant) {
            DerivativeVariant::Thumb => 'preview/thumb',
            DerivativeVariant::Large => 'preview/large',
            DerivativeVariant::WatermarkedJpeg => 'watermarked/jpeg',
            DerivativeVariant::WatermarkedPng => 'watermarked/png',
        };

        $derivativeRelative = self::DERIVATIVE_DIR.'/'.$subDir.'/'.ltrim($derivativeFile, '/');

        return $this->resolvePath(
            $source->root_path,
            $gallery->relative_path,
            $derivativeRelative
        );
    }

    /**
     * 为资源创建派生文件目录（含父目录），返回新派生根目录的基础路径。
     */
    public function ensureDerivativeDir(MediaResource $resource): string
    {
        $sourceId = (string) $resource->storageSource->id;
        $galleryId = (string) $resource->gallery->id;
        $resourceId = (string) $resource->id;

        $base = $this->derivativesRoot.'/'.$sourceId.'/'.$galleryId.'/'.$resourceId;

        if (! is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        return $base;
    }

    /**
     * 获取旧模式原文件的绝对路径。
     */
    public function resolveLegacyOriginalAbsolute(MediaResource $resource): string
    {
        return Storage::disk('local')->path($resource->originalRelativePath());
    }

    /**
     * 获取旧模式派生文件的绝对路径。
     */
    public function resolveLegacyDerivativeAbsolute(MediaResource $resource, DerivativeVariant $variant): string
    {
        return Storage::disk('local')->path(match ($variant) {
            DerivativeVariant::Thumb => $resource->thumbRelativePath(),
            DerivativeVariant::Large => $resource->previewRelativePath(),
            default => $resource->previewRelativePath(),
        });
    }

    /**
     * 获取派生文件相对于 Laravel local 磁盘的路径（旧模式）。
     */
    public function resolveLegacyRelative(MediaResource $resource, DerivativeVariant $variant): string
    {
        return match ($variant) {
            DerivativeVariant::Thumb => $resource->thumbRelativePath(),
            DerivativeVariant::Large => $resource->previewRelativePath(),
            DerivativeVariant::WatermarkedJpeg => $resource->previewRelativePath(),
            DerivativeVariant::WatermarkedPng => $resource->previewRelativePath(),
        };
    }

    /**
     * 获取旧模式原文件相对于 local 磁盘的路径。
     */
    public function resolveLegacyOriginalRelative(MediaResource $resource): string
    {
        return $resource->originalRelativePath();
    }

    /**
     * 解析并安全验证绝对路径。
     */
    public function resolvePath(string $rootPath, string ...$segments): string
    {
        $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $combined = $rootPath;
        foreach ($segments as $segment) {
            $combined .= '/'.ltrim($this->normalizePath($segment), '/');
        }

        $real = realpath($combined);

        if ($real !== false) {
            $this->validateWithinRoot($real, $rootPath);

            return $real;
        }

        $this->validateWithinRoot($combined, $rootPath);

        return $combined;
    }

    /**
     * 验证路径在允许的根路径内（使用 realpath 边界校验，非简单字符串前缀）。
     */
    public function validateWithinRoot(string $resolvedPath, string $rootPath): void
    {
        $rootReal = realpath($rootPath);
        if ($rootReal === false) {
            throw new RuntimeException(sprintf('根路径不存在: %s', $rootPath));
        }
        $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');

        $pathReal = realpath($resolvedPath);
        if ($pathReal !== false) {
            $pathReal = rtrim(str_replace('\\', '/', $pathReal), '/');

            if ($pathReal === $rootReal) {
                return;
            }

            if (! str_starts_with($pathReal, $rootReal.'/')) {
                throw new RuntimeException(sprintf(
                    '路径越界: %s 不在根路径 %s 内。',
                    $resolvedPath,
                    $rootPath
                ));
            }

            return;
        }

        $parentDir = dirname($resolvedPath);
        $parentReal = false;
        $walkDir = $parentDir;

        while ($walkDir !== '' && $walkDir !== '.' && $walkDir !== '/') {
            $parentReal = realpath($walkDir);
            if ($parentReal !== false) {
                break;
            }
            $walkDir = dirname($walkDir);
        }

        if ($parentReal === false) {
            throw new RuntimeException(sprintf(
                '目标路径 %s 及其所有上级目录均不存在，无法完成路径边界校验。',
                $resolvedPath
            ));
        }
        $parentReal = rtrim(str_replace('\\', '/', $parentReal), '/');

        if ($parentReal === $rootReal || str_starts_with($parentReal, $rootReal.'/')) {
            return;
        }

        throw new RuntimeException(sprintf(
            '路径越界: %s 父目录 %s 不在根路径 %s 内。',
            $resolvedPath,
            $parentReal,
            $rootPath
        ));
    }

    /**
     * 规范化路径：去除 ..、\0、统一分隔符。
     */
    public function normalizePath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('路径包含空字节。');
        }

        $path = str_replace('\\', '/', $path);

        $segments = explode('/', $path);
        $clean = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException('路径包含非法上级目录引用。');
            }
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }

    /**
     * 验证相对路径是否安全（用于存储前校验）。
     */
    public function validateRelativePath(string $relativePath): void
    {
        $trimmed = trim($relativePath);

        if ($trimmed === '' || $trimmed === '.') {
            throw new RuntimeException('相对路径不能为空。');
        }

        if (str_starts_with($trimmed, '/')) {
            throw new RuntimeException('相对路径不能以 / 开头。');
        }

        $path = $this->normalizePath($trimmed);

        if ($path === '') {
            throw new RuntimeException('相对路径不能为空。');
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new RuntimeException('相对路径包含控制字符。');
        }

        $firstSegment = explode('/', $path)[0];
        $dangerous = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4',
            'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3',
            'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        if (in_array(strtoupper($firstSegment), $dangerous, true)) {
            throw new RuntimeException('目录名与系统保留名称冲突。');
        }
    }

    /**
     * 获取相对路径的安全规范化版本。
     */
    public function safeBasename(string $path): string
    {
        $base = basename($this->normalizePath($path));

        if ($base === '' || $base === '.') {
            throw new RuntimeException('无效的目录名。');
        }

        return $base;
    }
}
