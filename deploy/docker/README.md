> 当前推荐的 daemon / dashboard 分体部署入口：[`../SPLIT-DEPLOYMENT.md`](../SPLIT-DEPLOYMENT.md)。
> `compose.split.yaml` 启动两个独立网络中的容器；地址与密钥由管理员在控制台网页中配置。
> 本文其余内容保留开发与旧单容器部署说明。

# VisionSY Docker Compose 部署（deploy/docker/）

生产使用一个容器：FrankenPHP、MariaDB 11.4、队列和调度器由 Supervisor 管理。
后端通过 `127.0.0.1:3306` 连接数据库；只发布网站端口。开发环境保留独立服务及 Vite 热重载。

Linux 生产服务器推荐在仓库根目录执行 `bash install.sh`，见[一键安装说明](../INSTALL-LINUX.md)。
安装器会生成独立配置 `.env.install` 并叠加 `compose.install.yaml`，以下手工命令保留给开发与运维参考。

---

## 文件

```text
deploy/docker/
├── compose.yaml          # 开发基础：app / worker / scheduler / db
├── compose.dev.yaml      # 开发覆盖：源码热重载 + Vite HMR + 暴露 DB 端口
├── compose.prod.yaml     # 独立生产配置：单个 app 容器，内置数据库和后台任务
├── .env.example          # 环境模板（通用，含全部字段说明）
└── README.md
# 应用镜像定义在 web/Dockerfile 与 web/docker/Caddyfile（构建上下文 = web/）
```

---

## 快速开始（开发，Windows / Linux 通用）

```bash
cd deploy/docker

# 1) 准备环境文件
cp .env.example .env

# 2) 首次构建并启动（dev = 基础 + 开发覆盖）
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env up -d --build

# 3) 生成 APP_KEY 并填回 .env（首次）
docker compose -f compose.yaml -f compose.dev.yaml exec app php artisan key:generate
#   将输出的 base64:... 写入 .env 的 APP_KEY，然后：
docker compose -f compose.yaml -f compose.dev.yaml up -d

# 4) 迁移（单独步骤，不在启动时自动执行）
docker compose -f compose.yaml -f compose.dev.yaml exec app php artisan migrate
#   仅可丢弃的开发库可 seed：php artisan migrate --seed

# 5) 访问
#   Web : http://localhost:8000
#   Vite HMR : http://localhost:5173
```

> 提示：在 Bash 中可设置别名以缩短命令：
> `alias dcd='docker compose -f compose.yaml -f compose.dev.yaml --env-file .env'`。

---

## 生产部署（Linux）

```bash
cd deploy/docker
cp .env.example .env       # 填入真实秘密（来自密钥管理），勿入库

# 设置 APP_ENV=production、APP_DEBUG=false，填入 APP_KEY 和数据库密码。
# 构建 + 启动（只使用独立生产文件）
docker compose -f compose.prod.yaml --env-file .env up -d --build --wait --wait-timeout 300

# 启动时自动 migrate --force，成功后才启动网站和后台任务；更新前先备份数据库。
docker compose -f compose.prod.yaml exec app php artisan config:cache
docker compose -f compose.prod.yaml exec app php artisan route:cache
docker compose -f compose.prod.yaml exec app php artisan view:cache

# 健康检查
curl -fsS http://127.0.0.1:8000/up
```

生产发布脚本（首次部署 / 日常更新 / 回滚）见 `deploy/scripts/`（阶段 3 提供）。
**单机无法真正零停机**：`up -d --build` 会重建并重启容器，存在秒级中断，请安排维护窗口。

---

## 媒体目录：自动扫描与上传共用

宿主机先挂载 SMB/NFS，再设置 `.env`：

```dotenv
# 相对路径（项目目录内，也可以位于已经挂载的 NAS 共享上）
DAM_HOST_MANAGED=./data/media
# 或宿主机的正式 NAS 挂载路径
# DAM_HOST_MANAGED=/mnt/nas/photos
```

Compose 将这个目录读写挂载到 `/data/visionsy/managed`。这是兼容已有配置的容器内路径名，已有文件和新上传文件共用该目录，不再区分受管/外部图库。

