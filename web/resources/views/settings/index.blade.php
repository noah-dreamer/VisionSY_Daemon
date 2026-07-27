@extends('layouts.settings')

@section('title', '个人资料 · VisionSY')
@section('page-title', '个人资料')

@section('settings-content')
<div class="mx-auto w-full max-w-4xl" x-data="avatarUploader()">
    <section class="overflow-hidden rounded-[1.75rem] border border-ink-100 bg-white shadow-card">
        {{-- 账户身份与头像操作 --}}
        <div class="flex min-h-[10.5rem] flex-col gap-6 border-b border-ink-100 px-6 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-9">
            {{-- 左侧：头像、用户名、邮箱 --}}
            <div class="flex min-w-0 items-center gap-5 sm:gap-6">
                {{-- 头像只负责展示，不响应点击 --}}
                <div class="h-24 w-24 shrink-0 overflow-hidden rounded-full bg-ink-100 ring-4 ring-white shadow-md">
                    <img
                        x-show="previewUrl"
                        :src="previewUrl"
                        alt="新头像预览"
                        class="h-full w-full object-cover"
                        x-cloak
                    >

                    @if ($user->avatar_path)
                        <img
                            x-show="!previewUrl"
                            src="{{ Storage::disk('public')->url($user->avatar_path) }}"
                            alt="{{ $user->display_name }} 的头像"
                            class="h-full w-full object-cover"
                        >
                    @else
                        <div
                            x-show="!previewUrl"
                            class="brand-mark flex h-full w-full items-center justify-center rounded-full text-2xl"
                        >
                            {{ mb_substr($user->display_name, 0, 1) }}
                        </div>
                    @endif
                </div>

                <div class="min-w-0">
                    <h2 class="truncate text-xl font-semibold tracking-tight text-ink-900">
                        {{ $user->display_name }}
                    </h2>

                    <p class="mt-1.5 truncate text-sm text-ink-500">
                        {{ $user->email }}
                    </p>

                    <div class="mt-3 inline-flex items-center rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-600/15">
                        {{ $user->role->label() }}
                    </div>

                    <p
                        x-show="fileSelected"
                        x-cloak
                        class="mt-2 max-w-xs truncate text-xs text-brand-600"
                    >
                        已选择：
                        <span x-text="selectedFileName"></span>
                    </p>
                </div>
            </div>

            {{-- 右侧：头像操作与格式说明 --}}
            <form
                method="POST"
                action="{{ route('settings.avatar.update') }}"
                enctype="multipart/form-data"
                class="flex w-full shrink-0 flex-col items-start gap-3 sm:w-auto sm:items-end"
            >
                @csrf

                <input
                    type="file"
                    name="avatar"
                    accept="image/jpeg,image/png,image/webp"
                    class="hidden"
                    x-ref="fileInput"
                    @change="onFileSelected"
                >

                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <button
                        type="button"
                        class="btn-secondary btn-sm"
                        @click="$refs.fileInput.click()"
                    >
                        <svg
                            class="mr-1.5 h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"
                            />
                        </svg>
                        选择新头像
                    </button>

                    <template x-if="fileSelected">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-primary btn-sm">
                                保存头像
                            </button>

                            <button
                                type="button"
                                class="btn-ghost btn-sm"
                                @click="cancelFile"
                            >
                                取消
                            </button>
                        </div>
                    </template>
                </div>

                <p class="max-w-xs text-xs leading-relaxed text-ink-400 sm:text-right">
                    支持 JPG、PNG、WebP 图片格式。
                </p>

                @error('avatar')
                    <p class="form-error max-w-xs text-left sm:text-right" role="alert">
                        {{ $message }}
                    </p>
                @enderror
            </form>
        </div>

        {{-- 显示名称 --}}
        @php
            $displayNameHasError = $errors->has('display_name');
        @endphp

        <div
            class="min-h-[13rem] px-6 py-8 sm:px-8 sm:py-9"
            x-data="nameChecker(
                @js(old('display_name', $user->display_name)),
                @js($user->display_name),
                @js(route('settings.profile.check-name'))
            )"
        >
            <div>
                <h3 class="text-lg font-semibold tracking-tight text-ink-900">
                    修改显示名称
                </h3>

                <p class="mt-2 text-sm leading-relaxed text-ink-500">
                    该名称会显示在系统导航、审计记录和素材操作信息中，并且必须保持唯一。
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('settings.profile.update') }}"
                class="mt-6"
                @submit="if (checking || status === 'occupied') $event.preventDefault()"
            >
                @csrf
                @method('PATCH')

                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    <div class="min-w-0">
                        <label for="display_name" class="form-label">
                            显示名
                        </label>

                        <div class="relative">
                            <input
                                type="text"
                                name="display_name"
                                id="display_name"
                                value="{{ old('display_name', $user->display_name) }}"
                                x-model="name"
                                @input.debounce.500ms="checkName()"
                                maxlength="50"
                                required
                                autocomplete="off"
                                class="form-input pr-11
                                       {{ $displayNameHasError
                                           ? 'border-red-300 bg-red-50/30 focus:border-red-500 focus:ring-red-500/20'
                                           : '' }}"
                                :class="statusClass()"
                                @if ($displayNameHasError)
                                    aria-invalid="true"
                                    aria-describedby="display-name-error"
                                @endif
                            >

                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                                <svg
                                    x-show="checking"
                                    class="h-5 w-5 animate-spin text-ink-400"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                    x-cloak
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"
                                    />
                                </svg>

                                <svg
                                    x-show="!checking && status === 'available'"
                                    class="h-5 w-5 text-emerald-500"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    aria-hidden="true"
                                    x-cloak
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="m4.5 12.75 6 6 9-13.5"
                                    />
                                </svg>

                                <svg
                                    x-show="!checking && status === 'occupied'"
                                    class="h-5 w-5 text-red-500"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    aria-hidden="true"
                                    x-cloak
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M6 18 18 6M6 6l12 12"
                                    />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="btn-ghost btn-sm"
                            x-show="name !== original"
                            @click="resetName"
                            x-cloak
                        >
                            重置
                        </button>

                        <button
                            type="submit"
                            class="btn-primary btn-sm"
                            :disabled="
                                checking
                                || status === 'occupied'
                                || name.trim() === ''
                                || name === original
                            "
                        >
                            保存名称
                        </button>
                    </div>
                </div>

                @error('display_name')
                    <p id="display-name-error" class="form-error" role="alert">
                        {{ $message }}
                    </p>
                @enderror

                <p
                    x-show="!checking && status === 'available'"
                    class="mt-2 text-sm text-emerald-600"
                    x-cloak
                >
                    该名称可以使用。
                </p>

                <p
                    x-show="!checking && status === 'occupied'"
                    class="mt-2 text-sm text-red-600"
                    x-cloak
                >
                    该名称已被使用，请选择其他名称。
                </p>

                <div
                    x-show="!checking && status === 'occupied' && suggestions.length > 0"
                    x-cloak
                    class="mt-3 rounded-2xl border border-red-100 bg-red-50/30 px-4 py-3"
                >
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <p class="shrink-0 text-xs font-medium text-ink-500">
                            可用名称建议：
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <template
                                x-for="suggestion in suggestions"
                                :key="suggestion"
                            >
                                <button
                                    type="button"
                                    class="rounded-lg border border-ink-200 bg-white px-2.5 py-1 text-xs font-medium text-ink-700 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                                    @click="useSuggestion(suggestion)"
                                    x-text="suggestion"
                                ></button>
                            </template>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('avatarUploader', () => ({
        previewUrl: null,
        fileSelected: false,
        selectedFileName: '',

        onFileSelected(event) {
            const file = event.target.files?.[0];

            if (! file) {
                return;
            }

            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }

            this.previewUrl = URL.createObjectURL(file);
            this.selectedFileName = file.name;
            this.fileSelected = true;
        },

        cancelFile() {
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }

            this.previewUrl = null;
            this.fileSelected = false;
            this.selectedFileName = '';
            this.$refs.fileInput.value = '';
        }
    }));

    Alpine.data('nameChecker', (initialName, originalName, checkUrl) => ({
        name: initialName,
        original: originalName,
        status: 'idle',
        checking: false,
        suggestions: [],
        requestSequence: 0,

        async checkName() {
            const trimmed = this.name.trim();
            const sequence = ++this.requestSequence;

            if (! trimmed || trimmed === this.original) {
                this.checking = false;
                this.status = 'idle';
                this.suggestions = [];
                return;
            }

            this.checking = true;
            this.status = 'idle';
            this.suggestions = [];

            try {
                const response = await fetch(
                    `${checkUrl}?name=${encodeURIComponent(trimmed)}`,
                    {
                        headers: {
                            'Accept': 'application/json'
                        }
                    }
                );

                if (! response.ok) {
                    throw new Error('Name check failed');
                }

                const data = await response.json();

                if (sequence !== this.requestSequence) {
                    return;
                }

                this.checking = false;
                this.status = data.available
                    ? 'available'
                    : 'occupied';

                this.suggestions = data.available
                    ? []
                    : (data.suggestions || []);
            } catch (error) {
                if (sequence !== this.requestSequence) {
                    return;
                }

                this.checking = false;
                this.status = 'idle';
                this.suggestions = [];
            }
        },

        useSuggestion(suggestion) {
            this.name = suggestion;
            this.checkName();
        },

        resetName() {
            this.requestSequence++;
            this.name = this.original;
            this.checking = false;
            this.status = 'idle';
            this.suggestions = [];
        },

        statusClass() {
            if (this.checking) {
                return '';
            }

            if (this.status === 'available') {
                return 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-500/20';
            }

            if (this.status === 'occupied') {
                return 'border-red-400 bg-red-50/30 focus:border-red-500 focus:ring-red-500/20';
            }

            return '';
        }
    }));
});
</script>
@endpush
