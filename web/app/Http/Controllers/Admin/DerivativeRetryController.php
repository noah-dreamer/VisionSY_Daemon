<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Media\GenerateMediaDerivatives;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DerivativeRetryController extends Controller
{
    public function retryResource(Request $request, MediaResource $resource, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', $resource);

        $resource->update(['derivative_status' => \App\Enums\DerivativeStatus::Pending->value, 'derivative_error' => null]);
        GenerateMediaDerivatives::dispatch($resource->id);

        $audit->log('media.derivatives.retry', $request->user(), 'media_resource', (string) $resource->id);

        return back()->with('status', '已重新派发派生任务。');
    }

    public function retryGallery(Request $request, MediaGallery $gallery, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', $gallery);

        $count = MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->whereIn('derivative_status', ['failed', 'partial'])
            ->update(['derivative_status' => \App\Enums\DerivativeStatus::Pending->value, 'derivative_error' => null]);

        $failedIds = MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->where('derivative_status', \App\Enums\DerivativeStatus::Pending->value)
            ->pluck('id');

        foreach ($failedIds as $id) {
            GenerateMediaDerivatives::dispatch($id);
        }

        $audit->log('media.derivatives.retry_gallery', $request->user(), 'media_gallery', (string) $gallery->id, [
            'count' => count($failedIds),
        ]);

        return back()->with('status', "已重新派发 {$count} 个资源的派生任务。");
    }
}
