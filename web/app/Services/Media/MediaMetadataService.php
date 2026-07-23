<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 媒体元数据提取：
 *
 * 依赖 ExifTool 读取上传文件的全部内嵌标签并映射到元数据字段。
 * 本服务同样**优先调用 ExifTool**（`visionsy.media.exiftool_path` 配置或
 * PATH 中的 exiftool），一次拿到照片/视频的全部标签（EXIF/IPTC/XMP/
 * QuickTime/Matroska…）；未安装 ExifTool 时回退：
 *   - 图片 → PHP exif_read_data（全部 IFD）+ getimagesize；
 *   - 视频/音频 → getID3（composer: james-heinrich/getid3）读取
 *     时长、分辨率、编码、码率、容器标签等。
 *
 * 返回结构：
 *   'all'   => 全部标签（分组的关联数组，直接展示在素材详情页"全部元数据"）
 *   'quick' => 提炼字段：taken_at / camera / lens / exposure / iso /
 *              focal_length / gps / duration_seconds / video_codec / frame_rate …
 */
class MediaMetadataService
{
    private const MAX_JSON_BYTES = 262144; // 存库上限 256KB，防异常巨型标签

    /** @return array{all: array, quick: array} */
    public function extract(string $absolutePath, string $mime): array
    {
        $type = MediaType::fromMime($mime);

        $all = $this->viaExifTool($absolutePath)
            ?? ($type === MediaType::Image
                ? $this->viaPhpExif($absolutePath)
                : $this->viaGetId3($absolutePath));

        $all = $this->sanitize($all ?? []);

        return [
            'all' => $this->capSize($all),
            'quick' => $this->quickFields($all, $type),
        ];
    }

    /* ---------------- ExifTool ---------------- */

