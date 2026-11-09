> 2026-11-10 部署更新：生产改为独立 `compose.prod.yaml`，仅一个容器，内部运行
> FrankenPHP、MariaDB 11.4、队列和调度器；Supervisor 管理生命周期。
> 数据库监听 `127.0.0.1:3306`，启动时先执行增量迁移再启动应用；持久卷名称沿用旧部署。
> 下文涉及多服务 Compose 和显式迁移的描述对应开发环境及旧部署；最新生产操作见 `deploy/INSTALL-LINUX.md`。

# VisionSY 部署与 DAM 存储架构

> 长期架构文档。保存已确认的长期设计、当前与目标组件图、边界与职责划分。
> 实施进度与阶段记录见 [`../implementation/deployment-modernization-status.md`](../implementation/deployment-modernization-status.md)。
> 最后更新：2026-10-25（统一媒体目录与图库内 preview）。

---

## 1. 产品形态

VisionSY 的默认产品形态是：

> **Laravel 12 单体应用**，官网、账号权限与内置 DAM 位于 `web/`。

- `web/`：Laravel 12 应用（官网 + `/media` 内置媒体库 + transfer-token 内部接口）。
- `deploy/`：部署模板。Docker Compose 入口见 `deploy/docker/`。

---

## 2. 当前组件图（Docker Compose + FrankenPHP）

```text
                    ┌────────────────────────────────────────────┐
                    │            Docker Compose（统一入口）         │
                    │  Windows 测试机 与 Linux 生产机 共用同一套     │
                    │  compose + 相同容器内部路径                   │
                    └────────────────────────────────────────────┘
                                         │
   ┌─────────────────────────────────────┼─────────────────────────────────────┐
   │                                     │                                     │
┌──▼───────────────┐         ┌───────────▼──────────┐         ┌────────────────▼─────────┐
│  app             │         │  worker              │         │  scheduler               │
│  FrankenPHP      │         │  queue:work          │         │  schedule:work           │
│  public/ 为 Web 根│         │  (同一镜像)           │         │  (同一镜像)               │
│  :80 / :443      │         │  --sleep --tries     │         │  持续运行                 │
│                  │         │  --timeout --max-time│         │                          │
└──┬───────────────┘         └───────────┬──────────┘         └────────────────┬─────────┘
   │                                     │                                     │
   └───────────────┬─────────────────────┴─────────────────────┬───────────────┘
                   │                                            │
        ┌──────────▼──────────┐                      ┌──────────▼──────────┐
        │  db (MariaDB)       │                      │  共享存储（卷/挂载）  │
        │  固定版本            │                      │  storage/            │
        │  独立 volume         │                      │  bootstrap/cache     │
        │  生产不发布公网端口   │                      │  统一媒体目录           │
        │  healthcheck        │                      └──────────────────────┘
        └─────────────────────┘

仅开发：vite 服务（HMR），生产镜像在构建期生成前端资源，不运行 vite。
```

目标要点：

- **app、worker、scheduler 使用同一个应用镜像**，以不同命令运行。
- HTTP 使用 **FrankenPHP**（`public/` 为 Web 根）。
- 生产**不**运行 `php artisan serve`，**不**运行 Vite dev server。
- 开发与生产共享基础 compose，通过 override / profile / 环境文件表达差异。
- Windows 与 Linux 使用**相同容器内部路径**，仅宿主机 bind source 不同。

---

## 4. 开发与生产差异

| 维度 | 开发（dev） | 生产（prod） |
| --- | --- | --- |
| HTTP 入口 | FrankenPHP（compose）；可选原生 `artisan serve` | FrankenPHP（compose） |
| 前端资源 | Vite HMR（`vite` 服务） | 镜像构建期 `npm run build`，无 vite |
| 数据库 | MariaDB（compose `db`） | MariaDB（compose `db`，不发布公网端口） |
| 自动化测试 | 入口固定为 `node deploy/docker/test.mjs`；跑在独立库 `visionsy_test` 上（与生产同构） | 不在生产运行测试 |
| 队列 | database（worker 服务） | database（worker 服务，长驻） |
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| 媒体目录挂载 | 可读写，扫描和上传共用 | 可读写，图库内 preview 保存生成文件 |
| 迁移 | 显式任务/命令 | 单独、可观察的发布步骤 |
| 秘密 | 占位符 `.env`（不入库） | 从密钥管理/环境变量注入 |

---

## 5. 容器与卷边界

### 5.1 服务

| 服务 | 镜像 | 命令 | 说明 |
| --- | --- | --- | --- |
| `app` | `visionsy-app`（构建） | FrankenPHP 运行 | Web 入口，`public/` 为根 |
| `worker` | `visionsy-app`（同镜像） | `queue:work`（显式 sleep/tries/timeout/max-time） | 长驻消费者 |
| `scheduler` | `visionsy-app`（同镜像） | `schedule:work` | 持续运行调度器 |
| `db` | 固定版本 MariaDB | 数据库 | 独立 volume，healthcheck |
| `vite` | node（仅 dev profile） | `npm run dev` | 仅开发 HMR |

