<?php

namespace App\Models;

use App\Enums\DerivativeStatus;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaResource extends Model
{
    protected $table = 'media_resources';

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
        'storage_source_id',
        'gallery_id',
        'relative_path',
        'source_size_bytes',
        'source_modified_at',
        'source_identity',
        'is_raw',
        'is_system_managed',
        'index_status',
        'derivative_status',
        'derivative_error',
        'missing_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'taken_at' => 'datetime',
            'duration_seconds' => 'float',
            'source_modified_at' => 'datetime',
            'missing_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'index_status' => MediaStatus::class,
            'has_thumb' => 'boolean',
            'has_preview' => 'boolean',
            'is_raw' => 'boolean',
            'is_system_managed' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function storageSource(): BelongsTo
    {
        return $this->belongsTo(MediaStorageSource::class, 'storage_source_id');
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(MediaGallery::class, 'gallery_id');
    }

    /* ---------- 兼容旧路径（storage_source_id === null） ---------- */

    public function isLegacy(): bool
    {
        return $this->storage_source_id === null;
    }

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

    public function scopeNotMissing(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('index_status', '!=', MediaStatus::Missing->value)
                ->orWhereNull('index_status');
        });
    }

    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return $query;
        }

        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
        $like = '%'.$escaped.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('original_name', 'like', $like)
                ->orWhere('tags', 'like', $like)
                ->orWhere('camera', 'like', $like);
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

    public function derivativeStatusEnum(): ?DerivativeStatus
    {
        return DerivativeStatus::tryFrom((string) $this->derivative_status);
    }

    public function isMissing(): bool
    {
        return $this->index_status === MediaStatus::Missing->value;
    }
}
