<?php

namespace App\Models;

use App\Enums\ScanStatus;
use App\Enums\StorageSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaStorageSource extends Model
{
    protected $table = 'media_storage_sources';

    protected $fillable = [
        'name',
        'driver',
        'root_path',
        'is_enabled',
        'is_read_only',
        'is_managed',
        'scan_recursive',
        'last_scan_started_at',
        'last_scan_finished_at',
        'last_scan_status',
        'last_scan_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_read_only' => 'boolean',
            'is_managed' => 'boolean',
            'scan_recursive' => 'boolean',
            'last_scan_started_at' => 'datetime',
            'last_scan_finished_at' => 'datetime',
            'driver' => StorageSourceType::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(MediaGallery::class, 'storage_source_id');
    }

    public function mediaResources(): HasMany
    {
        return $this->hasMany(MediaResource::class, 'storage_source_id');
    }

    public function isLocal(): bool
    {
        return $this->driver === StorageSourceType::Local;
    }

    public function isNetworkMount(): bool
    {
        return $this->driver?->isNetworkMount() ?? false;
    }

    public function isWritable(): bool
    {
        return ! $this->is_read_only && is_writable($this->root_path);
    }

    public function isValidPath(): bool
    {
        $real = realpath($this->root_path);

        return $real !== false && is_dir($real) && is_readable($real);
    }

    public function getScanStatusEnum(): ?ScanStatus
    {
        return ScanStatus::tryFrom((string) $this->last_scan_status);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function markScanStarted(): void
    {
        $this->update([
            'last_scan_started_at' => now(),
            'last_scan_status' => ScanStatus::Scanning->value,
            'last_scan_error' => null,
        ]);
    }

    public function markScanCompleted(): void
    {
        $this->update([
            'last_scan_finished_at' => now(),
            'last_scan_status' => ScanStatus::Completed->value,
        ]);
    }

    public function markScanFailed(string $error): void
    {
        $this->update([
            'last_scan_finished_at' => now(),
            'last_scan_status' => ScanStatus::Failed->value,
            'last_scan_error' => mb_substr($error, 0, 2000),
        ]);
    }
}
