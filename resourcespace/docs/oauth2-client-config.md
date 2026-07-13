# ResourceSpace 侧 OAuth2 客户端配置（对接 VisionSY 官网 SSO）

本文件描述 ResourceSpace（10.0.0.20，对外 `https://media.example.com`）作为 OAuth2 客户端接入官网（`https://www.example.com`）的全部参数。配套插件见仓库 `resourcespace-plugin/visionsy_sso/`。

## 一、端点信息（官网为 Provider）

| 项目 | 值 |
| --- | --- |
| 授权端点 Authorization Endpoint | `https://www.example.com/oauth/authorize` |
| 令牌端点 Token Endpoint | `https://www.example.com/oauth/token` |
| 用户信息端点 UserInfo Endpoint | `https://www.example.com/oauth/userinfo` |
| 授权类型 | `authorization_code`（支持 `refresh_token` 刷新） |
| Scope | `profile` |

内网直连优化：RS 与官网同段（10.0.0.20 → 10.0.0.10），插件中的服务端到服务端请求（token / userinfo）可将 `provider_internal_base_url` 配置为 `http://10.0.0.10`，避免出公网绕行；浏览器跳转（authorize）必须使用公网 `https://www.example.com`。

## 二、客户端凭据

| 项目 | 值 |
| --- | --- |
| client_id | `resourcespace` |
| client_secret | **不写入任何文件**，真实值在`<SECRET_MANAGER>`。本地开发种子值为 `<OAUTH_CLIENT_SECRET>`（`.env` 的 `SEED_RS_CLIENT_SECRET`）。 |
| redirect_uri | `https://media.example.com/plugins/visionsy_sso/pages/callback.php` |

官网后台「OAuth 客户端」页可重建客户端：secret 随机生成且只展示一次，生成后立即存入 `<SECRET_MANAGER>`，再填入 RS 插件配置。

## 三、userinfo 响应字段

```json
{
  "sub": "42",
  "email": "user@example.edu.cn",
  "name": "张同学",
  "real_name": null,
  "role": "normal_user",
  "groups": ["visionsy_normal_user"],
  "status": "active",
  "real_name_required": false
}
```

- `groups` 是 RS 侧用户组映射的唯一依据，每次登录都以最新值同步（角色变更后用户重新 SSO 即生效）。
- `status` 非 `active` 的用户官网侧直接拒绝授权（403），不会走到 RS。
- `real_name_required` 为 `true` 表示该角色要求实名但尚未填写，RS 侧可提示联系管理员补全。

## 四、登录流程

1. 用户访问 `https://media.example.com`，RS 登录页显示「使用 VisionSY 账号登录」按钮（插件注入）。
2. 浏览器跳转 `https://www.example.com/oauth/authorize?client_id=resourcespace&redirect_uri=...&response_type=code&state=<随机>`。
3. 官网校验登录态与账号状态后 302 携 `code` 回 `callback.php`。
4. `callback.php` 服务端 POST `oauth/token` 换取 access token，再 GET `oauth/userinfo`。
5. 按 `groups` 建立 / 更新 RS 本地账号与用户组，写 RS 会话，跳转首页。

## 五、安全要求

- token / userinfo 请求必须服务端发起，access token 不落浏览器。
- `state` 参数必须校验（插件已实现，存 PHP session）。
- 回调地址列表精确匹配，不使用通配。
- client_secret 仅存放于 RS 插件配置文件（`config.php`，不入 Git）与密钥管理服务。
