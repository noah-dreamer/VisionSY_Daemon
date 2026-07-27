@extends('layouts.settings')

@section('title', '新建用户组 · 管理后台')
@section('page-title', '新建用户组')

@section('settings-content')
    <div class="card max-w-3xl p-7">
        <form method="POST" action="{{ route('admin.user-groups.store') }}">
            @include('admin.user-groups._form', ['group' => null])
            <div class="sticky bottom-0 z-10 -mx-7 -mb-7 mt-7 flex flex-wrap items-center gap-3 rounded-b-3xl border-t border-ink-100 bg-white/95 px-7 py-4 backdrop-blur">
                <button type="submit" class="btn-primary">创建用户组</button>
                <a href="{{ route('admin.user-groups.index') }}" class="btn-secondary">取消</a>
            </div>
        </form>
    </div>
@endsection
