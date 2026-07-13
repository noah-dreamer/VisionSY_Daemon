<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaResource;
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

        return view('media.create', [
            'maxUploadMb' => (int) config('visionsy.media.max_upload_mb', 512),
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
        ], [], ['file' => '文件', 'title' => '标题', 'description' => '描述', 'tags' => '标签']);

        // 重复素材提示（同内容已入库则拒绝重复归档）
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
        ], $request->user());

        return redirect()->route('media.show', $resource)->with('status', '素材上传成功，已自动生成带水印预览。');
    }
}
