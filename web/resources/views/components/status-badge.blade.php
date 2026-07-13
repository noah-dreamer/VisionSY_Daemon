@props(['status'])
@php
    // 接受 UserStatus 枚举或其字符串值
    $value = is_object($status) ? $status->value : $status;
    [$tone, $label] = match ($value) {
        'active'                     => ['green', '已激活'],
        'pending_activation'         => ['amber', '待激活'],
        'pending_email_verification' => ['amber', '待验证邮箱'],
        'disabled'                   => ['red', '已禁用'],
        default                      => ['gray', $value],
    };
@endphp
<x-badge :tone="$tone" dot>{{ $label }}</x-badge>
