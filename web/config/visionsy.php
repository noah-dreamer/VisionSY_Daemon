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

    /*
    | ---------------- 内置媒体库（DAM，取代独立 ResourceSpace 部署） ----------------
    | direct_serve = true ：本机直接流式返回文件（本地开发 / 单机部署）。
    | direct_serve = false：大文件下载走 307 直连（TransferToken + RedirectDecision，
    |                        与 README「流量分流机制」一致，由网关按 token 放行）。
    */
    'media' => [
        'direct_serve' => filter_var(env('MEDIA_DIRECT_SERVE', true), FILTER_VALIDATE_BOOL),
        // 游客（未登录）是否允许浏览缩略图列表
        'guest_browse' => filter_var(env('MEDIA_GUEST_BROWSE', true), FILTER_VALIDATE_BOOL),
        // 官微编辑（老师）是否允许上传（README：视情况）
        'teacher_can_upload' => filter_var(env('MEDIA_TEACHER_CAN_UPLOAD', true), FILTER_VALIDATE_BOOL),
        'max_upload_mb' => (int) env('MEDIA_MAX_UPLOAD_MB', 512),
        // 水印文本：默认 ASCII（GD 内置字体不支持中文）；
        // 提供 TTF 字体路径后可使用中文水印文本。
        'watermark_text' => env('MEDIA_WATERMARK_TEXT', 'VisionSY'),
        'watermark_font' => env('MEDIA_WATERMARK_FONT'), // 可选：TTF 字体绝对路径
        // 外部工具（对齐 ResourceSpace 的 $exiftool_path / $ffmpeg_path；留空则用 PATH，
        // 未安装时自动回退：元数据 → PHP exif + getID3；视频封面 → 跳过）
        'exiftool_path' => env('MEDIA_EXIFTOOL_PATH'),
        'ffmpeg_path' => env('MEDIA_FFMPEG_PATH'),

        'preview_max_edge' => (int) env('MEDIA_PREVIEW_MAX_EDGE', 1600),
        'thumb_max_edge' => (int) env('MEDIA_THUMB_MAX_EDGE', 640),
    ],

    // ---------------- 登录人机验证（PlayCaptcha：滑块拼图 + 轨迹校验） ----------------
    'captcha' => [
        // 登录人机验证：PlayCaptcha（github.com/mortspace/playcaptcha，抓娃娃机）
        'enabled' => env('CAPTCHA_ENABLED', true),
        // 挑战 id 有效期（秒）：页面加载领取 → 通关消费
        'challenge_ttl' => (int) env('CAPTCHA_CHALLENGE_TTL', 180),
        // 通关后 pass token 有效期（秒）
        'pass_ttl' => (int) env('CAPTCHA_PASS_TTL', 300),
        // 人类通关耗时下限（秒）：移动爪子+抓取+搬运+投放的最短合理时间
        'min_solve_seconds' => (float) env('CAPTCHA_MIN_SOLVE_SECONDS', 3),
    ],

    // ---------------- 邮箱验证码（注册后 6 位数字验证码） ----------------
    'email_code' => [
        'ttl_seconds' => (int) env('EMAIL_CODE_TTL_SECONDS', 600),
        'resend_cooldown_seconds' => (int) env('EMAIL_CODE_RESEND_COOLDOWN', 60),
        'max_attempts' => 5,
        'length' => 6,
    ],
];
