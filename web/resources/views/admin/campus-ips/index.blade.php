@extends('layouts.admin')

@section('title', '校园出口 IP · 管理后台')
@section('page-title', '学校公网出口 IP（CIDR 白名单）')

@section('content')
    <div class="card max-w-3xl p-7">
        <h2 class="text-sm font-semibold text-ink-900">添加出口 IP</h2>
        <p class="mt-1.5 prose-muted">
            此处填写<strong class="font-medium text-ink-800">学校公网出口 IP</strong>。校内用户访问官网时经学校 NAT 出口、
            再由 Cloudflare 透传，官网看到的是学校公网 IP（<strong class="font-medium text-ink-800">而非内网 10.x 地址</strong>）。
            命中启用条目的真实客户端 IP 将触发待激活账号自动激活。多出口 / 多线学校可添加多条；
            单个 IP 写 <code class="rounded bg-ink-100 px-1 py-0.5 text-xs">203.0.113.10/32</code>，整段写 <code class="rounded bg-ink-100 px-1 py-0.5 text-xs">203.0.113.0/24</code>。支持 IPv4 与 IPv6 CIDR。
        </p>

        <form method="POST" action="{{ route('admin.campus-ips.store') }}" class="mt-5 flex flex-col gap-3 sm:flex-row">
            @csrf
            <input type="text" name="cidr" value="{{ old('cidr') }}" class="form-input font-mono sm:max-w-[15rem]" placeholder="203.0.113.10/32" required>
            <input type="text" name="description" value="{{ old('description') }}" class="form-input" placeholder="说明，例如：学校电信出口">
            <button type="submit" class="btn-primary shrink-0">添加</button>
        </form>
        @error('cidr') <p class="form-error mt-2">{{ $message }}</p> @enderror
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-ink-100">
            <thead class="bg-ink-50/70">
                <tr>
                    <th class="table-head whitespace-nowrap">CIDR</th>
                    <th class="table-head">说明</th>
                    <th class="table-head whitespace-nowrap">状态</th>
                    <th class="table-head whitespace-nowrap text-right">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($ranges as $range)
                    <tr x-data="{
                            editing: false,
                            cidr:    @js($range->cidr),
                            desc:    @js($range->description ?? ''),
                            reset()  { this.cidr = @js($range->cidr); this.desc = @js($range->description ?? ''); this.editing = false; }
                        }"
                        class="transition hover:bg-ink-50/60"
                        :class="editing ? 'bg-brand-50/40' : ''">

                        <td class="table-cell whitespace-nowrap font-mono">
                            <span x-show="!editing">{{ $range->cidr }}</span>
                            <input x-show="editing" x-cloak type="text" x-model="cidr" class="form-input w-40 py-1.5 font-mono text-sm" placeholder="203.0.113.10/32">
                        </td>

                        <td class="table-cell text-ink-600">
                            <span x-show="!editing" x-text="desc || '—'"></span>
                            <input x-show="editing" x-cloak type="text" x-model="desc" class="form-input w-full py-1.5 text-sm" placeholder="说明（可选）">
                            @error('cidr_'.$range->id) <p class="form-error">{{ $message }}</p> @enderror
                        </td>

                        <td class="table-cell whitespace-nowrap">
                            @if ($range->enabled)
                                <x-badge tone="green" dot>启用</x-badge>
                            @else
                                <x-badge tone="gray">禁用</x-badge>
                            @endif
                        </td>

                        <td class="table-cell text-right">
                            <div x-show="!editing" class="inline-flex items-center justify-end gap-1.5">
                                <button type="button" @click="editing = true" class="btn-ghost btn-sm shrink-0">编辑</button>
                                <form method="POST" action="{{ route('admin.campus-ips.toggle', $range) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost btn-sm shrink-0">{{ $range->enabled ? '禁用' : '启用' }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.campus-ips.destroy', $range) }}"
                                      x-data @submit="if (! confirm('确认删除 {{ $range->cidr }}？')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger btn-sm shrink-0">删除</button>
                                </form>
                            </div>

                            <div x-show="editing" x-cloak class="inline-flex items-center justify-end gap-1.5">
                                <form method="POST" action="{{ route('admin.campus-ips.update', $range) }}"
                                      class="inline-flex items-center gap-1.5"
                                      @submit.prevent="
                                          $refs.cidrField_{{ $range->id }}.value = cidr;
                                          $refs.descField_{{ $range->id }}.value = desc;
                                          $el.submit();
                                      ">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="cidr" x-ref="cidrField_{{ $range->id }}">
                                    <input type="hidden" name="description" x-ref="descField_{{ $range->id }}">
                                    <button type="submit" class="btn-primary btn-sm shrink-0">保存</button>
                                </form>
                                <button type="button" @click="reset()" class="btn-ghost btn-sm shrink-0">取消</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-ink-400">尚未配置出口 IP</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
