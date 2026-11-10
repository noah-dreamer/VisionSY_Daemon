# daemon / dashboard 分体部署

`visionsy-daemon` 包含普通用户媒体网站、文件传输、缩略图、扫描任务、队列、调度器及 MariaDB 11.4。
`visionsy-dashboard` 仅提供静态管理界面。管理员浏览器直接连接 daemon 的管理 API，用户和权限的唯一业务数据保存在 daemon。

两个容器使用不同的 Docker 网络。即使在同机测试，也由浏览器通过宿主机 IP 和发布端口连接 daemon，
不依赖 Docker 服务名，不共享数据库/媒体目录，不挂载 Docker socket。

## 首次启动

以下命令从仓库根目录执行。旧的 `install.sh` 暂时保留原安装流程，新架构使用独立 Compose 文件。

1. 复制 `deploy/docker/.env.daemon.example` 为同目录的 `.env.daemon`。
2. 填入独立随机的 `APP_KEY`、`DB_PASSWORD`、`DB_ROOT_PASSWORD`、`INTERNAL_API_SECRET`，设置媒体目录和访问地址。
   `APP_KEY` 可按模板中的 Python 命令生成。其余秘密可分别用 `python3 -c 'import secrets;print(secrets.token_hex(32))'` 生成。
3. 如 NAS 原件位于 SMB/NFS，先在 daemon 主机挂载，再把路径填入 `DAM_HOST_MANAGED`。
4. 构建并启动 daemon 与 dashboard：

```bash
docker compose -p visionsy-split -f deploy/docker/compose.split.yaml \
  --env-file deploy/docker/.env.daemon up -d --build --wait --wait-timeout 300
```

默认媒体网站端口为 8000，控制台端口为 8090。二者可分别用 `DAEMON_PORT` 和 `DASHBOARD_PORT` 改变。
控制台不依赖 daemon 的启动顺序；它可以先启动，管理员在浏览器初始化本地解锁账号。
启动前设置 `MANAGEMENT_ALLOWED_ORIGINS` 为实际面板来源，例如 `http://192.168.1.10:8090`，多个来源以逗号分隔。
同一主机运行多个 daemon 时，为每个实例设置不同的 `DAEMON_SESSION_COOKIE`，避免不同端口的网站登录 Cookie 相互覆盖。

下面的命令初始化默认用户组与媒体目录，并随机生成初始管理员密码（仅显示一次，请保存；已有账号保持不变）：

```bash
docker compose -p visionsy-split -f deploy/docker/compose.split.yaml \
  --env-file deploy/docker/.env.daemon exec daemon php artisan db:seed --class=ProductionSeeder --force

docker compose -p visionsy-split -f deploy/docker/compose.split.yaml \
  --env-file deploy/docker/.env.daemon exec daemon php artisan media:init-managed-source

docker compose -p visionsy-split -f deploy/docker/compose.split.yaml \
  --env-file deploy/docker/.env.daemon exec daemon php artisan visionsy:ensure-admin admin@example.com --if-missing
```

daemon 初次启动会创建数据库和增量迁移；不会自动创建演示用户。上述生产种子只创建默认用户组。

## 连接控制台

在 daemon 上创建管理密钥：

```bash
docker compose -p visionsy-split -f deploy/docker/compose.split.yaml \
  --env-file deploy/docker/.env.daemon exec daemon php artisan visionsy:management-key admin@example.com --name=Dashboard
```

密钥只显示一次，绑定一个当前已激活的系统管理员。该管理员被禁用、删除或降权后，密钥立即失效。
连接密钥仅在添加或编辑 daemon 时填写，不复用 `INTERNAL_API_SECRET`。控制台不提供独立的密钥管理页面。

打开 `http://服务器IP:8090`，创建 **当前浏览器**的连接解锁账号。
选择“添加 daemon”，输入：

