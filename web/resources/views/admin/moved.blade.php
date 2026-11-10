@extends('layouts.app')
@section('title', '管理控制台 · VisionSY')
@section('content')
<div class="mx-auto max-w-xl px-6 py-32">
    <h1 class="text-2xl font-semibold">管理功能已迁移至独立控制台</h1>
    <p class="mt-4 text-ink-600">请打开 VisionSY Dashboard，连接此 daemon 后管理用户、图库、任务与数据库备份。媒体库继续在本网站使用。</p>
    @if ($dashboardUrl)
        <a class="mt-6 inline-block underline" href="{{ $dashboardUrl }}">打开管理控制台</a>
    @endif
</div>
@endsection
