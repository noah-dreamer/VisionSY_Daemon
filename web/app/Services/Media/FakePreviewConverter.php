<?php

namespace App\Services\Media;

class FakePreviewConverter implements MediaPreviewConverter
{
    private bool $shouldFail = false;

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return '1.0.0-test';
    }

    public function available(): bool
    {
        return true;
    }

    public function failNext(): self
    {
        $this->shouldFail = true;

        return $this;
    }

    public function toJpeg(string $sourcePath, string $destDir, int $maxEdge): array
    {
        if ($this->shouldFail) {
            $this->shouldFail = false;
            throw new \RuntimeException('Fake converter failure for testing.');
        }

        @mkdir($destDir, 0775, true);
        $destPath = $destDir.'/preview-'.bin2hex(random_bytes(4)).'.jpg';

        $img = imagecreatetruecolor(min(200, $maxEdge ?: 200), min(200, $maxEdge ?: 200));
        imagefilledrectangle($img, 0, 0, 200, 200, imagecolorallocate($img, 200, 100, 50));
        imagejpeg($img, $destPath, 90);
        imagedestroy($img);

        return ['path' => $destPath, 'width' => 200, 'height' => 200];
    }
}
