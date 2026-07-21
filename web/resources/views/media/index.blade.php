@extends('layouts.app')

@section('title', '媒体库 · VisionSY')

@section('content')
    <section class="shell py-6 sm:py-10">
        <div class="mx-auto max-w-6xl space-y-8">

            {{-- 页头 --}}
            <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="meta-label mb-2">CAMPUS MEDIA LIBRARY</p>
                    <h1 class="h-page text-ink-950">媒体库</h1>
                    <p class="mt-2 prose-muted">
                        统一归档、分级取用。列表与预览均为带水印压缩图，原图按角色权限开放下载。
                    </p>
                </div>
                @auth
                    @can('create', App\Models\MediaResource::class)
                        <a href="{{ route('media.create') }}" class="btn-primary shrink-0">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            上传素材
                        </a>
                    @endcan
                @endauth
            </div>

            {{-- 视图切换 --}}
            <div class="flex items-center gap-3">
                <a href="{{ route('media.index', ['view' => 'galleries'] + request()->except('view', 'page')) }}"
                   class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $viewMode === 'galleries' ? 'bg-brand-600 text-white shadow-sm' : 'bg-ink-100 text-ink-600 hover:bg-ink-200' }}">
                    <svg class="mr-1.5 inline-block h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0113.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 0113.182 0L2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21Z"/></svg>
                    图库模式
                </a>
                <a href="{{ route('media.index', ['view' => 'photos'] + request()->except('view', 'page')) }}"
                   class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $viewMode === 'photos' ? 'bg-brand-600 text-white shadow-sm' : 'bg-ink-100 text-ink-600 hover:bg-ink-200' }}">
                    <svg class="mr-1.5 inline-block h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                    全部照片
                </a>
            </div>

            @if($viewMode === 'galleries')
                {{-- 图库模式 --}}
                @if($galleries->isEmpty())
                    <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-100 text-ink-400">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0113.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 0113.182 0L2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21Z"/></svg>
                        </div>
                        <p class="text-sm text-ink-500">图库还是空的，请等待管理员创建图库并执行扫描。</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($galleries as $gallery)
                            <a href="{{ route('media.galleries.show', $gallery) }}"
                               class="card card-hover group overflow-hidden">
                                <div class="relative aspect-[4/3] overflow-hidden bg-ink-100">
                                    @if($gallery->coverResource && $gallery->coverResource->has_thumb)
                                        <img src="{{ route('media.thumb', $gallery->coverResource) }}"
                                             alt="{{ $gallery->name }}" loading="lazy"
                                             class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                    @else
                                        <div class="flex h-full w-full flex-col items-center justify-center gap-2 text-ink-300">
                                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0113.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 0113.182 0L2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21Z"/></svg>
                                            <span class="font-meta text-xs uppercase tracking-widest text-ink-400">无封面</span>
                                        </div>
                                    @endif
                                    <span class="absolute left-2.5 top-2.5 badge-blue">
                                        {{ $gallery->media_resources_count }} 张
                                    </span>
                                </div>
                                <div class="p-4">
                                    <h3 class="text-sm font-semibold text-ink-950">{{ $gallery->name }}</h3>
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        @if($gallery->event_started_at)
                                            <span class="text-xs text-ink-400">{{ $gallery->event_started_at->format('Y-m-d') }}
                                                @if($gallery->event_ended_at) ~ {{ $gallery->event_ended_at->format('Y-m-d') }} @endif</span>
                                        @endif
                                        @if($gallery->storageSource)
                                            <span class="text-xs text-ink-300">· {{ $gallery->storageSource->name }}</span>
                                        @endif
                                    </div>
                                    @if($gallery->tagList())
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            @foreach($gallery->tagList() as $tag)
                                                <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[10px] text-ink-500">{{ $tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div>{{ $galleries->links() }}</div>
                @endif
            @else
                {{-- 全部照片模式 --}}
                <form method="GET" action="{{ route('media.index') }}" class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <input type="hidden" name="view" value="photos">
                    @if($showingArchived ?? false)<input type="hidden" name="status" value="archived">@endif
                    <div class="relative flex-1">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/></svg>
                        <input type="search" name="q" value="{{ $keyword }}" placeholder="搜索标题、描述、标签或文件名…"
                               class="form-input pl-11" aria-label="搜索素材">
                    </div>
                    <div class="flex items-center gap-2">
                        <select name="type" class="form-input w-auto py-3" aria-label="素材类型">
                            <option value="">全部类型</option>
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}" @selected(($type ?? '') === $t->value)>{{ $t->label() }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn-secondary">检索</button>
                    </div>
                </form>

                @auth
                    @if (auth()->user()->isSystemAdmin())
                        <div class="flex items-center gap-3 text-sm">
                            <a href="{{ route('media.index', ['view' => 'photos']) }}"
                               class="{{ ($showingArchived ?? false) ? 'text-ink-500 hover:text-ink-900' : 'font-semibold text-brand-600' }}">正常素材</a>
                            <span class="text-ink-200">|</span>
                            <a href="{{ route('media.index', ['view' => 'photos', 'status' => 'archived']) }}"
                               class="{{ ($showingArchived ?? false) ? 'font-semibold text-brand-600' : 'text-ink-500 hover:text-ink-900' }}">已归档</a>
                        </div>
                    @endif
                @endauth

                @if ($resources->isEmpty())
                    <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-100 text-ink-400">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                        </div>
                        <p class="text-sm text-ink-500">
                            {{ $keyword !== '' ? '没有匹配「'.$keyword.'」的素材。' : '媒体库还是空的。' }}
                        </p>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($resources as $resource)
                            <a href="{{ route('media.show', $resource) }}"
                               class="card card-hover group overflow-hidden">
                                <div class="relative aspect-[4/3] overflow-hidden bg-ink-100">
                                    @if ($resource->has_thumb)
                                        <img src="{{ route('media.thumb', $resource) }}" alt="{{ $resource->title }}"
                                             loading="lazy"
                                             class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                    @else
                                        <div class="flex h-full w-full flex-col items-center justify-center gap-2 text-ink-400">
                                            <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                                @if ($resource->type->value === 'video')
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/>
                                                @else
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                                                @endif
                                            </svg>
                                            <span class="font-meta text-[10px] uppercase tracking-widest">.{{ $resource->original_ext }}</span>
                                        </div>
                                    @endif
                                    <span class="absolute left-2.5 top-2.5 badge-blue">{{ $resource->type->label() }}</span>
                                    @if ($resource->status->value === 'archived')
                                        <span class="absolute right-2.5 top-2.5 badge-gray">已归档</span>
                                    @endif
                                </div>
                                <div class="p-4">
                                    <h3 class="truncate text-sm font-semibold text-ink-950">{{ $resource->title }}</h3>
                                    <p class="mt-1 truncate text-xs text-ink-400">
                                        {{ $resource->uploader?->display_name ?? '—' }} · {{ $resource->created_at->format('Y-m-d') }} · {{ $resource->humanSize() }}
                                    </p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div>{{ $resources->links() }}</div>
                @endif

                @guest
                    <div class="alert-info">
                        <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                        <span>当前为游客模式，仅可浏览缩略图。<a href="{{ route('login') }}" class="font-medium text-brand-700 underline underline-offset-2">登录</a>后可查看大图预览并按权限下载素材。</span>
                    </div>
                @endguest
            @endif
        </div>
    </section>
@endsection
