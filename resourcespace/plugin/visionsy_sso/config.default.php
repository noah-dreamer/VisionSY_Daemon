<?php
/**
 * VisionSY SSO 插件 —— 默认配置
 *
 * 不要直接修改本文件。站点本地配置写在同目录 config.php 中覆盖，
 * 尤其是 $visionsy_sso_client_secret —— 真实值来自 `<SECRET_MANAGER>`
 * <SECRET_MANAGER>，config.php 不提交 Git。
 */

// 官网 Provider 基础地址（浏览器跳转使用，必须公网域名）
$visionsy_sso_provider_base_url = 'https://auth.vssy.cn';

// 服务端到服务端请求（token / userinfo）使用的地址。
// RS 与官网同内网段，走内网直连，不经过公网。
$visionsy_sso_provider_internal_base_url = 'https://auth.vssy.cn';

// 客户端凭据（secret 在 config.php 中覆盖，禁止写在这里）
$visionsy_sso_client_id = 'resourcespace';
$visionsy_sso_client_secret = '<OAUTH_CLIENT_SECRET>';

// 回调地址：必须与官网后台 OAuth 客户端登记的完全一致
$visionsy_sso_redirect_uri = 'https://media.example.com/plugins/visionsy_sso/pages/callback.php';

// 官网用户组名 -> ResourceSpace 用户组 ID 映射。
// 组 ID 以实际 RS 实例为准（见 deploy/resourcespace/group-mapping.md），
// 在 config.php 中覆盖为真实值。
$visionsy_sso_group_map = [
    'visionsy_system_admin' => 0,
    'visionsy_propaganda_member' => 0,
    'visionsy_wechat_editor_teacher' => 0,
    'visionsy_normal_user' => 0,
    'visionsy_guest' => 0,
];

// 当 userinfo 返回的组不在映射表中时使用的兜底 RS 组 ID（建议指向最低权限组）
$visionsy_sso_fallback_group_id = 0;

// 服务端请求超时（秒）
$visionsy_sso_http_timeout = 10;
