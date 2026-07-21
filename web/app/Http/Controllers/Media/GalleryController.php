<?php

namespace App\Http\Controllers\Media;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GalleryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MediaResource::class);

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

        return view('media.galleries.index', [
            'galleries' => $galleries,
        ]);
    }

    public function show(Request $request, MediaGallery $gallery): View
    {
        Gate::authorize('viewAny', MediaResource::class);

        $type = $request->query('type');
        $keyword = $request->query('q');

        $resources = $gallery->mediaResources()
            ->where('index_status', '!=', MediaStatus::Missing->value)
            ->when(! ($request->user()?->isSystemAdmin() && $request->query('status') === 'archived'),
                fn ($q) => $q->where('status', '!=', MediaStatus::Archived->value))
            ->search($keyword)
            ->when(in_array($type, ['image', 'video', 'other'], true),
                fn ($q) => $q->where('type', $type))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        $user = $request->user();
        $canManage = $user !== null && $user->isSystemAdmin();

        return view('media.galleries.show', [
            'gallery' => $gallery,
            'resources' => $resources,
            'keyword' => (string) $request->query('q', ''),
            'type' => $type,
            'types' => MediaType::cases(),
            'canManage' => $canManage,
        ]);
    }
}
