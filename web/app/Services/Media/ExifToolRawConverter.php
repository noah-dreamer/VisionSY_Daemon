<?php

namespace App\Services\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

class ExifToolRawConverter implements RawConverterInterface
{
    private string $exifToolBin;
    private string $externalConverter;

    public function __construct()
    {
        $this->exifToolBin = config('visionsy.media.exiftool_path') ?: 'exiftool';
        $this->externalConverter = config('visionsy.media.raw.converter', '');
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

    /**
     * 将 RAW 文件转换为 JPEG。
     *
     * 策略：
     * 1. 尝试提取内嵌 JPEG 预览（ExifTool -b -PreviewImage）
     * 2. 内嵌预览不可用时调用外部转换器
     * 3. 外部转换器路径由 config('visionsy.media.raw.converter') 提供，不存在时不阻塞
     */
    public function convert(string $sourcePath, string $destPath, int $maxEdge): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException("RAW 文件不可访问: {$sourcePath}");
        }

        $result = $this->tryExtractPreview($sourcePath, $destPath);
        if ($result !== null) {
            return $this->resizeIfNeeded($destPath, $maxEdge);
        }

        if ($this->externalConverter !== '' && $this->externalConverter !== 'exiftool_preview') {
            return $this->tryExternalConverter($sourcePath, $destPath, $maxEdge);
        }

        throw new RuntimeException('RAW 文件没有内嵌 JPEG 预览，且未配置外部转换器。');
    }

    /**
     * 尝试提取 RAW 文件内嵌的 JPEG 预览。
     */
    private function tryExtractPreview(string $sourcePath, string $destPath): ?array
    {
        $minPreviewBytes = config('visionsy.media.raw.min_preview_bytes', 1024);

        try {
            $tempFile = $destPath.'.extract-tmp-'.bin2hex(random_bytes(8));
            $process = new Process([
                $this->exifToolBin, '-b', '-PreviewImage',
                '-w', $tempFile,
                $sourcePath,
            ]);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful() && is_file($tempFile) && filesize($tempFile) >= $minPreviewBytes) {
                @mkdir(dirname($destPath), 0775, true);
                rename($tempFile, $destPath);

                return $this->probeImageSize($destPath);
            }

            @unlink($tempFile);
        } catch (\Throwable) {
            // 提取失败 → 继续尝试外部转换器
        }

        return null;
    }

    /**
     * 使用外部转换器将 RAW 转为 JPEG。
     */
    private function tryExternalConverter(string $sourcePath, string $destPath, int $maxEdge): array
    {
        $converter = $this->externalConverter;
        $tempFile = $destPath.'.conv-tmp-'.bin2hex(random_bytes(8));

        try {
            list($bin, $args) = $this->parseConverter($converter, $sourcePath, $tempFile);

            $processArgs = array_merge([$bin], $args);
            $process = new Process($processArgs);
            $process->setTimeout(120);
            $process->run();

            if ($process->isSuccessful() && is_file($tempFile) && filesize($tempFile) > 0) {
                @mkdir(dirname($destPath), 0775, true);
                rename($tempFile, $destPath);

                return $this->resizeIfNeeded($destPath, $maxEdge);
            }

            @unlink($tempFile);
        } catch (\Throwable) {
            @unlink($tempFile);
        }

        throw new RuntimeException("外部 RAW 转换器执行失败: {$converter}");
    }

    /**
     * 解析转换器配置字符串为 [bin, args]。
     *
     * 支持的格式：
     *  - "exiftool_preview" (占位符，使用内嵌预览)
     *  - "dcraw -T -c" (dcraw 管道模式)
     *  - "/usr/bin/dcraw" (仅路径)
     */
    private function parseConverter(string $converter, string $source, string $dest): array
    {
        $parts = explode(' ', $converter);
        $bin = $parts[0];

        $args = array_slice($parts, 1);
        if ($args === []) {
            $args = ['-T', '-c'];
        }

        $args[] = $source;
        $args[] = $dest;

        return [$bin, $args];
    }

    /**
     * 如果输出超过最大边长则缩放（使用 GD）。
     */
    private function resizeIfNeeded(string $path, int $maxEdge): array
    {
        $dim = $this->probeImageSize($path);
        if ($dim === null) {
            throw new RuntimeException('无法读取转换后的图像尺寸。');
        }

        if ($maxEdge <= 0 || ($dim['width'] <= $maxEdge && $dim['height'] <= $maxEdge)) {
            return $dim;
        }

        $scale = min(1.0, $maxEdge / max($dim['width'], $dim['height']));
        $newW = max(1, (int) round($dim['width'] * $scale));
        $newH = max(1, (int) round($dim['height'] * $scale));

        $src = @imagecreatefromjpeg($path);
        if (! $src instanceof \GdImage) {
            throw new RuntimeException('无法以 GD 打开转换后的 JPEG。');
        }

        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $dim['width'], $dim['height']);

        $tempFile = $path.'.resize-'.bin2hex(random_bytes(4));
        imagejpeg($dst, $tempFile, 92);
        imagedestroy($src);
        imagedestroy($dst);

        rename($tempFile, $path);

        return ['width' => $newW, 'height' => $newH];
    }

    private function probeImageSize(string $path): ?array
    {
        $info = @getimagesize($path);

        return $info === false ? null : ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }
}
