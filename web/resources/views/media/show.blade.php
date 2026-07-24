@extends('layouts.app')

@section('title', $resource->title.' · 媒体库 · VisionSY')

@push('head')
    <meta name="intranet-probe-url" content="{{ config('visionsy.intranet_probe_url') }}">
@endpush

@section('content')
    <section class="shell py-12 sm:py-16">
        <div class="mx-auto max-w-5xl space-y-6">

            <a href="{{ route('media.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12l7.5-7.5M3 12h18"/></svg>
                返回媒体库
            </a>

            <div class="card overflow-hidden">
                {{-- 预览区 --}}
                <div class="relative flex items-center justify-center bg-ink-950/95 p-4 sm:p-6" data-nav-tone="dark">
                    @if ($canPreview && $resource->has_preview)
                        <img src="{{ route('media.preview', $resource) }}" alt="{{ $resource->title }}"
                             class="max-h-[70vh] w-auto max-w-full rounded-xl shadow-lift">
                    @elseif ($resource->has_thumb)
                        <div class="flex flex-col items-center gap-4 py-6">
                            <img src="{{ route('media.thumb', $resource) }}" alt="{{ $resource->title }}"
                                 class="max-h-[40vh] w-auto max-w-full rounded-xl opacity-90 shadow-lift">
                            <p class="text-sm text-white/60">登录后可查看高清带水印预览。</p>
                        </div>
                    @else
                        <div class="flex flex-col items-center gap-3 py-16 text-white/50">
                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                            <p class="text-sm">该类型素材暂无网页预览（.{{ $resource->original_ext }}）</p>
                        </div>
                    @endif
                </div>

                <div class="space-y-6 px-6 py-7 sm:px-8">
                    {{-- 标题行 --}}
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl font-semibold tracking-tight text-ink-950">{{ $resource->title }}</h1>
                                <x-badge tone="blue">{{ $resource->type->label() }}</x-badge>
                                @if ($resource->status->value === 'archived')
                                    <x-badge tone="gray">已归档</x-badge>
                                @endif
                            </div>
                            @if ($resource->description)
                                <p class="mt-2 prose-muted">{{ $resource->description }}</p>
                            @endif
                            @if ($resource->tagList())
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @foreach ($resource->tagList() as $tag)
                                        <a href="{{ route('media.index', ['q' => $tag]) }}" class="badge-gray transition hover:bg-ink-200">{{ $tag }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- 下载按钮：按用户组分级 --}}
                        <div class="flex shrink-0 flex-col gap-2">
                            @auth
                                @if ($canDownloadOriginal)
                                    <a href="{{ route('media.download.original', $resource) }}" class="btn-primary intranet-download-link">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                        下载无水印原图
                                    </a>
                                @endif
                                @if ($canDownloadWatermarked && $resource->has_preview)
                                    <a href="{{ route('media.download.watermarked', $resource) }}" class="btn-secondary intranet-download-link">下载带水印压缩图</a>
                                @endif
                                @if (! $canDownloadOriginal && ! $canDownloadWatermarked)
                                    <div class="alert-warning max-w-xs">
                                        <span class="text-xs">账号待激活或无下载权限。请在校园网环境登录一次完成激活。</span>
                                    </div>
                                @endif
                            @else
                                <a href="{{ route('login') }}" class="btn-primary">登录后下载</a>
                            @endauth
                        </div>
                    </div>

                    {{-- 元数据 --}}
                    <dl class="grid gap-4 rounded-xl border border-ink-100 bg-ink-50/60 p-5 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">文件</dt>
                            <dd class="mt-1 truncate font-mono text-sm text-ink-900" title="{{ $resource->original_name }}">{{ $resource->original_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">大小 / 尺寸</dt>
                            <dd class="mt-1 text-sm text-ink-900">
                                {{ $resource->humanSize() }}
                                @if ($resource->width) · {{ $resource->width }}×{{ $resource->height }} @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">上传</dt>
                            <dd class="mt-1 text-sm text-ink-900">{{ $resource->uploader?->display_name ?? '—' }} · {{ $resource->created_at->format('Y-m-d H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">SHA-256</dt>
                            <dd class="mt-1 truncate font-mono text-xs text-ink-700" title="{{ $resource->checksum_sha256 }}">{{ $resource->checksum_sha256 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">原图下载次数</dt>
                            <dd class="mt-1 text-sm text-ink-900">{{ $resource->download_count_original }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">水印图下载次数</dt>
                            <dd class="mt-1 text-sm text-ink-900">{{ $resource->download_count_watermarked }}</dd>
                        </div>
                        @if ($resource->taken_at)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">拍摄时间</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ $resource->taken_at->format('Y-m-d H:i:s') }}</dd>
                            </div>
                        @endif
                        @if ($resource->camera)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">拍摄设备</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ $resource->camera }}</dd>
                            </div>
                        @endif
                        @if ($resource->duration_seconds)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">时长</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ gmdate($resource->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', (int) $resource->duration_seconds) }}</dd>
                            </div>
                        @endif
                    </dl>

                    {{-- 全部内嵌元数据（上传时用 exiftool / exif / getID3 读取的全部标签） --}}
                    @if ($resource->metadata)
                        <details class="group rounded-xl border border-ink-100 bg-ink-50/60">
                            <summary class="flex cursor-pointer items-center justify-between px-5 py-3.5 text-sm font-medium text-ink-700 transition hover:text-ink-950">
                                全部元数据（{{ collect($resource->metadata)->map(fn ($t) => is_array($t) ? count($t) : 1)->sum() }} 个标签）
                                <svg class="h-4 w-4 text-ink-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                            </summary>
                            <div class="max-h-96 overflow-y-auto border-t border-ink-100 px-5 py-4">
                                @foreach ($resource->metadata as $section => $tags)
                                    @continue(! is_array($tags))
                                    <p class="meta-label {{ $loop->first ? '' : 'mt-4' }}">{{ $section }}</p>
                                    <table class="mt-1.5 w-full text-sm">
                                        <tbody class="divide-y divide-ink-100/80">
                                            @foreach ($tags as $name => $value)
                                                <tr>
                                                    <td class="w-2/5 py-1 pr-3 align-top font-mono text-xs text-ink-500">{{ $name }}</td>
                                                    <td class="break-all py-1 text-ink-800">{{ is_array($value) ? implode('; ', array_map('strval', $value)) : $value }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    {{-- 管理操作 --}}
                    @if ($canManage)
                        <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 pt-5">
                            <span class="mr-1 text-xs font-medium uppercase tracking-wider text-ink-400">管理</span>
                            <a href="{{ route('media.edit', $resource) }}" class="btn-secondary btn-sm">编辑信息</a>
                            <form method="POST" action="{{ route('media.toggle-archive', $resource) }}">
                                @csrf
                                <button type="submit" class="btn-secondary btn-sm">
                                    {{ $resource->status->value === 'archived' ? '恢复素材' : '归档素材' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('media.destroy', $resource) }}"
                                  onsubmit="return confirm('确定永久删除该素材及其所有派生文件？此操作不可恢复。')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger btn-sm">删除</button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
<script type="module">
import { probeIntranet } from '{{ Vite::asset('resources/js/intranet-probe.js') }}';

(async () => {
    const isCampus = await probeIntranet();
    const param = isCampus ? 'is_campus=1' : 'is_campus=0';
    document.querySelectorAll('.intranet-download-link').forEach(link => {
        const sep = link.href.includes('?') ? '&' : '?';
        link.href += sep + param;
    });
})();
</script>
@endpush
