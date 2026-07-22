<?php

namespace App\Services\Media;

interface MediaPreviewConverter
{
    public function name(): string;

    public function version(): string;

    public function available(): bool;

    /**
     * @return array{path:string,width:int,height:int} 转换后的 JPEG 路径和尺寸
     * @throws \RuntimeException
     */
    public function toJpeg(string $sourcePath, string $destDir, int $maxEdge): array;
}
