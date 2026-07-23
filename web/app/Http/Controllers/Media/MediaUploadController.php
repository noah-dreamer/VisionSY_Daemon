<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MediaUploadController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', MediaResource::class);

        $sources = MediaStorageSource::query()
            ->where('is_enabled', true)
            ->where('is_managed', true)
            ->get();

        $galleries = collect();
        if ($sources->isNotEmpty()) {
            $sourceIds = $sources->pluck('id');
            $galleries = MediaGallery::query()
                ->whereIn('storage_source_id', $sourceIds)
                ->where('is_managed', true)
                ->orderBy('name')
                ->get();
        }

        return view('media.create', [
            'maxUploadMb' => (int) config('visionsy.media.max_upload_mb', 512),
            'sources' => $sources,
            'galleries' => $galleries,
        ]);
    }

    public function store(Request $request, MediaStorageService $storage): RedirectResponse
    {
        Gate::authorize('create', MediaResource::class);

        $maxKb = ((int) config('visionsy.media.max_upload_mb', 512)) * 1024;

        $validated = $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}"],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:300'],
            'storage_source_id' => ['nullable', 'integer', 'exists:media_storage_sources,id'],
            'gallery_id' => ['nullable', 'integer', 'exists:media_galleries,id'],
        ], [], ['file' => '文件', 'title' => '标题', 'description' => '描述', 'tags' => '标签']);

        if (($validated['storage_source_id'] ?? null) && ($validated['gallery_id'] ?? null)) {
            $gallery = MediaGallery::findOrFail($validated['gallery_id']);
            if ((int) $gallery->storage_source_id !== (int) $validated['storage_source_id']) {
                return back()->withInput()->withErrors([
                    'gallery_id' => '所选图库不属于所选存储源。',
                ]);
            }
        }

        $checksum = hash_file('sha256', $validated['file']->getRealPath());
        if (($dup = $storage->findDuplicate($checksum)) !== null) {
            return back()->withInput()->withErrors([
                'file' => "相同内容的素材已存在：「{$dup->title}」（#{$dup->id}），请勿重复上传。",
            ]);
        }

        $resource = $storage->store($validated['file'], [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'storage_source_id' => $validated['storage_source_id'] ?? null,
            'gallery_id' => $validated['gallery_id'] ?? null,
        ], $request->user());

        return redirect()->route('media.show', $resource)->with('status', '素材上传成功，已自动生成带水印预览。');
    }
}
