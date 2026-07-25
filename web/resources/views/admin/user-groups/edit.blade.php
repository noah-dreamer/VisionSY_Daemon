@extends('layouts.settings')

@section('title', '编辑用户组 · 管理后台')
@section('page-title', '编辑用户组')

@section('settings-content')
    <div class="card max-w-3xl p-7">
        <form method="POST" action="{{ route('admin.user-groups.update', $group) }}">
            @method('PUT')
            @include('admin.user-groups._form')
            <div class="mt-7 flex gap-3">
                <button type="submit" class="btn-primary">保存修改</button>
                <a href="{{ route('admin.user-groups.index') }}" class="btn-secondary">返回</a>
            </div>
        </form>
    </div>
@endsection
