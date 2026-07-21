<?php

namespace App\Services\Media;

use App\Models\MediaResource;
use GdImage;
use RuntimeException;

class ImageDerivativeService
{
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * 依据原图生成 thumb + preview 两个带水印派生图（JPEG）。
     *
     * @return array{width:int,height:int} 原图尺寸
     */
    public function generate(string $originalAbsPath, string $thumbAbsPath, string $previewAbsPath): array
    {
        $src = $this->open($originalAbsPath);
        $width = imagesx($src);
        $height = imagesy($src);

        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.thumb_max_edge', 640), $thumbAbsPath, 18, true);
        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.preview_max_edge', 1600), $previewAbsPath, 22, true);

        imagedestroy($src);

        return ['width' => $width, 'height' => $height];
    }

    /**
     * 从 JPEG 源生成全部四种派生图，使用 PathResolverService 解析目标路径。
     *
     * @return array{width:int,height:int}
     */
    public function generateAllDerivatives(
        string $jpegSourcePath,
        MediaResource $resource,
        PathResolverService $pathResolver,
    ): array {
        $src = $this->open($jpegSourcePath);
        $width = imagesx($src);
        $height = imagesy($src);

        $thumbPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $largePath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large);
        $wmJpegPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedJpeg);
        $wmPngPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedPng);

        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.thumb_max_edge', 640), $thumbPath, 18, true);
        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.preview_max_edge', 1600), $largePath, 22, true);

        $wmLarge = $this->scaleImage($src, $width, $height, (int) config('visionsy.media.watermark_max_edge', 3200));
        $wmW = imagesx($wmLarge);
        $wmH = imagesy($wmLarge);
        $this->applyTiledWatermark($wmLarge, $wmW, $wmH, 28);

        @mkdir(dirname($wmJpegPath), 0775, true);
        imagejpeg($wmLarge, $wmJpegPath, 90);
        @mkdir(dirname($wmPngPath), 0775, true);
        imagepng($wmLarge, $wmPngPath, 6);

        imagedestroy($wmLarge);
        imagedestroy($src);

        $this->stripGpsExif($wmJpegPath);
        $this->stripGpsExif($thumbPath);
        $this->stripGpsExif($largePath);

        return ['width' => $width, 'height' => $height];
    }

    /** 读取图像尺寸（不生成派生图，供非图片或探测用）。 */
    public function probeSize(string $absPath): ?array
    {
        $info = @getimagesize($absPath);

        return $info === false ? null : ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /* ---------------------------------------------------------------- */

    private function open(string $absPath): GdImage
    {
        if (! self::available()) {
            throw new RuntimeException('PHP GD 扩展不可用，无法生成派生图。请安装/启用 ext-gd。');
        }

        $info = @getimagesize($absPath);
        if ($info === false) {
            throw new RuntimeException('无法识别的图像文件。');
        }

        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absPath),
            IMAGETYPE_PNG => @imagecreatefrompng($absPath),
            IMAGETYPE_GIF => @imagecreatefromgif($absPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($absPath) : false,
            default => false,
        };

        if (! $img instanceof GdImage) {
            throw new RuntimeException('不支持的图像格式：'.($info['mime'] ?? 'unknown'));
        }

        return $img;
    }

    private function scaleImage(GdImage $src, int $srcW, int $srcH, int $maxEdge): GdImage
    {
        $scale = min(1.0, $maxEdge / max($srcW, $srcH));
        $w = max(1, (int) round($srcW * $scale));
        $h = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

        return $dst;
    }

    private function writeDerivative(GdImage $src, int $srcW, int $srcH, int $maxEdge, string $destPath, int $fontSize, bool $watermark): void
    {
        $dst = $this->scaleImage($src, $srcW, $srcH, $maxEdge);
        $w = imagesx($dst);
        $h = imagesy($dst);

        if ($watermark) {
            $this->applyTiledWatermark($dst, $w, $h, $fontSize);
        }

        $tmpPath = $destPath.'.tmp-'.bin2hex(random_bytes(4));
        @mkdir(dirname($tmpPath), 0775, true);
        if (! imagejpeg($dst, $tmpPath, 82)) {
            imagedestroy($dst);
            @unlink($tmpPath);
            throw new RuntimeException('派生图写入失败：'.$destPath);
        }
        imagedestroy($dst);

        rename($tmpPath, $destPath);
    }

    /** 45° 平铺半透明水印。 */
    private function applyTiledWatermark(GdImage $img, int $w, int $h, int $fontSize): void
    {
        $text = (string) config('visionsy.media.watermark_text', 'VisionSY');
        $font = config('visionsy.media.watermark_font');
        $useTtf = is_string($font) && $font !== '' && is_file($font) && function_exists('imagettftext');

        $pad = 40;
        if ($useTtf) {
            $box = imagettfbbox($fontSize, 0, $font, $text);
            $textW = abs($box[4] - $box[0]);
            $textH = abs($box[5] - $box[1]);
        } else {
            $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?: 'VisionSY';
            $textW = strlen($text) * 9;
            $textH = 15;
        }

        $tileW = $textW + $pad * 2;
        $tileH = $textH + $pad * 2;

        $tile = imagecreatetruecolor($tileW, $tileH);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        $transparent = imagecolorallocatealpha($tile, 0, 0, 0, 127);
        imagefilledrectangle($tile, 0, 0, $tileW, $tileH, $transparent);
        imagealphablending($tile, true);

        $shadow = imagecolorallocatealpha($tile, 0, 0, 0, 96);
        $ink = imagecolorallocatealpha($tile, 255, 255, 255, 88);

        if ($useTtf) {
            imagettftext($tile, $fontSize, 0, $pad + 1, $pad + $textH + 1, $shadow, $font, $text);
            imagettftext($tile, $fontSize, 0, $pad, $pad + $textH, $ink, $font, $text);
        } else {
            imagestring($tile, 5, $pad + 1, $pad + 1, $text, $shadow);
            imagestring($tile, 5, $pad, $pad, $text, $ink);
        }

        $rotated = imagerotate($tile, 30, $transparent);
        imagesavealpha($rotated, true);
        $rw = imagesx($rotated);
        $rh = imagesy($rotated);

        $row = 0;
        for ($y = -$rh; $y < $h + $rh; $y += max(1, (int) ($rh * 0.9))) {
            $offset = ($row++ % 2 === 0) ? 0 : intdiv($rw, 2);
            for ($x = -$rw + $offset; $x < $w + $rw; $x += $rw) {
                imagecopy($img, $rotated, $x, $y, 0, 0, $rw, $rh);
            }
        }

        imagedestroy($tile);
        imagedestroy($rotated);
    }

    /**
     * 从 JPEG 派生图中移除 GPS 标签（调用 ExifTool）。
     */
    private function stripGpsExif(string $path): void
    {
        $bin = config('visionsy.media.exiftool_path') ?: 'exiftool';

        try {
            $process = new \Symfony\Component\Process\Process([
                $bin, '-overwrite_original',
                '-gps:all=', '-XMP:GPSLatitude=', '-XMP:GPSLongitude=',
                $path,
            ]);
            $process->setTimeout(10);
            $process->run();
        } catch (\Throwable) {
            // exiftool 不可用时静默跳过
        }
    }
}
