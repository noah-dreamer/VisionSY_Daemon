@extends('layouts.settings')
@section('title', '编辑图库 · 管理后台 · VisionSY')
@section('page-title', '编辑图库: '.$gallery->name)

@section('settings-content')
<form method="POST" action="{{ route('admin.galleries.update', $gallery) }}" class="card max-w-2xl space-y-6 p-6">
    @csrf @method('PUT')

    <x-field label="存储源">
        <input type="text" value="{{ $gallery->storageSource?->name }}" readonly class="form-input bg-ink-50 text-ink-500">
    </x-field>

    <x-field label="展示名称" name="name">
        <input type="text" name="name" id="name" value="{{ old('name', $gallery->name) }}" required maxlength="255"
               class="form-input" aria-label="展示名称">
    </x-field>

    <x-field label="文件夹路径" hint="物理目录路径，不可在编辑时修改。">
        <input type="text" value="{{ $gallery->relative_path }}" readonly class="form-input bg-ink-50 font-mono text-sm text-ink-500">
    </x-field>

    <x-field label="描述" name="description">
        <textarea name="description" id="description" rows="3" maxlength="2000"
                  class="form-input" placeholder="图库描述" aria-label="描述">{{ old('description', $gallery->description) }}</textarea>
    </x-field>

    <x-field label="标签" name="tags" hint="逗号分隔，最多 12 个标签。">
        <input type="text" name="tags" id="tags"
               value="{{ old('tags', is_array($gallery->tags) ? implode(', ', $gallery->tags) : $gallery->tags) }}"
               maxlength="300" class="form-input" placeholder="例如：运动会, 春季, 2024" aria-label="标签">
    </x-field>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-field label="活动开始时间" name="event_started_at">
            <input type="datetime-local" name="event_started_at" id="event_started_at"
                   value="{{ old('event_started_at', $gallery->event_started_at?->format('Y-m-d\TH:i')) }}"
                   class="form-input" aria-label="活动开始时间">
        </x-field>
        <x-field label="活动结束时间" name="event_ended_at">
            <input type="datetime-local" name="event_ended_at" id="event_ended_at"
                   value="{{ old('event_ended_at', $gallery->event_ended_at?->format('Y-m-d\TH:i')) }}"
                   class="form-input" aria-label="活动结束时间">
        </x-field>
    </div>

    <x-field label="可见性" name="is_visible">
        <select name="is_visible" id="is_visible" class="form-input" aria-label="可见性">
            <option value="1" @selected(old('is_visible', $gallery->is_visible) === true)>可见</option>
            <option value="0" @selected(old('is_visible', $gallery->is_visible) === false)>隐藏</option>
        </select>
    </x-field>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="btn-primary">保存修改</button>
        <a href="{{ route('admin.galleries.index') }}" class="btn-secondary">取消</a>
    </div>
</form>
@endsection
