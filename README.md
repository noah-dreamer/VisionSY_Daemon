# VisionSY

> **⚡ 集成版（Integrated Edition）说明**
>
> 本版本将媒体资产管理（DAM）**直接内置进官网应用**（`web/`），统一架构、统一账号、统一部署：
>
> - **统一账户**：媒体库直接使用官网的用户表与角色体系（宣传部 / 官微老师 / 普通用户 / 游客），无 SSO 跳转、无第二套账号数据库；
> - **统一 UI**：媒体库页面复用官网的设计系统（同一套 Tailwind 设计令牌、卡片、按钮、徽章组件），视觉零违和；
> - **核心能力**：上传时读取照片/视频**全部内嵌标签**（exiftool 优先，回退 exif/getID3）、分级下载 + GD 自动平铺水印、SHA-256 去重、归档、审计；
> - **用户/用户组/权限统一在官网后台管理**：权限位 + 每日下载限额 + IP 限制 + 注册默认组，与账号体系同库同后台，零割裂；
> - **注册邮箱验证码**：注册后发送 6 位数字验证码（10 分钟有效、60 秒重发冷却、5 次尝试上限）；
> - **登录人机验证**：直接接入开源 [PlayCaptcha](https://github.com/mortspace/playcaptcha)（React 抓娃娃机组件，npm 包 + 官方素材，服务端叠加一次性挑战/耗时下限/一次性 token 防线）；
> - **一键测试（Docker）**：`.vscode/tasks.json` 提供「初始化开发环境 (Docker)」「一键部署 (开发)」任务，Docker Compose + FrankenPHP + MariaDB，开箱即用（见下方 Quick Start）；
>
>
> **Quick Start（Docker Compose，推荐）**：安装 Docker Desktop 后，打开仓库 → `Ctrl/Cmd+Shift+P` → *Tasks: Run Task* → **VisionSY: 初始化开发环境 (Docker)**（首次）→ **VisionSY: 一键部署 (开发)**（日常：构建+启动+迁移）→ 访问 <http://localhost:8000>（Vite HMR :5173）。
> 命令行方式见 [`deploy/docker/README.md`](deploy/docker/README.md)。
> 种子账号密码统一 `visionsy-dev`：`admin@example.com`（管理员）、`media@example.com`（宣传部，可上传/下原图）、`teacher@example.com`（官微编辑）、`student@student.example.com`（普通用户，仅水印图）。
>
>
> 依赖（Docker 方式）：Docker Desktop（Windows）或 Docker Engine + Compose（Linux）。

[English](#english) | [简体中文](#简体中文)

<a id="english"></a>

## English

VisionSY is a campus-oriented visual media management platform. It combines a custom web portal for account management, authentication, role synchronization, and integration APIs with a built-in media library (DAM) for visual asset storage, review, access control, watermarking, and download workflows.

This repository is organized as a **monorepo**. The web application, deployment examples, and project documentation are maintained in separate directories while staying within one project repository.

## Project Status

VisionSY is currently being prepared for a public source-code release.

This repository contains source code, sanitized configuration examples, and public-facing documentation. Production secrets, private deployment details, internal network information, database backups, and environment-specific credentials are intentionally excluded.

## Repository Structure

```text
VisionSY/
├── README.md
├── LICENSE
├── .gitignore
│
├── web/
│   ├── README.md
│   ├── .gitignore
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   ├── tests/
│   ├── docs/
│   ├── composer.json
│   ├── package.json
│   └── vite.config.js
│
├── deploy/
│   ├── README.md
│   ├── .gitignore
│   ├── docker/
│   └── scripts/
│
└── docs/
```

## Components

### Web Portal

The `web/` directory contains the Laravel-based VisionSY web portal.

It provides:

* Account registration and activation
* Authentication and user management
* Role-based access model
* OAuth2 SSO provider functionality
* Internal integration APIs
* Administrative workflows for campus media access

See [`web/README.md`](web/README.md) for development and setup instructions.

### Deployment

The `deploy/` directory contains Docker Compose deployment templates and operational scripts.
Default entry point is [`deploy/docker/`](deploy/docker/).

It provides:

* Docker Compose files (base / dev / production)
* Cross-platform init script (`init.mjs`)
* Production deploy script (`deploy-docker.sh.example`)
* Environment file examples (.env / .env.dev / .env.prod)

See [`deploy/README.md`](deploy/README.md) for details.

See [`deploy/README.md`](deploy/README.md) for details.

### Documentation

The `docs/` directory contains cross-component documentation, architecture notes, design decisions, and public project documents.

## Security Notice

This public repository must not contain:

* `.env` files
* Production secrets
* API tokens
* OAuth client secrets
* Database passwords
* Private keys or certificates
* Real internal IP addresses
* Private network topology
* Production-only deployment details
* Database dumps
* Runtime logs
* Cache files
* Private operational notes

Use placeholder values such as `example.com`, `10.0.0.0/24`, `<APP_SECRET>`, and `<YOUR_DOMAIN>` in public documentation and example configuration.

## Getting Started

Start with the component you want to work on:

| Area                      | Entry Point                                          |
| ------------------------- | ---------------------------------------------------- |
| Web portal development    | [`web/README.md`](web/README.md)                     |
| Deployment                | [`deploy/README.md`](deploy/README.md)               |
| General documentation     | [`docs/`](docs/)                                     |

For local development, the default path is **Docker Compose** (FrankenPHP + MariaDB):

```bash
cd deploy/docker
cp .env.dev.example .env          # then edit secrets / DAM_HOST_* paths
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env up -d --build
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec -T app php artisan key:generate --show
#   paste the printed key into .env (APP_KEY), then: docker compose ... up -d
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec -T app php artisan migrate
#   Web: http://localhost:8000   Vite HMR: http://localhost:5173
```

See [`deploy/docker/README.md`](deploy/docker/README.md) for full details (Windows/Linux DAM mounts, production deploy).

## License

License information will be added in [`LICENSE`](LICENSE).

---

<a id="简体中文"></a>

## 简体中文

VisionSY 是一个面向校园场景的视觉素材管理平台。它由自研官网与内置媒体库（DAM）组成：官网负责账号体系、注册激活、鉴权、角色权限与内部集成接口；内置 DAM 负责视觉素材的存储、审核、权限控制、水印与下载流程。

本仓库采用 **monorepo** 结构：官网应用、部署模板和项目文档统一放在一个仓库中维护，并通过不同目录清晰划分。

## 项目状态

VisionSY 当前正在整理私有源码版本。

本仓库只包含源码、脱敏后的配置示例和可公开文档。生产密钥、真实部署细节、内网信息、数据库备份和环境专用凭据不会进入公开仓库。

## 仓库结构

```text
VisionSY/
├── README.md
├── LICENSE
├── .gitignore
│
├── web/
│   ├── README.md
│   ├── .gitignore
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   ├── tests/
│   ├── docs/
│   ├── artisan
│   ├── composer.json
│   ├── package.json
│   └── vite.config.js
│
├── deploy/
│   ├── README.md
│   ├── .gitignore
│   ├── docker/
│   └── scripts/
│
└── docs/
```

## 组件说明

### 官网应用

`web/` 目录包含 VisionSY 的 Laravel 官网应用。

它主要提供：

* 用户注册与账号激活
* 登录鉴权与用户管理
* 基于角色的访问模型
* OAuth2 SSO Provider 能力
* 内部集成接口
* 校园媒体访问相关的管理流程

开发与运行说明见 [`web/README.md`](web/README.md)。

### 部署

`deploy/` 目录包含 Docker Compose 部署模板与运维脚本。默认入口为 [`deploy/docker/`](deploy/docker/)。

它提供：

* Docker Compose 文件（base / dev / production）
* 跨平台初始化脚本（`init.mjs`）
* 生产发布脚本（`deploy-docker.sh.example`）
* 环境文件示例（.env / .env.dev / .env.prod）

详细说明见 [`deploy/README.md`](deploy/README.md)。

### 项目文档

`docs/` 目录用于放置跨组件的文档，例如架构说明、设计决策、公开项目说明等。

## 安全说明

公开仓库中不得包含：

* `.env` 文件
* 生产密钥
* API token
* OAuth client secret
* 数据库密码
* 私钥或证书
* 真实内网 IP
* 私有网络拓扑
* 生产环境专用部署细节
* 数据库备份
* 运行日志
* 缓存文件
* 私有运维说明

公开文档和示例配置中请使用 `example.com`、`10.0.0.0/24`、`<APP_SECRET>`、`<YOUR_DOMAIN>` 等占位值。

## 从哪里开始

你可以根据要处理的内容进入对应目录：

| 内容               | 入口                                                   |
| ---------------- | ---------------------------------------------------- |
| 官网应用开发           | [`web/README.md`](web/README.md)                     |
| 部署入口             | [`deploy/README.md`](deploy/README.md)               |
| 通用项目文档           | [`docs/`](docs/)                                     |

如果要本地运行，默认使用 **Docker Compose**（FrankenPHP + MariaDB）：

```bash
cd deploy/docker
cp .env.dev.example .env          # 然后修改秘密与 DAM_HOST_* 路径
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env up -d --build
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec -T app php artisan key:generate --show
#   将输出的密钥填入 .env 的 APP_KEY，然后：docker compose ... up -d
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec -T app php artisan migrate
#   Web: http://localhost:8000   Vite HMR: http://localhost:5173
```

完整说明（Windows/Linux DAM 挂载、生产部署）见 [`deploy/docker/README.md`](deploy/docker/README.md)。

## 许可证

许可证信息将写入 [`LICENSE`](LICENSE)。
