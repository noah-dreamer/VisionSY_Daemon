@extends('layouts.settings')

@section('title', '修改密码 · VisionSY')

@section('page-title', '修改密码')

@section('settings-content')
<div class="grid w-full items-start gap-6 lg:grid-cols-2">
    {{-- 修改密码表单 --}}
    <div class="card p-6 sm:p-8">
        <h2 class="h-card">修改密码</h2>

        <form
            method="POST"
            action="{{ route('settings.password.update') }}"
            class="mt-5 space-y-5"
        >
            @csrf
            @method('PUT')

            <div>
                <label for="current_password" class="form-label">
                    当前密码
                </label>

                <input
                    type="password"
                    name="current_password"
                    id="current_password"
                    required
                    autocomplete="current-password"
                    class="form-input mt-1 w-full
                           @error('current_password')
                               border-red-300 bg-red-50/30 focus:border-red-500 focus:ring-red-500/20
                           @enderror"
                    @error('current_password')
                        aria-invalid="true"
                        aria-describedby="current-password-error"
                    @enderror
                >

                @error('current_password')
                    <p
                        id="current-password-error"
                        class="form-error"
                        role="alert"
                    >
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label for="password" class="form-label">
                    新密码
                </label>

                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    autocomplete="new-password"
                    class="form-input mt-1 w-full
                           @error('password')
                               border-red-300 bg-red-50/30 focus:border-red-500 focus:ring-red-500/20
                           @enderror"
                    @error('password')
                        aria-invalid="true"
                        aria-describedby="password-error"
                    @enderror
                >

                @error('password')
                    <p
                        id="password-error"
                        class="form-error"
                        role="alert"
                    >
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="form-label">
                    确认新密码
                </label>

                <input
                    type="password"
                    name="password_confirmation"
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    class="form-input mt-1 w-full"
                >
            </div>

            <div class="pt-1">
                <button type="submit" class="btn-primary text-sm">
                    更新密码
                </button>
            </div>
        </form>
    </div>

    {{-- 安全提示 --}}
    <aside class="card p-6 sm:p-8">
        <h2 class="h-card">安全提示</h2>

        <ul class="mt-5 space-y-4 text-sm leading-relaxed text-ink-600">
            <li class="flex gap-3">
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0 text-brand-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m4.5 12.75 6 6 9-13.5"
                    />
                </svg>

                <span>
                    使用至少 8 位字符，混合大小写字母、数字和符号
                </span>
            </li>

            <li class="flex gap-3">
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0 text-brand-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m4.5 12.75 6 6 9-13.5"
                    />
                </svg>

                <span>
                    避免使用与个人信息相关的密码（生日、姓名拼音等）
                </span>
            </li>

            <li class="flex gap-3">
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0 text-brand-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m4.5 12.75 6 6 9-13.5"
                    />
                </svg>

                <span>
                    不要与其他网站共用同一密码
                </span>
            </li>

            <li class="flex gap-3">
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0 text-brand-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m4.5 12.75 6 6 9-13.5"
                    />
                </svg>

                <span>
                    定期更换密码有助于保护账户安全
                </span>
            </li>
        </ul>
    </aside>
</div>
@endsection
