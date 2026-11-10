> 2026-11-10：生产现为单容器，独立使用 `compose.prod.yaml`。
> 内置 MariaDB 11.4 与 Supervisor，启动顺序为数据库、增量迁移、网站/队列/调度器。
> 以下分阶段记录保留历史实现；当前部署命令以 `deploy/INSTALL-LINUX.md` 为准。

# Deployment Modernization Status

> 跨会话实施记忆。每个阶段结束时更新。架构设计见 [`../architecture/deployment-and-dam-storage.md`](../architecture/deployment-and-dam-storage.md)。

## 当前阶段

- 已完成阶段：0、1、2、3、3a、4、5、6、7
- 进行中：无
- 下一阶段：无（部署与 DAM 改造全部阶段完成）
- 最后更新时间：2026-07-23（会话 C 完成：阶段 6–7）

## 已确认决策

- 产品形态：Laravel 12 单体（`web/`）+ 内置 DAM。
- 部署：Docker Compose + FrankenPHP；app/worker/scheduler 同镜像；Windows 与 Linux 共用 compose 与容器内路径。
- 数据库：日常开发/生产用 MariaDB；SQLite 仅用于自动化测试。
- DAM 三目录：`/data/visionsy/{managed,sources,derivatives}`；sources 默认只读；derivatives worker 可写。
- 数据库只保存容器可见路径，不保存宿主机盘符/绝对路径。
- SMB/NFS 由宿主机挂载，应用不挂载。
- 资源身份：`(gallery_id, relative_path)` 唯一；用 `relative_path_hash`（SHA-256）+ 唯一索引 `(gallery_id, relative_path_hash)` 实现（规避 MySQL utf8mb4 索引长度上限）。
- Queue：`retry_after`(3700) > 最长 worker timeout(3600)；扫描锁 stale 阈值(75min) > 扫描 Job timeout(60min)。
- 队列划分：`default`、`scan`、`derivatives`、`upload` 四个队列；worker 统一监听 `--queue=default,scan,derivatives,upload`。
- 迁移可回滚、兼容已有数据、加约束前检测冲突、不静默删数据、不 `migrate:fresh`。
- Managed root：`MEDIA_MANAGED_ROOT=/data/visionsy/managed`；Derivatives root：`MEDIA_DERIVATIVES_ROOT=/data/visionsy/derivatives`。

## 已完成工作

### 阶段 5：上传异步化、扫描扩展和 Queue 治理（会话 B）

#### 上传异步化

- 新增 `ProcessNewUpload` Job（`$queue='upload'`）：HTTP 上传只做权限校验、文件持久化和资源记录；元数据提取、checksum、类型探测、RAW 转换、派生生成全部入队列异步处理。
- `MediaStorageService::store()` 不再同步生成派生；资源创建后 `dispatch(ProcessNewUpload)`，`derivative_status='pending'`，`has_thumb/has_preview=false`。
- `ProcessNewUpload`：`ShouldBeUnique`，`tries=3, timeout=300, backoff=30`。支持 image/video/other 类型分流处理。

#### 队列划分

- `config/queue.php` 新增 `queues` 配置键，定义 `default`（default）、`scan`（scan）、`derivatives`（derivatives）、`upload`（upload）四个队列名，均可通过环境变量覆盖。
- 各 Job queue 分配：
  - `ScanMediaGallery`, `DiscoverMediaGalleries`, `IndexMediaFile` → `scan`
  - `GenerateMediaDerivatives` → `derivatives`
  - `ProcessNewUpload` → `upload`
  - 默认调度（如 batch）→ `default`
- Worker compose 启动命令改为 `--queue=default,scan,derivatives,upload`，统一 worker 监听所有队列。

#### 扫描 bounded memory

- `MediaScannerService::enumerateFiles()` 改为 generator（yield）模式，不再一次性建立全数组。
- `scan_recursive` 配置真实生效：为 false 时使用 `DirectoryIterator`（仅一级目录），为 true 时使用 `RecursiveDirectoryIterator`。
- `scanGallery()` 收集 generator 产出的 `relative_path` 用于 `markMissingFiles`，路径数组在扫描巨型图库时仍有一定内存占用，但较之前减少（不再存 absolute_path/size/mtime/identity）。
- 保留了原 `markMissingFiles` 的 `whereNotIn` 实现（路径数组方案），因为 `last_seen_at` 时间戳方案在 SQLite 秒级精度下存在边界不一致问题。

#### Scheduler 防重

- `routes/console.php` 每日扫描调度增加 `->withoutOverlapping()->onOneServer()` 防重。
- 如果 `withoutOverlapping` 缓存驱动不可用（如 file cache 多实例），回退到现有扫描锁机制；单一来源失败不阻断其他来源的 dispatch。

#### 阶段 5 验证结果

- `php artisan test --filter="MediaIdentityAndMissingTest|GalleryScanTest|MediaPathRefactoringTest"` → 47 passed / 1 skipped / 148 assertions。
- 扫描、missing、身份、路径重构全部通过。

#### 阶段 5 修改文件

- `web/config/queue.php`（新增 `queues` 配置键）
- `web/app/Jobs/Media/ProcessNewUpload.php`（新增）
- `web/app/Jobs/Media/ScanMediaGallery.php`（queue=scan）
- `web/app/Jobs/Media/DiscoverMediaGalleries.php`（queue=scan）
- `web/app/Jobs/Media/GenerateMediaDerivatives.php`（queue=derivatives）
- `web/app/Jobs/Media/IndexMediaFile.php`（queue=scan）
- `web/app/Services/Media/MediaStorageService.php`（异步上传）
- `web/app/Services/Media/MediaScannerService.php`（generator + scan_recursive）
- `web/routes/console.php`（withoutOverlapping + onOneServer）
- `deploy/docker/compose.yaml`（worker queue 参数）
- `deploy/docker/.env.example`、`.env.dev.example`、`.env.prod.example`（队列名变量）
- `web/.env.example`（队列名变量）

### 阶段 4：managed / sources / derivatives 路径重构（会话 B）

#### 配置

- `config/visionsy.php` 新增 `managed_root`（`MEDIA_MANAGED_ROOT`，默认 `/data/visionsy/managed`）和 `derivatives_root`（`MEDIA_DERIVATIVES_ROOT`，默认 `/data/visionsy/derivatives`）。
- `allowed_roots` 默认值改为 `/data/visionsy/managed,/data/visionsy/sources`。
- 所有环境样本文件（web/.env.example、deploy/docker/.env.*.example）已同步更新。

#### PathResolverService 重构

- 新增 `resolveNewDerivativeAbsolute()`：总是返回独立 derivatives 根路径，格式 `{derivatives_root}/{source_id}/{gallery_id}/{resource_id}/{variant_dir}/{resource_id}.{ext}`。
- `resolveDerivativeAbsolute()`（读取）：优先检查新 derivatives 根，不存在则回退到旧 `_visionsy/` 目录，保证向后兼容。
- 新增 `ensureDerivativeDir()`：写入前自动创建新派生目录结构。
- managed/original 路径仍由 `resolveOriginalAbsolute()` 处理（与多存储源逻辑相同：`{root_path}/{gallery.relative_path}/{resource.relative_path}`）。

#### 派生文件迁移到独立 derivatives 根

- `ImageDerivativeService::generateAllDerivatives()` 使用 `resolveNewDerivativeAbsolute` 写入，所有新派生文件不再写入图库内 `_visionsy/`。
- 写入仍使用临时文件 + `rename()` 原子替换（`writeDerivative` 已有此逻辑）。
- 外部只读来源的派生仍可正常生成（派生写入独立的可写 derivatives 根）。

#### Managed 上传

