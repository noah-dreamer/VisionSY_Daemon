<?php

namespace App\Models;

use App\Enums\ScanStatus;
use App\Services\Media\PathResolverService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MediaGallery extends Model
{
    protected $table = 'media_galleries';

    protected $fillable = [
        'storage_source_id',
        'relative_path',
        'name',
        'description',
        'tags',
        'event_started_at',
        'event_ended_at',
        'cover_media_resource_id',
        'is_managed',
        'is_visible',
        'scan_status',
        'last_scanned_at',
        'media_count',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'event_started_at' => 'datetime',
            'event_ended_at' => 'datetime',
            'is_managed' => 'boolean',
            'is_visible' => 'boolean',
            'last_scanned_at' => 'datetime',
            'locked_at' => 'datetime',
            'media_count' => 'integer',
        ];
    }

    public function storageSource(): BelongsTo
    {
        return $this->belongsTo(MediaStorageSource::class, 'storage_source_id');
    }

    public function coverResource(): BelongsTo
    {
        return $this->belongsTo(MediaResource::class, 'cover_media_resource_id');
    }

    public function mediaResources(): HasMany
    {
        return $this->hasMany(MediaResource::class, 'gallery_id');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderByRaw('event_started_at IS NOT NULL DESC')
            ->orderBy('event_started_at', 'desc')
            ->orderBy('created_at', 'desc');
    }

    public function fullPath(): string
    {
        return rtrim(rtrim(str_replace('\\', '/', $this->storageSource->root_path), '/').'/'.ltrim($this->relative_path, '/'), '/');
    }

    public function derivativeRootPath(): string
    {
        return $this->fullPath().'/'.PathResolverService::DERIVATIVE_DIR;
    }

    public function isScanning(): bool
    {
        return $this->scan_status === ScanStatus::Scanning->value;
    }

    /**
     * 扫描锁 stale 阈值（分钟）。必须大于扫描 Job 的最长 timeout，
     * 避免合法长扫描被误判为 stale 而并发重入。
     */
    public static function staleLockMinutes(): int
    {
        $minutes = (int) config('visionsy.media.scanner.stale_lock_minutes', 75);

        return $minutes > 0 ? $minutes : 75;
    }

    public function canStartScan(): bool
    {
        if ($this->isScanning() && $this->locked_at !== null) {
            if ($this->locked_at->diffInMinutes(now()) >= self::staleLockMinutes()) {
                return true;
            }

            return false;
        }

        return true;
    }

    public function acquireScanLock(): bool
    {
        $affected = static::query()
            ->where('id', $this->id)
            ->where(function ($q) {
                $q->whereNull('locked_at')
                    ->orWhere('locked_at', '<', Carbon::now()->subMinutes(self::staleLockMinutes()));
            })
            ->update([
                'locked_at' => now(),
                'scan_status' => ScanStatus::Scanning->value,
            ]);

        if ($affected > 0) {
            $this->refresh();

            return true;
        }

        return false;
    }

    public function releaseScanLock(): void
    {
        $this->updateQuietly(['locked_at' => null]);
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->tags)));
    }
}
