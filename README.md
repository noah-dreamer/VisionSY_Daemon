# VisionSY

[English](#english) | [简体中文](#简体中文)

<a id="english"></a>

## English

VisionSY is a campus-oriented visual media management platform. It combines a custom web portal for account management, authentication, role synchronization, OAuth2-based SSO, and integration APIs with a ResourceSpace-based media library for visual asset storage, review, access control, watermarking, and download workflows.

This repository is organized as a **monorepo**. The web application, ResourceSpace integration, deployment examples, and project documentation are maintained in separate directories while staying within one project repository.

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
│   ├── .env.example
│   ├── composer.json
│   ├── package.json
│   └── vite.config.js
│
├── resourcespace/
│   ├── README.md
│   ├── .gitignore
│   ├── plugin/
│   │   └── visionsy_sso/
│   └── docs/
│
├── deploy/
│   ├── README.md
│   ├── .gitignore
│   ├── nginx/
│   ├── reverse-proxy/
│   ├── systemd/
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

### ResourceSpace Integration

The `resourcespace/` directory contains the VisionSY integration for ResourceSpace.

It includes:

* ResourceSpace SSO plugin code
* OAuth2 client integration notes
* User group and role mapping documentation
* Permission and watermarking checklist

See [`resourcespace/README.md`](resourcespace/README.md) for details.

### Deployment Examples

The `deploy/` directory contains sanitized deployment examples and operational templates.

It may include example configuration for:

* Reverse proxy routing
* Nginx
* systemd services
* backup scripts
* deployment helper scripts

These examples are intended as references only. They must not contain real production secrets, internal IP addresses, private hostnames, certificates, private keys, or database backups.

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
| ResourceSpace integration | [`resourcespace/README.md`](resourcespace/README.md) |
| Deployment examples       | [`deploy/README.md`](deploy/README.md)               |
| General documentation     | [`docs/`](docs/)                                     |

For local development of the web portal, go to the `web/` directory first:

```bash
cd web
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

## License

License information will be added in [`LICENSE`](LICENSE).

---

<a id="简体中文"></a>

## 简体中文

VisionSY 是一个面向校园场景的视觉素材管理平台。它由自研官网与 ResourceSpace 媒体库两部分组成：官网负责账号体系、注册激活、鉴权、角色同步、OAuth2 SSO 与内部集成接口；ResourceSpace 负责视觉素材的存储、审核、权限控制、水印与下载流程。

本仓库采用 **monorepo** 结构：官网应用、ResourceSpace 接入、部署示例和项目文档统一放在一个仓库中维护，并通过不同目录清晰划分。

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
│   ├── .env.example
│   ├── composer.json
│   ├── package.json
│   └── vite.config.js
│
├── resourcespace/
│   ├── README.md
│   ├── .gitignore
│   ├── plugin/
│   │   └── visionsy_sso/
│   └── docs/
│
├── deploy/
│   ├── README.md
│   ├── .gitignore
│   ├── nginx/
│   ├── reverse-proxy/
│   ├── systemd/
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

### ResourceSpace 接入

`resourcespace/` 目录包含 VisionSY 与 ResourceSpace 的集成内容。

它包括：

* ResourceSpace SSO 插件代码
* OAuth2 Client 对接说明
* 用户角色与 ResourceSpace 用户组映射文档
* 权限与水印配置核对清单

详细说明见 [`resourcespace/README.md`](resourcespace/README.md)。

### 部署示例

`deploy/` 目录包含脱敏后的部署示例和运维模板。

它可以包括：

* 反向代理路由示例
* Nginx 示例配置
* systemd 服务模板
* 备份脚本示例
* 部署辅助脚本

这些内容仅作为参考示例，不应包含真实生产密钥、真实内网 IP、私有主机名、证书、私钥或数据库备份。

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
| ResourceSpace 接入 | [`resourcespace/README.md`](resourcespace/README.md) |
| 部署示例             | [`deploy/README.md`](deploy/README.md)               |
| 通用项目文档           | [`docs/`](docs/)                                     |

如果要本地运行官网应用，先进入 `web/` 目录：

```bash
cd web
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

## 许可证

许可证信息将写入 [`LICENSE`](LICENSE)。
