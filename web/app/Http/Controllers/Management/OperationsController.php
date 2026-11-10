<?php
namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Jobs\Management\CreateDatabaseBackup;
use App\Models\AuditLog;
use App\Models\DatabaseBackup;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AuditLogger;
use App\Services\Management\RuntimeStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function info(Request $request)
    {
        return response()->json(['product' => 'visionsy-daemon', 'api_version' => config('management.api_version'),
            'version' => config('management.version'), 'name' => config('management.name'),
            'administrator' => $request->user()->only(['id', 'email', 'display_name']),
            'capabilities' => ['users', 'groups', 'library', 'tasks', 'audit', 'backups', 'mail'], 'time' => now()->toIso8601String()]);
    }
    public function overview(RuntimeStatus $runtime)
    {
        $processes = $runtime->processes();
        return response()->json([
            'stats' => ['users' => User::count(), 'groups' => UserGroup::count(), 'galleries' => MediaGallery::count(),
                'resources' => MediaResource::count(), 'sources' => MediaStorageSource::count(),
                'pending_jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(),
                'failed_derivatives' => MediaResource::whereIn('derivative_status', ['failed', 'partial'])->count()],
            'runtime' => ['processes' => $processes, 'database' => DB::selectOne('SELECT VERSION() AS version')->version,
                'php' => PHP_VERSION, 'storage_free_bytes' => @disk_free_space(storage_path()) ?: 0,
                'database_connected' => true],
            'recent_logs' => AuditLog::latest('id')->limit(8)->get(),
            'recent_galleries' => MediaGallery::latest('updated_at')->limit(6)->get(),
        ]);
    }
    public function audit(Request $request)
    {
        return AuditLog::when($request->query('action'), fn ($q, $s) => $q->where('action', 'like', '%'.$s.'%'))
            ->when($request->query('actor'), fn ($q, $s) => $q->where('actor_email', 'like', '%'.$s.'%'))->latest('id')->paginate(30);
    }
    public function tasks()
    {
        $pending = DB::table('jobs')->select(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at'])->latest('id')->limit(50)->get();
        $failed = DB::table('failed_jobs')->select(['uuid', 'queue', 'failed_at'])->latest('id')->limit(50)->get();
        return response()->json(['pending' => $pending, 'failed' => $failed,
            'scanning' => MediaGallery::where('scan_status', 'scanning')->orWhereNotNull('locked_at')->limit(50)->get(),
            'backups' => DatabaseBackup::whereIn('status', ['pending', 'running'])->latest()->get()]);
    }
    public function taskAction(Request $request, string $uuid, string $action)
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        if ($action === 'retry') {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
        } elseif ($action === 'forget') {
            Artisan::call('queue:forget', ['id' => $uuid]);
        } else {
            abort(404);
        }
        app(AuditLogger::class)->log('queue.failed_'.$action, $request->user(), 'failed_job', $uuid);
        return response()->json(['message' => $action === 'retry' ? '任务已重新加入队列。' : '失败记录已删除。']);
    }
    public function restartWorker(Request $request)
    {
        Artisan::call('queue:restart');
        app(AuditLogger::class)->log('queue.restart_requested', $request->user());
        return response()->json(['message' => '已请求队列进程在当前任务结束后重新启动。']);
    }
    public function backups()
    {
        // Recover interrupted jobs after the maximum runtime, including abrupt container termination.
        DatabaseBackup::whereIn('status', ['pending', 'running'])->where('updated_at', '<', now()->subHours(2))
            ->update(['status' => 'failed', 'error' => '备份任务长时间未完成，请检查后台进程后重新创建。']);
        return DatabaseBackup::latest()->paginate(20);
    }
    public function createBackup(Request $request)
    {
        $backup = Cache::lock('management:create-backup', 15)->block(3, function () use ($request) {
            abort_if(DatabaseBackup::whereIn('status', ['pending', 'running'])->exists(), 409, '已有备份正在处理，请等待完成。');
            $backup = DatabaseBackup::create(['created_by' => $request->user()->id]);
            CreateDatabaseBackup::dispatch($backup->id);
            return $backup;
        });
        app(AuditLogger::class)->log('database.backup_requested', $request->user(), 'database_backup', $backup->id);
        return response()->json(['message' => '数据库备份已加入队列。', 'data' => $backup], 202);
    }
    public function downloadBackup(Request $request, DatabaseBackup $backup)
    {
        abort_unless($backup->status === 'completed' && is_file($backup->path()), 404, '备份文件尚未生成或已被移除。');
        app(AuditLogger::class)->log('database.backup_downloaded', $request->user(), 'database_backup', $backup->id);
        return response()->download($backup->path(), 'visionsy-'.$backup->created_at->format('Ymd-His').'-'.substr($backup->id, 0, 8).'.sql.gz', ['Cache-Control' => 'no-store']);
    }
    public function deleteBackup(Request $request, DatabaseBackup $backup)
    {
        abort_if(in_array($backup->status, ['pending', 'running']), 409, '正在处理的备份不能删除。');
        if (is_file($backup->path())) {
            unlink($backup->path());
        }
        $backup->delete();
        app(AuditLogger::class)->log('database.backup_deleted', $request->user(), 'database_backup', $backup->id);
        return response()->json(['message' => '备份已删除。']);
    }
}
