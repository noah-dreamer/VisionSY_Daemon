# VisionSY 官网（web/）

自研官网，Laravel 12。承担账号体系、注册与校园 IP 激活、后台管理、审计日志、大文件直连用的内部 transfer-token 接口，并**内置媒体资产管理（DAM）模块**（`/media`：统一账户库、统一 UI、分级下载、自动水印）。OAuth2 Provider 能力保留（供历史或后续第三方服务接入）。

**集成版新增**：
- `/media` 媒体库：上传、检索、带水印缩略图与预览、分级下载、归档、SHA-256 去重；上传时读取照片/视频**全部内嵌元数据**（exiftool 优先，回退 PHP exif + getID3，详情页可展开全部标签）；配置 ffmpeg 后视频自动抽帧生成水印封面；
- 后台新增「**用户组**」管理：权限位 + 每日下载限额 + IP 限制 + 注册默认组；用户管理可直接指派组，账号/权限管理与官网完全统一；
- 注册邮箱 **6 位验证码** 验证（10 分钟有效、60 秒重发冷却、5 次尝试上限）；
- 登录接入开源 **PlayCaptcha**（github.com/mortspace/playcaptcha，React 抓娃娃机组件；npm 依赖 + postinstall 自动复制官方素材；服务端保留一次性挑战/耗时下限/一次性 token 防线）；
- 依赖新增 **ext-gd**（必需）、composer 包 **getid3**；建议安装 **exiftool**（完整元数据）与 **ffmpeg**（视频封面）。

- 内网：`10.0.0.10`
- 对外：`https://www.example.com`
- 生产部署目录：`/var/www/example/web`（本仓库是 monorepo，Laravel 在 `web/` 子目录；部署路径说明见根目录与 `deploy/README.md`）

---

## 一、技术栈

| 项 | 版本 / 说明 |
| --- | --- |
| PHP | ≥ 8.2（`pdo_mysql`、`mbstring`、`openssl`、`sqlite3`、**`gd`**） |
| Laravel | 12 |
| 前端 | Vite + Tailwind（设计系统在 `resources/`） |
| 数据库 | 生产 MySQL；测试用 sqlite 内存库 |
| Node | ≥ 20 |

---

## 二、目录速览

```
web/
├── app/            # 枚举(UserRole/UserStatus)、模型、服务类、控制器、中间件
├── config/         # 含 config/visionsy.php：固定域名、角色组映射、token 配置
├── database/       # 迁移、工厂、种子(DatabaseSeeder)
├── resources/      # Blade 视图 + Tailwind 设计系统 + app.js
├── routes/         # web.php、console.php 等
├── tests/          # Feature 测试（OAuth 流程、校园激活、后台、鉴权…）
├── public/  bootstrap/
├── composer.json  package.json
└── phpunit.xml
```

---

## 三、本地快速开始

**默认：Docker Compose**（FrankenPHP + MariaDB，见 `../deploy/docker/README.md`）：

```bash
cd ../deploy/docker
cp .env.dev.example .env
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env up -d --build
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec -T app php artisan key:generate --show
#   将输出密钥填入 .env 的 APP_KEY 后再次 up -d
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec -T app php artisan migrate
#   Web http://localhost:8000 · Vite HMR http://localhost:5173
```


---

## 四、与其他组件的接口

- **作为 OAuth2 Provider**：`/oauth/authorize`、`/oauth/token`、`/oauth/userinfo`；客户端配置见 OAuth 客户端后台页。
- **内部 transfer-token 接口**：`/api/transfer-tokens*`，用 `X-Internal-Secret` 鉴权，给大文件 307 直连发 / 验 / 销 token。

---

## 五、上线前务必

- `.env` 的 `INTERNAL_API_SECRET` 换成密钥管理服务真实值；
- `TRUSTED_PROXY_IPS` 确认为 反向代理网关 地址 `10.0.0.30`；
- 后台「OAuth 客户端」创建客户端，拿到正式 secret（只展示一次，立刻存密钥管理服务）。

> 注意：本仓库代码在无网络环境编写，`composer install` / `npm run build` / `php artisan test` 需在你本机执行；测试只依赖 sqlite。