- 名称：用于区分保存的连接。
- IP 或域名：管理员电脑可访问的 daemon 地址，如 `192.0.2.10`，不包含协议与路径。
- 协议和端口：如 HTTP、8000；跨不可信网络时使用有效证书的 HTTPS。
- 管理 API 密钥：刚才创建的完整密钥。

点击“测试连接”校验连通性、管理员权限及 API 版本，再保存。已保存密钥不再回显；编辑时留空表示保留。
可以保存多个连接并切换当前工作空间。daemon 离线或密钥失效时仍可打开控制台，修改地址/密钥后重新连接。
`127.0.0.1` 指向管理员电脑；Dashboard 主机不需要能访问 daemon。
连接配置在当前浏览器加密保存，刷新后重新解锁。更换浏览器或清除网站数据需重新添加连接。

## 分开服务器部署

daemon 主机只运行：

```bash
docker compose -p visionsy-daemon -f deploy/docker/compose.daemon.yaml \
  --env-file deploy/docker/.env.daemon up -d --build --wait --wait-timeout 300
```

dashboard 主机只需要 相邻 `VisionSY_Dashboard/` 独立项目构建上下文和相应的 Compose 文件，运行：

```bash
docker compose -p visionsy-dashboard -f deploy/docker/compose.dashboard.yaml up -d --build --wait
```

浏览器访问 dashboard，按相同流程输入 daemon 地址。控制台与 daemon 可以分别更新。
Dashboard 的 `public/` 也可直接部署到普通静态服务器，无需 Node 运行时。
正式部署为面板和 daemon 都配置 HTTPS，并把面板的实际来源加入 daemon 的 `MANAGEMENT_ALLOWED_ORIGINS`。
HTTPS 面板不能连接 HTTP daemon；浏览器的本地网络访问询问需要管理员允许。普通用户文件和图片流量始终走 daemon。

## 管理 API 与权限

管理接口前缀为 `/api/management/v1`，使用 `Authorization: Bearer <管理密钥>`。
`info` 返回产品、版本、API 主版本及密钥绑定的管理员；其他接口覆盖：

| 路径 | 能力 |
| --- | --- |
| `overview` | 统计、数据库连接与 Supervisor 进程状态 |
| `users`、`groups` | 用户与权限组维护 |
| `sources`、`galleries`、`resources` | 媒体目录、图库、素材与扫描/预览任务 |
| `tasks`、`worker/restart` | 队列状态、失败任务重试/遗忘、平滑重启队列 |
| `audit` | 操作与业务审计，按操作和邮箱筛选 |
| `backups` | 创建、列出、下载、删除数据库备份 |

daemon 对每个请求重新检查密钥及其所属账号；禁止删除、停用或降低当前连接管理员的权限。
用户、密钥、备份等变更记入 daemon 审计。API 不返回密码哈希、SMB 密码、任务序列化载荷或异常堆栈。
新 daemon 不再开放旧 `/admin` 管理页面；管理员访问时显示迁移说明，设置 `DASHBOARD_URL` 可提供控制台链接。
旧单容器安装路径保留兼容模式，开发与旧后台回归测试也保留原页面。

## 注册验证码与邮件服务

控制台选择当前 daemon 后打开“邮件服务”，填写 SMTP 主机、端口、加密方式、账号、授权码及发件地址，
勾选启用并保存。授权信息加密保存在 daemon 的数据库中，页面不回显密码；留空表示保留已有授权码。
常见组合为端口 587 + STARTTLS，或 465 + SSL/TLS，以实际发信服务商的说明为准。
“无加密”仅用于可信网络中的邮件中继或本地测试。

保存后先发送测试邮件，确认收件箱或垃圾箱中可以收到。页面显示邮件服务器接受，并不保证最终进入收件箱。
网页配置即刻生效，无需修改容器环境或重新构建镜像；没有网页配置时沿用环境中的 SMTP 配置。
默认日志模式不能投递真实邮件：生产注册会明确提示尚未配置，不再假称验证码已发送。

