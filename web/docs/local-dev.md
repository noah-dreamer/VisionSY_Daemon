# VisionSY 官网 —— 本地开发指南

## 一、环境要求

| 软件 | 版本 |
| --- | --- |
| PHP | ≥ 8.2（含 `sqlite3`、`mbstring`、`openssl`、**`gd`** 扩展；`gd` 用于媒体库水印与登录滑块验证码；连生产库才需要 `pdo_mysql`） |
| Composer | 2.x |
| Node.js | ≥ 20 |
| MySQL / MariaDB | **可选**。本地默认 SQLite；只有验证真实 MySQL 行为时才需要（`brew install mysql`） |

## 二、首次启动（SQLite）

```bash
cd web
composer install
npm install                     # 首次会生成 package-lock.json，请提交它

cp .env.example .env            # 或直接使用仓库外发的本地 .env 模板
# 将 .env 中数据库改为 SQLite：
#   DB_CONNECTION=sqlite / SESSION_DRIVER=file / CACHE_STORE=file / QUEUE_CONNECTION=sync / MAIL_MAILER=log
touch database/database.sqlite

php artisan key:generate
php artisan migrate:fresh --seed
php artisan test                # 全部测试应通过（sqlite 内存库）
```

## 二·五、日常开发（两个终端，保存即刷新）

```bash
# 终端 1
php artisan serve --host=127.0.0.1 --port=8000
# 终端 2
npm run dev                     # Vite 热更新，无需 npm run build
```

打开 http://127.0.0.1:8000 。修改 `resources/views/`、`resources/css|js/`、`routes/`、`app/Http/Controllers/` 等目录均会自动刷新浏览器（见 `vite.config.js` 的 refresh 列表）。`npm run build` 只在生产部署时执行。

在 VS Code 中推荐直接用任务一键完成（任务定义见仓库根 `.vscode/tasks.json`）：

1. `Cmd/Ctrl+Shift+P → Tasks: Run Task → VisionSY: 初始化开发环境`（首次；自动装依赖 + 复制 `.env.dev.example` 为 `.env` + 建 SQLite + 迁移 + 演示数据，含媒体库示例素材）
2. `Tasks: Run Task → VisionSY: 一键启动`（同时拉起 Laravel :8000 与 Vite）
3. 需要重来一遍数据时运行 `VisionSY: 重置简易数据库`；`VisionSY: 查看最新验证码邮件` 可直接从日志提取注册验证码。

### 可选：需要真实 MySQL / 收件箱时

```bash
brew install mysql && brew services start mysql     # .env 改回 mysql 连接
brew install mailpit && mailpit                     # SMTP 1025 / UI http://localhost:8025
# .env：MAIL_MAILER=smtp / MAIL_HOST=127.0.0.1 / MAIL_PORT=1025
```

想在浏览器里收注册验证邮件：把 `.env` 改为 `MAIL_MAILER=smtp`、`MAIL_HOST=127.0.0.1`、`MAIL_PORT=1025`，邮件会出现在 http://localhost:8025 。默认 `MAIL_MAILER=log` 时邮件写入 `storage/logs/laravel.log`，注册验证码（6 位数字）可从日志复制，也可运行 VS Code 任务「VisionSY: 查看最新验证码邮件」。

## 三、种子账号（密码均为 `<LOCAL_DEV_PASSWORD>`，由 `SEED_DEFAULT_PASSWORD` 控制）

| 邮箱 | 角色 | 状态 | 用途 |
| --- | --- | --- | --- |
| `admin@example.com` | system_admin | active | 管理后台 `/admin` |
| `media@example.com` | propaganda_member | active | 宣传部成员（原图下载组） |
| `teacher@example.com` | wechat_editor_teacher | active | 官微编辑老师 |
| `student@student.example.com` | normal_user | active | 已激活普通用户 |
| `pending@student.example.com` | normal_user | pending_activation | 演示校园 IP 激活流程 |

种子同时创建：校园网段 `10.20.0.0/16`、`10.30.0.0/16`（启用）与 `192.168.50.0/24`（禁用）、3 条审计日志样例。

