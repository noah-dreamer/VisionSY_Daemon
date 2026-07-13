<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Media\ImageDerivativeService;
use App\Services\Media\MediaStorageService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * 媒体库演示数据：用 GD 生成若干示例照片并走完整入库流程
 * （原图落盘 + 自动水印派生图），让一键启动后媒体库不为空。
 * 仅本地开发使用。
 */
class MediaDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! ImageDerivativeService::available()) {
            $this->command?->warn('未检测到 PHP GD 扩展，跳过媒体库演示数据。');

            return;
        }

        $uploader = User::where('email', 'media@example.com')->first()
            ?? User::query()->first();

        if ($uploader === null) {
            return;
        }

        /** @var MediaStorageService $storage */
        $storage = app(MediaStorageService::class);

        $samples = [
            ['title' => '2026 校运会开幕式 · 入场式', 'tags' => '校运会, 开幕式, 2026', 'palette' => [[40, 90, 210], [130, 90, 250]]],
            ['title' => '春季合唱节 · 舞台全景', 'tags' => '合唱节, 舞台, 春季', 'palette' => [[210, 110, 40], [250, 190, 90]]],
            ['title' => '科技节机器人展区', 'tags' => '科技节, 机器人', 'palette' => [[20, 130, 140], [90, 200, 170]]],
            ['title' => '毕业季 · 操场航拍', 'tags' => '毕业季, 航拍, 操场', 'palette' => [[80, 60, 150], [210, 100, 150]]],
            ['title' => '校园秋景 · 银杏大道', 'tags' => '秋景, 校园风光', 'palette' => [[160, 120, 30], [240, 210, 110]]],
            ['title' => '元旦晚会 · 灯光彩排', 'tags' => '元旦晚会, 彩排, 灯光', 'palette' => [[30, 40, 90], [110, 70, 220]]],
        ];

        foreach ($samples as $i => $sample) {
            $path = $this->drawSample($i, $sample['palette']);

            $file = new UploadedFile($path, "demo-photo-{$i}.jpg", 'image/jpeg', null, true);

            $storage->store($file, [
                'title' => $sample['title'],
                'description' => '演示素材（种子数据自动生成），用于本地体验分级下载与自动水印。',
                'tags' => $sample['tags'],
            ], $uploader);

            @unlink($path);
        }

        $this->command?->info('媒体库演示素材已生成（'.count($samples).' 张，含带水印派生图）。');
    }

    /** 生成一张 1920x1280 的示例"照片"（渐变 + 随机图形）。 */
    private function drawSample(int $seed, array $palette): string
    {
        mt_srand(20260712 + $seed);
        $w = 1920;
        $h = 1280;
        $img = imagecreatetruecolor($w, $h);

        [$c1, $c2] = $palette;
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $col = imagecolorallocate(
                $img,
                (int) ($c1[0] + ($c2[0] - $c1[0]) * $t),
                (int) ($c1[1] + ($c2[1] - $c1[1]) * $t),
                (int) ($c1[2] + ($c2[2] - $c1[2]) * $t),
            );
            imageline($img, 0, $y, $w, $y, $col);
        }

        for ($i = 0; $i < 40; $i++) {
            $col = imagecolorallocatealpha($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(75, 110));
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, $h), mt_rand(80, 500), mt_rand(80, 500), $col);
        }

        $path = tempnam(sys_get_temp_dir(), 'vssy_demo_').'.jpg';
        imagejpeg($img, $path, 88);
        imagedestroy($img);

        return $path;
    }
}