    private function viaExifTool(string $path): ?array
    {
        $bin = config('visionsy.media.exiftool_path') ?: 'exiftool';

        try {
            $process = new Process([$bin, '-json', '-g1', '-n', '-api', 'largefilesupport=1', $path]);
            $process->setTimeout(20);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $decoded = json_decode($process->getOutput(), true);

            if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
                return null;
            }

            $groups = $decoded[0];
            unset($groups['SourceFile']);

            return $groups;
        } catch (Throwable) {
            return null; // 未安装 / 不可执行 → 回退
        }
    }

    /* ---------------- 回退：PHP exif（图片） ---------------- */

    private function viaPhpExif(string $path): array
    {
        $all = [];

        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($path, null, true) ?: [];
                foreach ($exif as $section => $tags) {
                    if (is_array($tags)) {
                        $all[$section] = $tags;
                    }
                }
            } catch (Throwable) {
                // 忽略损坏的 EXIF 段
            }
        }

        if ($info = @getimagesize($path)) {
            $all['File'] = array_merge($all['File'] ?? [], [
                'ImageWidth' => $info[0],
                'ImageHeight' => $info[1],
                'MIMEType' => $info['mime'] ?? null,
            ]);
        }

        return $all;
    }

    /* ---------------- 回退：getID3（视频/音频/其他） ---------------- */

    private function viaGetId3(string $path): array
    {
        if (! class_exists(\getID3::class)) {
            return [];
        }

        try {
            $getID3 = new \getID3();
            $info = $getID3->analyze($path);
        } catch (Throwable) {
            return [];
        }

        $all = [];

        $all['File'] = array_filter([
            'FileFormat' => $info['fileformat'] ?? null,
            'MIMEType' => $info['mime_type'] ?? null,
            'Duration' => isset($info['playtime_seconds']) ? round((float) $info['playtime_seconds'], 3) : null,
            'PlaytimeString' => $info['playtime_string'] ?? null,
            'OverallBitrate' => isset($info['bitrate']) ? (int) $info['bitrate'] : null,
        ], fn ($v) => $v !== null);

        if (isset($info['video']) && is_array($info['video'])) {
            $all['Video'] = array_filter([
                'ImageWidth' => $info['video']['resolution_x'] ?? null,
                'ImageHeight' => $info['video']['resolution_y'] ?? null,
                'FrameRate' => $info['video']['frame_rate'] ?? null,
                'Codec' => $info['video']['fourcc_lookup'] ?? $info['video']['dataformat'] ?? null,
                'Bitrate' => $info['video']['bitrate'] ?? null,
                'Rotation' => $info['video']['rotate'] ?? null,
            ], fn ($v) => $v !== null);
        }

        if (isset($info['audio']) && is_array($info['audio'])) {
            $all['Audio'] = array_filter([
                'Codec' => $info['audio']['dataformat'] ?? null,
                'SampleRate' => $info['audio']['sample_rate'] ?? null,
                'Channels' => $info['audio']['channels'] ?? null,
                'Bitrate' => $info['audio']['bitrate'] ?? null,
            ], fn ($v) => $v !== null);
        }

        // 容器标签（QuickTime/Matroska/ID3v2…：拍摄时间、设备、GPS 常在这里）
        if (isset($info['tags']) && is_array($info['tags'])) {
            foreach ($info['tags'] as $tagGroup => $tags) {
                $flat = [];
                foreach ((array) $tags as $name => $values) {
                    $flat[$name] = is_array($values) ? implode('; ', array_map('strval', $values)) : $values;
                }
                if ($flat !== []) {
                    $all['Tags.'.$tagGroup] = $flat;
                }
            }
        }

        return $all;
    }

    /* ---------------- 提炼常用字段 ---------------- */

    private function quickFields(array $all, MediaType $type): array
    {
        $get = function (array $keys) use ($all) {
            foreach ($keys as $key) {
                [$section, $tag] = array_pad(explode('.', $key, 2), 2, null);
                foreach ($all as $groupName => $tags) {
                    if (! is_array($tags)) {
                        continue;
                    }
                    if ($section !== '*' && ! str_contains(strtolower($groupName), strtolower($section))) {
                        continue;
                    }
                    foreach ($tags as $name => $value) {
                        if (strcasecmp($name, $tag ?? $name) === 0 && $value !== '' && $value !== null) {
                            return $value;
                        }
                    }
                }
            }

            return null;
        };

        $quick = [];

        $takenAt = $get(['ExifIFD.DateTimeOriginal', 'IFD0.DateTime', 'EXIF.DateTimeOriginal',
            '*.DateTimeOriginal', '*.CreateDate', '*.creation_date', '*.CreationDate']);
        if ($takenAt !== null) {
            try {
                $quick['taken_at'] = Carbon::parse(str_replace(':', '-', substr((string) $takenAt, 0, 10)).substr((string) $takenAt, 10))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $quick['taken_at'] = (string) $takenAt;
            }
        }

        $make = $get(['IFD0.Make', '*.Make', '*.make']);
        $model = $get(['IFD0.Model', '*.Model', '*.model']);
        if ($make !== null || $model !== null) {
            $quick['camera'] = trim(trim((string) $make).' '.trim((string) $model));
        }

        foreach ([
            'lens' => ['*.LensModel', '*.LensID', '*.Lens'],
            'exposure' => ['*.ExposureTime'],
            'aperture' => ['*.FNumber', '*.ApertureValue'],
            'iso' => ['*.ISO', '*.ISOSpeedRatings'],
            'focal_length' => ['*.FocalLength'],
        ] as $field => $keys) {
            if (($v = $get($keys)) !== null) {
                $quick[$field] = is_array($v) ? implode('/', $v) : (string) $v;
            }
        }

        $lat = $get(['*.GPSLatitude']);
        $lng = $get(['*.GPSLongitude']);
        if (is_numeric($lat) && is_numeric($lng)) {
            $quick['gps'] = round((float) $lat, 6).', '.round((float) $lng, 6);
        }

        if ($type !== MediaType::Image) {
            if (($d = $get(['File.Duration', '*.Duration', '*.MediaDuration'])) !== null && is_numeric($d)) {
                $quick['duration_seconds'] = (float) $d;
            }
            if (($c = $get(['Video.Codec', '*.CompressorID', '*.VideoCodec'])) !== null) {
                $quick['video_codec'] = (string) $c;
            }
            if (($f = $get(['Video.FrameRate', '*.VideoFrameRate'])) !== null) {
                $quick['frame_rate'] = (string) $f;
            }
            $w = $get(['Video.ImageWidth', '*.ImageWidth']);
            $h = $get(['Video.ImageHeight', '*.ImageHeight']);
            if (is_numeric($w) && is_numeric($h)) {
                $quick['video_resolution'] = ((int) $w).'×'.((int) $h);
            }
        }

        return $quick;
    }

    /* ---------------- 工具 ---------------- */

    /** 递归清洗为 UTF-8 / JSON 安全的标量。 */
    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return null;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $cleanKey = mb_convert_encoding((string) $k, 'UTF-8', 'UTF-8');
                $clean = $this->sanitize($v, $depth + 1);
                if ($clean !== null && $clean !== '' && $clean !== []) {
                    $out[$cleanKey] = $clean;
                }
            }

            return $out;
        }

        if (is_string($value)) {
            // 丢弃二进制标签（如内嵌缩略图）
            if (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8') && strlen($value) > 512) {
                return null;
            }

            return mb_substr(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 0, 1024);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return null;
    }

    private function capSize(array $all): array
    {
        while (strlen((string) json_encode($all)) > self::MAX_JSON_BYTES && $all !== []) {
            array_pop($all); // 从末尾整组丢弃直到合规
        }

        return $all;
    }
}
