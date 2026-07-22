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

    public function generate(string $originalAbsPath, string $thumbAbsPath, string $previewAbsPath): array
    {
        $src = $this->open($originalAbsPath);
        $width = imagesx($src);
        $height = imagesy($src);

        $thumbQ = (int) config('visionsy.media.thumb_jpeg_quality', 75);
        $prevQ = (int) config('visionsy.media.preview_jpeg_quality', 82);

        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.thumb_max_edge', 640), $thumbAbsPath, 18, true, $thumbQ);
        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.preview_max_edge', 1600), $previewAbsPath, 22, true, $prevQ);

        imagedestroy($src);

        return ['width' => $width, 'height' => $height];
    }

    public function generateAllDerivatives(
        string $jpegSourcePath,
        MediaResource $resource,
        PathResolverService $pathResolver,
    ): array {
        $src = $this->open($jpegSourcePath);
        $width = imagesx($src);
        $height = imagesy($src);

        $src = $this->applyExifOrientation($src, $jpegSourcePath, $width, $height);
        if ($src instanceof GdImage) {
            $width = imagesx($src);
            $height = imagesy($src);
        }

        $thumbPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $largePath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large);
        $wmJpegPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedJpeg);
        $wmPngPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedPng);

        $thumbQ = (int) config('visionsy.media.thumb_jpeg_quality', 75);
        $prevQ = (int) config('visionsy.media.preview_jpeg_quality', 82);
        $wmJpegQ = (int) config('visionsy.media.watermark_jpeg_quality', 85);
        $wmPngC = (int) config('visionsy.media.watermark_png_compression', 6);

        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.thumb_max_edge', 640), $thumbPath, 18, true, $thumbQ);
        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.preview_max_edge', 1600), $largePath, 22, true, $prevQ);

        $wmLarge = $this->scaleImage($src, $width, $height, (int) config('visionsy.media.watermark_max_edge', 3200));
        $wmW = imagesx($wmLarge);
        $wmH = imagesy($wmLarge);
        $this->applyTiledWatermark($wmLarge, $wmW, $wmH, 28);

        @mkdir(dirname($wmJpegPath), 0775, true);
        imagejpeg($wmLarge, $wmJpegPath, $wmJpegQ);
        @mkdir(dirname($wmPngPath), 0775, true);
        imagepng($wmLarge, $wmPngPath, $wmPngC);

        imagedestroy($wmLarge);
        imagedestroy($src);

        $this->stripGpsExif($wmJpegPath);
        $this->stripGpsExif($thumbPath);
        $this->stripGpsExif($largePath);

        return ['width' => $width, 'height' => $height];
    }

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

    /**
     * 根据 EXIF Orientation 旋转/翻转图像，输出 sRGB-suitable 方向。
     */
    private function applyExifOrientation(GdImage $src, string $absPath, int &$width, int &$height): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $src;
        }

        $exif = @exif_read_data($absPath, 'IFD0');
        $orientation = (int) ($exif['Orientation'] ?? 0);

        if ($orientation <= 1) {
            return $src;
        }

        return match ($orientation) {
            2 => $this->flipHorizontal($src, $width, $height),
            3 => $this->rotate180($src, $width, $height),
            4 => $this->flipVertical($src, $width, $height),
            5 => $this->transpose($src, $width, $height),
            6 => $this->rotate90($src, $width, $height),
            7 => $this->transverse($src, $width, $height),
            8 => $this->rotate270($src, $width, $height),
            default => $src,
        };
    }

    private function flipHorizontal(GdImage $src, int &$w, int &$h): GdImage
    {
        $dst = $this->createCanvas($w, $h);
        for ($x = 0; $x < $w; $x++) {
            imagecopy($dst, $src, $x, 0, $w - $x - 1, 0, 1, $h);
        }
        imagedestroy($src);

        return $dst;
    }

    private function flipVertical(GdImage $src, int &$w, int &$h): GdImage
    {
        $dst = $this->createCanvas($w, $h);
        imageflip($src, IMG_FLIP_VERTICAL);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        return $dst;
    }

    private function rotate180(GdImage $src, int &$w, int &$h): GdImage
    {
        $rotated = imagerotate($src, 180, imagecolorallocate($src, 255, 255, 255));
        imagedestroy($src);

        return $rotated;
    }

    private function rotate90(GdImage $src, int &$w, int &$h): GdImage
    {
        $rotated = imagerotate($src, -90, imagecolorallocate($src, 255, 255, 255));
        imagedestroy($src);
        [$w, $h] = [$h, $w];

        return $rotated;
    }

    private function rotate270(GdImage $src, int &$w, int &$h): GdImage
    {
        $rotated = imagerotate($src, 90, imagecolorallocate($src, 255, 255, 255));
        imagedestroy($src);
        [$w, $h] = [$h, $w];

        return $rotated;
    }

    private function transpose(GdImage $src, int &$w, int &$h): GdImage
    {
        $rotated = imagerotate($src, -90, imagecolorallocate($src, 255, 255, 255));
        imagedestroy($src);
        imageflip($rotated, IMG_FLIP_HORIZONTAL);
        [$w, $h] = [$h, $w];

        return $rotated;
    }

    private function transverse(GdImage $src, int &$w, int &$h): GdImage
    {
        $rotated = imagerotate($src, 90, imagecolorallocate($src, 255, 255, 255));
        imagedestroy($src);
        imageflip($rotated, IMG_FLIP_HORIZONTAL);
        [$w, $h] = [$h, $w];

        return $rotated;
    }

    private function createCanvas(int $w, int $h): GdImage
    {
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));

        return $dst;
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

    private function writeDerivative(GdImage $src, int $srcW, int $srcH, int $maxEdge, string $destPath, int $fontSize, bool $watermark, int $jpegQuality): void
    {
        $dst = $this->scaleImage($src, $srcW, $srcH, $maxEdge);
        $w = imagesx($dst);
        $h = imagesy($dst);

        if ($watermark) {
            $this->applyTiledWatermark($dst, $w, $h, $fontSize);
        }

        $tmpPath = $destPath.'.tmp-'.bin2hex(random_bytes(4));
        @mkdir(dirname($tmpPath), 0775, true);
        if (! imagejpeg($dst, $tmpPath, $jpegQuality)) {
            imagedestroy($dst);
            @unlink($tmpPath);
            throw new RuntimeException('派生图写入失败：'.$destPath);
        }
        imagedestroy($dst);

        rename($tmpPath, $destPath);
    }

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
        }
    }
}
