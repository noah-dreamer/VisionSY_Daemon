@extends('layouts.settings')
@section('title', '编辑存储源 · 管理后台 · VisionSY')
@section('page-title', '编辑存储源: '.$source->name)

@section('content')
<form method="POST" action="{{ route('admin.storage-sources.update', $source) }}" class="card max-w-2xl space-y-6 p-6">
    @csrf @method('PUT')

    <x-field label="名称" name="name">
        <input type="text" name="name" id="name" value="{{ old('name', $source->name) }}" required maxlength="120"
               class="form-input" aria-label="存储源名称">
    </x-field>

    <x-field label="类型" name="driver" hint="当前状态，不可在编辑时修改。">
        <input type="text" value="{{ $source->driver->label() }}" readonly class="form-input bg-ink-50 text-ink-500">
    </x-field>

    <x-field label="根路径" name="root_path">
        <input type="text" name="root_path" id="root_path" value="{{ old('root_path', $source->root_path) }}" required maxlength="1024"
               class="form-input font-mono text-sm" aria-label="根路径">
    </x-field>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-field label="状态" name="is_enabled">
            <select name="is_enabled" id="is_enabled" class="form-input" aria-label="启用状态">
                <option value="1" @selected(old('is_enabled', $source->is_enabled) === true)>启用</option>
                <option value="0" @selected(old('is_enabled', $source->is_enabled) === false)>停用</option>
            </select>
        </x-field>
        <x-field label="写入权限" name="is_writable">
            <select name="is_writable" id="is_writable" class="form-input" aria-label="写入权限">
                <option value="0" @selected(!$source->is_read_only)>只读</option>
                <option value="1" @selected(!$source->is_read_only)>可写</option>
            </select>
        </x-field>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-field label="扫描模式" name="scan_recursive">
            <select name="scan_recursive" id="scan_recursive" class="form-input" aria-label="递归扫描">
                <option value="1" @selected(old('scan_recursive', $source->scan_recursive) === true)>递归扫描子目录</option>
                <option value="0" @selected(old('scan_recursive', $source->scan_recursive) === false)>仅扫描一级目录</option>
            </select>
        </x-field>
        <x-field label="受管模式" name="is_managed">
            <select name="is_managed" id="is_managed" class="form-input" aria-label="受管模式">
                <option value="0" @selected(old('is_managed', $source->is_managed) === false)>外部存储（只索引）</option>
                <option value="1" @selected(old('is_managed', $source->is_managed) === true)>受管存储（可操作）</option>
            </select>
        </x-field>
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="btn-primary">保存修改</button>
        <a href="{{ route('admin.storage-sources.index') }}" class="btn-secondary">取消</a>
    </div>
</form>
@endsection
