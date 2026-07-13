<?php
/**
 * VisionSY SSO 插件 —— OAuth2 回调
 *
 * 流程：校验 state -> 服务端换 token -> 取 userinfo
 *      -> 按 userinfo 创建/更新 RS 账号并同步用户组 -> 写 RS 会话 -> 跳首页。
 *
 * 部署位置：plugins/visionsy_sso/pages/callback.php
 *
 * 重要：本文件中与 ResourceSpace 内部 API 交互的部分（账号创建、会话写入）
 * 依赖具体 RS 版本的函数签名，所有此类调用点均以
 * 「需在 ResourceSpace 插件环境验证」标注，请在测试实例上确认后再上线。
 */

// 引导 RS 环境。需在 ResourceSpace 插件环境验证：include 相对层级。
include_once dirname(__DIR__, 3) . '/include/db.php';
include_once dirname(__DIR__, 3) . '/include/authenticate.php'; // 提供登录会话相关函数

include_once dirname(__DIR__) . '/config.default.php';
if (file_exists(dirname(__DIR__) . '/config.php')) {
    include_once dirname(__DIR__) . '/config.php';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** 统一失败出口：不泄露细节，记录到 PHP error_log。 */
function visionsy_sso_fail(string $logMessage, string $userMessage = 'SSO 登录失败，请返回重试或联系管理员。'): void
{
    error_log('[visionsy_sso] ' . $logMessage);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8"><title>SSO 登录失败</title>'
        . '<body style="font-family:system-ui;padding:3rem;color:#252836">'
        . '<h1 style="font-size:1.25rem">' . htmlspecialchars($userMessage, ENT_QUOTES) . '</h1>'
        . '<p><a href="/">返回媒体库首页</a></p></body></html>';
    exit;
}

/** 简单的服务端 HTTP 请求（cURL）。 */
function visionsy_sso_http(string $method, string $url, array $form = [], array $headers = [], int $timeout = 10): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'error' => $err];
}

// ---------------------------------------------------------------------
// 1. 校验回调参数与 state
// ---------------------------------------------------------------------
$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';
$expectedState = isset($_SESSION['visionsy_sso_state']) ? (string) $_SESSION['visionsy_sso_state'] : '';
unset($_SESSION['visionsy_sso_state']); // 一次性

if (isset($_GET['error'])) {
    visionsy_sso_fail('provider returned error: ' . (string) $_GET['error']);
}
if ($code === '' || $state === '' || $expectedState === '' || ! hash_equals($expectedState, $state)) {
    visionsy_sso_fail('state mismatch or missing code');
}
if ($visionsy_sso_client_secret === '') {
    visionsy_sso_fail('client_secret not configured (plugins/visionsy_sso/config.php)');
}

$apiBase = rtrim($visionsy_sso_provider_internal_base_url ?: $visionsy_sso_provider_base_url, '/');

// ---------------------------------------------------------------------
// 2. 授权码换 access token（服务端到服务端，token 不落浏览器）
// ---------------------------------------------------------------------
$tokenResp = visionsy_sso_http('POST', $apiBase . '/oauth/token', [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $visionsy_sso_redirect_uri,
    'client_id' => $visionsy_sso_client_id,
    'client_secret' => $visionsy_sso_client_secret,
], ['Accept: application/json'], $visionsy_sso_http_timeout);

if ($tokenResp['status'] !== 200) {
    visionsy_sso_fail('token endpoint status ' . $tokenResp['status'] . ' body=' . substr($tokenResp['body'], 0, 200));
}

$tokenData = json_decode($tokenResp['body'], true);
$accessToken = is_array($tokenData) && isset($tokenData['access_token']) ? (string) $tokenData['access_token'] : '';
if ($accessToken === '') {
    visionsy_sso_fail('no access_token in token response');
}

