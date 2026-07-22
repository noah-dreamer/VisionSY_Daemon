<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaStorageSource;
use App\Services\AuditLogger;
use App\Services\Media\MediaMountManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MountController extends Controller
{
    public function mount(Request $request, MediaStorageSource $storageSource, MediaMountManager $mount, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', $storageSource);

        $result = $mount->mount($storageSource);

        if ($result['mounted'] ?? false) {
            $storageSource->update(['mount_status' => 'mounted', 'mount_error' => null]);
            $audit->log('storage_source.mounted', $request->user(), 'media_storage_source', (string) $storageSource->id, [
                'name' => $storageSource->name,
            ]);

            return back()->with('status', '存储源已挂载。');
        }

        $storageSource->update([
            'mount_status' => 'failed',
            'mount_error' => mb_substr((string) ($result['error'] ?? '未知错误'), 0, 2000),
        ]);
        $audit->log('storage_source.mount_failed', $request->user(), 'media_storage_source', (string) $storageSource->id, [
            'error' => $result['error'] ?? 'unknown',
        ]);

        return back()->withErrors(['mount' => $result['error'] ?? '挂载失败。']);
    }

    public function unmount(Request $request, MediaStorageSource $storageSource, MediaMountManager $mount, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', $storageSource);

        $result = $mount->unmount($storageSource);

        if (! ($result['mounted'] ?? true)) {
            $storageSource->update(['mount_status' => 'unmounted', 'mount_error' => null]);
            $audit->log('storage_source.unmounted', $request->user(), 'media_storage_source', (string) $storageSource->id);

            return back()->with('status', '存储源已卸载。');
        }

        $storageSource->update(['mount_status' => 'unmount_failed', 'mount_error' => mb_substr((string) ($result['error'] ?? ''), 0, 2000)]);

        return back()->withErrors(['unmount' => $result['error'] ?? '卸载失败。']);
    }

    public function testConnection(Request $request, MediaStorageSource $storageSource, MediaMountManager $mount): RedirectResponse
    {
        Gate::authorize('manage', $storageSource);

        $ok = $mount->healthCheck($storageSource);

        return back()->with('status', $ok ? '连接测试成功。' : '连接测试失败。');
    }
}
