# VisionSY 官网（web/）

自研官网，Laravel 12。承担账号体系、注册与校园 IP 激活、后台管理、审计日志，并作为 **OAuth2 Provider** 给 ResourceSpace 提供 SSO，以及大文件直连用的内部 transfer-token 接口。

- 内网：`10.0.0.10`
- 对外：`https://www.example.com`
- 生产部署目录：`/var/www/example/web`（本仓库是 monorepo，Laravel 在 `web/` 子目录；部署路径说明见根目录与 `deploy/README.md`）

---

## 一、技术栈

| 项 | 版本 / 说明 |
| --- | --- |
| PHP | ≥ 8.2（`pdo_mysql`、`mbstring`、`openssl`、`sqlite3`） |
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
├── docs/local-dev.md   # 本地开发完整指南
├── .env.example
├── composer.json  package.json
└── phpunit.xml
```

---

## 三、本地快速开始

完整版见 `docs/local-dev.md`，简版：

```bash
cp .env.example .env        # 数据库用 SQLite，无需任何容器
composer install
npm install
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan test            # 测试用 sqlite 内存库
php artisan serve           # 终端 1：http://127.0.0.1:8000
npm run dev                 # 终端 2：保存即刷新（生产才 npm run build）
```

种子账号密码统一 `<LOCAL_DEV_PASSWORD>`：`admin@example.com`（后台）、`media@example.com`（宣传部 / 原图组）、`teacher@example.com`（官微老师）、`student@student.example.com`（已激活）、`pending@student.example.com`（演示校园 IP 激活）。

---

## 四、与其他组件的接口

- **作为 OAuth2 Provider**：`/oauth/authorize`、`/oauth/token`、`/oauth/userinfo`；客户端配置见 `../resourcespace/docs/oauth-client.md`。
- **内部 transfer-token 接口**：`/api/transfer-tokens*`，用 `X-Internal-Secret` 鉴权，给大文件 307 直连发 / 验 / 销 token。

---

## 五、上线前务必

- `.env` 的 `INTERNAL_API_SECRET` 换成密钥管理服务真实值；
- `TRUSTED_PROXY_IPS` 确认为 反向代理网关 地址 `10.0.0.30`；
- 后台「OAuth 客户端」重建 `resourcespace` 客户端，拿到正式 secret（只展示一次，立刻存密钥管理服务并填进 RS 插件）。

> 注意：本仓库代码在无网络环境编写，`composer install` / `npm run build` / `php artisan test` 需在你本机执行；测试只依赖 sqlite。
