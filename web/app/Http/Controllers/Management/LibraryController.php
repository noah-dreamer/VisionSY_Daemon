<?php
namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Jobs\Media\DiscoverMediaGalleries;
use App\Jobs\Media\ProcessNewUpload;
use App\Jobs\Media\ScanMediaGallery;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Services\AuditLogger;
use App\Services\Media\DerivativeVariant;
use App\Services\Media\GalleryService;
use App\Services\Media\MediaMountManager;
use App\Services\Media\MediaStorageService;
use App\Services\Media\PathResolverService;
use App\Services\Media\StorageSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LibraryController extends Controller
{
    private function run(callable $callback)
    {
        try {
            return $callback();
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['operation' => $error->getMessage()]);
        }
    }
    private function audit(Request $request, string $action, string $type, $id): void
    {
        app(AuditLogger::class)->log($action, $request->user(), $type, (string) $id);
    }
    public function sources()
    {
        return response()->json(['data' => MediaStorageSource::withCount(['galleries', 'mediaResources'])->latest('id')->get(),
            'allowed_roots' => config('visionsy.media.allowed_roots')]);
    }
    public function saveSource(Request $request, ?MediaStorageSource $source = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'root_path' => ['required', 'string', 'max:1024'],
            // NAS mounts belong to the host; dashboard registers a local accessible path.
            'driver' => [Rule::in(['local', 'smb', 'nfs'])],
            'is_enabled' => ['boolean'], 'is_writable' => ['boolean'], 'scan_recursive' => ['boolean'],
        ]);
        abort_if($source && $source->root_path !== $data['root_path'] && $source->mediaResources()->exists(), 422, '已有媒体的目录不能直接改路径，请新增媒体目录。');
        $service = app(StorageSourceService::class);
        $saved = $this->run(fn () => $source ? $service->update($source, $data) : $service->create($data, $request->user()));
        $this->audit($request, 'storage_source.saved', 'media_storage_source', $saved->id);
        return response()->json(['message' => '媒体目录已保存。', 'data' => $saved]);
    }
    public function deleteSource(Request $request, MediaStorageSource $source)
    {
        $this->run(fn () => app(StorageSourceService::class)->delete($source));
        $this->audit($request, 'storage_source.deleted', 'media_storage_source', $source->id);
        return response()->json(['message' => '目录登记已移除，磁盘文件未删除。']);
    }
    public function sourceAction(Request $request, MediaStorageSource $source, string $action)
    {
        if ($action === 'scan') {
            abort_unless($source->is_enabled, 422, '请先启用媒体目录。');
            DiscoverMediaGalleries::dispatch($source->id);
            $result = ['message' => '目录发现与扫描任务已加入队列。'];
        } elseif ($action === 'verify') {
            $result = app(MediaMountManager::class)->status($source);
            $result['message'] = $result['readable'] ? '路径可以读取。' : '路径不可访问，请检查 daemon 主机上的挂载。';
        } else {
            abort(404);
        }
        $this->audit($request, 'storage_source.'.$action, 'media_storage_source', $source->id);
        return response()->json($result);
    }
    public function galleries(Request $request)
    {
        $result = MediaGallery::with('storageSource')->withCount('mediaResources')
            ->when($request->query('source_id'), fn ($q, $id) => $q->where('storage_source_id', $id))
            ->when($request->query('q'), fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
            ->latest('id')->paginate(24);
        return $result;
    }
    public function saveGallery(Request $request, ?MediaGallery $gallery = null)
    {
        $data = $request->validate([
            'storage_source_id' => [$gallery ? 'sometimes' : 'required', 'exists:media_storage_sources,id'],
            'name' => ['required', 'string', 'max:255'],
            'folder_name' => [$gallery ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'tags' => ['nullable', 'string', 'max:300'],
            'event_started_at' => ['nullable', 'date'], 'event_ended_at' => ['nullable', 'date', 'after_or_equal:event_started_at'],
            'is_visible' => ['boolean'],
            'cover_media_resource_id' => ['nullable', 'integer', Rule::exists('media_resources', 'id')->where('gallery_id', $gallery?->id ?? 0)],
        ]);
        $service = app(GalleryService::class);
        $saved = $this->run(fn () => $gallery ? $service->update($gallery, $data)
            : $service->create(MediaStorageSource::findOrFail($data['storage_source_id']), $data));
        $this->audit($request, 'gallery.saved', 'media_gallery', $saved->id);
        return response()->json(['message' => '图库已保存。', 'data' => $saved]);
    }
    public function deleteGallery(Request $request, MediaGallery $gallery)
    {
        $this->run(fn () => app(GalleryService::class)->delete($gallery));
        $this->audit($request, 'gallery.deleted', 'media_gallery', $gallery->id);
        return response()->json(['message' => '空图库记录已删除，磁盘目录未删除。']);
    }
    public function galleryAction(Request $request, MediaGallery $gallery, string $action)
    {
        if ($action === 'scan') {
            ScanMediaGallery::dispatch($gallery->id, $request->user()->id);
        } elseif ($action === 'retry-derivatives') {
            $gallery->mediaResources()->whereNotNull('storage_source_id')->whereIn('derivative_status', ['failed', 'partial', 'pending'])->orderBy('id')->chunkById(100, function ($resources) {
                foreach ($resources as $resource) {
                    $resource->update(['derivative_status' => 'pending', 'derivative_error' => null]);
                    ProcessNewUpload::dispatch($resource->id);
                }
            });
        } else {
            abort(404);
        }
        $this->audit($request, 'gallery.'.$action, 'media_gallery', $gallery->id);
        return response()->json(['message' => '后台任务已派发。']);
    }
    public function resources(Request $request)
    {
        $result = MediaResource::with('gallery:id,name')->search($request->query('q'))
            ->when($request->query('gallery_id'), fn ($q, $id) => $q->where('gallery_id', $id))
            ->when($request->query('derivative_status'), fn ($q, $value) => $q->where('derivative_status', $value))
            ->latest('id')->paginate(24);
        $result->through(function ($resource) {
            $resource->setAttribute('thumbnail_url', $resource->has_thumb
                ? URL::temporarySignedRoute('management.thumbnail', now()->addMinutes(3), ['resource' => $resource->id]) : null);
            return $resource;
        });
        return $result;
    }
    public function saveResource(Request $request, MediaResource $resource)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'], 'tags' => ['nullable', 'array', 'max:12'],
            'tags.*' => ['string', 'max:80'], 'status' => [Rule::in(['active', 'archived'])]]);
        $resource->update($data);
        $this->audit($request, 'media.updated', 'media_resource', $resource->id);
        return response()->json(['message' => '素材已保存。']);
    }
    public function deleteResource(Request $request, MediaResource $resource)
    {
        $this->run(fn () => app(MediaStorageService::class)->delete($resource, $request->user()));
        return response()->json(['message' => '素材已删除。']);
    }
    public function retryResource(Request $request, MediaResource $resource)
    {
        abort_if($resource->isLegacy(), 422, '旧版素材请先迁移到图库后重新生成预览。');
        $resource->update(['derivative_status' => 'pending', 'derivative_error' => null]);
        ProcessNewUpload::dispatch($resource->id);
        $this->audit($request, 'media.derivatives.retry', 'media_resource', $resource->id);
        return response()->json(['message' => '预览生成任务已派发。']);
    }
    public function thumbnail(MediaResource $resource)
    {
        abort_unless($resource->has_thumb, 404);
        $path = $resource->isLegacy() ? app(MediaStorageService::class)->disk()->path($resource->thumbRelativePath())
            : app(PathResolverService::class)->resolveDerivativeAbsolute($resource->loadMissing(['storageSource', 'gallery']), DerivativeVariant::Thumb);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=60', 'Referrer-Policy' => 'no-referrer']);
    }
}
