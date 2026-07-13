@extends('layouts.admin')

@section('title', '新建用户组 · 管理后台')
@section('page-title', '新建用户组')

@section('content')
    <div class="card max-w-3xl p-7">
        <form method="POST" action="{{ route('admin.user-groups.store') }}">
            @include('admin.user-groups._form', ['group' => null])
            <div class="mt-7 flex gap-3">
                <button type="submit" class="btn-primary">创建用户组</button>
                <a href="{{ route('admin.user-groups.index') }}" class="btn-secondary">取消</a>
            </div>
        </form>
    </div>
@endsection