## 四、本地模拟校园 IP 激活

测试环境信任 `127.0.0.1` 为代理（`TRUSTED_PROXY_IPS`），所以本机请求带 `X-Real-IP` 头即可模拟任意来源 IP：

```bash
# 用 pending@student.example.com 登录后，带头请求重检接口（或直接用浏览器插件改头）
curl -i -X POST http://localhost:8000/dashboard/recheck-activation \
  -H 'X-Real-IP: 10.20.1.2' \
  -b cookies.txt -H "X-CSRF-TOKEN: <从页面取>"
```

更直接的方式是跑测试：`tests/Feature/CampusActivationTest.php` 覆盖了命中 / 未命中 / 禁用网段 / 伪造头四种场景。

## 五、调试内部 API（transfer token）

```bash
SECRET='<INTERNAL_API_SECRET>'   # .env 的 INTERNAL_API_SECRET

# 生成（校外 IP -> 目标 files.example.com）
curl -s -X POST http://localhost:8000/api/transfer-tokens \
  -H "X-Internal-Secret: $SECRET" -H 'Content-Type: application/json' \
  -d '{"action":"download","original_method":"GET","client_ip":"8.8.8.8"}'

# 校验
curl -s "http://localhost:8000/api/transfer-tokens/validate?token=<上一步token>&host=files.example.com&action=download" \
  -H "X-Internal-Secret: $SECRET"

# 消费（一次性 token 第二次消费返回 403）
curl -s -X POST http://localhost:8000/api/transfer-tokens/consume \
  -H "X-Internal-Secret: $SECRET" -H 'Content-Type: application/json' \
  -d '{"token":"<token>"}'
```

## 六、常用命令

```bash
php artisan test                          # 全部测试
php artisan test --filter=OAuthFlowTest   # 只跑 OAuth 流程
npm run dev                               # Vite 热更新
php artisan migrate:fresh --seed          # 重置数据库
php artisan schedule:work                 # 本地跑调度（过期 token 清理）
```

## 七、沙箱 / 离线环境说明

本仓库代码在无网络环境中编写，`composer install`、`npm install` 与 `php artisan test` 需在你的本机（可联网）执行。若任何测试失败，优先检查 PHP 版本与扩展，其次按报错定位——所有测试都只依赖 sqlite 内存库。


## 附：集成版新增功能速览

| 功能 | 入口 | 说明 |
| --- | --- | --- |
| 内置媒体库（DAM） | `/media` | 统一账户与 UI；游客可浏览缩略图（可配置），登录后按角色分级下载 |
| 上传素材 | `/media/upload` | 宣传部 / 管理员 / 官微老师（可配置）；自动生成带水印缩略图与预览图；同内容（SHA-256）去重 |
| 分级下载 | 素材详情页 | 宣传部 / 官微 / 管理员可下无水印原图；已激活普通用户仅带水印压缩图 |
| 307 直连模式 | `MEDIA_DIRECT_SERVE=false` | 生产环境下载返回 307 到校内/校外直连域名，凭一次性 TransferToken 放行（本地默认 direct 直出） |
| 注册邮箱验证码 | `/email/verify` | 6 位数字，10 分钟有效、60 秒重发冷却、5 次尝试上限；本地写入日志 |
| 登录人机验证 | `/login` | 开源 PlayCaptcha 抓娃娃机（github.com/mortspace/playcaptcha）：npm 直接接入上游组件；服务端一次性挑战 + 耗时下限 + 一次性 token；`CAPTCHA_ENABLED=false` 可关闭（测试环境默认关闭） |
| 全部元数据 | 素材详情页 | 上传时 exiftool（优先）/ PHP exif / getID3 读取照片、视频全部内嵌标签，折叠面板逐组展示；快捷字段（拍摄时间/相机/时长等）入库 |
| 用户组管理 | 后台 → 用户组 | RS 式权限位（浏览/预览/水印图/原图/上传/管理）+ 每日下载限额 + IP 限制 + 注册默认组；用户管理页可指派组 |

角色与下载权限的完整矩阵见 `app/Policies/MediaResourcePolicy.php` 顶部注释。
