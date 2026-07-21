<?php

namespace App\Services\Media;

interface RawConverterInterface
{
    public function available(): bool;

    /**
     * @param  string  $sourcePath  原始 RAW 文件绝对路径
     * @param  string  $destPath    目标 JPEG 绝对路径
     * @param  int     $maxEdge     输出的最大边长（0=不限制）
     * @return array{width:int,height:int} 输出图像尺寸
     * @throws \RuntimeException 转换失败时抛出
     */
    public function convert(string $sourcePath, string $destPath, int $maxEdge): array;
}
