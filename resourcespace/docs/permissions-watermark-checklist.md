# ResourceSpace 权限与水印配置核对清单

按本清单在 RS 后台逐项配置并勾选确认，实现 README 要求的「分级下载 + 自动水印」。具体选项路径以实际部署的 RS 版本为准；**标注「需在 ResourceSpace 环境验证」的条目请在测试实例上先行确认**。

## 一、水印

- [ ] 启用全局水印：`config.php` 中设置 `$watermark = "gfx/watermark.png";`（上传学校 logo 半透明水印图到该路径）。
- [ ] 预览图 / 压缩图（scr、pre 尺寸）应用水印；缩略图（thm、col）是否加水印按需选择。
- [ ] 重建已有资源预览以套用水印：`php pages/tools/update_previews.php`（大库耗时长，建议夜间执行）。需在 ResourceSpace 环境验证：不同版本该脚本路径与参数可能不同。

## 二、分级下载权限（按 group-mapping.md 的 5 个组）

权限串在「用户组管理 → 编辑组 → 权限」中配置。核心目标：

- [ ] `visionsy_system_admin`：管理员权限（参照 Administrators 复制）。
- [ ] `visionsy_propaganda_member`：
  - [ ] 允许上传（贡献资源）与编辑元数据；
  - [ ] 允许下载原始文件（原图，无水印）。
- [ ] `visionsy_wechat_editor_teacher`：
  - [ ] 禁止上传；
  - [ ] 允许下载原始文件（原图，无水印）。
- [ ] `visionsy_normal_user`：
  - [ ] 禁止上传；
  - [ ] **禁止下载原始文件**，仅允许下载带水印的预览 / 压缩尺寸（在「下载尺寸限制」中仅勾选 scr/pre 等带水印尺寸）。需在 ResourceSpace 环境验证：限制原图下载的权限标志（如 `T` 系列 restrict 标志）在不同版本中的具体写法。
- [ ] `visionsy_guest`：只读浏览公开资源，禁止任何下载。

- [ ] 用三个测试账号（普通 / 老师 / 宣传部）各自登录，逐一验证「下载」菜单出现的尺寸列表符合预期。

## 三、大文件 307 直连对接

- [ ] RS 下载与分片上传路径（`/filestore/`、`/pages/ajax/chunk_upload.php`）已在 边缘服务器 Nginx 配置 307（见 `deploy/nginx/media.conf.example`），RS 侧无需改动即可被动直连。
- [ ] 若启用 token 直连闭环：RS 在生成下载 / 上传链接时向官网 `POST /api/transfer-tokens`（头 `X-Internal-Secret`，内网地址 `http://10.0.0.10/api/transfer-tokens`）申请 token，并把返回的 `token` 追加为链接查询参数、把 `target_base_url` 作为链接 host。需在 ResourceSpace 环境验证：注入下载 URL 的 hook 名称（不同版本下载链接生成位置不同），不要凭空假设 hook 存在。
- [ ] 反向代理网关 侧鉴权（无 token 403）按 `deploy/reverse-proxy/rules.example.md` 配置完成。

## 四、安全核对

- [ ] RS 后台 `/pages/admin/*` 已在 反向代理网关 限制为内网 IP 段访问。
- [ ] RS 本地登录入口保留 `system_admin` 应急账号一枚（密码入`<SECRET_MANAGER>`），其余用户一律走 SSO。
- [ ] 关闭 RS 自助注册（注册统一走官网）。
- [ ] RS 数据库凭据仅存在 RS 容器内配置与`<SECRET_MANAGER>`。
