<?php

/*
|--------------------------------------------------------------------------
| VisionSY 项目配置
|--------------------------------------------------------------------------
| 所有真实凭据均不写死在代码中：
|   - INTERNAL_API_SECRET 生产真实值来自`<SECRET_MANAGER>`
|   - 数据库凭据来自`<SECRET_MANAGER>` 侧）
| 域名按 README《域名、端口与反代关系》固定，不使用泛化占位。
*/

return [

    // 仅信任这些来源 IP（反向代理网关 / 本机）的 X-Real-IP / CF-Connecting-IP / XFF
    'trusted_proxy_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXY_IPS', '127.0.0.1,10.0.0.30'))
    ))),

    // Nginx / 反向代理网关 / ResourceSpace 调用内部 API 用的共享密钥
    'internal_api_secret' => env('INTERNAL_API_SECRET', ''),

    'transfer_token' => [
        'ttl_seconds' => (int) env('TRANSFER_TOKEN_TTL_SECONDS', 600),
        'one_time' => filter_var(env('TRANSFER_TOKEN_ONE_TIME', true), FILTER_VALIDATE_BOOL),
    ],

    'urls' => [
        'resourcespace' => env('VISION_RESOURCE_BASE_URL', 'https://media.example.com'),
        'site' => env('VISION_SITE_URL', 'https://www.example.com'),
        'public_site' => env('VISION_PUBLIC_SITE_URL', 'https://example.com'),
        'external_transfer' => env('VISION_EXTERNAL_TRANSFER_BASE_URL', 'https://files.example.com:8443'),
        'lan_transfer' => env('VISION_LAN_TRANSFER_BASE_URL', 'https://files-internal.example.com:8443'),
        'auth' => env('VISION_AUTH_URL', 'https://auth.example.com'),
    ],

    'transfer_hosts' => [
        'external' => 'files.example.com',
        'lan' => 'files-internal.example.com',
    ],

    // 角色 -> ResourceSpace 用户组（固定映射，勿改名）
    'role_group_map' => [
        'system_admin' => 'visionsy_system_admin',
        'propaganda_member' => 'visionsy_propaganda_member',
        'wechat_editor_teacher' => 'visionsy_wechat_editor_teacher',
        'normal_user' => 'visionsy_normal_user',
        'guest' => 'visionsy_guest',
    ],

    'oauth' => [
        'code_ttl_seconds' => (int) env('OAUTH_CODE_TTL_SECONDS', 300),
        'access_token_ttl_seconds' => (int) env('OAUTH_ACCESS_TOKEN_TTL_SECONDS', 3600),
        'refresh_token_ttl_seconds' => (int) env('OAUTH_REFRESH_TOKEN_TTL_SECONDS', 2592000),
    ],
];
