# visionsy_sso —— ResourceSpace 接入 VisionSY 官网 SSO 插件

把 ResourceSpace 的登录托管给 VisionSY 官网（OAuth2 Authorization Code Flow）：用户在官网注册并激活后，免密进入媒体库；角色与用户组每次登录自动同步。

## 一、文件结构

```
plugins/visionsy_sso/
├── index.php             # 登录入口：生成 state 并跳转官网授权端点
├── config.default.php    # 默认配置（不要修改）
├── config.php            # 站点本地配置（自行创建，含 client_secret，不入 Git）
└── pages/
    └── callback.php      # OAuth2 回调：换 token、取 userinfo、建账号、写会话
```

## 二、安装步骤

1. 将本目录整体复制到 ResourceSpace 安装目录的 `plugins/visionsy_sso/`。
2. 创建 `plugins/visionsy_sso/config.php`：

   ```php
   <?php
   // client_secret 真实值来自`<SECRET_MANAGER>`
   $visionsy_sso_client_secret = '<从密钥管理服务取出后填入>';

   // RS 实际用户组 ID（见 deploy/resourcespace/group-mapping.md）
   $visionsy_sso_group_map = [
       'visionsy_system_admin'          => 1,
       'visionsy_propaganda_member'     => 10,
       'visionsy_wechat_editor_teacher' => 11,
       'visionsy_normal_user'           => 12,
       'visionsy_guest'                 => 13,
   ];
   $visionsy_sso_fallback_group_id = 13;
   ```

3. 按 `deploy/resourcespace/group-mapping.md` 在 RS 后台创建 5 个 `visionsy_*` 用户组并回填上面的 ID。
4. 在官网后台「OAuth 客户端」确认存在 `client_id = resourcespace`，回调地址为
   `https://media.example.com/plugins/visionsy_sso/pages/callback.php`（种子已内置；重建时 secret 只展示一次，立即入密钥管理服务）。
5. 在 RS 登录页加入入口链接（指向 `/plugins/visionsy_sso/index.php`）：
   - 最稳妥方式：修改登录页模板加一个「使用 VisionSY 账号登录」按钮；
   - 若希望通过 RS 插件 hook 注入登录页 UI：**需在 ResourceSpace 插件环境验证**目标版本登录页可用的 hook 名称后再实现，本插件刻意不预置未经验证的 hook 代码。

## 三、上线前必须验证的点（已在代码中逐处标注）

| # | 验证点 | 位置 |
| --- | --- | --- |
| 1 | `include/db.php`、`include/authenticate.php` 的相对路径层级 | `index.php`、`pages/callback.php` 顶部 |
| 2 | `new_user()` 是否存在及其参数 / 返回值 | `callback.php` 第 4 步 |
| 3 | `user` 表列名（`username/fullname/email/usergroup/approved/password/session`） | `callback.php` 第 4、5 步 |
| 4 | 会话机制：目标版本使用 `user.session` 列还是 `rs_session` 表，cookie 名是否为 `user` | `callback.php` 第 5 步 |
| 5 | 登录页注入按钮的 hook（如需要） | 见上节第 5 条 |

## 四、安全说明

- access token 仅在服务端使用，不写入浏览器。
- `state` 一次性校验，防 CSRF / 授权码注入。
- 同步时把 RS 本地密码覆盖为随机值，`visionsy_*` 账号无法绕过 SSO 用本地密码登录。
- 官网侧已保证：`pending_activation` 与 `disabled` 用户在授权端点即被拒绝（403），不会到达本插件。
