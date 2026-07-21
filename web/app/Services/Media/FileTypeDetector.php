<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use RuntimeException;

class FileTypeDetector
{
    private array $rawExtensions;

    public function __construct()
    {
        $this->rawExtensions = config('visionsy.media.raw.extensions', [
            'cr2', 'cr3', 'crw', 'nef', 'nrw', 'arw', 'srf', 'sr2',
            'dng', 'orf', 'pef', 'raf', 'rw2', '3fr', 'ari', 'bay',
            'cap', 'dcr', 'drf', 'eip', 'erf', 'fff', 'iiq', 'k25',
            'kdc', 'mdc', 'mef', 'mos', 'mrw', 'obm', 'ori', 'ptx',
            'pxn', 'r3d', 'raw', 'rwl', 'rwz', 'srw', 'x3f',
        ]);
    }

    public function detect(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException("文件不可访问: {$absolutePath}");
        }

        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($absolutePath) ?: 'application/octet-stream';

        $isRaw = false;

        if (in_array($ext, $this->rawExtensions, true)) {
            $exifType = $this->checkExifToolFileType($absolutePath);
            if ($exifType !== null && in_array(strtolower($exifType), $this->rawExtensions, true)) {
                $isRaw = true;
            } elseif ($exifType === null) {
                $isRaw = true;
            }
        }

        $category = $this->determineCategory($mime, $ext, $isRaw);

        return [
            'ext' => $ext,
            'mime' => $mime,
            'is_raw' => $isRaw,
            'category' => $category,
            'category_label' => $this->categoryLabel($category),
        ];
    }

    public function isRaw(string $absolutePath): bool
    {
        $result = $this->detect($absolutePath);

        return $result['is_raw'];
    }

    private function checkExifToolFileType(string $absolutePath): ?string
    {
        $bin = config('visionsy.media.exiftool_path') ?: 'exiftool';

        try {
            $process = new \Symfony\Component\Process\Process([
                $bin, '-FileType', '-s', '-s', '-s', $absolutePath,
            ]);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (\Throwable) {
            // exiftool 不可用
        }

        return null;
    }

    private function determineCategory(string $mime, string $ext, bool $isRaw): string
    {
        if ($isRaw) {
            return 'image';
        }

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg', 'ico', 'heic', 'heif'];

        if (in_array($ext, $imageExtensions, true)) {
            return 'image';
        }

        return 'other';
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'image' => '图片',
            'video' => '视频',
            default => '其他',
        };
    }

    public function toMediaType(string $category): MediaType
    {
        return match ($category) {
            'image' => MediaType::Image,
            'video' => MediaType::Video,
            default => MediaType::Other,
        };
    }
}
