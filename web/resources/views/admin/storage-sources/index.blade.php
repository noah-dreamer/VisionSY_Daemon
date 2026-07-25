@extends('layouts.settings')
@section('title', '存储源 · 管理后台 · VisionSY')
@section('page-title', '存储源管理')

@section('content')
<div class="flex items-center justify-between">
    <p class="text-sm text-ink-500">管理媒体文件的物理存储位置。当前仅支持宿主机已挂载的本地 / SMB / NFS 路径。</p>
    <a href="{{ route('admin.storage-sources.create') }}" class="btn-primary shrink-0">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        新增存储源
    </a>
</div>

@if($sources->isEmpty())
    <div class="card flex flex-col items-center gap-3 px-6 py-16 text-center">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-100 text-ink-400">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3m-19.5 0a4.5 4.5 0 0 1 .9-2.7L5.737 5.1a3.375 3.375 0 0 1 2.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 0 1 .9 2.7m0 0a3 3 0 0 1-3 3m0 3h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Zm-3 6h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Z"/></svg>
        </div>
        <p class="text-sm text-ink-500">还没有配置存储源。</p>
    </div>
@else
    <div class="overflow-x-auto rounded-2xl border border-ink-100 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-ink-100 bg-ink-50/70">
                <tr>
                    <th class="px-5 py-3.5 font-semibold text-ink-600">名称</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden sm:table-cell">类型</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden lg:table-cell">根路径</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden md:table-cell">图库数</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden md:table-cell">素材数</th>
                    <th class="px-5 py-3.5 font-semibold text-ink-600 hidden sm:table-cell">状态</th>
                    <th class="px-5 py-3.5 text-right font-semibold text-ink-600">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach($sources as $source)
                <tr class="hover:bg-ink-50/50">
                    <td class="px-5 py-4">
                        <p class="font-semibold text-ink-900">{{ $source->name }}</p>
                        <p class="mt-0.5 text-xs text-ink-400">创建于 {{ $source->created_at->format('Y-m-d') }}</p>
                    </td>
                    <td class="px-5 py-4 hidden sm:table-cell">
                        <span class="badge-blue">{{ $source->driver->label() }}</span>
                    </td>
                    <td class="px-5 py-4 hidden lg:table-cell">
                        <code class="rounded bg-ink-100 px-1.5 py-0.5 text-xs font-mono text-ink-600 break-all">{{ $source->root_path }}</code>
                    </td>
                    <td class="px-5 py-4 hidden md:table-cell text-ink-900">{{ $source->galleries_count }}</td>
                    <td class="px-5 py-4 hidden md:table-cell text-ink-900">{{ $source->media_resources_count }}</td>
                    <td class="px-5 py-4 hidden sm:table-cell">
                        <span class="text-xs {{ $source->is_enabled ? 'text-emerald-600' : 'text-ink-400' }}">
                            {{ $source->is_enabled ? '启用' : '停用' }}
                            @if($source->is_read_only) · 只读 @endif
                        </span>
                        @if($source->last_scan_status)
                            <span class="ml-1 text-xs {{ $source->last_scan_status === 'completed' ? 'text-emerald-600' : ($source->last_scan_status === 'scanning' ? 'text-amber-600' : 'text-rose-600') }}">
                                · {{ $source->getScanStatusEnum()?->label() ?? $source->last_scan_status }}
                            </span>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <form method="POST" action="{{ route('admin.storage-sources.verify', $source) }}" class="inline">
                                @csrf
                                <button type="submit" class="rounded-lg px-2.5 py-1.5 text-xs text-ink-500 hover:bg-ink-100 hover:text-ink-700" title="验证路径">验证</button>
                            </form>
                            <form method="POST" action="{{ route('admin.storage-sources.scan', $source) }}" class="inline">
                                @csrf
                                <button type="submit" class="rounded-lg px-2.5 py-1.5 text-xs text-ink-500 hover:bg-ink-100 hover:text-ink-700" title="扫描">扫描</button>
                            </form>
                            <a href="{{ route('admin.storage-sources.edit', $source) }}" class="rounded-lg px-2.5 py-1.5 text-xs text-ink-500 hover:bg-ink-100 hover:text-ink-700">编辑</a>
                            <form method="POST" action="{{ route('admin.storage-sources.destroy', $source) }}" class="inline" onsubmit="return confirm('确定删除存储源「{{ $source->name }}」？')">
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
@endif

<div class="alert-info mt-6">
    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
    <span>SMB/NFS 类型的存储源需要在宿主机上预先挂载好网络共享目录，再在此处注册路径。VisionSY 不会执行 mount/umount 操作。</span>
</div>
@endsection
