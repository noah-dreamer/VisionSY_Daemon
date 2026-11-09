@extends('layouts.settings')
@section('title', '新增存储源 · 管理后台 · VisionSY')
@section('page-title', '新增存储源')

@section('settings-content')
<p class="mb-4 prose-muted">目录会自动扫描；可写目录同时支持上传，预览文件保存在图库的 preview/ 中。</p>
<form method="POST" action="{{ route('admin.storage-sources.store') }}" class="card max-w-2xl space-y-6 p-6">
    @csrf

    <x-field label="名称" name="name">
        <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="120"
               class="form-input" placeholder="例如：NAS-Photo-Archive" aria-label="存储源名称">
    </x-field>

    <x-field label="类型" name="driver" hint="SMB/NFS 类型需要宿主机预先挂载好对应共享目录。">
        <select name="driver" id="driver" required class="form-input" aria-label="存储源类型">
            <option value="local" @selected(old('driver', 'local') === 'local')>本地目录</option>
            <option value="smb" @selected(old('driver') === 'smb')>SMB/CIFS 共享（已挂载）</option>
            <option value="nfs" @selected(old('driver') === 'nfs')>NFS 共享（已挂载）</option>
        </select>
    </x-field>

    <x-field label="根路径" name="root_path" hint="宿主机上可访问的绝对路径，必须位于配置允许的根路径范围内。">
        <input type="text" name="root_path" id="root_path" value="{{ old('root_path') }}" required maxlength="1024"
               class="form-input font-mono text-sm" placeholder="/mnt/nas-photos" aria-label="根路径">
    </x-field>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-field label="状态" name="is_enabled">
            <select name="is_enabled" id="is_enabled" class="form-input" aria-label="启用状态">
                <option value="1" @selected(old('is_enabled', '1') === '1')>启用</option>
                <option value="0" @selected(old('is_enabled') === '0')>停用</option>
            </select>
        </x-field>
        <x-field label="写入权限" name="is_writable">
            <select name="is_writable" id="is_writable" class="form-input" aria-label="写入权限">
                <option value="0" @selected(old('is_writable', '1') === '0')>只读</option>
                <option value="1" @selected(old('is_writable', '1') === '1')>可写</option>
            </select>
        </x-field>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-field label="扫描模式" name="scan_recursive">
            <select name="scan_recursive" id="scan_recursive" class="form-input" aria-label="递归扫描">
                <option value="1" @selected(old('scan_recursive', '1') === '1')>递归扫描子目录</option>
                <option value="0" @selected(old('scan_recursive') === '0')>仅扫描一级目录</option>
            </select>
        </x-field>
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="btn-primary">创建存储源</button>
        <a href="{{ route('admin.storage-sources.index') }}" class="btn-secondary">取消</a>
    </div>
</form>
@endsection