// ---------------------------------------------------------------------
// 3. 取 userinfo（角色 / 组 / 状态以官网为准）
// ---------------------------------------------------------------------
$userResp = visionsy_sso_http('GET', $apiBase . '/oauth/userinfo', [], [
    'Accept: application/json',
    'Authorization: Bearer ' . $accessToken,
], $visionsy_sso_http_timeout);

if ($userResp['status'] !== 200) {
    visionsy_sso_fail('userinfo status ' . $userResp['status'], '账号未激活或已被禁用，无法进入媒体库。');
}

$info = json_decode($userResp['body'], true);
if (! is_array($info) || ! isset($info['sub'], $info['email'], $info['groups'][0])) {
    visionsy_sso_fail('malformed userinfo payload');
}

$sub = (string) $info['sub'];
$email = (string) $info['email'];
$displayName = (string) ($info['name'] ?? $email);
$groupName = (string) $info['groups'][0];

$rsGroupId = $visionsy_sso_group_map[$groupName] ?? $visionsy_sso_fallback_group_id;
if ((int) $rsGroupId <= 0) {
    visionsy_sso_fail("group '{$groupName}' has no RS group id mapping (config.php)");
}

// RS 本地用户名：与官网用户一一对应且不与本地账号冲突
$rsUsername = 'visionsy_' . $sub;

// ---------------------------------------------------------------------
// 4. 创建 / 更新 RS 账号并同步用户组
//
// 需在 ResourceSpace 插件环境验证：
//   - get_user_by_username() / new_user() 的存在性与签名随 RS 版本变化；
//   - 字段更新此处直接写 user 表，列名（username/fullname/email/usergroup/
//     approved）需对照目标版本的表结构确认。
// ---------------------------------------------------------------------
$escapedUsername = escape_check($rsUsername); // RS 自带 SQL 转义；需在 ResourceSpace 插件环境验证

$existing = sql_query("SELECT ref, usergroup FROM user WHERE username = '{$escapedUsername}' LIMIT 1");

if (empty($existing)) {
    // 创建账号。优先使用 RS 官方 API（不同版本签名不同，先行验证）。
    if (function_exists('new_user')) {
        $newRef = new_user($rsUsername, (int) $rsGroupId); // 需在 ResourceSpace 插件环境验证：参数与返回值
        if ($newRef === false || $newRef === -1) {
            visionsy_sso_fail('new_user() failed for ' . $rsUsername);
        }
        $userRef = (int) $newRef;
    } else {
        visionsy_sso_fail('new_user() unavailable in this RS version');
    }
} else {
    $userRef = (int) $existing[0]['ref'];
}

// 每次登录同步邮箱 / 显示名 / 用户组（角色变更下次登录生效），并禁用本地密码登录
$escapedEmail = escape_check($email);
$escapedName = escape_check($displayName);
$randomPasswordHash = escape_check(password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT));

sql_query(
    "UPDATE user SET email = '{$escapedEmail}', fullname = '{$escapedName}', "
    . "usergroup = '" . (int) $rsGroupId . "', approved = 1, password = '{$randomPasswordHash}' "
    . "WHERE ref = '" . (int) $userRef . "'"
);

// ---------------------------------------------------------------------
// 5. 写 RS 登录会话
//
// 需在 ResourceSpace 插件环境验证：RS 的会话建立方式（不同版本分别使用
// rs_session 或 user 表 session 列 + 浏览器 cookie）。下面采用「user 表
// session 列 + cookie」的经典方式，目标版本若使用 rs_session 表请改写。
// ---------------------------------------------------------------------
$sessionKey = bin2hex(random_bytes(32));
$escapedSession = escape_check($sessionKey);
sql_query("UPDATE user SET session = '{$escapedSession}', lastactive = NOW() WHERE ref = '" . (int) $userRef . "'");

// cookie 名与路径以 RS 配置为准（默认 user / baseurl 路径）
setcookie('user', $sessionKey, [
    'expires' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: /pages/home.php', true, 302);
exit;