### 5.2 卷与挂载

| 容器路径 | 类型 | 访问 | 说明 |
| --- | --- | --- | --- |
| `/app/storage` | named volume | app/worker/scheduler 一致 | Laravel storage（日志/缓存/会话/受保护文件） |
| `/app/bootstrap/cache` | named volume | app/worker/scheduler 一致 | 启动缓存 |
| `/data/visionsy/managed` | bind（宿主机） | 可读写 | 已有原件、上传文件及图库内 preview |
| `db` 数据 | named volume | 仅 db | 数据库持久化 |

> 应用容器根目录为 `/app`（FrankenPHP 默认 Web 根 `/app/public`）。
> 稳定容器路径（Windows 与 Linux 一致）：
>
> ```text
> /data/visionsy/managed
> ```

---

## 6. 统一媒体目录职责

目录扫描与网页上传共用同一套索引和后台处理。登记目录后每分钟自动扫描，图库内 `preview/{resource_id}/` 保存缩略图、预览和水印。详细规则见 [统一媒体库](media-library.md)。

- 根目录文件归属根图库；一级子目录自动发现为图库，图库内可递归扫描。
- 所有可写图库都支持上传，能力不再取决于 `is_managed` 或文件来源标记。
- 只读属性控制原件写入，生成新预览仍需要图库内 preview 的写权限。
- 扫描剪枝排除 preview、旧 _visionsy、隐藏目录和符号链接。
- 旧独立派生目录及 _visionsy 缓存保留读取兼容，新预览统一写入 preview。
- 原件移动或缺失只标记 Missing，不自动删除其他文件。

---

## 7. 数据库允许保存的路径形式

数据库与业务配置中：

- **只保存容器可见路径**（如 `/data/visionsy/sources`、`/data/visionsy/managed`）。
- **不保存** Windows 盘符（`D:\...`）或 Linux 宿主机专用绝对路径（`/mnt/nas/...`）。
- 宿主机路径映射只存在于部署配置（compose 的 bind source、宿主机环境变量）。
- 资源身份保存相对路径 `relative_path`（相对图库根），以及用于唯一约束的 `relative_path_hash`（见 §10）。
- 存储源 `root_path` 保存容器内稳定路径。

---

## 8. SMB/NFS 挂载责任

Laravel、FrankenPHP 和普通 Worker **不负责挂载**：

- 不运行 `mount` / `umount`；
- 不获得任意 root shell；
- 不使用宽泛 sudoers；
- 不把 SMB/NFS 密码放入命令行或数据库（`smb_password` 若保存须加密，且优先由宿主机凭据管理）；
- 不接受用户输入后拼接 shell 命令。

挂载、凭据、重连和开机自动挂载由**宿主机或基础设施层**负责。容器只把已挂载的 SMB/NFS 当作普通目录访问。

> 现状代码含 `MediaMountManager` 与受控挂载驱动（`PrivilegedHelperMountDriver` 等），属于可选的受控挂载子系统；默认部署仍推荐宿主机挂载。

---

## 9. Legacy 兼容与迁移原则

- **legacy 资源**（`storage_source_id === null`）保持可读；`PathResolverService` 对 legacy 与多存储源分别解析。
- 不为表面符合文档而伪造完成状态；迁移必须有验证命令与回滚方案后才执行。
- 旧部署（nginx/systemd/reverse-proxy/`deploy.sh.example`）标注 **legacy/reference**，不与 Docker 默认部署混用。
- ResourceSpace 已被内置 DAM 完全取代，相关文件和代码已于 2026-07-23 全量清理。
- 新增唯一约束前先检测已有冲突，**不静默删除/合并/覆盖数据**。

---

## 10. 资源身份与 Missing 语义（架构约定）

- 扫描资源以 **`(gallery_id, relative_path)`** 为图库内唯一身份。
- 不同图库中的同名 `relative_path` 不能互相覆盖。
- 由于 `relative_path` 为 `varchar(1024)`，在 MySQL/MariaDB（utf8mb4）下无法直接建唯一索引（超过 3072 字节索引上限），采用 **`relative_path_hash`（SHA-256）+ 唯一索引 `(gallery_id, relative_path_hash)`** 作为等价保护（见实施文档阶段 1）。
- Missing 语义：
  - 文件消失只标记 `missing`，不物理删除；
  - 只有**完整成功的扫描**结束后才可标记未发现资源为 missing；
  - 空图库成功扫描时，原有资源标记 missing；
  - 来源离线/无权限/扫描中断/全局错误时**不**批量标记 missing；
  - 恢复出现的文件清除 missing。
- 删除语义：外部扫描资源默认“删除”= 隐藏/归档/移出索引；物理删除仅限 VisionSY 管理、根可写、用户明确发起并通过权限与边界校验，且记录审计。

---

## 11. Queue 与 Scheduler 约定

