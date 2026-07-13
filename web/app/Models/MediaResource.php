<?php

namespace App\Models;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaResource extends Model
{
    protected $fillable = [
        'title',
        'description',
        'tags',
        'metadata',
        'taken_at',
        'camera',
        'duration_seconds',
        'type',
        'status',
        'original_name',
        'original_ext',
        'mime',
        'size_bytes',
        'checksum_sha256',
        'width',
        'height',
        'storage_dir',
        'has_thumb',
        'has_preview',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'taken_at' => 'datetime',
            'duration_seconds' => 'float',
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'has_thumb' => 'boolean',
            'has_preview' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /* ---------- 存储路径（相对 storage/app/protected，即 local 磁盘根） ---------- */

    public function originalRelativePath(): string
    {
        return "media/{$this->storage_dir}/original.{$this->original_ext}";
    }

    public function previewRelativePath(): string
    {
        return "media/{$this->storage_dir}/preview.jpg";
    }

    public function thumbRelativePath(): string
    {
        return "media/{$this->storage_dir}/thumb.jpg";
    }

    /* ---------- 查询作用域 ---------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MediaStatus::Active->value);
    }

    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($keyword) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $keyword).'%';
            $q->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('original_name', 'like', $like)
                ->orWhere('tags',
        'metadata',
        'taken_at',
        'camera',
        'duration_seconds', 'like', $like);
        });
    }

    /* ---------- 展示辅助 ---------- */

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return $unit === 'B' ? $bytes.' B' : number_format($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->tags)));
    }
}
