@extends('layouts.admin')
@section('title', '创建图库 · 管理后台 · VisionSY')
@section('page-title', '创建图库')

@section('content')
<form method="POST" action="{{ route('admin.galleries.store') }}" class="card max-w-2xl space-y-6 p-6">
    @csrf

    <x-field label="存储源" name="storage_source_id" hint="选择可写的存储源，将在其根目录下创建文件夹。">
        <select name="storage_source_id" id="storage_source_id" required class="form-input" aria-label="存储源">
            <option value="">请选择存储源</option>
            @foreach($sources as $s)
                <option value="{{ $s->id }}" @selected(old('storage_source_id', $selectedSource?->id) == $s->id)>
                    {{ $s->name }} ({{ $s->root_path }})
                </option>
            @endforeach
        </select>
    </x-field>

    <x-field label="展示名称" name="name">
        <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="255"
               class="form-input" placeholder="例如：2024 年春季运动会" aria-label="展示名称">
    </x-field>

    <x-field label="文件夹名称" name="folder_name" hint="在存储源根目录下创建的文件夹名称，只允许普通目录名。">
        <input type="text" name="folder_name" id="folder_name" value="{{ old('folder_name') }}" required maxlength="255"
               class="form-input font-mono text-sm" placeholder="例如：2024-sports-meet" aria-label="文件夹名称">
    </x-field>

    <x-field label="描述" name="description">
        <textarea name="description" id="description" rows="3" maxlength="2000"
                  class="form-input" placeholder="图库描述（可选）" aria-label="描述">{{ old('description') }}</textarea>
    </x-field>

    <x-field label="标签" name="tags" hint="逗号分隔，最多 12 个标签。">
        <input type="text" name="tags" id="tags" value="{{ old('tags') }}" maxlength="300"
               class="form-input" placeholder="例如：运动会, 春季, 2024" aria-label="标签">
    </x-field>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-field label="活动开始时间" name="event_started_at">
            <input type="datetime-local" name="event_started_at" id="event_started_at"
                   value="{{ old('event_started_at') }}" class="form-input" aria-label="活动开始时间">
        </x-field>
        <x-field label="活动结束时间" name="event_ended_at">
            <input type="datetime-local" name="event_ended_at" id="event_ended_at"
                   value="{{ old('event_ended_at') }}" class="form-input" aria-label="活动结束时间">
        </x-field>
    </div>

    <x-field label="可见性" name="is_visible">
        <select name="is_visible" id="is_visible" class="form-input" aria-label="可见性">
            <option value="1" @selected(old('is_visible', '1') === '1')>可见</option>
            <option value="0" @selected(old('is_visible') === '0')>隐藏</option>
        </select>
    </x-field>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="btn-primary">创建图库</button>
        <a href="{{ route('admin.galleries.index') }}" class="btn-secondary">取消</a>
    </div>
</form>
@endsection