注册邮件发送失败会撤销本次新账号创建，允许用户再次注册；重发失败不作废上一次验证码、不增加重发冷却。
已注册且等待验证的用户无需重新注册，邮件配置完成后在验证码页面点击“重新发送验证码”。
本地联调 `python3 -B deploy/tests/smoke_registration_mail.py` 使用临时 SMTP 接收器和虚构邮箱，
验证真实 SMTP 传输、注册、验证码验证，并在结束时恢复原来的邮件设置。

## 数据与数据库备份

- `db_data`：daemon 内 MariaDB 的持久数据。
- `storage`：应用文件、私有数据库备份等。
- `bootstrap_cache`：应用启动缓存。
- 媒体宿主机目录：照片/视频原件和图库 `preview/`。
- Dashboard 无服务端持久数据卷；连接加密保存在管理员浏览器。旧 `dashboard_data` 卷保留但不再挂载。

在“数据库备份”点击创建后，由 daemon 队列流式执行 `mariadb-dump` 并压缩为 `.sql.gz`。
完成后可在控制台下载或删除。处理中不允许删除；任务中断/超时会显示失败，长时间遗留任务会标记失败。
备份存储在 daemon 私有 storage，下载由浏览器携带有效连接密钥直接请求 daemon，响应禁止缓存。

数据库备份含用户和媒体索引，但**不包含媒体文件和 `APP_KEY`**。恢复服务器时必须另行保留原件、应用配置与密钥，
否则加密的存储凭据可能无法解密。此版本提供导出和删除，不提供覆盖运行数据库的网页恢复按钮。

## 更新与旧部署迁移

当前仍从本地代码构建镜像；Docker Hub 发布和安装器改造待后续确定。
更新前先创建并下载数据库备份，保留应用配置。随后重新构建所需容器，再用 `up -d --wait` 替换，数据卷保持不变。
管理 API 使用独立版本号；控制台连接时拒绝不兼容的主版本。

从旧 `visionsy-production` 迁移时，不要在旧容器仍运行时，让新 daemon 打开同一个数据库卷。
应先备份、记录原 Compose 项目名/媒体挂载与卷名，停止旧应用，再使用同一项目及明确的数据卷映射启动 daemon。
本机联调另建 `visionsy-split-test` 项目，使用独立卷和媒体目录，未接管旧生产实例。

## 验证

```bash
node --test ../VisionSY_Dashboard/test/*.test.mjs
python3 -B -m unittest discover -s deploy/tests -v
# 仅针对隔离的 split-test daemon，镜像须使用 INSTALL_COMPOSER_DEV=1 构建：
node deploy/docker/test.mjs --daemon-container visionsy-split-test-daemon-1
# 已准备本机测试项目及其私有凭据后：
PLAYWRIGHT_MODULE=/path/to/playwright-core/index.mjs node ../VisionSY_Dashboard/test/direct.browser.mjs
```

测试入口仍强制 PHP 测试库名以 `_test` 结尾；测试媒体目录使用独立临时路径。
实际联调覆盖不同 Docker 网络、宿主机地址连接、错误密钥、管理 CRUD、文件扫描和直接缩略图、
异步备份与下载、daemon 离线及重启恢复。浏览器验证覆盖全部页面、用户创建/删除、连接编辑/测试、
连接凭据不回显，以及手机布局。

## 从代理式 Dashboard 升级

现有服务器账号和加密连接不再用于面板登录。升级后首次在各浏览器创建本地解锁账号，并重新添加 daemon。
旧数据卷不会删除；daemon 现有连接密钥与业务数据保持有效。Dashboard 不再提供 `/api/*` 后端。

Dashboard 源码已移至工作区根目录的独立 Git 项目 `VisionSY_Dashboard/`，与 `VisionSY_Daemon/` 并列。
本仓库 Compose 通过相对路径构建相邻项目；异机部署时直接使用 Dashboard 仓库自带的 `compose.yaml`。
