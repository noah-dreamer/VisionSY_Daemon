<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\MediaResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 媒体入库：原图落盘（private 磁盘）→ 计算校验和 → 生成带水印派生图 → 建记录。
 * 目录结构（对齐 ResourceSpace filestore 思路，按散列目录避免单目录过大）：
 *   storage/app/protected/media/{ab}/{cd}/{ulid}/original.{ext}|preview.jpg|thumb.jpg
 */
class MediaStorageService
{
    public function __construct(
        private ImageDerivativeService $derivatives,
        private MediaMetadataService $metadata,
        private AuditLogger $audit,
    ) {
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local'); // storage/app/private
    }

    /**
     * @param array{title:string,description?:?string,tags?:?string} $meta
     */
    public function store(UploadedFile $file, array $meta, User $uploader): MediaResource
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $type = MediaType::fromMime($mime);

        $ulid = strtolower((string) Str::ulid());
        $dir = substr($ulid, 0, 2).'/'.substr($ulid, 2, 2).'/'.$ulid;

        // 1. 原图落盘
        $file->storeAs("media/{$dir}", "original.{$ext}", ['disk' => 'local']);
        $absOriginal = $this->disk()->path("media/{$dir}/original.{$ext}");

        $checksum = hash_file('sha256', $absOriginal);
        $size = filesize($absOriginal);

        // 2. 读取全部内嵌元数据（对齐 ResourceSpace：exiftool 优先，回退 exif/getID3）
        $extracted = $this->metadata->extract($absOriginal, $mime);

        // 3. 生成带水印派生图（图片直接处理；视频先用 ffmpeg 抽帧，未装 ffmpeg 则仅原片）
        $width = $height = null;
        $hasDerivatives = false;

        $frameSource = null;
        if ($type === MediaType::Image) {
            $frameSource = $absOriginal;
        } elseif ($type === MediaType::Video) {
            $frameSource = $this->extractVideoFrame($absOriginal, $this->disk()->path("media/{$dir}/frame.jpg"));
        }

        if ($frameSource !== null && ImageDerivativeService::available()) {
            try {
                $dim = $this->derivatives->generate(
                    $frameSource,
                    $this->disk()->path("media/{$dir}/thumb.jpg"),
                    $this->disk()->path("media/{$dir}/preview.jpg"),
                );
                if ($type === MediaType::Image) {
                    $width = $dim['width'];
                    $height = $dim['height'];
                }
                $hasDerivatives = true;
            } catch (\Throwable) {
                // 无法解码则按普通文件处理（仅原件）
            }
        }

        // 4. 建记录
        $tags = collect(explode(',', (string) ($meta['tags'] ?? '')))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $resource = MediaResource::create([
            'title' => $meta['title'],
            'description' => $meta['description'] ?? null,
            'tags' => $tags ?: null,
            'type' => $type,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'original_ext' => $ext,
            'mime' => $mime,
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'width' => $width,
            'height' => $height,
            'metadata' => $extracted['all'] ?: null,
            'taken_at' => $extracted['quick']['taken_at'] ?? null,
            'camera' => isset($extracted['quick']['camera']) ? mb_substr($extracted['quick']['camera'], 0, 120) : null,
            'duration_seconds' => $extracted['quick']['duration_seconds'] ?? null,
            'storage_dir' => $dir,
            'has_thumb' => $hasDerivatives,
            'has_preview' => $hasDerivatives,
            'uploaded_by' => $uploader->id,
        ]);

        $this->audit->log('media.uploaded', $uploader, 'media_resource', (string) $resource->id, [
            'title' => $resource->title,
            'mime' => $mime,
            'size' => $size,
            'checksum' => $checksum,
        ]);

        return $resource;
    }

    /**
     * 视频抽帧（配置 ffmpeg 后可用，对齐 ResourceSpace 的 $ffmpeg_path）：
     * 取第 1 秒画面为封面，供缩略图/水印预览流水线使用。
     */
    private function extractVideoFrame(string $videoPath, string $framePath): ?string
    {
        $bin = config('visionsy.media.ffmpeg_path') ?: 'ffmpeg';

        try {
            $process = new \Symfony\Component\Process\Process([
                $bin, '-y', '-ss', '1', '-i', $videoPath,
                '-frames:v', '1', '-q:v', '3', $framePath,
            ]);
            $process->setTimeout(30);
            $process->run();

            return $process->isSuccessful() && is_file($framePath) ? $framePath : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** 上传前重复素材提示（同 sha256 已存在）。 */
    public function findDuplicate(string $checksum): ?MediaResource
    {
        return MediaResource::query()->where('checksum_sha256', $checksum)->first();
    }

    /** 物理删除资源目录 + 记录。 */
    public function delete(MediaResource $resource, User $actor): void
    {
        $this->disk()->deleteDirectory("media/{$resource->storage_dir}");
        $id = (string) $resource->id;
        $title = $resource->title;
        $resource->delete();

        $this->audit->log('media.deleted', $actor, 'media_resource', $id, ['title' => $title]);
    }
}