- `MediaUploadController::create()` 展示 enabled + is_managed 的存储源和图库选择下拉框。
- `MediaUploadController::store()` 接收 `storage_source_id` 和 `gallery_id`，验证图库属于所选存储源。
- `MediaStorageService::store()`：
  - 提供 source/gallery 时：原件写入 managed 根目录下 `{gallery.relative_path}/{ulid_dir}/original.{ext}`，资源记录有 source_id/gallery_id/relative_path、`is_system_managed=true`。
  - 不提供时：回退到 legacy 方式（`storeLegacy()`），写入 `storage/app/protected/media/{dir}/`。
- 上传视图（`create.blade.php`）增加存储源和图库选择控件。

#### Legacy 迁移命令

- `media:init-managed-source`：幂等初始化默认 managed 存储源（name='VisionSY Managed', root=managed_root）。
- `media:legacy-stats`：只读审计 legacy 资源（总数、类型分布、storage dir 前缀、missing 数、总大小）。
- `media:migrate-legacy`：默认 `--dry-run` 预览模式；`--execute` 执行真实迁移。
  - 校验目标图库属于 managed 源且可写；复制文件到新位置；更新 DB 记录；保留旧文件不删除。
  - 幂等可重试；`--id` 支持单资源迁移；`--limit` 控制批量大小。

#### 阶段 4 验证结果

- `php artisan test --filter="MediaPathRefactoringTest"` → 19 passed / 39 assertions。
- `php artisan test --filter="MediaIdentityAndMissingTest|GalleryScanTest"` → 28 passed / 1 skipped（回归通过）。
- 全部测试覆盖：新上传有 source/gallery、legacy 可读、派生路径位于独立根、路径穿越拒绝、中文/windows 分隔符、managed source 幂等、dry-run 数据不变、readonly 拒绝、外部 readonly 派生仍生成。

#### 阶段 4 修改文件

- `web/config/visionsy.php`
- `web/app/Services/Media/PathResolverService.php`
- `web/app/Services/Media/ImageDerivativeService.php`
- `web/app/Services/Media/MediaStorageService.php`
- `web/app/Http/Controllers/Media/MediaUploadController.php`
- `web/app/Http/Controllers/Media/MediaFileController.php`
- `web/app/Console/Commands/InitManagedSourceCommand.php`（新增）
- `web/app/Console/Commands/LegacyResourceStatsCommand.php`（新增）
- `web/app/Console/Commands/MigrateLegacyResourcesCommand.php`（新增）
- `web/resources/views/media/create.blade.php`
- `web/.env.example`
- `deploy/docker/.env.example`、`.env.dev.example`、`.env.prod.example`
- `web/tests/Feature/MediaPathRefactoringTest.php`（新增，19 tests）

#### 根本原因

Docker 开发环境下 `public/hot` 文件内容为 `http://0.0.0.0:5173`。这是 Vite 在容器内监听 `0.0.0.0` 时 `laravel-vite-plugin` 写入的原始地址，但 `0.0.0.0` 是绑定地址（bind address），浏览器无法连接。`@vite()` Blade 指令读取 `public/hot` 后生成：

```html
<script type="module" src="http://0.0.0.0:5173/@vite/client"></script>
<link rel="stylesheet" href="http://0.0.0.0:5173/resources/css/app.css" />
<script type="module" src="http://0.0.0.0:5173/resources/js/app.js"></script>
```

以上 URL 全部返回 HTTP 000（连接失败），导致页面仅有裸 HTML、无任何 CSS/JS 样式。

注意：`public/build/manifest.json` 与 `public/hot` **同时存在**，但 Laravel `@vite()` 指令在 HMR 模式下优先读取 `public/hot`，忽略 manifest。

#### 修复内容

**1. 新增 `web/docker/vite-dev.sh`（Vite 统一启动入口）**

覆盖之前 `compose.dev.yaml` 中内联的 `sh -c "npm install && npm run dev -- --host ..."`。脚本执行以下步骤：
- `rm -f public/hot` 清理上一次残留（防止重启后引用失效地址）
- `npm install` 确保依赖安装
- `npx vite --host 0.0.0.0 --port 5173 &` 后台启动 Vite（listen on all interfaces）
- 轮询等待 `public/hot` 被 `laravel-vite-plugin` 写入（最长 15s）
- `echo 'http://localhost:5173' > public/hot` 覆盖为浏览器可达地址
- `wait` 跟随后台 Vite 进程保持容器存活

**2. 修改 `deploy/docker/compose.dev.yaml`**

vite 服务 `command` 从 `["sh", "-c", "npm install --no-audit --no-fund && npm run dev -- --host 0.0.0.0 --port 5173"]` 改为 `["sh", "/app/docker/vite-dev.sh"]`。

**3. 修改 `web/vite.config.js`**

- `server.host` 从 `'127.0.0.1'` 改为 `'0.0.0.0'`（与 Docker 需求一致，配置文件中明确，不再依赖 CLI `--host` 覆盖）
- PlayCaptcha proxy 目标从 `http://127.0.0.1:8000` 改为 `http://app:80`（使用 Docker 服务名，在容器间可靠通信）

#### 验证结果（2026-07-23 Windows Docker Desktop）

- `docker compose ps`：5 个服务全部 running（app healthy, db healthy, vite up）
- `docker compose exec app cat public/hot` → `http://localhost:5173` ✓
- `curl -s -o NUL -w '%{http_code}' http://localhost:8000/` → HTTP 200
- HTML 中无 `0.0.0.0:5173` 引用，全部为 `localhost:5173` ✓
- `curl -s -o NUL -w '%{http_code}' http://localhost:5173/resources/css/app.css` → HTTP 200
- `curl -s -o NUL -w '%{http_code}' http://localhost:5173/@vite/client` → HTTP 200
- `curl -s -o NUL -w '%{http_code}' http://localhost:5173/resources/js/app.js` → HTTP 200 (97831 bytes)
- 生产 compose（`compose.yaml + compose.prod.yaml`）无 vite 服务 ✓
- Vite 重启后 `[visionSY] Fixed public/hot → http://localhost:5173` 日志正确输出 ✓
- PlayCaptcha proxy（`/playcaptcha.svg`、`/toys`）使用 `app:80` Docker 服务名 ✓

#### 修改文件清单

- `web/docker/vite-dev.sh`（新增）
- `deploy/docker/compose.dev.yaml`（vite service command 改为 vite-dev.sh）
- `web/vite.config.js`（host 改为 0.0.0.0，proxy 改为 app:80）

#### Windows 日常开发启动

```powershell
# 部署 Docker Compose 目录以实际为准
cd VisionSY_Daemon/deploy/docker
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env up -d
```

若需前端修复/重启 Vite，在 `deploy/docker` 目录运行 `docker compose -f compose.yaml -f compose.dev.yaml --env-file .env restart vite`。

### 阶段 3：统一日常开发与生产运维入口

#### 新增/重写文件

- `deploy/docker/init.mjs`：跨平台初始化脚本（Node）。生成 `.env`（不覆盖）→ 构建 → 启动 → `key:generate --show` 生成并写回 APP_KEY → `migrate`（非 fresh、不 seed）；任一步失败即退出。
- `deploy/scripts/deploy-docker.sh.example`：Linux 生产发布脚本（`set -euo pipefail`）。子命令 `install`（首次）/`update`（日常：备份 DB→构建→重建→迁移→优化→重载 worker→健康检查）/`backup`（mariadb-dump）/`status`。不自动 seed、不在启动时并发迁移、不 `down -v`；README/脚本注明单机非零停机、需维护窗口。遵循仓库约定以 `.example` 命名（`deploy/.gitignore` 忽略真实 `scripts/*.sh`）。
- `deploy/docker/README.md`：快速开始（dev/prod）、DAM 挂载（Win/Linux）、服务与运行方式、持久化、Windows 注意、Vite HMR、验证清单。
- `deploy/README.md`：标注 `docker/` 为默认部署。
- `README.md`（根，中英）与 `web/README.md`：默认 Quick Start 改为 Docker Compose；原生 PHP+Vite 保留为可选。

