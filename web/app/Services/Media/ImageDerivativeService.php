<?php

namespace App\Services\Media;

use GdImage;
use RuntimeException;

/**
 * 图像派生服务（基于 PHP GD，需 ext-gd）。
 *
 * 与 ResourceSpace 的派生策略保持一致：
 *   - 原图（original）永不修改，仅授权用户组可下载；
 *   - 预览图（preview，长边默认 1600px）实时叠加平铺斜向水印；
 *   - 缩略图（thumb，长边默认 640px）同样带水印，供列表页展示。
 *
 * 水印文本默认使用 GD 内置字体（仅 ASCII）；如需中文水印，
 * 在 .env 中配置 MEDIA_WATERMARK_FONT 指向一个 TTF 字体文件即可。
 */
class ImageDerivativeService
{
    /** GD 是否可用（seed / 测试环境自检用）。 */
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

        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.thumb_max_edge', 640), $thumbAbsPath, 18);
        $this->writeDerivative($src, $width, $height, (int) config('visionsy.media.preview_max_edge', 1600), $previewAbsPath, 22);

        imagedestroy($src);

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

    private function writeDerivative(GdImage $src, int $srcW, int $srcH, int $maxEdge, string $destPath, int $fontSize): void
    {
        $scale = min(1.0, $maxEdge / max($srcW, $srcH));
        $w = max(1, (int) round($srcW * $scale));
        $h = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($w, $h);
        // 白底铺垫（透明 PNG 落到 JPEG 上时避免黑底）
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

        $this->applyTiledWatermark($dst, $w, $h, $fontSize);

        @mkdir(dirname($destPath), 0775, true);
        if (! imagejpeg($dst, $destPath, 82)) {
            imagedestroy($dst);
            throw new RuntimeException('派生图写入失败：'.$destPath);
        }
        imagedestroy($dst);
    }

    /** 45° 平铺半透明水印。 */
    private function applyTiledWatermark(GdImage $img, int $w, int $h, int $fontSize): void
    {
        $text = (string) config('visionsy.media.watermark_text', 'VisionSY');
        $font = config('visionsy.media.watermark_font');
        $useTtf = is_string($font) && $font !== '' && is_file($font) && function_exists('imagettftext');

        // 单元水印画在独立透明层上再旋转平铺
        $pad = 40;
        if ($useTtf) {
            $box = imagettfbbox($fontSize, 0, $font, $text);
            $textW = abs($box[4] - $box[0]);
            $textH = abs($box[5] - $box[1]);
        } else {
            // GD 内置字体 5：宽 9px、高 15px（仅 ASCII）
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
}
