<?php

namespace App\Services\Media;

use App\Enums\StorageSourceType;
use App\Models\MediaStorageSource;
use RuntimeException;
use Symfony\Component\Process\Process;

class PrivilegedHelperMountDriver implements MediaMountManager
{
    private string $helperPath;
    private string $mountBase;

    public function __construct()
    {
        $this->helperPath = config('visionsy.media.mount_helper_path', '/usr/local/bin/visionsy-mount');
        $this->mountBase = config('visionsy.media.mount_base', '/mnt/visionsy-media');
    }

    public function mount(MediaStorageSource $source): array
    {
        $this->validateSource($source);
        $mountPoint = $this->mountPoint($source);

        try {
            $input = json_encode([
                'action' => 'mount',
                'source_id' => $source->id,
                'protocol' => $source->driver->value,
                'mount_point' => $mountPoint,
                'config' => $this->buildConfig($source),
            ], JSON_THROW_ON_ERROR);

            $credFile = $this->writeCredentialFile($source);
            try {
                $process = new Process([$this->helperPath, $input, '--cred-file='.$credFile]);
                $process->setTimeout(30);
                $process->run();

                $output = json_decode($process->getOutput(), true);
                if (! is_array($output)) {
                    throw new RuntimeException('辅助程序返回格式无效。');
                }

                $source->updateQuietly(['root_path' => $mountPoint]);

                return $output;
            } finally {
                if ($credFile !== null && is_file($credFile)) {
                    @unlink($credFile);
                }
            }
        } catch (\Throwable $e) {
            return ['mounted' => false, 'error' => $this->sanitizeError($e->getMessage())];
        }
    }

    public function unmount(MediaStorageSource $source): array
    {
        $this->validateSource($source);
        $mountPoint = $this->mountPoint($source);

        if ($this->hasActiveTasks($source)) {
            return ['mounted' => true, 'error' => '存在运行中的扫描或派生任务，无法卸载。'];
        }

        try {
            $input = json_encode([
                'action' => 'unmount',
                'source_id' => $source->id,
                'mount_point' => $mountPoint,
            ], JSON_THROW_ON_ERROR);

            $process = new Process([$this->helperPath, $input]);
            $process->setTimeout(15);
            $process->run();

            $output = json_decode($process->getOutput(), true) ?: [];

            return $output;
        } catch (\Throwable $e) {
            return ['mounted' => (bool) is_dir($mountPoint), 'error' => $this->sanitizeError($e->getMessage())];
        }
    }

    public function status(MediaStorageSource $source): array
    {
        $mountPoint = $this->mountPoint($source);
        $isDir = is_dir($mountPoint);

        try {
            $input = json_encode([
                'action' => 'status',
                'source_id' => $source->id,
                'mount_point' => $mountPoint,
            ], JSON_THROW_ON_ERROR);

            $process = new Process([$this->helperPath, $input]);
            $process->setTimeout(10);
            $process->run();

            $output = json_decode($process->getOutput(), true);

            return array_merge([
                'mounted' => $isDir,
                'path' => $mountPoint,
                'exists' => $isDir,
                'driver' => 'privileged_helper',
            ], is_array($output) ? $output : []);
        } catch (\Throwable $e) {
            return [
                'mounted' => $isDir,
                'path' => $mountPoint,
                'exists' => $isDir,
                'driver' => 'privileged_helper',
                'error' => $this->sanitizeError($e->getMessage()),
            ];
        }
    }

    public function healthCheck(MediaStorageSource $source): bool
    {
        try {
            $st = $this->status($source);

            return $st['mounted'] === true && ($st['readable'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function validateSource(MediaStorageSource $source): void
    {
        if (! $source->isNetworkMount()) {
            throw new RuntimeException('仅 SMB/NFS 存储源支持特权辅助程序挂载。');
        }

        $this->validateMountBase();
    }

    private function validateMountBase(): void
    {
        $real = realpath($this->mountBase);
        if ($real === false) {
            throw new RuntimeException("挂载根目录不存在: {$this->mountBase}");
        }

        $forbidden = ['/', '/etc', '/var/www', storage_path(), base_path()];
        foreach ($forbidden as $path) {
            $forbidReal = realpath($path) ?: $path;
            if ($real === $forbidReal || str_starts_with($real, $forbidReal.'/') || str_starts_with($forbidReal, $real.'/')) {
                throw new RuntimeException("挂载根目录不允许位于系统关键目录内。");
            }
        }
    }

    private function mountPoint(MediaStorageSource $source): string
    {
        $hash = substr(hash('sha256', (string) $source->id.config('app.key')), 0, 16);

        return rtrim($this->mountBase, '/').'/'.$hash;
    }

    private function buildConfig(MediaStorageSource $source): array
    {
        $config = [];

        if ($source->driver === StorageSourceType::Smb) {
            $config = array_filter([
                'server' => $source->smb_server ?? null,
                'share' => $source->smb_share ?? null,
                'version' => $source->smb_version ?? '3.0',
                'domain' => $source->smb_domain ?? null,
                'read_only' => $source->is_read_only,
            ], fn ($v) => $v !== null);
        } elseif ($source->driver === StorageSourceType::Nfs) {
            $config = array_filter([
                'server' => $source->nfs_server ?? null,
                'export' => $source->nfs_export ?? null,
                'version' => $source->nfs_version ?? '4',
                'read_only' => $source->is_read_only,
            ], fn ($v) => $v !== null);
        }

        return $config;
    }

    private function writeCredentialFile(MediaStorageSource $source): ?string
    {
        $password = $source->smb_password ?? null;
        if ($password === null || $password === '') {
            return null;
        }

        $credFile = tempnam(sys_get_temp_dir(), 'vsymnt');
        $content = "username=".($source->smb_username ?? '')."\npassword=".$password."\n";
        if (! empty($source->smb_domain)) {
            $content .= "domain=".$source->smb_domain."\n";
        }
        file_put_contents($credFile, $content);
        chmod($credFile, 0600);

        return $credFile;
    }

    private function hasActiveTasks(MediaStorageSource $source): bool
    {
        return $source->galleries()
            ->whereIn('scan_status', ['scanning', 'pending'])
            ->exists();
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+/', '[email]', $message);
        $message = str_replace(["\n", "\r"], ' ', $message);

        return mb_substr($message, 0, 500);
    }
}
