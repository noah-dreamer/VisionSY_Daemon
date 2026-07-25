@extends('layouts.settings')

@section('title', '个人资料 · VisionSY')

@section('page-title', '个人资料')

@section('settings-content')
<div class="space-y-6" x-data="avatarUploader()">
    <div class="card p-6 sm:p-8">
        <h2 class="h-card">头像</h2>
        <form method="POST" action="{{ route('settings.avatar.update') }}" enctype="multipart/form-data" class="mt-4" x-ref="form">
            @csrf
            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" class="hidden" x-ref="fileInput" @change="onFileSelected">

            <div class="flex flex-col items-center gap-4">
                <button type="button"
                        class="group relative h-28 w-28 overflow-hidden rounded-full ring-4 ring-white shadow-md transition hover:shadow-lg"
                        @click="$refs.fileInput.click()"
                        aria-label="更换头像">
                    <img x-show="previewUrl" :src="previewUrl" alt="头像" class="h-full w-full object-cover">
                    <div x-show="!previewUrl && initialAvatar" class="h-full w-full">
                        <img src="{{ $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : '' }}" alt="头像" class="h-full w-full object-cover">
                    </div>
                    <div x-show="!previewUrl && !initialAvatar" class="brand-mark h-full w-full text-3xl flex items-center justify-center">
                        {{ mb_substr($user->display_name, 0, 1) }}
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-ink-950/50 opacity-0 transition group-hover:opacity-100">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"/></svg>
                        <span class="text-xs text-white">更换头像</span>
                    </div>
                </button>

                <div x-show="fileSelected" class="flex items-center gap-3" x-cloak>
                    <button type="submit" class="btn-primary text-sm">保存头像</button>
                    <button type="button" class="btn-ghost text-sm text-ink-500" @click="cancelFile">取消</button>
                </div>

                <p class="text-xs text-ink-400">支持 JPG / PNG / WebP 格式</p>
                @error('avatar')
                    <p class="form-error -mt-2">{{ $message }}</p>
                @enderror
            </div>
        </form>
    </div>

    <div class="card p-6 sm:p-8" x-data="nameChecker(@js($user->display_name))">
        <h2 class="h-card">账户名称</h2>
        <form method="POST" action="{{ route('settings.profile.update') }}" class="mt-4 space-y-4">
            @csrf
            @method('PATCH')
            <div>
                <label for="display_name" class="form-label">显示名</label>
                <div class="relative mt-1">
                    <input type="text" name="display_name" id="display_name"
                           value="{{ old('display_name', $user->display_name) }}"
                           x-model="name"
                           x-ref="nameInput"
                           @input.debounce.500ms="checkName()"
                           maxlength="50" required
                           autocomplete="off"
                           class="form-input w-full pr-10"
                           :class="statusClass()">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                        <svg x-show="checking" class="h-5 w-5 animate-spin text-ink-400" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <svg x-show="!checking && status === 'available'" class="h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        <svg x-show="!checking && status === 'occupied'" class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </div>
                </div>
                @error('display_name')
                    <p class="form-error mt-1">{{ $message }}</p>
                @enderror

                <div x-show="!checking && status === 'occupied' && suggestions.length > 0" x-cloak class="mt-2 rounded-xl border border-ink-100 bg-ink-50 p-3">
                    <p class="text-xs text-ink-500">该名称已被使用，试试以下备选：</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <template x-for="s in suggestions" :key="s">
                            <button type="button"
                                    class="rounded-lg border border-ink-200 bg-white px-2.5 py-1 text-xs font-medium text-ink-700 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                                    @click="name = s; checkName()"
                                    x-text="s"></button>
                        </template>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="btn-primary text-sm">保存</button>
                <button type="button" class="btn-ghost text-sm text-ink-500" x-show="name !== original" x-cloak @click="name = original; status = 'idle'; suggestions = []">取消</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('avatarUploader', () => ({
        previewUrl: null,
        fileSelected: false,
        initialAvatar: {{ $user->avatar_path ? 'true' : 'false' }},

        onFileSelected(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.previewUrl = URL.createObjectURL(file);
            this.fileSelected = true;
        },

        cancelFile() {
            this.previewUrl = null;
            this.fileSelected = false;
            this.$refs.fileInput.value = '';
        }
    }));

    Alpine.data('nameChecker', (original) => ({
        name: original,
        original: original,
        status: 'idle',
        checking: false,
        suggestions: [],

        init() {
            this.name = original;
            this.$nextTick(() => { this.$refs.nameInput.value = original });
        },

        async checkName() {
            const trimmed = this.name.trim();
            if (!trimmed || trimmed === original) {
                this.status = 'idle';
                this.suggestions = [];
                return;
            }

            this.checking = true;
            this.status = 'idle';
            this.suggestions = [];

            try {
                const resp = await fetch(`/settings/profile/check-name?name=${encodeURIComponent(trimmed)}`);
                const data = await resp.json();
                this.checking = false;

                if (data.available) {
                    this.status = 'available';
                    this.suggestions = [];
                } else {
                    this.status = 'occupied';
                    this.suggestions = data.suggestions || [];
                }
            } catch {
                this.checking = false;
                this.status = 'idle';
            }
        },

        statusClass() {
            if (this.checking) return '';
            if (this.status === 'available') return 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-500/20';
            if (this.status === 'occupied') return 'border-red-400 focus:border-red-500 focus:ring-red-500/20';
            return '';
        }
    }));
});
</script>
@endpush
