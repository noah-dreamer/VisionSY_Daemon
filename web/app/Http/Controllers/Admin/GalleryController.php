<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Media\ScanMediaGallery;
use App\Models\MediaGallery;
use App\Models\MediaStorageSource;
use App\Services\AuditLogger;
use App\Services\Media\GalleryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GalleryController extends Controller
{
    public function index(Request $request): View
    {
        $galleries = MediaGallery::query()
            ->with(['storageSource', 'coverResource'])
            ->when($request->query('source_id'), fn ($q, $id) => $q->where('storage_source_id', $id))
            ->withCount('mediaResources')
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $sources = MediaStorageSource::query()->orderBy('name')->get();

        return view('admin.galleries.index', compact('galleries', 'sources'));
    }

    public function create(Request $request): View
    {
        $sources = MediaStorageSource::query()
            ->enabled()
            ->where('is_read_only', false)
            ->orderBy('name')
            ->get();

        $selectedSource = null;
        if ($request->query('source_id')) {
            $selectedSource = MediaStorageSource::find($request->query('source_id'));
        }

        return view('admin.galleries.create', compact('sources', 'selectedSource'));
    }

    public function store(Request $request, GalleryService $service, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'storage_source_id' => ['required', 'exists:media_storage_sources,id'],
            'name' => ['required', 'string', 'max:255'],
            'folder_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:300'],
            'event_started_at' => ['nullable', 'date'],
            'event_ended_at' => ['nullable', 'date', 'after_or_equal:event_started_at'],
            'is_visible' => ['boolean'],
        ]);

        $source = MediaStorageSource::findOrFail($validated['storage_source_id']);
        $gallery = $service->create($source, $validated);

        $audit->log('gallery.created', $request->user(), 'media_gallery', (string) $gallery->id, [
            'name' => $gallery->name,
            'source' => $source->name,
        ]);

        return redirect()->route('admin.galleries.index')
            ->with('status', "图库「{$gallery->name}」已创建。");
    }

    public function edit(MediaGallery $gallery): View
    {
        $gallery->load('storageSource');

        return view('admin.galleries.edit', compact('gallery'));
    }

    public function update(Request $request, MediaGallery $gallery, GalleryService $service, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:300'],
            'event_started_at' => ['nullable', 'date'],
            'event_ended_at' => ['nullable', 'date', 'after_or_equal:event_started_at'],
            'is_visible' => ['boolean'],
            'cover_media_resource_id' => ['nullable', 'exists:media_resources,id'],
        ]);

        $service->update($gallery, $validated);

        $audit->log('gallery.updated', $request->user(), 'media_gallery', (string) $gallery->id, [
            'name' => $gallery->name,
        ]);

        return redirect()->route('admin.galleries.index')
            ->with('status', "图库「{$gallery->name}」已更新。");
    }

    public function destroy(Request $request, MediaGallery $gallery, GalleryService $service, AuditLogger $audit): RedirectResponse
    {
        $name = $gallery->name;
        $service->delete($gallery);

        $audit->log('gallery.deleted', $request->user(), 'media_gallery', null, [
            'name' => $name,
        ]);

        return redirect()->route('admin.galleries.index')
            ->with('status', "图库「{$name}」已删除。");
    }

    public function scan(Request $request, MediaGallery $gallery, AuditLogger $audit): RedirectResponse
    {
        ScanMediaGallery::dispatch($gallery->id, $request->user()->id);

        $audit->log('gallery.scan_triggered', $request->user(), 'media_gallery', (string) $gallery->id);

        return back()->with('status', "图库「{$gallery->name}」的扫描任务已派发。");
    }
}
