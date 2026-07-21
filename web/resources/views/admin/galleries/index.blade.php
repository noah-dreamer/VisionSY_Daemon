@extends('layouts.admin')
@section('title', '图库管理 · 管理后台 · VisionSY')
@section('page-title', '图库管理')

@section('content')
<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <form method="GET" action="{{ route('admin.galleries.index') }}" class="flex items-center gap-2">
        <select name="source_id" class="form-input w-auto py-2 text-sm" aria-label="按存储源筛选" onchange="this.form.submit()">
            <option value="">全部存储源</option>
            @foreach($sources as $s)
                <option value="{{ $s->id }}" @selected(request('source_id') == $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('admin.galleries.create') }}" class="btn-primary shrink-0">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        创建图库
    </a>
</div>

@if($galleries->isEmpty())
    <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-100 text-ink-400">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0113.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 0113.182 0L2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21Z"/></svg>
        </div>
        <p class="text-sm text-ink-500">还没有图库。请先创建存储源并执行扫描，或手动创建图库。</p>
    </div>
@else
    <div class="overflow-x-auto rounded-2xl border border-ink-100 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-ink-100 bg-ink-50/70">
                <tr>
                    <th class="px-5 py-3.5 font-semibold text-ink-600">名称</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden sm:table-cell">存储源</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden lg:table-cell">路径</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden md:table-cell">素材数</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden sm:table-cell">扫描状态</th>
                    <th class="px-5 py-3.5 text-right font-semibold text-ink-600">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach($galleries as $gallery)
                <tr class="hover:bg-ink-50/50">
                    <td class="px-5 py-4">
                        <p class="font-semibold text-ink-900">{{ $gallery->name }}</p>
                        @if($gallery->event_started_at)
                            <p class="mt-0.5 text-xs text-ink-400">{{ $gallery->event_started_at->format('Y-m-d') }}
                                @if($gallery->event_ended_at) ~ {{ $gallery->event_ended_at->format('Y-m-d') }} @endif</p>
                        @endif
                    </td>
                    <td class="px-5 py-4 hidden sm:table-cell text-ink-600">{{ $gallery->storageSource?->name }}</td>
                    <td class="px-5 py-4 hidden lg:table-cell">
                        <code class="rounded bg-ink-100 px-1.5 py-0.5 text-xs font-mono text-ink-600">{{ $gallery->relative_path }}</code>
                    </td>
                    <td class="px-5 py-4 hidden md:table-cell text-ink-900">{{ $gallery->media_resources_count }}</td>
                    <td class="px-5 py-4 hidden sm:table-cell">
                        <span class="text-xs {{ $gallery->scan_status === 'completed' ? 'text-emerald-600' : ($gallery->scan_status === 'scanning' ? 'text-amber-600' : ($gallery->scan_status === 'failed' ? 'text-rose-600' : 'text-ink-400')) }}">
                            {{ \App\Enums\ScanStatus::tryFrom($gallery->scan_status)?->label() ?? $gallery->scan_status }}
                        </span>
                    </td>
                    <td class="px-5 py-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <form method="POST" action="{{ route('admin.galleries.scan', $gallery) }}" class="inline">
                                @csrf
                                <button type="submit" class="rounded-lg px-2.5 py-1.5 text-xs text-ink-500 hover:bg-ink-100 hover:text-ink-700" title="扫描">扫描</button>
                            </form>
                            <a href="{{ route('admin.galleries.edit', $gallery) }}" class="rounded-lg px-2.5 py-1.5 text-xs text-ink-500 hover:bg-ink-100 hover:text-ink-700">编辑</a>
                            <form method="POST" action="{{ route('admin.galleries.destroy', $gallery) }}" class="inline" onsubmit="return confirm('确定删除图库「{{ $gallery->name }}」？')">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-lg px-2.5 py-1.5 text-xs text-rose-600 hover:bg-rose-50">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $galleries->links() }}</div>
@endif
@endsection
