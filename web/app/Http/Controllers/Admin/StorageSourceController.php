<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaStorageSource;
use App\Services\AuditLogger;
use App\Services\Media\StorageSourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageSourceController extends Controller
{
    public function index(): View
    {
        $sources = MediaStorageSource::query()
            ->with('creator')
            ->withCount('galleries')
            ->withCount('mediaResources')
            ->latest()
            ->get();

        return view('admin.storage-sources.index', compact('sources'));
    }

    public function create(): View
    {
        return view('admin.storage-sources.create');
    }

    public function store(Request $request, StorageSourceService $service, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', 'string', 'in:local,smb,nfs'],
            'root_path' => ['required', 'string', 'max:1024'],
            'is_enabled' => ['boolean'],
            'is_writable' => ['boolean'],
            'is_managed' => ['boolean'],
            'scan_recursive' => ['boolean'],
            'auto_mount' => ['boolean'],
            'smb_server' => ['nullable', 'string', 'max:255'],
            'smb_share' => ['nullable', 'string', 'max:255'],
            'smb_username' => ['nullable', 'string', 'max:120'],
            'smb_password' => ['nullable', 'string', 'max:255'],
            'smb_domain' => ['nullable', 'string', 'max:120'],
            'smb_version' => ['nullable', 'string', 'max:10'],
            'nfs_server' => ['nullable', 'string', 'max:255'],
            'nfs_export' => ['nullable', 'string', 'max:512'],
            'nfs_version' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $source = $service->create($validated, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['root_path' => $e->getMessage()]);
        }

        $audit->log('storage_source.created', $request->user(), 'media_storage_source', (string) $source->id, [
            'name' => $source->name,
            'driver' => $source->driver->value,
            'root_path' => $source->root_path,
        ]);

        return redirect()->route('admin.storage-sources.index')
            ->with('status', "存储源「{$source->name}」已创建。");
    }

    public function edit(MediaStorageSource $storageSource): View
    {
        return view('admin.storage-sources.edit', ['source' => $storageSource]);
    }

    public function update(Request $request, MediaStorageSource $storageSource, StorageSourceService $service, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'root_path' => ['required', 'string', 'max:1024'],
            'is_enabled' => ['boolean'],
            'is_writable' => ['boolean'],
            'is_managed' => ['boolean'],
            'scan_recursive' => ['boolean'],
        ]);

        $service->update($storageSource, $validated);

        $audit->log('storage_source.updated', $request->user(), 'media_storage_source', (string) $storageSource->id, [
            'name' => $storageSource->name,
        ]);

        return redirect()->route('admin.storage-sources.index')
            ->with('status', "存储源「{$storageSource->name}」已更新。");
    }

    public function destroy(Request $request, MediaStorageSource $storageSource, StorageSourceService $service, AuditLogger $audit): RedirectResponse
    {
        $name = $storageSource->name;
        $service->delete($storageSource);

        $audit->log('storage_source.deleted', $request->user(), 'media_storage_source', null, [
            'name' => $name,
        ]);

        return redirect()->route('admin.storage-sources.index')
            ->with('status', "存储源「{$name}」已删除。");
    }

    public function verify(Request $request, MediaStorageSource $storageSource, StorageSourceService $service): RedirectResponse
    {
        $readable = $service->verifyReadable($storageSource);
        $writable = $service->verifyWritable($storageSource);

        if ($readable && $writable) {
            return back()->with('status', "存储源「{$storageSource->name}」路径可读可写。");
        } elseif ($readable) {
            return back()->with('status', "存储源「{$storageSource->name}」路径可读（只读）。");
        }

        return back()->withErrors(['root_path' => "存储源「{$storageSource->name}」路径不可访问。"]);
    }

    public function scan(Request $request, MediaStorageSource $storageSource, AuditLogger $audit): RedirectResponse
    {
        \App\Jobs\Media\DiscoverMediaGalleries::dispatch($storageSource->id);

        $audit->log('storage_source.scan_triggered', $request->user(), 'media_storage_source', (string) $storageSource->id);

        return back()->with('status', "存储源「{$storageSource->name}」的扫描任务已派发。");
    }
}