初始化默认目录：

```bash
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env exec app php artisan media:init-managed-source
```

调度器每分钟发现并重新扫描已启用的媒体目录。根目录直接存放的文件归属根图库；一级子目录作为其他图库，按配置递归扫描子目录。网站上传可以选择任意可写图库，默认写入根图库；文件名保留可辨认的原名并附加唯一后缀，同名文件不会覆盖。

```text
media/
├── 原有照片.jpg
├── 网页上传-唯一编号.jpg
├── preview/
│   └── 素材ID/
│       ├── thumb.jpg
│       ├── large.jpg
│       ├── watermarked_jpeg.jpg
│       └── watermarked_png.png
└── 活动相册/
    ├── 照片.jpg
    └── preview/
```

`preview/`、旧 `_visionsy/`、隐藏目录及符号链接会在遍历时跳过。原件变化后更新索引和预览，原件消失时标记 Missing。授权删除对扫描文件和上传文件使用同样的目录写权限规则。

新预览均写入图库内 `preview/`，因此 app/worker 需要对该目录有写权限。旧独立派生目录可通过自定义挂载和 `MEDIA_DERIVATIVES_ROOT` 保持读取兼容；新部署不需要独立 derivatives 或 sources 挂载。

更多说明见 [统一媒体库](../../docs/architecture/media-library.md)。

---

## 开发服务与运行方式

| 服务 | 镜像 | 命令 | 端口 |
| --- | --- | --- | --- |
| `app` | `visionsy-app` | FrankenPHP（`web/docker/Caddyfile`，根 `/app/public`） | `${APP_PORT}:80` |
| `worker` | `visionsy-app` | `php artisan queue:work --sleep=3 --tries=3 --timeout=3600 --max-time=3600 --max-jobs=1000` | 无 |
| `scheduler` | `visionsy-app` | `php artisan schedule:work` | 无 |
| `db` | `mariadb:11.4` | MariaDB（命名卷 `db_data`，healthcheck） | 开发暴露 `${DB_HOST_PORT}` |
| `vite` | `node:22-alpine` | `npm run dev -- --host 0.0.0.0`（仅 dev） | `${VITE_PORT}:5173` |

队列约定：`worker --timeout=3600` **<** database 队列 `retry_after`（`DB_QUEUE_RETRY_AFTER=3700`）。
扫描锁 stale 阈值 `MEDIA_SCAN_STALE_LOCK_MINUTES=75` **>** 扫描 Job timeout（60 分钟）。

---

## 持久化

| 卷 | 内容 |
| --- | --- |
| `storage` | Laravel `storage/`（开发多服务共享；生产容器内共用） |
| `bootstrap_cache` | `bootstrap/cache/`（共享一致） |
| `db_data` | MariaDB 数据 |
| 媒体目录 | `DAM_HOST_MANAGED` → `/data/visionsy/managed`，包含原件及图库内 `preview/` |

开发覆盖另用匿名卷保护 `/app/vendor`、`/app/node_modules`（源码绑定挂载时不被本机产物覆盖）。

---

## Windows 开发注意

- Docker Desktop 需在 Settings → Resources 中共享 `DAM_HOST_*` 所在盘符。
- 绑定挂载性能与文件事件较弱：Vite 已启用 `CHOKIDAR_USEPOLLING=true`。
- 源码绑定挂载下，`vendor` / `node_modules` 由匿名卷提供（Linux 兼容），无需本机 Composer。
- 部署与测试脚本通过 Node 调用 `docker compose`，可直接从终端运行。

---

## Vite HMR 说明（开发）

- Vite 服务监听 `0.0.0.0:5173`，浏览器经 `http://localhost:5173` 访问。
- Vite 与 `app` 共享源码绑定挂载，`public/hot` 对 `app` 可见，`@vite` 自动走 HMR。
- 若 HMR WebSocket 在 Docker 下解析到错误主机（如 `0.0.0.0`），可在 `web/vite.config.js`
  设置 `server.origin: 'http://localhost:5173'`；或在宿主机直接 `npm run dev`
  作为可靠回退。

