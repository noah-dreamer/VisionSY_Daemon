# ResourceSpace 接入（resourcespace/）

把 ResourceSpace（`10.0.0.20` / `https://media.example.com`）的登录托管给官网 SSO，并实现分级下载 + 自动水印。本目录包含两部分：要部署进 RS 的**插件代码**，以及在 RS 后台照着配置的**对接文档**。

```
resourcespace/
├── plugin/
│   └── visionsy_sso/       # 插件代码 → 复制到 RS 安装目录 plugins/visionsy_sso/
└── docs/
    ├── oauth-client.md          # OAuth2 客户端对接参数（端点、凭据、userinfo 字段、流程、安全要求）
    ├── group-mapping.md         # 官网角色 → RS 用户组映射表（固定，勿改名）
    └── permissions-watermark.md # 分级下载权限 + 水印逐项核对清单
```

---

## 一、插件部署要点

1. 把 `plugin/visionsy_sso/` 整个目录复制到 ResourceSpace 安装目录的 `plugins/visionsy_sso/`。
2. 在该目录下创建 `config.php`（**本仓库已 .gitignore，不入库**），填入：
   - `$visionsy_sso_client_secret`：从`<SECRET_MANAGER>` 取；
   - `$visionsy_sso_group_map`：真实 RS 用户组 ID（见 `docs/group-mapping.md`）。
3. 按 `docs/group-mapping.md` 在 RS 后台建好 5 个 `visionsy_*` 用户组，回填 ID。
4. 在 RS 登录页加「使用 VisionSY 账号登录」入口，指向 `/plugins/visionsy_sso/index.php`。

更细的安装步骤与「上线前必须验证的点」清单见 `plugin/visionsy_sso/README.md`。

---

## 二、一句话说明分级下载

| 官网角色 | RS 组 | 能做什么 |
| --- | --- | --- |
| system_admin | visionsy_system_admin | 全部管理 |
| propaganda_member | visionsy_propaganda_member | 上传 + 下载无水印原图 |
| wechat_editor_teacher | visionsy_wechat_editor_teacher | 下载无水印原图（不可上传） |
| normal_user | visionsy_normal_user | 仅下载带水印压缩图 |
| guest | visionsy_guest | 仅浏览缩略图，不可下载 |

---

## 三、安全红线

- 插件 `config.php` 含 secret，**绝不入库**（已在 `.gitignore`）。
- access token 仅服务端使用，不落浏览器；`state` 一次性校验防 CSRF。
- 所有依赖 RS 版本的调用点（include 路径、`new_user()`、会话写入、登录页 hook）在代码中都标注了「需在 ResourceSpace 插件环境验证」，请在测试实例确认后再上线，不要凭空假设 hook 存在。
