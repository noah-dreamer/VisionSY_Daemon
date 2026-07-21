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
     */
    public function resolveDerivativeAbsolute(MediaResource $resource, DerivativeVariant $variant): string
    {
        if ($resource->isLegacy()) {
            return $this->resolveLegacyDerivativeAbsolute($resource, $variant);
        }

        $gallery = $resource->gallery;
        if ($gallery === null || $resource->storageSource === null) {
            throw new RuntimeException('图库缺失，无法解析派生路径。');
        }

        $relPath = $resource->relative_path;
        if ($relPath === null || $relPath === '') {
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
            $resource->storageSource->root_path,
            $gallery->relative_path,
            $derivativeRelative
        );
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
        $rootPath = rtrim($rootPath, '/');
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
        $parentReal = realpath($parentDir);
        if ($parentReal === false) {
            throw new RuntimeException(sprintf(
                '目标路径 %s 及其父目录均不存在，无法完成路径边界校验。',
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
