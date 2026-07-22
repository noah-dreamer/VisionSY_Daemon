<?php

namespace App\Services\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

class ExifToolPreviewConverter implements MediaPreviewConverter
{
    private string $exifToolBin;
    private int $minPreviewBytes;
    private array $previewTags;

    public function __construct()
    {
        $this->exifToolBin = config('visionsy.media.exiftool_path') ?: 'exiftool';
        $this->minPreviewBytes = (int) config('visionsy.media.raw.min_preview_bytes', 1024);
        $this->previewTags = config('visionsy.media.raw.preview_tags', ['PreviewImage', 'JpgFromRaw', 'ThumbnailImage']);
    }

    public function name(): string
    {
        return 'exiftool_preview';
    }

    public function version(): string
    {
        try {
            $process = new Process([$this->exifToolBin, '-ver']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : 'unknown';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    public function available(): bool
    {
        try {
            $process = new Process([$this->exifToolBin, '-ver']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function toJpeg(string $sourcePath, string $destDir, int $maxEdge): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException("源文件不可访问: {$sourcePath}");
        }

        $convertedPath = $destDir.'/preview-'.bin2hex(random_bytes(4)).'.jpg';

        foreach ($this->previewTags as $tag) {
            $extracted = $this->tryExtractTag($sourcePath, $convertedPath, $tag);
            if ($extracted !== null) {
                return $this->resizeIfNeeded($convertedPath, $maxEdge);
            }
        }

        throw new RuntimeException('RAW 文件没有足够大的内嵌 JPEG 预览。');
    }

    private function tryExtractTag(string $sourcePath, string $destPath, string $tag): ?array
    {
        try {
            $tempFile = $destPath.'.extract-'.bin2hex(random_bytes(8));
            $process = new Process([
                $this->exifToolBin, '-b', '-'.$tag,
                '-w', $tempFile,
                $sourcePath,
            ]);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful() && is_file($tempFile) && filesize($tempFile) >= $this->minPreviewBytes) {
                @mkdir(dirname($destPath), 0775, true);
                rename($tempFile, $destPath);
                $dim = $this->probeImageSize($destPath);

                return $dim ?? ['width' => 0, 'height' => 0];
            }

            @unlink($tempFile);
        } catch (\Throwable) {
        }

        return null;
    }

    private function resizeIfNeeded(string $path, int $maxEdge): array
    {
        $dim = $this->probeImageSize($path);
        if ($dim === null || $dim['width'] <= 0) {
            throw new RuntimeException('无法读取预览图像尺寸。');
        }

        if ($maxEdge <= 0 || ($dim['width'] <= $maxEdge && $dim['height'] <= $maxEdge)) {
            return ['path' => $path, 'width' => $dim['width'], 'height' => $dim['height']];
        }

        $scale = min(1.0, $maxEdge / max($dim['width'], $dim['height']));
        $newW = max(1, (int) round($dim['width'] * $scale));
        $newH = max(1, (int) round($dim['height'] * $scale));

        $src = @imagecreatefromjpeg($path);
        if (! $src instanceof \GdImage) {
            return ['path' => $path, 'width' => $dim['width'], 'height' => $dim['height']];
        }

        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $dim['width'], $dim['height']);
        imagedestroy($src);

        $tempFile = $path.'.rs-'.bin2hex(random_bytes(4));
        imagejpeg($dst, $tempFile, 92);
        imagedestroy($dst);
        rename($tempFile, $path);

        return ['path' => $path, 'width' => $newW, 'height' => $newH];
    }

    private function probeImageSize(string $path): ?array
    {
        $info = @getimagesize($path);

        return $info === false ? null : ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }
}
