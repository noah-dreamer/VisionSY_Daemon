@extends('layouts.app')

@section('title', $gallery->name.' · 图库 · VisionSY')

@section('content')
<section class="shell py-6 sm:py-10">
    <div class="mx-auto max-w-6xl space-y-8">
        <div>
            <a href="{{ route('media.galleries.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm text-ink-400 hover:text-ink-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                返回图库列表
            </a>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="h-page text-ink-950">{{ $gallery->name }}</h1>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-ink-500">
                        @if($gallery->event_started_at)
                            <span>{{ $gallery->event_started_at->format('Y-m-d') }}
                                @if($gallery->event_ended_at) ~ {{ $gallery->event_ended_at->format('Y-m-d') }} @endif</span>
                        @endif
                        <span>{{ $resources->total() }} 个素材</span>
                        @if($gallery->storageSource)
                            <span class="text-ink-300">{{ $gallery->storageSource->name }}</span>
                        @endif
                    </div>
                    @if($gallery->description)
                        <p class="mt-2 prose-muted max-w-xl">{{ $gallery->description }}</p>
                    @endif
                    @if($gallery->tagList())
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach($gallery->tagList() as $tag)
                                <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs text-ink-600">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
                @auth
                    @if($canManage)
                        <form method="POST" action="{{ route('admin.galleries.scan', $gallery) }}" class="shrink-0">
                            @csrf
                            <button type="submit" class="btn-secondary text-sm">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                扫描图库
                            </button>
                        </form>
                    @endif
                @endauth
            </div>
        </div>

        <form method="GET" action="{{ route('media.galleries.show', $gallery) }}" class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/></svg>
                <input type="search" name="q" value="{{ $keyword }}" placeholder="搜索标题、文件名或相机…"
                       class="form-input pl-11" aria-label="搜索素材">
            </div>
            <div class="flex items-center gap-2">
                <select name="type" class="form-input w-auto py-3" aria-label="素材类型">
                    <option value="">全部类型</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}" @selected($type === $t->value)>{{ $t->label() }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn-secondary">检索</button>
            </div>
        </form>

        @if ($resources->isEmpty())
            <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-100 text-ink-400">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                </div>
                <p class="text-sm text-ink-500">此图库中暂无素材。请尝试执行扫描。</p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($resources as $resource)
                    <a href="{{ route('media.show', $resource) }}" class="card card-hover group overflow-hidden">
                        <div class="relative aspect-[4/3] overflow-hidden bg-ink-100">
                            @if ($resource->has_thumb)
                                <img src="{{ route('media.thumb', $resource) }}" alt="{{ $resource->title }}"
                                     loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center gap-2 text-ink-400">
                                    <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
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
                            @if($resource->is_raw)
                                <span class="absolute bottom-2.5 left-2.5 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">RAW</span>
                            @endif
                        </div>
                        <div class="p-4">
                            <h3 class="truncate text-sm font-semibold text-ink-950">{{ $resource->title }}</h3>
                            <p class="mt-1 truncate text-xs text-ink-400">
                                @if($resource->taken_at){{ $resource->taken_at->format('Y-m-d') }} · @endif{{ $resource->humanSize() }}
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
            <div>{{ $resources->links() }}</div>
        @endif
    </div>
</section>
@endsection
