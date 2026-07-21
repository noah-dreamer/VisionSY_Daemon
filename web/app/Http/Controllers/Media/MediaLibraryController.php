<?php

namespace App\Http\Controllers\Media;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Services\AuditLogger;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MediaLibraryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MediaResource::class);

        $view = $request->query('view');

        if ($view === null) {
            $hasGalleries = MediaGallery::visible()->exists();
            $view = $hasGalleries ? 'galleries' : 'photos';
        }

        if ($view === 'galleries') {
            return $this->galleryView($request);
        }

        return $this->allPhotosView($request);
    }

    private function galleryView(Request $request): View
    {
        $galleries = MediaGallery::query()
            ->visible()
            ->with(['storageSource', 'coverResource'])
            ->withCount(['mediaResources' => function ($q) {
                $q->where('index_status', '!=', MediaStatus::Missing->value)
                    ->where('status', '!=', MediaStatus::Archived->value);
            }])
            ->ordered()
            ->paginate(24)
            ->withQueryString();

        return view('media.index', [
            'viewMode' => 'galleries',
            'galleries' => $galleries,
            'keyword' => (string) $request->query('q', ''),
        ]);
    }

    private function allPhotosView(Request $request): View
    {
        $type = $request->query('type');
        $canManageArchived = $request->user()?->isSystemAdmin() ?? false;

        $resources = MediaResource::query()
            ->with('uploader')
            ->notMissing()
            ->when(! ($canManageArchived && $request->query('status') === 'archived'),
                fn ($q) => $q->active(),
                fn ($q) => $q->where('status', MediaStatus::Archived->value))
            ->search($request->query('q'))
            ->when(in_array($type, ['image', 'video', 'other'], true),
                fn ($q) => $q->where('type', $type))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        return view('media.index', [
            'viewMode' => 'photos',
            'resources' => $resources,
            'keyword' => (string) $request->query('q', ''),
            'type' => $type,
            'types' => MediaType::cases(),
            'showingArchived' => $canManageArchived && $request->query('status') === 'archived',
        ]);
    }

    public function show(Request $request, MediaResource $resource): View
    {
        Gate::authorize('view', $resource);

        $user = $request->user();

        return view('media.show', [
            'resource' => $resource,
            'canPreview' => $user !== null && $user->can('preview', $resource),
            'canDownloadOriginal' => $user !== null && $user->can('downloadOriginal', $resource),
            'canDownloadWatermarked' => $user !== null && $user->can('downloadWatermarked', $resource),
            'canManage' => $user !== null && $user->can('manage', $resource),
        ]);
    }

    public function edit(MediaResource $resource): View
    {
        Gate::authorize('manage', $resource);

        return view('media.edit', ['resource' => $resource]);
    }

    public function update(Request $request, MediaResource $resource, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', $resource);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:300'],
        ], [], ['title' => '标题', 'description' => '描述', 'tags' => '标签']);

        $tags = collect(explode(',', (string) ($validated['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->unique()->take(12)->values()->all();

        $resource->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'tags' => $tags ?: null,
        ]);

        $audit->log('media.updated', $request->user(), 'media_resource', (string) $resource->id);

        return redirect()->route('media.show', $resource)->with('status', '素材信息已更新。');
    }

    public function toggleArchive(Request $request, MediaResource $resource, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', $resource);

        $archived = $resource->status === MediaStatus::Archived;
        $resource->update(['status' => $archived ? MediaStatus::Active : MediaStatus::Archived]);

        $audit->log($archived ? 'media.restored' : 'media.archived',
            $request->user(), 'media_resource', (string) $resource->id);

        return back()->with('status', $archived ? '素材已恢复。' : '素材已归档，普通用户不再可见。');
    }

    public function destroy(Request $request, MediaResource $resource, MediaStorageService $storage): RedirectResponse
    {
        Gate::authorize('manage', $resource);

        $storage->delete($resource, $request->user());

        return redirect()->route('media.index')->with('status', '素材已删除。');
    }
}