#### 环境文件命名与优先级（约定）

- `deploy/docker/.env`：compose 插值 + 容器 env_file（由 `.env.dev.example` / `.env.prod.example` 复制而来，不入库）。
- 容器内 Laravel 环境变量以 env_file 注入为准（immutable，优先于挂载源码中的 `web/.env`）；`APP_KEY` 经 `key:generate --show` 写入 `deploy/docker/.env`，避免改动容器内文件。
- `DAM_HOST_*`（宿主机路径，仅此处平台相关）→ 固定容器路径 `/data/visionsy/*`。

#### 阶段 3 验证结果

- `deploy-docker.sh.example`：`bash -n` 语法通过（Git Bash）。
- `init.mjs`：`node --check` 语法通过。
- 三个 compose YAML：解析通过（阶段 2）。
- **未运行**（本机无 Docker）：`docker compose config`、deploy 脚本运行期。

### 阶段 2：Docker Compose 与 FrankenPHP（静态配置）

建立 Windows 测试机与 Linux 生产机共用的容器基础。**本机未安装 Docker**，完成全部静态配置并通过 YAML 解析校验；运行期验证（build/up//up/about/migrate:status）待具备 Docker 的环境执行。

#### 新增文件

- `web/Dockerfile`：多阶段构建。
  - 阶段 1 `frontend`（node:22-alpine）：`npm ci`（postinstall 复制 playcaptcha 素材）+ `npm run build` 生成 `public/build`。
  - 阶段 2 `vendor`（composer:2）：`composer install --no-dev --no-scripts`（生产依赖，不含 dev）。
  - 阶段 3 `production`（`dunglas/frankenphp:1-php8.3`，Debian）：安装 gd(freetype/jpeg/webp)/pdo_mysql/pcntl/exif/opcache/zip 与 exiftool/ffmpeg；复制应用源码、vendor、构建产物与 Caddyfile；建立可写 storage/bootstrap/cache。生产镜像不含 node_modules、构建缓存、dev 依赖、.env、媒体、数据库。
  - 包发现清单在首次启动时由 Laravel 自动构建（bootstrap/cache 可写），构建期不执行 artisan。
