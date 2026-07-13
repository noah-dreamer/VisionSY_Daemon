@extends('layouts.app')

@section('title', '编辑素材 · 媒体库 · VisionSY')

@section('content')
    <section class="shell py-12 sm:py-16">
        <div class="mx-auto max-w-2xl space-y-6">

            <a href="{{ route('media.show', $resource) }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12l7.5-7.5M3 12h18"/></svg>
                返回素材详情
            </a>

            <div class="card p-6 sm:p-8">
                <h1 class="h-page text-ink-950">编辑素材信息</h1>
                <p class="mt-2 prose-muted">仅修改元数据；文件本体不可替换（如需替换请删除后重新上传）。</p>

                <form method="POST" action="{{ route('media.update', $resource) }}" class="mt-7 space-y-5">
                    @csrf
                    @method('PUT')

                    <x-field name="title" label="标题" :value="old('title', $resource->title)" required :maxlength="120" />

                    <div>
                        <label for="description" class="form-label">描述</label>
                        <textarea id="description" name="description" rows="3" maxlength="2000"
                                  class="form-input">{{ old('description', $resource->description) }}</textarea>
                        @error('description')<p class="form-error">{{ $message }}</p>@enderror
                    </div>

                    <x-field name="tags" label="标签（英文逗号分隔）"
                             :value="old('tags', implode(', ', $resource->tagList()))" :maxlength="300" />

                    <div class="flex gap-3">
                        <button type="submit" class="btn-primary flex-1">保存修改</button>
                        <a href="{{ route('media.show', $resource) }}" class="btn-secondary">取消</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
