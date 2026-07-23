@extends('layouts.app')

@section('title', '上传素材 · 媒体库 · VisionSY')

@section('content')
    <section class="shell py-12 sm:py-16">
        <div class="mx-auto max-w-2xl space-y-6">

            <a href="{{ route('media.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12l7.5-7.5M3 12h18"/></svg>
                返回媒体库
            </a>

            <div class="card p-6 sm:p-8">
                <h1 class="h-page text-ink-950">上传素材</h1>
                <p class="mt-2 prose-muted">
                    原图入库后自动生成带水印缩略图与预览图；原图仅授权用户组可下载。单文件上限 {{ $maxUploadMb }} MB。
                </p>

                <form method="POST" action="{{ route('media.store') }}" enctype="multipart/form-data" class="mt-7 space-y-5"
                      x-data="mediaUpload()" @submit="submitting = true">
                    @csrf

                    @if($sources->isNotEmpty() && $galleries->isNotEmpty())
                    <div>
                        <label for="storage_source_id" class="form-label">存储源</label>
                        <select id="storage_source_id" name="storage_source_id"
                                class="form-input">
                            <option value="">-- 请选择 --</option>
                            @foreach($sources as $src)
                                <option value="{{ $src->id }}" {{ old('storage_source_id') == $src->id ? 'selected' : '' }}>
                                    {{ $src->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('storage_source_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="gallery_id" class="form-label">图库</label>
                        <select id="gallery_id" name="gallery_id"
                                class="form-input">
                            <option value="">-- 请选择 --</option>
                            @foreach($galleries as $gal)
                                <option value="{{ $gal->id }}" data-source="{{ $gal->storage_source_id }}"
                                    {{ old('gallery_id') == $gal->id ? 'selected' : '' }}>
                                    {{ $gal->name }} ({{ $gal->storageSource?->name ?? '#' . $gal->storage_source_id }})
                                </option>
                            @endforeach
                        </select>
                        @error('gallery_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    @endif

                    {{-- 拖拽 / 点击选择文件 --}}
                    <div>
                        <label class="form-label" for="file">文件</label>
                        <label for="file"
                               class="group flex cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition"
                               :class="dragging ? 'border-brand-500 bg-brand-50/60' : 'border-ink-200 bg-ink-50/50 hover:border-brand-400 hover:bg-brand-50/40'"
                               @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
                               @drop.prevent="dragging = false; setFile($event.dataTransfer.files)">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-brand-600 shadow-sm ring-1 ring-inset ring-ink-100 transition group-hover:scale-105">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                            </div>
                            <template x-if="!fileName">
                                <p class="text-sm text-ink-500">拖拽文件到此处，或 <span class="font-medium text-brand-600">点击选择</span></p>
                            </template>
                            <template x-if="fileName">
                                <p class="max-w-full truncate text-sm font-medium text-ink-900" x-text="fileName + '（' + fileSize + '）'"></p>
                            </template>
                            <input id="file" name="file" type="file" class="sr-only" required
                                   @change="setFile($event.target.files)" x-ref="input">
                        </label>
                        @error('file')<p class="form-error">{{ $message }}</p>@enderror
                    </div>

                    <x-field name="title" label="标题" :value="old('title')"
                             placeholder="例如：2026 校运会开幕式 · 入场式" required :maxlength="120" />

                    <div>
                        <label for="description" class="form-label">描述（可选）</label>
                        <textarea id="description" name="description" rows="3" maxlength="2000"
                                  class="form-input" placeholder="拍摄场合、人物、注意事项等">{{ old('description') }}</textarea>
                        @error('description')<p class="form-error">{{ $message }}</p>@enderror
                    </div>

                    <x-field name="tags" label="标签（可选，英文逗号分隔）" :value="old('tags')"
                             placeholder="校运会, 开幕式, 2026" :maxlength="300"
                             hint="最多 12 个标签，用于检索。" />

                    <button type="submit" class="btn-primary w-full" :disabled="submitting">
                        <span x-show="!submitting">上传并生成水印预览</span>
                        <span x-show="submitting" x-cloak class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                            上传中，请稍候…
                        </span>
                    </button>
                </form>
            </div>
        </div>
    </section>
@endsection
