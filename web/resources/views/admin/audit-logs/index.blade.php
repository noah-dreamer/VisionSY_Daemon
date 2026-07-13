@extends('layouts.admin')

@section('title', '审计日志 · 管理后台')
@section('page-title', '审计日志')

@section('content')
    <div class="card p-4">
        <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <input type="search" name="action" value="{{ $filters['action'] ?? '' }}" class="form-input sm:max-w-xs" placeholder="按动作筛选，例如 admin.user">
            <input type="search" name="actor" value="{{ $filters['actor'] ?? '' }}" class="form-input sm:max-w-xs" placeholder="按操作者邮箱筛选">
            <button type="submit" class="btn-secondary shrink-0">筛选</button>
        </form>
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-ink-100">
            <thead class="bg-ink-50/70">
                <tr>
                    <th class="table-head">时间</th>
                    <th class="table-head">动作</th>
                    <th class="table-head">操作者</th>
                    <th class="table-head hidden md:table-cell">对象</th>
                    <th class="table-head hidden md:table-cell">IP</th>
                    <th class="table-head hidden lg:table-cell">详情</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($logs as $log)
                    <tr class="align-top transition hover:bg-ink-50/60">
                        <td class="table-cell whitespace-nowrap text-xs tabular-nums text-ink-500">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="table-cell"><span class="badge-gray font-mono">{{ $log->action }}</span></td>
                        <td class="table-cell text-xs">{{ $log->actor_email ?? '系统' }}</td>
                        <td class="table-cell hidden text-xs text-ink-500 md:table-cell">
                            {{ $log->target_type }}@if($log->target_id)#{{ $log->target_id }}@endif
                        </td>
                        <td class="table-cell hidden font-mono text-xs md:table-cell">{{ $log->ip ?? '—' }}</td>
                        <td class="table-cell hidden lg:table-cell">
                            @if ($log->context)
                                <code class="block max-w-md break-all rounded-lg bg-ink-50 px-2 py-1 text-xs text-ink-600">{{ json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-ink-400">没有符合条件的日志</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>
@endsection
