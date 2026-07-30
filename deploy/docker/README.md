# VisionSY Docker Compose 部署（deploy/docker/）

统一的开发与生产容器入口：**FrankenPHP + Docker Compose**。`app` / `worker` /
`scheduler` 共享同一应用镜像，以不同命令运行；Windows 测试机与 Linux 生产机共用
同一套 compose 与**相同的容器内路径**。

---

## 文件

```text
deploy/docker/
├── compose.yaml          # 基础：app / worker / scheduler / db（dev+prod 共享）
├── compose.dev.yaml      # 开发覆盖：源码热重载 + Vite HMR + 暴露 DB 端口
├── compose.prod.yaml     # 生产覆盖：restart=always、不发布 DB 端口
├── .env.example          # 环境模板（通用，含全部字段说明）
├── .env.dev.example      # 开发环境示例
├── .env.prod.example     # 生产环境示例（占位符）
└── README.md
# 应用镜像定义在 web/Dockerfile 与 web/docker/Caddyfile（构建上下文 = web/）
```

---

## 快速开始（开发，Windows / Linux 通用）

```bash
cd deploy/docker

# 1) 准备环境文件
cp .env.dev.example .env

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

> 提示：命令较长，可用 VS Code 任务（见仓库 `.vscode/tasks.json`）或设置别名
> `alias dcd='docker compose -f compose.yaml -f compose.dev.yaml --env-file .env'`。

---

## 生产部署（Linux）

```bash
cd deploy/docker
cp .env.prod.example .env       # 填入真实秘密（来自密钥管理），勿入库

# 构建 + 启动（prod = 基础 + 生产覆盖）
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env up -d --build

# 迁移（发布步骤，先备份数据库）
docker compose -f compose.yaml -f compose.prod.yaml exec app php artisan migrate
docker compose -f compose.yaml -f compose.prod.yaml exec app php artisan config:cache
docker compose -f compose.yaml -f compose.prod.yaml exec app php artisan route:cache
docker compose -f compose.yaml -f compose.prod.yaml exec app php artisan view:cache

# 健康检查
curl -fsS http://127.0.0.1:8000/up
```

生产发布脚本（首次部署 / 日常更新 / 回滚）见 `deploy/scripts/`（阶段 3 提供）。
**单机无法真正零停机**：`up -d --build` 会重建并重启容器，存在秒级中断，请安排维护窗口。

---

## DAM 挂载（managed / sources / derivatives）

容器内路径**固定**（Windows 与 Linux 一致）：

| 容器路径 | 读写 | 说明 |
| --- | --- | --- |
| `/data/visionsy/managed` | 可读写 | VisionSY 管理的新上传原件 |
| `/data/visionsy/sources` | **只读**（`:ro`） | 外部图库来源原件 |
| `/data/visionsy/derivatives` | worker 可写 | 缩略图 / 预览 / 水印派生 |

宿主机路径由 `.env` 的 `DAM_HOST_*` 提供，**仅此处**因平台而异：

```dotenv
# Windows（Docker Desktop，正斜杠）
DAM_HOST_MANAGED=D:/visionsy/managed
DAM_HOST_SOURCES=D:/visionsy/sources
DAM_HOST_DERIVATIVES=D:/visionsy/derivatives

# Linux
DAM_HOST_MANAGED=/srv/visionsy/managed
DAM_HOST_SOURCES=/srv/visionsy/sources
DAM_HOST_DERIVATIVES=/srv/visionsy/derivatives

# 或相对路径（相对本目录，开箱即用；数据落在 deploy/docker/data/，已被 .gitignore）
DAM_HOST_MANAGED=./data/managed
DAM_HOST_SOURCES=./data/sources
DAM_HOST_DERIVATIVES=./data/derivatives
```

> SMB/NFS 由**宿主机**挂载到上述目录，应用容器不负责挂载、不持有凭据。
> 数据库与业务配置只保存**容器内路径**（如 `/data/visionsy/sources`），
> 不保存宿主机盘符或绝对路径。`MEDIA_ALLOWED_ROOTS` 同理使用容器内路径。

---

## 服务与运行方式

| 服务 | 镜像 | 命令 | 端口 |
| --- | --- | --- | --- |
| `app` | `visionsy-app` | FrankenPHP（`web/docker/Caddyfile`，根 `/app/public`） | `${APP_PORT}:80` |
| `worker` | `visionsy-app` | `php artisan queue:work --sleep=3 --tries=3 --timeout=3600 --max-time=3600 --max-jobs=1000` | 无 |
| `scheduler` | `visionsy-app` | `php artisan schedule:work` | 无 |
| `db` | `mariadb:11.4` | MariaDB（命名卷 `db_data`，healthcheck） | dev 暴露 `${DB_HOST_PORT}`；prod 不暴露 |
| `vite` | `node:22-alpine` | `npm run dev -- --host 0.0.0.0`（仅 dev） | `${VITE_PORT}:5173` |

队列约定：`worker --timeout=3600` **<** database 队列 `retry_after`（`DB_QUEUE_RETRY_AFTER=3700`）。
扫描锁 stale 阈值 `MEDIA_SCAN_STALE_LOCK_MINUTES=75` **>** 扫描 Job timeout（60 分钟）。

---

## 持久化

| 卷 | 内容 |
| --- | --- |
| `storage` | Laravel `storage/`（app/worker/scheduler 共享一致） |
| `bootstrap_cache` | `bootstrap/cache/`（共享一致） |
| `db_data` | MariaDB 数据 |
| DAM bind | `DAM_HOST_*` → `/data/visionsy/*` |

开发覆盖另用匿名卷保护 `/app/vendor`、`/app/node_modules`（源码绑定挂载时不被本机产物覆盖）。

---

## Windows 开发注意

- Docker Desktop 需在 Settings → Resources 中共享 `DAM_HOST_*` 所在盘符。
- 绑定挂载性能与文件事件较弱：Vite 已启用 `CHOKIDAR_USEPOLLING=true`。
- 源码绑定挂载下，`vendor` / `node_modules` 由匿名卷提供（Linux 兼容），无需本机 Composer。
- 任务入口不依赖 bash 特有命令（见 `.vscode/tasks.json`，均为 `docker compose` 调用）。

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
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env config

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
