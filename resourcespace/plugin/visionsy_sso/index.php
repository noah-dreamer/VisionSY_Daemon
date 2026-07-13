<?php
/**
 * VisionSY SSO 插件 —— 登录入口
 *
 * 访问 https://media.example.com/plugins/visionsy_sso/index.php 即跳转到
 * 官网 OAuth2 授权端点，完成后回到 pages/callback.php。
 *
 * 部署位置：ResourceSpace 安装目录 plugins/visionsy_sso/index.php
 */

// 引导 ResourceSpace 运行环境（取得 session 与插件配置）。
// 注意：include/db.php 的相对层级在不同 RS 版本可能不同，
// 需在 ResourceSpace 插件环境验证后按实际路径调整。
include_once dirname(__DIR__, 2) . '/include/db.php';

include_once __DIR__ . '/config.default.php';
if (file_exists(__DIR__ . '/config.php')) {
    include_once __DIR__ . '/config.php'; // 站点本地配置（含 client_secret，不入 Git）
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 防 CSRF 的 state（回调时校验）
$state = bin2hex(random_bytes(16));
$_SESSION['visionsy_sso_state'] = $state;

$authorizeUrl = rtrim($visionsy_sso_provider_base_url, '/') . '/oauth/authorize?' . http_build_query([
    'client_id' => $visionsy_sso_client_id,
    'redirect_uri' => $visionsy_sso_redirect_uri,
    'response_type' => 'code',
    'scope' => 'profile',
    'state' => $state,
]);

header('Location: ' . $authorizeUrl, true, 302);
exit;
