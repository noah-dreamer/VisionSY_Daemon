<?php

/*
|--------------------------------------------------------------------------
| VisionSY 项目配置
|--------------------------------------------------------------------------
| 所有真实凭据均不写死在代码中：INTERNAL_API_SECRET 生产真实值来自`<SECRET_MANAGER>`。
*/

return [

    // 仅信任这些来源 IP（反向代理网关 / 本机）的 X-Real-IP / CF-Connecting-IP / XFF
    'trusted_proxy_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXY_IPS', '127.0.0.1,10.0.0.30'))
    ))),

    // 内部 API 共享密钥
    'internal_api_secret' => env('INTERNAL_API_SECRET', ''),

    'transfer_token' => [
        'ttl_seconds' => (int) env('TRANSFER_TOKEN_TTL_SECONDS', 600),
        'one_time' => filter_var(env('TRANSFER_TOKEN_ONE_TIME', true), FILTER_VALIDATE_BOOL),
    ],

    'urls' => [
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

    /*
    | ---------------- 内置媒体库（DAM） ----------------
    | direct_serve = true ：本机直接流式返回文件（本地开发 / 单机部署）。
    | direct_serve = false：大文件下载走 307 直连（TransferToken + RedirectDecision）。
    |
    | DAM 三目录（容器内固定路径，不因平台变化）：
    |   managed     ：VisionSY 管理的新上传原件，可读写
    |   sources     ：宿主机挂载的外部图库来源，默认只读
    |   derivatives ：缩略图/预览/水印派生文件，可重建
    */
    'media' => [
        'direct_serve' => filter_var(env('MEDIA_DIRECT_SERVE', true), FILTER_VALIDATE_BOOL),
        'guest_browse' => filter_var(env('MEDIA_GUEST_BROWSE', true), FILTER_VALIDATE_BOOL),
        'teacher_can_upload' => filter_var(env('MEDIA_TEACHER_CAN_UPLOAD', true), FILTER_VALIDATE_BOOL),
        'max_upload_mb' => (int) env('MEDIA_MAX_UPLOAD_MB', 512),
        'watermark_text' => env('MEDIA_WATERMARK_TEXT', 'VisionSY'),
        'watermark_font' => env('MEDIA_WATERMARK_FONT'),
        'exiftool_path' => env('MEDIA_EXIFTOOL_PATH'),
        'ffmpeg_path' => env('MEDIA_FFMPEG_PATH'),

        'preview_max_edge' => (int) env('MEDIA_PREVIEW_MAX_EDGE', 1600),
        'thumb_max_edge' => (int) env('MEDIA_THUMB_MAX_EDGE', 640),
        'preview_jpeg_quality' => (int) env('MEDIA_PREVIEW_JPEG_QUALITY', 82),
        'thumb_jpeg_quality' => (int) env('MEDIA_THUMB_JPEG_QUALITY', 75),
        'watermark_jpeg_quality' => (int) env('MEDIA_WATERMARK_JPEG_QUALITY', 85),
        'watermark_png_compression' => (int) env('MEDIA_WATERMARK_PNG_COMPRESSION', 6),

        'managed_root' => env('MEDIA_MANAGED_ROOT', '/data/visionsy/managed'),
        'derivatives_root' => env('MEDIA_DERIVATIVES_ROOT', '/data/visionsy/derivatives'),

        'allowed_roots' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MEDIA_ALLOWED_ROOTS', '/data/visionsy/managed,/data/visionsy/sources'))
        ))),

        'scan_exclude_patterns' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MEDIA_SCAN_EXCLUDE', '._*,Thumbs.db,.DS_Store,@eaDir'))
        ))),

        'scanner' => [
            'batch_size' => (int) env('MEDIA_SCAN_BATCH_SIZE', 100),
            'checksum_changed_only' => filter_var(env('MEDIA_SCAN_CHECKSUM_CHANGED', true), FILTER_VALIDATE_BOOL),
            // 扫描锁 stale 阈值（分钟）。必须大于扫描 Job 的最长 timeout（ScanMediaGallery=3600s=60min），
            // 否则合法的长扫描会被误判为 stale 锁而并发重入。默认 75 分钟。
            'stale_lock_minutes' => (int) env('MEDIA_SCAN_STALE_LOCK_MINUTES', 75),
        ],

        'raw' => [
            'extensions' => [
                'cr2', 'cr3', 'crw', 'nef', 'nrw', 'arw', 'srf', 'sr2',
                'dng', 'orf', 'pef', 'raf', 'rw2', '3fr', 'ari', 'bay',
                'cap', 'dcr', 'drf', 'eip', 'erf', 'fff', 'iiq', 'k25',
                'kdc', 'mdc', 'mef', 'mos', 'mrw', 'obm', 'ori', 'ptx',
                'pxn', 'r3d', 'raw', 'rwl', 'rwz', 'srw', 'x3f',
            ],
            'converter' => env('MEDIA_RAW_CONVERTER', 'exiftool_preview'),
            'min_preview_bytes' => (int) env('MEDIA_RAW_MIN_PREVIEW_BYTES', 1024),
            'preview_tags' => ['PreviewImage', 'JpgFromRaw', 'ThumbnailImage'],
        ],

        'converters' => [
            'dcraw' => env('MEDIA_CONVERTER_DCRAW'),
            'darktable_cli' => env('MEDIA_CONVERTER_DARKTABLE_CLI'),
            'rawtherapee_cli' => env('MEDIA_CONVERTER_RAWTHERAPEE_CLI'),
            'imagemagick' => env('MEDIA_CONVERTER_IMAGEMAGICK'),
        ],

        'watermark_max_edge' => (int) env('MEDIA_WATERMARK_MAX_EDGE', 3200),

        'mount_helper_path' => env('MEDIA_MOUNT_HELPER_PATH', '/usr/local/bin/visionsy-mount'),
        'mount_base' => env('MEDIA_MOUNT_BASE', '/mnt/visionsy-media'),
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
    'intranet_probe_url' => env('INTRANET_PROBE_URL', 'http://127.0.0.1:8099'),

    'email_code' => [
        'ttl_seconds' => (int) env('EMAIL_CODE_TTL_SECONDS', 600),
        'resend_cooldown_seconds' => (int) env('EMAIL_CODE_RESEND_COOLDOWN', 60),
        'max_attempts' => 5,
        'length' => 6,
    ],
];
