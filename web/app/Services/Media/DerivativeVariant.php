<?php

namespace App\Services\Media;

enum DerivativeVariant: string
{
    case Thumb = 'thumb';
    case Large = 'large';
    case WatermarkedJpeg = 'watermarked_jpeg';
    case WatermarkedPng = 'watermarked_png';

    public function label(): string
    {
        return match ($this) {
            self::Thumb => '缩略图',
            self::Large => '大预览图',
            self::WatermarkedJpeg => '水印 JPEG',
            self::WatermarkedPng => '水印 PNG',
        };
    }
}
