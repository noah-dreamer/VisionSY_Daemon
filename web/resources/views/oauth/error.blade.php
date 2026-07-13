@extends('layouts.auth')

@section('title', '授权失败 · VisionSY')

@section('content')
    <div class="card p-8 text-center">
        <div class="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50 text-red-500 ring-1 ring-inset ring-red-100">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
        </div>

        <h1 class="text-xl font-semibold tracking-tight text-ink-950">无法完成授权</h1>
        <p class="mt-2.5 prose-muted">{{ $message }}</p>

        <div class="mt-7 flex flex-col gap-3">
            <a href="{{ route('dashboard') }}" class="btn-primary w-full">返回控制台</a>
            <a href="{{ route('home') }}" class="btn-ghost w-full">回到首页</a>
        </div>
    </div>
@endsection
