<?php
namespace App\Jobs\Management;

use App\Models\DatabaseBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

class CreateDatabaseBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 3600;
    public function __construct(public string $backupId) {}
    public function handle(): void
    {
        $backup = DatabaseBackup::find($this->backupId);
        if (! $backup || $backup->status !== 'pending') {
            return;
        }
        $backup->update(['status' => 'running', 'error' => null]);
        $partial = $backup->path().'.part';
        $stream = null;
        try {
            $directory = dirname($backup->path());
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $config = config('database.connections.mysql');
            $rootPassword = getenv('MARIADB_ROOT_PASSWORD');
            $args = ['mariadb-dump', '--single-transaction', '--quick', '--triggers', '--hex-blob', '--skip-comments'];
            if ($rootPassword !== false && $rootPassword !== '') {
                array_push($args, '--user=root', '--socket=/run/mysqld/mysqld.sock', '--routines', '--events');
                $password = $rootPassword;
            } else {
                array_push($args, '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username']);
                $password = $config['password'];
            }
            array_push($args, '--databases', $config['database']);
            $stream = gzopen($partial, 'wb6');
            if ($stream === false) {
                throw new \RuntimeException('Cannot create backup file');
            }
            chmod($partial, 0600);
            $process = new Process($args, null, ['MYSQL_PWD' => $password]);
            $process->setTimeout(3500);
            // Callback output is streamed to disk; never accumulate an entire database in memory.
            $process->run(function ($type, $chunk) use ($stream, $process) {
                if ($type === Process::OUT && gzwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Backup write failed');
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            if (! gzclose($stream)) {
                $stream = null;
                throw new \RuntimeException('Backup finalization failed');
            }
            $stream = null;
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Database dump failed');
            }
            if (! rename($partial, $backup->path()) || ! is_file($backup->path()) || filesize($backup->path()) <= 0) {
                throw new \RuntimeException('Backup publishing failed');
            }
            $backup->update(['status' => 'completed', 'size_bytes' => filesize($backup->path()), 'finished_at' => now()]);
        } catch (\Throwable $error) {
            if (is_resource($stream)) {
                gzclose($stream);
            }
            @unlink($partial);
            @unlink($backup->path());
            $backup->update(['status' => 'failed', 'error' => '数据库备份未完成，请检查数据库连接、磁盘空间和后台进程。', 'finished_at' => now()]);
            // Do not serialize command/environment/SQL into failed_jobs or HTTP responses.
        }
    }
    public function failed(?\Throwable $exception): void
    {
        if ($backup = DatabaseBackup::find($this->backupId)) {
            @unlink($backup->path().'.part');
            $backup->update(['status' => 'failed', 'error' => '备份任务超时或被中断，请重新创建。', 'finished_at' => now()]);
        }
    }
}
