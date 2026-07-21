@extends('layouts.app')

@section('title', '图库 · VisionSY')

@section('content')
<section class="shell py-6 sm:py-10">
    <div class="mx-auto max-w-6xl space-y-8">
        <div>
            <p class="meta-label mb-2">CAMPUS MEDIA LIBRARY</p>
            <h1 class="h-page text-ink-950">图库</h1>
            <p class="mt-2 prose-muted">按活动浏览照片与视频，选择图库查看全部素材。</p>
        </div>

        @if($galleries->isEmpty())
            <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-100 text-ink-400">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0113.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 0113.182 0L2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21Z"/></svg>
                </div>
                <p class="text-sm text-ink-500">图库还是空的，请等待管理员创建。</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($galleries as $gallery)
                    <a href="{{ route('media.galleries.show', $gallery) }}"
                       class="card card-hover group overflow-hidden">
                        <div class="relative aspect-[4/3] overflow-hidden bg-ink-100">
                            @if($gallery->coverResource && $gallery->coverResource->has_thumb)
                                <img src="{{ route('media.thumb', $gallery->coverResource) }}"
                                     alt="{{ $gallery->name }}"
                                     loading="lazy"
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
                            @if($gallery->scan_status !== 'completed')
                                <span class="absolute right-2.5 top-2.5 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 uppercase">
                                    {{ \App\Enums\ScanStatus::tryFrom($gallery->scan_status)?->label() ?? '待扫描' }}
                                </span>
                            @endif
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
    </div>
</section>
@endsection
