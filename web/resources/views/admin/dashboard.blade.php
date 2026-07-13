@extends('layouts.admin')

@section('title', '概览 · 管理后台')
@section('page-title', '概览')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat-card label="用户总数" :value="$stats['total_users']" />
        <x-stat-card label="已激活" :value="$stats['active_users']" tone="text-emerald-600" />
        <x-stat-card label="待激活" :value="$stats['pending_activation']" tone="text-amber-600" />
        <x-stat-card label="已禁用" :value="$stats['disabled_users']" tone="text-red-600" />
        <x-stat-card label="启用网段" :value="$stats['campus_ranges']" tone="text-brand-600" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- 最近审计日志 --}}
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between border-b border-ink-100 px-6 py-4">
                <h2 class="text-sm font-semibold text-ink-900">最近审计日志</h2>
                <a href="{{ route('admin.audit-logs.index') }}" class="text-sm font-medium text-brand-600 transition hover:text-brand-700">查看全部</a>
            </div>
            <ul class="divide-y divide-ink-100">
                @forelse ($recentLogs as $log)
                    <li class="flex flex-col gap-1 px-6 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <span class="badge-gray font-mono">{{ $log->action }}</span>
                            <span class="ml-2 text-sm text-ink-600">{{ $log->actor_email ?? '系统' }}</span>
                        </div>
                        <time class="shrink-0 text-xs tabular-nums text-ink-400">{{ $log->created_at->format('Y-m-d H:i:s') }}</time>
                    </li>
                @empty
                    <li class="px-6 py-10 text-center text-sm text-ink-400">暂无审计记录</li>
                @endforelse
            </ul>
        </div>

        {{-- 快捷操作 --}}
        <div class="card p-6">
            <h2 class="text-sm font-semibold text-ink-900">快捷操作</h2>
            <div class="mt-4 space-y-2">
                <a href="{{ route('admin.users.create') }}" class="flex items-center justify-between rounded-xl border border-ink-100 px-4 py-3 text-sm font-medium text-ink-800 transition hover:border-ink-200 hover:bg-ink-50">
                    创建实名账号
                    <svg class="h-4 w-4 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
                <a href="{{ route('admin.campus-ips.index') }}" class="flex items-center justify-between rounded-xl border border-ink-100 px-4 py-3 text-sm font-medium text-ink-800 transition hover:border-ink-200 hover:bg-ink-50">
                    管理校园出口 IP
                    <svg class="h-4 w-4 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
                <a href="{{ route('admin.oauth-clients.index') }}" class="flex items-center justify-between rounded-xl border border-ink-100 px-4 py-3 text-sm font-medium text-ink-800 transition hover:border-ink-200 hover:bg-ink-50">
                    OAuth 客户端
                    <svg class="h-4 w-4 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>
@endsection