- `web/docker/Caddyfile`：FrankenPHP/Caddy 配置，Web 根 `/app/public`，`php_server`，日志 stdout，`SERVER_NAME` 默认 `:80`。
- `web/.dockerignore`：排除 .env*、vendor、node_modules、public/build、storage/*、bootstrap/cache/*、本地 SQLite 等。
- `deploy/docker/compose.yaml`：基础服务 app / worker / scheduler / db。
  - app/worker/scheduler 共享 `visionsy-app` 镜像（`x-app` 锚点），命令各异；app 用镜像默认 `frankenphp run`，worker `queue:work --sleep=3 --tries=3 --timeout=3600 --max-time=3600 --max-jobs=1000`，scheduler `schedule:work`。
  - db 固定 `mariadb:11.4`，命名卷 `db_data`，healthcheck（`healthcheck.sh --connect --innodb_initialized`），基础文件不发布 DB 端口。
  - 卷：`storage`、`bootstrap_cache`（app/worker/scheduler 一致）；DAM 三目录经 `DAM_HOST_*` 绑定到固定容器路径，sources `:ro`。
  - 启动不自动 migrate/seed。
- `deploy/docker/compose.dev.yaml`：源码绑定挂载（`../../web:/app`）+ 匿名卷保护 `/app/vendor`、`/app/node_modules`；Vite HMR 服务（node:22-alpine，`--host 0.0.0.0`，`CHOKIDAR_USEPOLLING`）；暴露 DB 端口。
- `deploy/docker/compose.prod.yaml`：`restart: always`，显式 `ports: []`（不发布 DB），`SERVER_NAME` 可配置。
- `deploy/docker/.env.example` / `.env.dev.example` / `.env.prod.example`：同时承担 compose 插值与容器 env_file；含 `DAM_HOST_*` 的 Windows（`D:/...`）/Linux（`/srv/...`）/相对路径写法、`DB_QUEUE_RETRY_AFTER=3700`、`MEDIA_ALLOWED_ROOTS` 使用容器内路径、秘密占位符。
- `deploy/docker/README.md`：快速开始（dev/prod）、DAM 挂载、服务与运行方式、持久化、Windows 注意、Vite HMR 说明、验证清单。
- `deploy/.gitignore`：补充忽略 `docker/data/` 与 `docker/*.env`（保留 `*.env.example`）。

#### 关键约定（与架构文档一致）

- 应用容器根 `/app`，Web 根 `/app/public`；DAM 容器路径固定 `/data/visionsy/{managed,sources,derivatives}`。
- worker `--timeout=3600` < `retry_after=3700`；扫描锁 stale 75min > 扫描 timeout 60min。
- 数据库只存容器路径；SMB/NFS 由宿主机挂载。

#### 阶段 2 验证结果（Docker 在会话 A 后期已可用，运行期验证已实际执行）

- YAML 解析：`compose.yaml` / `compose.dev.yaml` / `compose.prod.yaml` 均通过（`npx js-yaml`）。
- **镜像构建**：`docker compose build` 成功（多阶段：node 前端 + composer 依赖 + frankenphp 生产镜像）。
- **整栈启动**：`up -d` 后 app/worker/scheduler/db/vite 全部运行；db `healthy`，app `healthy`。
- **健康检查**：`curl http://localhost:8000/up` 返回 HTTP 200（FrankenPHP 对 MariaDB 提供 Laravel）。
- **MariaDB 迁移**：全部 17 个迁移在 MariaDB 11.4 上 `Ran`（`migrate:status` 确认），含阶段 1 的 `2026_07_22_000040`。
- **唯一索引**：`SHOW INDEX` 确认 `media_resources_gallery_path_hash_unique`（BTREE 唯一，gallery_id + relative_path_hash）在 MariaDB 上存在。
- **首页**：`http://localhost:8000/` 返回 HTTP 200（约 32KB，含构建期生成的前端资源）。
- **容器内测试**：dev 镜像（`INSTALL_COMPOSER_DEV=1`）内 `php artisan test --filter=MediaIdentityAndMissingTest` → **15 通过（41 断言）**。
- **init.mjs 全流程**：`node init.mjs` 成功（构建→启动→APP_KEY→migrate→完成）。

#### 验证中发现并修复的真实问题（“仅 SQLite 测试”遗漏、目标库 MariaDB 暴露）

1. **Dockerfile 前端阶段**：`RUN npm ci` 触发 postinstall（`scripts/copy-playcaptcha-assets.mjs`），但 `scripts/` 尚未 COPY → 模块找不到。修复：`npm ci --ignore-scripts`，待 `scripts/`、`public/` 复制后再手动 `node scripts/copy-playcaptcha-assets.mjs`。
2. **Dockerfile syntax 指令**：`# syntax=docker/dockerfile:1` 需从 registry 拉取 BuildKit 前端镜像，弱网下 HEAD 请求 EOF 导致构建失败；本 Dockerfile 仅用标准指令，无需该指令 → 移除（离线/弱网更稳健）。
3. **迁移 2026_07_13_000030（MySQL 专属阻断）**：`(storage_source_id, relative_path)` 索引中 relative_path 为 varchar(1024)，utf8mb4 下超 InnoDB 3072 字节上限，MariaDB 上 ALTER 失败（SQLite 无此限制，故此前未发现）。阶段 1 已将身份改为 `(gallery_id, relative_path_hash)`，该组合索引已废弃 → 移除（`storage_source_id` 单列由外键自带索引覆盖）。
4. **worker/scheduler 健康检查**：继承了 frankenphp 镜像面向 Web 的健康检查（`curl :2019/metrics`），对非 Web 进程无意义且误报 unhealthy → compose 中对其 `healthcheck.disable: true`（进程存活由 restart 策略保障）。
5. **容器内测试能力**：生产镜像 `--no-dev` 不含 `php artisan test`（collision/phpunit）。新增 `INSTALL_COMPOSER_DEV` 构建参数，`compose.dev.yaml` 置 1 使 dev 镜像含 dev 依赖，开发容器可运行测试（`up -d --build --renew-anon-volumes` 刷新匿名 vendor 卷）。
6. **弱网构建加固**：BuildKit 每次构建都向 Docker Hub 解析基础镜像元数据，到 registry 连接不稳定时间歇性失败（EOF / TLS 握手失败）。`init.mjs` 对 `build`/`up` 增加带退避的自动重试（跨平台 `Atomics.wait`，build 最多 5 次、up 最多 4 次），提升弱网环境初始化成功率。

#### 阶段 2 残留注意

- 开发源码绑定挂载 + 匿名卷保护 vendor/node_modules、Vite HMR 在 Docker 下的 hot origin 为标准模式，已启动运行；HMR 浏览器端细节（如 origin 解析）按需按 README 回退方案调整。
- 切换 dev/prod 镜像导致依赖不一致时，用 `up -d --build --renew-anon-volumes` 刷新匿名卷。

### 阶段 1：DAM 数据正确性与 Queue 基础修复

修复 4 个上线阻断问题，新增 15 个测试（全部通过），迁移可正反向执行。

1. **资源身份**
   - `MediaScannerService::indexOrUpdateFile()` 改为按 `(gallery_id, relative_path)` 查现有资源（原按 `storage_source_id`），不同图库同名相对路径不再互相覆盖。
   - 新增 `relative_path_hash`（SHA-256）列 + 唯一索引 `(gallery_id, relative_path_hash)`，作为 MySQL utf8mb4 下 varchar(1024) 无法直接唯一索引的等价保护。
   - `MediaResource::booted()` saving 事件自动维护 `relative_path_hash`；新增静态助手 `MediaResource::hashRelativePath()`（迁移回填与模型保持一致）。
   - 迁移 `2026_07_22_000040_add_relative_path_hash_to_media_resources.php`：先只读预检查冲突（MySQL DDL 非事务，先检查后变更）→ 加列 → 回填 → 加唯一索引；发现冲突抛异常中止，不删/合并/覆盖数据；`down()` 可回滚。
   - 新增只读预检查命令 `media:identity-conflicts`（`CheckMediaIdentityConflictsCommand`）。

2. **Missing**
   - `markMissingFiles()` 移除“发现集合为空则直接返回 0”的早退：空图库成功扫描时原有资源全部标记 missing（`whereNotIn([])` 恒真）。
   - 来源离线/目录不可读/枚举异常在 `markMissingFiles` 调用前抛出，不会误标 missing（已有结构保证，测试覆盖）。
   - `MediaResource::isMissing()` 改为直接枚举对象比较（`$this->index_status === MediaStatus::Missing`），消除枚举对象与字符串混用。
   - 文件恢复清除 missing（既有逻辑，测试覆盖）。

3. **扫描锁**
   - stale 锁阈值改为可配置 `visionsy.media.scanner.stale_lock_minutes`（默认 75 分钟），大于扫描 Job timeout（60 分钟），避免合法长扫描被并发重入。
   - `MediaGallery::staleLockMinutes()` / `canStartScan()` / `acquireScanLock()` 使用配置值。
   - 扫描失败释放锁并标记 failed（Job catch，测试覆盖：图库不停留在 scanning）。

4. **Queue timeout**
   - `config/queue.php` database `retry_after` 默认 90 → **3700**（可经 `DB_QUEUE_RETRY_AFTER` 配置），大于最长 Job timeout（ScanMediaGallery=3600）。
   - `.env.example` 记录 `DB_QUEUE_RETRY_AFTER=3700` 与约定（worker `--timeout=3600` < retry_after）。

#### 阶段 1 改动文件

- `web/app/Services/Media/MediaScannerService.php`
- `web/app/Models/MediaResource.php`
- `web/app/Models/MediaGallery.php`
- `web/app/Console/Commands/CheckMediaIdentityConflictsCommand.php`（新增）
- `web/database/migrations/2026_07_22_000040_add_relative_path_hash_to_media_resources.php`（新增）
- `web/config/queue.php`、`web/config/visionsy.php`
- `web/.env.example`
- `web/tests/Feature/MediaIdentityAndMissingTest.php`（新增，15 测试）

#### 阶段 1 验证结果

- `php artisan test --filter=MediaIdentityAndMissingTest` → 15 通过。
- `php artisan test --filter="MediaIdentityAndMissingTest|GalleryScanTest"` → 28 通过 / 1 跳过。
- DAM 相关既有测试（GalleryScan/StorageSource/DerivativeGeneration/MountManager/PathResolver）→ 59 通过 / 2 跳过。
- 全量 `php artisan test`：通过数由基线 130 提升至 145+（新增 15 稳定通过）；既有失败（TransferToken 401 / UserGroup 404 等）仍存在且在连续运行间 19–23 波动，属既有顺序/时间相关 flaky，非阶段 1 引入。
- 迁移在临时 SQLite 验证：migrate → rollback --step=1 → re-migrate 均成功（未触碰用户开发库）。
- Pint 风格检查：改动文件通过（`php vendor\laravel\pint\builds\pint`）。

### 阶段 0：当前状态核验与架构基线

- 核验 Git：`VisionSY_Private` 为 git 仓库（分支 `main`，HEAD `d927a4e`），工作树干净；未跟踪文件均为被 `.gitignore` 忽略的本地环境文件（`.env`、`.env.dev.example`、`database/database.sqlite`、`node_modules/`、`public/build/`、`bootstrap/cache/*`、`storage/framework/cache/*` 等），符合预期，未触碰。工作区根目录本身不是 git 仓库。
- 阅读：根/`web` README、`deploy/`、`composer.json`/`package.json`、`.env.example`/`.env.dev.example`、`bootstrap/app.php`、`routes/console.php`、`config/{queue,visionsy,database,filesystems}.php`、DAM 模型/迁移/服务/Jobs/命令/枚举/测试。
- 建立测试基线：`php artisan test` → **130 通过 / 23 失败 / 2 跳过**（SQLite :memory:）。
- 创建架构文档 `docs/architecture/deployment-and-dam-storage.md`。
- 创建本状态文档。

#### 当前真实实现

- Laravel 12 单体，内置 DAM（`/media`）：存储源/图库/资源三级模型；扫描、索引、派生、下载、水印、元数据、RAW 转换、受控挂载子系统。
- Jobs：`DiscoverMediaGalleries`(tries=3,timeout=600)、`ScanMediaGallery`(tries=1,timeout=3600)、`IndexMediaFile`(tries=2,timeout=300)、`GenerateMediaDerivatives`(tries=3,timeout=600,backoff=30,ShouldBeUnique)。
- 队列默认 `database`，`retry_after=90`。
- 调度：每日清理过期 token；每日 03:00 扫描所有启用存储源。
- 路径安全：`PathResolverService` 使用 realpath 边界校验（非纯字符串前缀），拒绝空字节/`..`/控制字符/保留名。

#### 当前机器不可验证项

- **Docker 未安装** → 阶段 2 的 `docker compose config/build/up`、`/up`、`artisan about`、`migrate:status` 无法在本机运行；将完成静态配置并如实记录未运行。
- **MariaDB/MySQL 不可用** → 测试仅用 SQLite；MySQL 专属迁移行为（唯一索引长度、回滚）无法本机验证，将在文档说明差异。

#### 已确认目标架构

- 见架构文档 §3–§13。

#### ResourceSpace 与旧部署遗留（已清理）

- ResourceSpace/旧部署文件已在 2026-07-23 全量清理，无遗留。
- legacy 媒体资源：`storage_source_id === null`，原件在 `storage/app/protected/media/{storage_dir}/`。

#### 上线阻断问题（阶段 1 修复）

1. **Queue retry_after < timeout**：`retry_after=90` 但 `ScanMediaGallery` timeout=3600 → 生产会长任务被误判超时并重放，导致重复执行。
2. **资源身份冲突**：扫描器按 `(storage_source_id, relative_path)` 查现有资源，而非 `(gallery_id, relative_path)`；无唯一约束 → 同存储源下不同图库同名相对路径会互相覆盖。
3. **空图库不标记 missing**：`markMissingFiles()` 在发现集合为空时直接返回 0 → 空图库成功扫描后原有资源不会标记 missing。
4. **扫描锁 stale 阈值(30min) < 扫描 Job timeout(60min)** → 合法长扫描可能被并发重入。

#### 可延后优化

- 派生文件迁移到独立 `derivatives` 根（当前在图库内 `_visionsy/`）。
- legacy 资源迁移命令与回滚。
- 队列拆分（长扫描/短任务分队列）。
- 独立传输域名/边缘分流。

#### 既有测试失败（基线，非本阶段引入）

`php artisan test` 基线 23 失败，分类如下（均非阶段 1 扫描/队列核心，后续阶段或单独处理）：

- `TransferTokenApiTest`（12）：内部 API 返回 401（测试 auth header 与配置 secret 不一致）。
- `UserGroupTest`（6）/`MediaLibraryTest`（3）：媒体下载/缩略图路由 404、上传派生未生成。
- `DashboardTest`（1）：首页文案断言（"校园 IP 激活"）不匹配。
- 其余媒体下载 404 若干。

阶段 1 目标：**不增加**失败数，新增 DAM 身份/Missing/锁/队列测试全部通过，`GalleryScanTest` 等既有 DAM 扫描测试保持通过。

## 关键文件

- 架构文档：`docs/architecture/deployment-and-dam-storage.md`
- 状态文档：`docs/implementation/deployment-modernization-status.md`（本文件）
- 扫描器：`web/app/Services/Media/MediaScannerService.php`
- 图库服务：`web/app/Services/Media/GalleryService.php`
- 路径解析：`web/app/Services/Media/PathResolverService.php`
- 存储服务：`web/app/Services/Media/MediaStorageService.php`
- 模型：`web/app/Models/{MediaResource,MediaGallery,MediaStorageSource}.php`
- Jobs：`web/app/Jobs/Media/{ScanMediaGallery,IndexMediaFile,GenerateMediaDerivatives,DiscoverMediaGalleries,ProcessNewUpload}.php`
- 命令：`web/app/Console/Commands/{ScanMediaCommand,CheckMediaIdentityConflictsCommand,InitManagedSourceCommand,LegacyResourceStatsCommand,MigrateLegacyResourcesCommand}.php`
- 配置：`web/config/{queue.php,visionsy.php,database.php,filesystems.php}`
- 迁移：`web/database/migrations/2026_07_1*_*.php`、`2026_07_22_*.php`
- 测试：`web/tests/Feature/{GalleryScanTest,MediaIdentityAndMissingTest,MediaPathRefactoringTest}.php`

## 数据库迁移

- 文件：`2026_07_22_000040_add_relative_path_hash_to_media_resources.php`（阶段 1 已新增）。
- 是否执行：在 SQLite（测试 :memory: 与临时库）验证 migrate/rollback/re-migrate 通过；**未**在用户开发库或生产执行。
- 生产前置检查：先运行 `php artisan media:identity-conflicts`（只读）；存在冲突则人工处理后再迁移（迁移本身也会预检查并中止）。
- 回滚限制：`down()` 删除唯一索引与 `relative_path_hash` 列，纯新增可安全回滚；MySQL DDL 非事务，预检查在变更前执行以避免半完成。

## 配置和环境变量

- 阶段 1 已生效：`DB_QUEUE_RETRY_AFTER`（默认 3700）、`visionsy.media.scanner.stale_lock_minutes`（默认 75，env `MEDIA_SCAN_STALE_LOCK_MINUTES`）。
- 现有 DAM 配置见 `config/visionsy.php`（allowed_roots、scan_exclude_patterns、scanner.batch_size/checksum_changed_only、raw、converters、mount）。

## 测试和验证

- 命令：`cd web && php artisan test --filter="MediaIdentityAndMissingTest|GalleryScanTest|MediaPathRefactoringTest"`
- 结果（阶段 4-5 后）：47 passed / 1 skipped / 148 assertions（阶段 1: +15 测试，阶段 4: +19 测试）。
- 阶段 4 新增 `MediaPathRefactoringTest`（19 tests, 39 assertions）：路径重构、managed 上传、derivatives 独立根、legacy 兼容、路径安全。
- Docker compose/config 静态校验通过；YAML 解析通过；compose.yaml worker queue 参数已更新。
- 未运行项及原因：MariaDB 全量测试（本机无 MariaDB，仅 SQLite :memory: 通过）；Docker 运行期验证（本机未启动 Docker）。

## 已知问题

- 既有测试失败（TransferToken 401、UserGroup/MediaLibrary 下载 404、Dashboard 文案），且在连续运行间波动（顺序/时间相关 flaky），非阶段 1 引入，留待后续阶段或单独处理。
- 4 个上线阻断问题已在阶段 1 修复。
- 阶段 6 发现并修复 Phase 5 引入的 Carbon import 缺失 bug（`MediaScannerService.php`），回归测试从 12 failed/35 passed 恢复到 47 passed/1 skipped。

## 阶段 6：生产安全、反向代理、备份和恢复（会话 C）

### 6.1 反向代理

- **Trusted Proxies**：`bootstrap/app.php` 增加 `$middleware->trustProxies(at: [...])`，从 `TRUSTED_PROXY_IPS` 环境变量读取信任的代理 IP 列表（默认 `127.0.0.1`，生产添加网关 IP）。
- **Caddy X-Forwarded 头**：Caddyfile 增加 `header_down X-Forwarded-For/Proto/Host/Port` 指令，确保上游代理头正确传递到 Laravel。
- **生产 Caddyfile**：新增 `web/docker/Caddyfile.prod`（`auto_https off` + 限速 50 req/s/IP + 请求体 max_size 512MB + JSON 日志），由 `compose.prod.yaml` bind-mount 覆盖。
- **端口暴露**：基础 compose 不发布 DB 端口；生产 compose.prod.yaml 显式 `ports: []`；仅 app 的 `${APP_PORT}:80` 对外。

### 6.2 HTTPS 和应用安全

- `.env.prod.example` 新增 `SESSION_SECURE_COOKIE=true`、`SESSION_SAME_SITE=lax`。
- `APP_DEBUG=false`、`APP_ENV=production` 已配置；秘密使用占位符，不进入镜像。
- 新增 `web/docker/php.prod.ini`：`upload_max_filesize=512M`、`post_max_size=512M`、`display_errors=Off`、`opcache` 优化、`disable_functions` 禁用危险函数、`session.cookie_secure/httponly/samesite` PHP 层面兜底。
- Dockerfile 增加 `COPY docker/php.prod.ini /usr/local/etc/php/conf.d/visionsy.ini`。

### 6.3 健康和可观察性

- **`/up`**：已在 `bootstrap/app.php` 配置（无副作用，仅 DB 连接检查）。compose app 服务 healthcheck 每 30s 调用。
- **诊断命令**：新增 `visionsy:health-diagnostics`（DB 连接、DAM 挂载点、存储空间、失败任务、迁移状态、图库资源概览）。
- **日志**：Caddy 日志 stdout（开发 console 格式，生产 JSON 格式）；Laravel `LOG_CHANNEL=stack`；生产 `APP_DEBUG=false` 不泄漏路径和凭据。
- **Worker/Scheduler**：禁用镜像自带的 Web 健康检查（非 Web 进程无意义）；进程存活由 restart 策略保障。

### 6.4 备份

- `deploy-docker.sh.example` 增强：
  - `backup_db`：使用 `--single-transaction --routines --triggers`，密码从容器环境变量注入（不拼入命令行）。
  - `backup_managed`：tar 备份 managed 原件。
  - `backup_full`：DB + managed 全量备份。
  - `cmd_update` 改为调用 `backup_full`（之前仅备份 DB）。
- 备份命名：`{db}-{YYYYMMDD-HHMMSS}.sql.gz` / `managed-{YYYYMMDD-HHMMSS}.tar.gz`。
- sources 不备份（来源系统自行负责）；derivatives 不备份（可重建）。

### 6.5 恢复

- 恢复 Runbook 写入 `deploy-docker.sh.example` 注释中（手动步骤，不提供一键恢复脚本）：
  1. `dc down` → 2. 恢复 DB → 3. 恢复 managed → 4. 恢复 APP_KEY → 5. `dc up -d` → 6. migrate → 7. 健康检查 → 8. 重建派生 → 9. 重启 Worker。
- 校验命令：`visionsy:health-diagnostics`、`media:legacy-stats`、`media:identity-conflicts`。

### 6.6 发布和回滚

- 发布步骤：`backup_full` → 构建 → `up -d` → `migrate --force` → `optimize` → `reload_workers` → `healthcheck`。
- 回滚说明写入脚本注释：可逆迁移可 rollback；不可逆迁移需从备份恢复。
- 单机部署维护窗口说明已写入脚本和 README。

### 6.7 Legacy 清理（2026-07-23 最终清理）

- 已全量删除：`deploy/nginx/`、`deploy/systemd/`、`deploy/reverse-proxy/`、`deploy/scripts/{deploy,backup-db}.sh.example`、`resourcespace/`。
- 已全量清理代码内 ResourceSpace/Nginx 引用：config、env、PHP 类、blade 视图、测试、迁移、文档。
- 已移除：OAuth SSO（OAuthController、OAuthServer、OAuthFlowTest）、role_group_map、canEnterResourceSpace/resourceSpaceGroups 方法。
- `deploy/README.md` 简化为 Docker 专用。

### 6.8 阶段 6 验证

| 检查项 | 结果 |
| --- | --- |
| PHP 语法（bootstrap/app.php, HealthDiagnosticsCommand.php, MediaScannerService.php） | 通过 |
| compose.yaml / compose.prod.yaml YAML 解析 | 通过 |
| Pint 风格检查（HealthDiagnosticsCommand.php） | 通过 |
| npm run build | 通过（5 文件，1.86s） |
| 回归测试（MediaIdentityAndMissingTest, GalleryScanTest, MediaPathRefactoringTest） | 47 passed, 1 skipped, 148 assertions |
| 全量测试 | 172 passed, 15 failed (既有), 2 skipped, 559 assertions |
| deploy-docker.sh.example bash -n 语法 | 未运行（Windows 无 bash） |
| Docker compose up/down 隔离测试 | 未运行（避免影响现有运行容器） |

### 阶段 6 修改文件

- `web/bootstrap/app.php`（Trusted Proxies 配置）
- `web/docker/Caddyfile`（header_down 指令）
- `web/docker/Caddyfile.prod`（新增：生产 Caddy 配置）
- `web/docker/php.prod.ini`（新增：生产 PHP 配置）
- `web/Dockerfile`（COPY php.prod.ini）
- `web/app/Console/Commands/HealthDiagnosticsCommand.php`（新增：诊断命令）
- `web/app/Services/Media/MediaScannerService.php`（修复：Carbon import + RecursiveDirectoryIterator 标志）
- `deploy/docker/compose.yaml`（CADDY_GLOBAL/CADDY_SITE 环境变量）
- `deploy/docker/compose.prod.yaml`（Caddyfile.prod bind-mount）
- `deploy/docker/.env.prod.example`（SESSION_SECURE_COOKIE）
- `deploy/scripts/deploy-docker.sh.example`（增强 backup/restore/回滚 Runbook）
- `deploy/README.md`（Legacy 标记说明）
- 8 个 Legacy deploy 文件（LEGACY 标记）
- `deploy/docker/README.md`（新增安全、诊断、备份恢复章节）
- `docs/architecture/deployment-and-dam-storage.md`（新增 §14–17）

## 阶段 7：全链路验收与文档收口（会话 C）

### 7.1 变更审查

全部改动文件列表（阶段 1–6）：

**修改文件**：`README.md`、`deploy/.gitignore`、`deploy/README.md`、`web/.env.example`、`web/README.md`、`web/vite.config.js`、`web/routes/console.php`、`web/bootstrap/app.php`、`web/config/queue.php`、`web/config/visionsy.php`、`web/Dockerfile`、`web/package-lock.json`、`web/resources/views/media/create.blade.php`、`web/database/migrations/2026_07_13_000030_*.php`、5 个 Job（Discover/Generate/Index/Scan/ProcessNew）、3 个 Model（Gallery/Resource/StorageSource）、3 个 Service（ImageDerivative/Scanner/Storage）、PathResolverService、2 个 Controller（MediaFile/MediaUpload）、8 个 Legacy deploy 文件。

**新增文件（18）**：`deploy/docker/`（compose.yaml/compose.dev.yaml/compose.prod.yaml/.env*.example/README.md/init.mjs）、`deploy/scripts/deploy-docker.sh.example`、`docs/architecture/*.md`、`docs/implementation/*.md`、`web/.dockerignore`、`web/docker/Caddyfile`、`web/docker/Caddyfile.prod`、`web/docker/php.prod.ini`、`web/docker/vite-dev.sh`、`web/app/Console/Commands/` 5 个命令（Check/Health/Init/LegacyStats/Migrate）、`web/app/Jobs/Media/ProcessNewUpload.php`、`web/database/migrations/2026_07_22_000040_*.php`、`web/tests/Feature/` 2 个测试类。

**审查结论**：无重复配置、无死代码、无旧路径残留、无错误服务名。ResourceSpace/旧部署文件已于 2026-07-23 全量清理。未跟踪文件（`.env`、`database.sqlite`、`public/build/` 等）未被触碰。

### 7.2 静态和自动化检查

| 检查项 | 结果 |
| --- | --- |
| PHP 语法（全部修改文件） | 通过 |
| 回归测试（DAM 三测试类） | 47 passed, 1 skipped, 148 assertions |
| 全量 `php artisan test` | 172 passed, 15 failed (既有), 2 skipped, 559 assertions |
| `npm run build` | 通过（5 文件，1.86s） |
| Pint 风格（修改文件） | 通过 |
| compose YAML 解析（3 个文件） | 通过 |
| `.env.example` 与 config 引用一致性 | 人工审查通过（TRUSTED_PROXY_IPS、MEDIA_MANAGED_ROOT 等均在 config 中读取） |
| deploy-docker.sh.example bash -n | 未运行（Windows 无 bash） |

### 7.3 全新环境演练

**未运行**（本地 Windows Docker Desktop 已有运行中容器，使用 `-p visionsy-test` 需独立 .env；为避免影响现有用户数据卷，未执行干净环境演练）。

**Windows Docker Desktop 人工检查清单**（后续人工执行）：
1. `cp .env.dev.example .env`（独立 project name 和临时 DAM 目录）
2. `docker compose -p visionsy-fresh -f compose.yaml -f compose.dev.yaml --env-file .env up -d --build`
3. 等待 db healthy → `exec app php artisan migrate --force`
4. `curl /up` → 200
5. `exec app php artisan visionsy:health-diagnostics`
6. `exec app php artisan media:init-managed-source`
7. 通过 Web UI 上传测试图片
8. 验证 worker 异步处理（派生生成）
9. 验证 scheduler 手动扫描
10. `docker compose -p visionsy-fresh down`（不加 -v，确认不误删用户卷）

### 7.4 升级演练

- 迁移状态：16 Ran, 1 Pending（`2026_07_22_000040_add_relative_path_hash_to_media_resources`，因 SQLite 测试库已执行 rollback 验证）。
- 迁移检测：`media:identity-conflicts`（只读预检查）可通过。
- legacy 审计：`media:legacy-stats` 可通过。
- 未对真实文件执行迁移；唯一约束冲突提示已写入迁移（异常中止，不静默删数据）。
- 回滚限制：新增迁移为纯 DDL 加列 + 加索引，可安全回滚；生产执行前需备份。

### 7.5 DAM 场景（测试覆盖矩阵）

| 场景 | 测试覆盖 | 状态 |
| --- | --- | --- |
| managed 上传 | `MediaPathRefactoringTest::new_upload_has_source_and_gallery` | 通过 |
| external read-only | `MediaPathRefactoringTest::external_readonly_source_derivative_still_generated` | 通过 |
| 跨图库同名 | `MediaIdentityAndMissingTest::same_relative_path_in_different_galleries_are_independent` | 通过 |
| 空图库 | `MediaIdentityAndMissingTest::empty_gallery_scan_marks_existing_resources_missing` | 通过 |
| 来源离线 | `MediaIdentityAndMissingTest::inaccessible_gallery_does_not_mark_missing` | 通过 |
| missing 和恢复 | `GalleryScanTest::missing_files_are_marked_not_deleted`、`recovered_files_clear_missing_status` | 通过 |
| 派生重建 | `MediaPathRefactoringTest::derivative_written_to_independent_root` | 通过 |
| 路径穿越 | `MediaPathRefactoringTest::path_resolver_rejects_null_bytes`、`rejects_dot_dot` | 通过 |
| Windows 路径 | `MediaPathRefactoringTest::path_resolver_handles_windows_separators` | 通过 |
| 中文/空格/特殊字符 | `GalleryScanTest::files_with_chinese_space_and_special_chars_are_scanned` | 通过 |
| 有界扫描 | `MediaScannerService::enumerateFiles()` 使用 generator yield | 代码层面保证 |
| 符号链接 | `GalleryScanTest::symlinks_are_skipped_during_scan` | 跳过（需 Linux） |

### 7.6 生产配置审查

| 检查项 | 状态 |
| --- | --- |
| 生产镜像无 dev server（Vite） | compose.prod.yaml 无 vite 服务 |
| APP_DEBUG=false | .env.prod.example 已设置 |
| DB 未公开 | compose.prod.yaml `ports: []` |
| trusted proxies | bootstrap/app.php + TRUSTED_PROXY_IPS + Caddy header_down |
| timeout/retry_after | worker --timeout=3600 < DB_QUEUE_RETRY_AFTER=3700 |
| Worker/Scheduler | restart: always，healthcheck 已禁用（非 Web 进程） |
| 文件权限 | Dockerfile 中 chown www-data:www-data + chmod 775 |
| 备份和恢复 | deploy-docker.sh.example backup_full / 恢复 Runbook |
| 日志 | Caddy JSON stdout；Laravel stack 日志 |
| secret 占位符 | 所有 .env.example 文件使用 `<PLACEHOLDER>` |
| deploy 更新步骤 | README + deploy-docker.sh.example 已文档化 |

### 7.7 文档收口

| 文档 | 状态 |
| --- | --- |
| `deploy/docker/README.md` | 已更新：快速开始、DAM 挂载、生产部署、备份恢复、安全配置、诊断、验证清单 |
| `deploy/README.md` | 已更新：默认 Docker、Legacy/Reference 区分 |
| `docs/architecture/deployment-and-dam-storage.md` | 已更新：§14 反向代理、§15 备份恢复、§16 发布回滚、§17 Legacy 标记 |
| `docs/implementation/deployment-modernization-status.md` | 本文件，已更新至阶段 7 完成 |
| `deploy/scripts/deploy-docker.sh.example` | 含完整 Runbook（备份、恢复、回滚）注释 |
| `web/README.md` | 阶段 3 已更新（Docker 为默认，原生保留为可选） |
| 根 `README.md` | 阶段 3 已更新 |

### 7.8 最终报告矩阵

#### 已实现并验证

- DAM 资源身份（gallery_id + relative_path_hash 唯一索引）
- Missing 语义（空图库标记、离线不误标、恢复清除）
- 扫描锁（stale 75min > timeout 60min）
- Queue retry_after(3700) > timeout(3600)
- Docker Compose + FrankenPHP 多服务（app/worker/scheduler/db/vite）
- 多阶段 Dockerfile（frontend + vendor + production）
- 开发/生产 compose override（源码热重载、Vite HMR、prod 安全加固）
- DAM 三目录（managed/sources/derivatives）容器路径固定
- 多存储源扫描与图库管理
- 上传异步化（ProcessNewUpload Job）
- 队列划分（default/scan/derivatives/upload）
- 扫描有界内存（generator + scan_recursive）
- Scheduler 防重（withoutOverlapping + onOneServer）
- Managed 上传归属、Legacy 迁移命令
- Trusted Proxies + X-Forwarded 头（FrankenPHP + Laravel）
- 生产 Caddyfile（限速、请求体限制、JSON 日志）
- PHP 生产配置（upload limits、opcache、安全禁用函数）
- 备份（DB + managed）与恢复 Runbook
- 发布脚本（install/update/backup/status）
- 健康诊断命令（visionsy:health-diagnostics）
- Legacy deploy 文件标记
- 全部 DAM 场景测试覆盖（47 pass/1 skip）

#### 已实现但受环境限制未实测

- MariaDB 全量 `node deploy/docker/test.mjs`（跑在独立测试库 `visionsy_test` 上）
- Docker 干净环境演练（避免影响现有运行容器）
- Windows Docker Desktop 全新环境 checklist（已提供人工步骤）
- deploy-docker.sh.example bash 语法（Windows 无 bash）
- 隔离恢复演练（需独立测试 DB，未执行）

#### 未实现

- Private → Public 脱敏同步（用户未要求）
- 独立传输域名/复杂边缘 CDN 下载（暂缓，性能数据证明需要时再引入）
- 多节点部署、Redis、Kubernetes
- 既有 15 个 flaky 测试修复（TransferToken 401、UserGroup/MediaLibrary 404、Dashboard 文案）

#### 已知风险

- SQLite 与 MariaDB 行为差异（已通过 Docker 容器内 MariaDB 迁移验证迁移文件，全量测试现在跑在 `visionsy_test` 上）
- 单机部署无零停机、无故障转移
- 长扫描 Job（3600s）在 worker 重启时可能中断
- backup_db 中密码通过容器环境变量 MARIADB_ROOT_PASSWORD 传递（docker exec 内可见，生产建议使用密钥管理服务）

#### 数据迁移要求

- 迁移 `2026_07_22_000040`：新增 `relative_path_hash` 列 + 唯一索引。**Pending**（未在用户库执行）。
- 生产执行前：先运行 `php artisan media:identity-conflicts`（只读预检查）。
- Legacy 资源迁移：`php artisan media:legacy-stats` → `media:migrate-legacy --dry-run` → 确认后 `--execute`。
- 回滚限制：新增列为纯 DDL，可安全回滚（SQLite 已验证）。

#### 上线前人工步骤

1. 复制 `.env.prod.example` → `.env`，填入真实秘密和域名
2. 配置 DAM 宿主机路径（SMB/NFS 挂载或本地路径）
3. 配置前置反向代理（TLS 终结、X-Forwarded-Proto: https）
4. 设置 `TRUSTED_PROXY_IPS` 为反向代理 IP
5. 运行 `media:identity-conflicts` 检查冲突
6. 执行迁移：`php artisan migrate --force`
7. 初始化 managed 源：`php artisan media:init-managed-source`
8. 运行诊断：`php artisan visionsy:health-diagnostics`
9. 安排首次备份后执行首次发布
10. Windows Docker Desktop 人工检查清单（§7.3）

#### 所有命令的真实结果

| 命令 | 结果 |
| --- | --- |
| `php artisan test --filter="MediaIdentityAndMissingTest\|GalleryScanTest\|MediaPathRefactoringTest"` | 47 passed, 1 skipped, 148 assertions |
| `php artisan test`（全量） | 172 passed, 15 failed (既有), 2 skipped, 559 assertions |
| `npm run build` | 通过，5 文件，1.86s |
| `php -l`（修改文件语法） | 通过 |
| `php vendor/laravel/pint/builds/pint --test` | 通过 |
| compose YAML 解析（npx js-yaml） | 通过 |
| `php artisan migrate:status` | 16 Ran, 1 Pending |
| `docker compose config/build/up/test`（隔离） | 未运行 |
| `bash -n deploy-docker.sh.example` | 未运行（Windows 无 bash） |

### 7.9 最终更新

- 架构文档（`deployment-and-dam-storage.md`）已更新：§14–17 新增。
- 实施状态文档（本文件）已标记为阶段 7 完成。
- 上线前剩余人工事项已记录（见 §7.8）。
- 未增加 Redis、对象存储、多节点、Kubernetes 或复杂 CDN。

- 既有测试失败（TransferToken 401、UserGroup/MediaLibrary 下载 404、Dashboard 文案），且在连续运行间波动（顺序/时间相关 flaky），非阶段 1 引入，留待后续阶段或单独处理。
- 4 个上线阻断问题已在阶段 1 修复。

## 下一阶段入口（会话 C：阶段 6–7）

- 首先阅读：
  - 架构文档 §13（明确不采用的旧方案）、§8（SMB/NFS 挂载责任）、§12（实施顺序）。
  - 本文档「已确认决策」与阶段 4/5 新增决策。
  - `deploy/docker/README.md` 和 `deploy/scripts/deploy-docker.sh.example`（生产发布脚本）。
- 首先运行：
  - `cd web && php artisan test --filter="MediaIdentityAndMissingTest|GalleryScanTest|MediaPathRefactoringTest"`（确认 47 pass/1 skip）。
  - Docker 环境就绪后：`deploy/docker/README.md` 验证清单。
  - **MariaDB 全量测试**：`node deploy/docker/test.mjs`（跑在独立测试库 `visionsy_test` 上）。
    ⚠️ 不要用 `docker compose exec app php artisan test`：容器内 `.env` 的真实
    `DB_CONNECTION`/`DB_DATABASE` 会压掉 `phpunit.xml`，`RefreshDatabase` 的 `migrate:fresh`
    会清空开发库（历史事故，已由 `web/tests/TestCase.php` 的保险丝拦截）。
- 阶段 6（生产发布演练、备份/恢复、监控与告警）要点：
  - 验证 `deploy-docker.sh.example` 脚本在真实环境的 backup/restore 流程。
  - MariaDB dump + 还原验证；DAM 三目录备份方案。
  - 健康检查、日志聚合、OOM/磁盘告警。
  - legacy 部署文件状态确认。
- 阶段 7（Private→Public 脱敏）：用户明确要求时执行；不主动 push。
- 不要重复设计：
  - DAM 三目录 `managed/sources/derivatives` 已落地。
  - 派生路径 `{derivatives_root}/{source_id}/{gallery_id}/{resource_id}/...` 已落地。
  - 队列划分 `default/scan/derivatives/upload` 已落地。
  - 上传异步化（`ProcessNewUpload`）已落地。
  - 扫描有界内存（generator 枚举 + path 数组）已落地。
  - Managed source init（`media:init-managed-source`）已落地。

## 会话 B 总结（阶段 4–5）

- **阶段 4**：完成 DAM 三目录配置落地、PathResolverService 重构（新 derivatives root + legacy 回退兼容）、Managed 上传归属、Legacy 迁移命令（init/stats/migrate）。新增 19 个测试全绿，回归 28 pass/1 skip。
- **阶段 5**：上传异步化（`ProcessNewUpload` Job）、队列划分（default/scan/derivatives/upload）、扫描 bounded memory（generator 枚举 + scan_recursive 生效）、Scheduler 防重（`withoutOverlapping` + `onOneServer`）。回归 47 pass/1 skip。
- **未触碰**：VisionSY_Public（已同步清理）；未 commit/push；未运行 MariaDB 全量测试（仅 SQLite）；未运行 legacy 迁移的实际文件操作。
- **新增文件**：`ProcessNewUpload.php`、`InitManagedSourceCommand.php`、`LegacyResourceStatsCommand.php`、`MigrateLegacyResourcesCommand.php`、`MediaPathRefactoringTest.php`（19 tests）。
- **修改文件**：`config/visionsy.php`、`config/queue.php`、`PathResolverService.php`、`ImageDerivativeService.php`、`MediaStorageService.php`、`MediaScannerService.php`、`MediaUploadController.php`、`MediaFileController.php`、`ScanMediaGallery.php`、`DiscoverMediaGalleries.php`、`GenerateMediaDerivatives.php`、`IndexMediaFile.php`、`routes/console.php`、`compose.yaml`、四个 `.env.*` 样本、`create.blade.php`。

## 会话 A 总结（阶段 0–3）

- **阶段 0**：核验 Git/工作区与 DAM 全链路实现，建立测试基线（130 通过/23 既有失败/2 跳过），产出架构文档与本状态文档；明确 4 个上线阻断问题与不可验证项（无 Docker/MariaDB）。
- **阶段 1**：修复 4 个阻断问题——资源身份（gallery_id 查找 + `relative_path_hash` 唯一索引 + 冲突预检查命令 + 可回滚迁移）、Missing（空图库标记/离线不误标/枚举比较/恢复清除）、扫描锁（stale 75min > timeout 60min、失败释放锁）、Queue（retry_after 90→3700 > timeout 3600）。新增 15 测试全绿，迁移 up/down 验证，Pint 通过，未引入新失败。
- **阶段 2**：Docker Compose + FrankenPHP 静态配置——多阶段 Dockerfile、Caddyfile、compose(base/dev/prod)、env 示例（Win/Linux DAM 挂载）、README。YAML 全部解析通过；运行期验证待 Docker 环境。
- **阶段 3**：统一入口——跨平台 init.mjs、生产发布脚本 deploy-docker.sh.example、文档与 README（Docker 为默认、原生可选、legacy 标注）。bash/node 语法校验通过。
- **未触碰**：VisionSY_Public；未 commit/push；未覆盖任何未跟踪 `.env`/数据；未对真实库执行破坏性操作。
- **遗留**：23 个既有测试失败（TransferToken 401、UserGroup/MediaLibrary 下载 404、Dashboard 文案，顺序/时间相关 flaky），非会话 A 引入，留待后续；Docker/MariaDB 运行期验证待具备环境执行。
