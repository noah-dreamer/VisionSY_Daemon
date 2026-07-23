{{-- 用户组表单（创建/编辑共用）。$group 可为 null。 --}}
@csrf

<div class="grid gap-5 sm:grid-cols-2">
    <x-field name="name" label="组名" :value="old('name', $group?->name ?? '')" required :maxlength="60"
             placeholder="例如：校史馆合作方" />
    <x-field name="description" label="说明" :value="old('description', $group?->description ?? '')" :maxlength="255"
             placeholder="这个组的用途（可选）" />
</div>

<div class="mt-6">
    <span class="form-label">权限位</span>
    <p class="form-hint mt-0">权限位；勾选即授予。组内用户以此为准，覆盖其角色默认矩阵。</p>
    <div class="mt-3 grid gap-2.5 sm:grid-cols-2">
        @foreach ($flags as $flag => [$label, $hint])
            <label class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300">
                <input type="checkbox" name="{{ $flag }}" value="1"
                       class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500"
                       @checked(old($flag, $group?->{$flag} ?? in_array($flag, ['can_browse','can_preview','can_download_watermarked'], true)))>
                <span>
                    <span class="block text-sm font-medium text-ink-900">{{ $label }}</span>
                    <span class="mt-0.5 block text-xs text-ink-500">{{ $hint }}</span>
                </span>
            </label>
        @endforeach
    </div>
</div>

<div class="mt-6">
    <span class="form-label">高级选项</span>
    <p class="form-hint mt-0">高级用户组选项。系统管理员不受这些限制。</p>
    <div class="mt-3 grid gap-5 sm:grid-cols-2">
        <x-field name="download_limit_per_day" label="每日下载限额" type="number"
                 :value="old('download_limit_per_day', $group?->download_limit_per_day ?? 0)"
                 hint="0 表示不限（RS: Resource download limit）" required />
        <x-field name="ip_restriction" label="下载来源 IP 限制" :value="old('ip_restriction', $group?->ip_restriction ?? '')"
                 placeholder="10.0.0.0/8, 172.16.0.0/12" hint="CIDR，逗号分隔；留空不限（RS: IP address restriction）" />
    </div>
    <label class="mt-4 flex items-center gap-2 text-sm text-ink-700">
        <input type="checkbox" name="is_registration_default" value="1"
               class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500"
               @checked(old('is_registration_default', $group?->is_registration_default ?? false))>
        设为新注册用户的默认用户组（全局唯一）
    </label>
</div>