---

## 验证清单

```bash
# 组合配置校验
docker compose -f compose.yaml -f compose.dev.yaml  --env-file .env config
docker compose -f compose.prod.yaml --env-file .env config

# 构建
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env build

# 隔离启动（独立 project name + 测试卷，不影响既有数据）
docker compose -p visionsy-test -f compose.yaml -f compose.dev.yaml --env-file .env up -d --wait
docker compose -p visionsy-test -f compose.yaml -f compose.dev.yaml ps
docker compose -p visionsy-test -f compose.yaml -f compose.dev.yaml exec app php artisan about
docker compose -p visionsy-test -f compose.yaml -f compose.dev.yaml exec app php artisan migrate:status
curl -fsS http://127.0.0.1:8000/up
node test.mjs        # 测试必须走此入口（隔离测试库），不要裸跑 php artisan test
docker compose -p visionsy-test down            # 保留卷；加 -v 才会删除数据（勿对真实数据执行）
```

## 在容器内运行测试

- **dev 镜像**经 `compose.dev.yaml` 的构建参数 `INSTALL_COMPOSER_DEV=1` 安装 Composer dev 依赖
  （phpunit/collision），因此开发容器内具备运行测试的能力。
- ⚠️ **必须用 `node deploy/docker/test.mjs` 运行，不要在容器内裸跑 `php artisan test`。**
  容器内 `env_file: .env` 注入的 `DB_CONNECTION=mysql` / `DB_DATABASE=visionsy` 是真实进程
  环境变量，会压掉 `phpunit.xml` 里 `force="false"` 的 `<env>` 值；而 `tests/Feature` 全部
  `use RefreshDatabase`，会在开发库上执行 `migrate:fresh`（drop 所有表且不 seed），
  直接后果是种子账号消失、登录报"邮箱或密码不正确"。
  `test.mjs` 用 `docker compose exec -e` 从外部注入测试变量，并指向独立的一次性库
  `visionsy_test`。

> 会话 A 环境已安装 Docker，上述构建 / 启动 / `/up` / MariaDB 迁移 / 唯一索引 / 容器内测试均已实测通过。

---

## 生产安全

### Trusted Proxies（反向代理）

当应用运行在反向代理（Caddy、API Gateway 等）之后时，Laravel 必须信任代理服务器设置的 `X-Forwarded-*` 头。

- `.env` 中设置 `TRUSTED_PROXY_IPS`（逗号分隔的代理 IP 列表，默认 `127.0.0.1`）。
- FrankenPHP/Caddy 通过 `header_down` 指令正确设置 `X-Forwarded-For/Proto/Host/Port`。
- Laravel `bootstrap/app.php` 中通过 `$middleware->trustProxies(at: [...])` 启用。

**生产示例**（`.env.prod.example`）：

```dotenv
TRUSTED_PROXY_IPS=127.0.0.1,10.0.0.30
```

### HTTPS 与 Session 安全

生产环境必须：

- `APP_ENV=production`，`APP_DEBUG=false`
- `SESSION_SECURE_COOKIE=true`（仅 HTTPS 传输 cookie）
- `SESSION_SAME_SITE=lax`
- `APP_KEY` 从外部密钥管理注入，不写入镜像
- 上传大小限制在 Caddy（`request_body max_size`）、PHP（`upload_max_filesize` / `post_max_size`）和 Laravel（`MEDIA_MAX_UPLOAD_MB`）三层一致，默认 512MB

### 错误处理

- 生产 `APP_DEBUG=false`：不向客户端暴露路径、命令和凭据。
- PHP `display_errors=Off`（由 `web/docker/php.prod.ini` 设置）。
- 详细错误仅写入日志（stdout/stderr），由 Docker 日志驱动收集。

---

## 健康与诊断

### `/up` 健康检查

Laravel `/up` 端点（`bootstrap/app.php` 中配置）无副作用，仅验证数据库连接。由 compose 中 app 服务的 healthcheck 每 30s 调用。