- `database` 队列的 **`retry_after` 必须大于最长 worker timeout**。
- 当前最长 Job timeout：`ScanMediaGallery` = 3600s。因此 `retry_after` 默认提升到 **3700s**（可配置），worker `--timeout` 设为 **3600**（< retry_after）。
- 图库扫描锁的 stale 阈值必须 **大于** 扫描 Job timeout，避免合法长扫描被误判为 stale 并发（默认 75 分钟，可配置）。
- Job 必须可重试、可观察、幂等，长任务使用唯一锁（`GenerateMediaDerivatives` 已实现 `ShouldBeUnique`）。
- Worker `--timeout` < 队列连接 `retry_after`；长扫描与短任务可使用不同队列/timeout（后续队列拆分保留配置空间）。
- Scheduler 在生产中持续运行（`schedule:work`），避免重复调度。

---

## 12. 实施顺序

| 阶段 | 内容 | 会话 |
| --- | --- | --- |
| 0 | 状态核验与架构基线（本文档 + 状态文档） | A |
| 1 | DAM 数据正确性与 Queue 基础修复（身份/Missing/锁/timeout/测试/迁移） | A |
| 2 | Docker Compose 与 FrankenPHP（Dockerfile/compose/持久化/DB/运行方式/Windows/示例） | A |
| 3 | 统一日常开发与生产运维入口（部署脚本/文档/README） | A |
| 4 | DAM 路径重构：派生文件迁移到独立 `derivatives` 根、managed 上传归属 | B |
| 5 | legacy 资源迁移命令与回滚、生产一致性验证（MariaDB 全量测试） | B/C |
| 6 | 生产发布演练、备份/恢复、监控与告警 | C |
| 7 | Private → Public 脱敏同步（仅用户明确要求时） | C |

---

## 13. 明确不采用或暂缓的旧方案

| 方案 | 状态 | 说明 |
| --- | --- | --- |
| 独立 ResourceSpace 部署 | 已移除 | 已被内置 DAM 完全取代 |
| 生产 `php artisan serve` | 不采用 | 生产使用 FrankenPHP |
| 生产 Vite dev server | 不采用 | 前端资源构建期生成 |
| SQLite 作为生产一致性验证唯一库 | 不采用 | SQLite 仅用于快速自动化测试 |
| 应用容器内挂载 SMB/NFS | 不采用 | 由宿主机/基础设施挂载 |
| 数据库保存宿主机盘符/绝对路径 | 不采用 | 只保存容器可见路径 |
| 独立传输域名/复杂边缘分流 | 暂缓 | 初期以应用鉴权后直出为默认，性能数据证明需要时再引入 |
| 容器启动自动 `migrate:fresh`/`seed` | 不采用 | 迁移为单独可观察发布步骤 |

---

## 14. 反向代理与 Trusted Proxies（阶段 6）

- FrankenPHP/Caddy 作为应用容器的 Web 层，通过 `header_down` 指令设置 `X-Forwarded-For/Proto/Host/Port`。
- 前置反向代理（API Gateway 等）必须正确设置 `X-Forwarded-Proto: https` 和客户端真实 IP。
- Laravel 通过 `TRUSTED_PROXY_IPS`（逗号分隔）配置信任的代理 IP；默认 `127.0.0.1`，生产添加网关 IP。
- `bootstrap/app.php` 中 `$middleware->trustProxies(at: [...])` 启用；不信任 `*`（全信任），显式列出 IP。
- 生产 Caddyfile（`Caddyfile.prod`）启用 `auto_https off`（TLS 由前置反向代理终结）、限速（50 req/s/IP）、请求体大小限制。
- 生产 compose.prod.yaml bind-mount Caddyfile.prod 覆盖默认 Caddyfile。

## 15. 备份与恢复（阶段 6）

- **必须备份**：数据库（`mariadb-dump --single-transaction`）和 managed 原件（tar）。
- **不备份**：sources（由来源系统自行备份）、derivatives（可重建）。
- 备份命名：`{db}-{YYYYMMDD-HHMMSS}.sql.gz` / `managed-{YYYYMMDD-HHMMSS}.tar.gz`。
- 密码安全：备份命令中密码从容器环境变量注入，不写入命令行或脚本。
- 恢复为手动步骤（不提供一键恢复脚本），含隔离验证环节。
- 恢复顺序：DB → managed → APP_KEY → 启动 → 迁移 → 校验。
- 校验命令：`visionsy:health-diagnostics`、`media:identity-conflicts`、`media:legacy-stats`。

## 16. 发布与回滚（阶段 6）

- 发布步骤：备份 → 构建 → 重建容器 → 迁移 → 优化缓存 → 重载 Worker → 健康检查。
- 单机部署无法零停机；`up -d` 重建容器存在秒级中断。
- 回滚：先恢复数据库（从备份），再切换到旧镜像；不可逆迁移需从备份恢复。
- `deploy/scripts/deploy-docker.sh.example` 提供 `install` / `update` / `backup` / `status` 子命令。
