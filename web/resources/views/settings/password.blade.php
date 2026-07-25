@extends('layouts.settings')

@section('title', '修改密码 · VisionSY')

@section('page-title', '修改密码')

@section('settings-content')
<div class="max-w-xl">
    <div class="card p-6 sm:p-8">
        <h2 class="h-card">修改密码</h2>
        <form method="POST" action="{{ route('settings.password.update') }}" class="mt-4 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="current_password" class="form-label">当前密码</label>
                <input type="password" name="current_password" id="current_password" required autocomplete="current-password" class="form-input mt-1 w-full">
                @error('current_password')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password" class="form-label">新密码</label>
                <input type="password" name="password" id="password" required autocomplete="new-password" class="form-input mt-1 w-full">
                @error('password')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password_confirmation" class="form-label">确认新密码</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password" class="form-input mt-1 w-full">
            </div>
            <button type="submit" class="btn-primary text-sm">更新密码</button>
        </form>
    </div>
</div>
@endsection