### 完整诊断命令

```bash
dc exec app php artisan visionsy:health-diagnostics
```

检查项：数据库连接、DAM 挂载点、存储空间、失败队列任务、迁移状态、图库与资源概览。

### 其他诊断

```bash
dc exec app php artisan about                    # Laravel 环境概览
dc exec app php artisan queue:failed             # 查看失败任务
dc exec app php artisan queue:monitor            # 监控队列大小
dc exec app php artisan media:identity-conflicts # 检查资源身份冲突
dc exec app php artisan media:legacy-stats       # legacy 资源统计
dc exec app php artisan migrate:status           # 迁移状态
```

### 日志

- 应用日志：Caddy 日志输出 `stdout`（JSON 格式），Laravel 日志输出到 `storage/logs/`（也可配 `LOG_CHANNEL=stderr`）。
- 日志轮转：docker compose 日志输出由 Docker 日志驱动管理；`storage/logs/` 内日志建议使用 `LOG_CHANNEL=daily` 或外部 logrotate。
- 查看日志：`dc logs --tail=100 app` / `dc logs -f worker`。

---

## 备份与恢复

### 备份

生产发布脚本 `deploy/scripts/deploy-docker.sh.example` 提供 `backup` 子命令：

```bash
sudo ./deploy-docker.sh backup
```

备份内容：

| 数据 | 备份方式 | 说明 |
| --- | --- | --- |
| DB | `mariadb-dump --single-transaction` → `.sql.gz` | 一致性快照，密码从容器环境变量注入 |
| managed | `tar -czf` → `.tar.gz` | VisionSY 管理的上传原件 |
| sources | **不备份** | 由来源系统（外部 NAS 等）自行备份 |
| derivatives | **不备份** | 可重建数据，通过扫描重新生成 |

备份命名格式：`{db}-{YYYYMMDD-HHMMSS}.sql.gz` / `managed-{YYYYMMDD-HHMMSS}.tar.gz`。

### 恢复

恢复为**手动步骤**（不提供一键恢复脚本，防止误操作）。详见 `deploy/scripts/deploy-docker.sh.example` 中的恢复 Runbook 注释。

简要流程：

1. 停止应用：`dc down`
2. 恢复数据库：`zcat backup.sql.gz | dc exec -T db sh -c 'mariadb ...'`
3. 恢复 managed：`tar -xzf backup.tar.gz`
4. 恢复 APP_KEY 到 `.env`
5. 启动：`dc up -d`
6. 迁移：`dc exec app php artisan migrate --force`
7. 校验：`dc exec app php artisan visionsy:health-diagnostics`

### 发布与回滚

```bash
sudo ./deploy-docker.sh update   # 日常更新（自动备份→构建→迁移→重载→健康检查）
sudo ./deploy-docker.sh install  # 首次部署
sudo ./deploy-docker.sh status   # 服务状态
```

回滚：单机部署无法零停机，需维护窗口。先从备份恢复数据库，再切换到旧镜像/commit。

## 单容器生产运维

生产命令统一使用 `docker compose -f compose.prod.yaml --env-file .env`；
一键安装的配置和项目名用法见 [Linux 安装说明](../INSTALL-LINUX.md)。
上面的独立 `worker`、`scheduler`、`db` 服务表及其日志命令仅适用于开发环境。
生产用 `exec app supervisorctl -c /etc/visionsy/supervisord.conf status` 查看内部进程，
用 `logs app` 查看所有日志。数据库备份也改为在 `app` 容器内执行 `mariadb-dump`。

首次启动初始化数据库，随后只执行增量迁移，不自动 seed，不清空数据。
Supervisor 先停止后台任务和网站，再停止数据库；长任务停止宽限最长 65 分钟。
数据库、Laravel storage 和启动缓存分别使用持久卷，媒体使用宿主机目录。
生产不再运行独立内网探测容器；需要内网识别时配置已有的内网探测 URL。
从多容器升级请使用安装器以便先备份、停止旧数据库并清理旧容器，避免两个数据库进程同时打开同一数据卷。
