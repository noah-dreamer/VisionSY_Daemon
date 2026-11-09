# Linux 一键安装

在已经下载完整代码的 Linux 服务器上，从仓库根目录运行：

```bash
bash install.sh
```

不需要预先安装 PHP、Composer、Node.js 或数据库。缺少系统依赖时，脚本会通过
`sudo` 请求本机授权，使用系统包管理器与 Docker 官方软件源尝试补齐环境。
支持自动准备 Ubuntu、Debian、Fedora、CentOS Stream、Rocky Linux、AlmaLinux；
其他 Linux 可以使用已安装的依赖。需要 Python 3.9+、Docker Engine 26+、Compose
2.35+ 和 Buildx。不会卸载现有 Docker、清空数据或改写镜像代理配置；旧版组件会尝试升级。

## 安装过程

1. 检测系统、Docker 服务及权限、Compose、Buildx、Python、curl 和系统 CA 根证书。
2. 自动构建生产镜像，前端资源也在镜像中构建。
3. 自动生成独立的数据库应用密码、数据库 root 密码、Laravel `APP_KEY` 和内部 API 密钥。
4. 询问应用监听 IP（默认 `0.0.0.0`）、HTTP 端口（默认 `8000`）和媒体目录。
5. 启动一个生产容器，内部先启动 MariaDB 并迁移表结构，再启动网站、队列和调度器；仅初始化生产默认用户组和媒体目录。
6. 隐藏输入 `admin@example.com` 的密码；输入后需要确认，直接回车则自动生成随机密码。
7. 建立缓存并检查数据库、后台进程、目录及网站健康。
8. 显示网站、登录页、管理后台、管理员账号以及配置和初始凭据的保存位置。

不会创建演示账号或演示图片。数据库使用 Docker 本地数据卷，图片原件和上传文件使用
同一个媒体目录，每个图库内的 `preview/` 保存缩略图、预览和水印。

## 应用监听与独立反向代理

安装器只部署 HTTP 应用及其数据库、队列和调度器。安装过程不需要域名、DNS 或网站证书，
不安装额外的反向代理，不申请证书，也不占用独立的 80/443 入口。系统 CA 根证书用于
下载 Docker 等依赖，与网站 TLS 证书无关。

默认监听 `0.0.0.0:8000`，完成后显示 `http://服务器IP:8000` 等直连地址。
同机反向代理也可以选择监听 `127.0.0.1`；反向代理位于其他主机时，使用
`0.0.0.0` 或服务器内网 IP，并在你的反向代理中将上游设置为 `http://服务器内网IP:端口`。
域名、HTTPS 和证书均在你专门的反向代理程序中配置。

反向代理应保留原始 `Host`，并设置 `X-Forwarded-Proto`、`X-Forwarded-For` 等头。
安装器自动信任本机回环和该应用的 Docker 网络；若代理位于其他主机，在 `.env.install`
的 `TRUSTED_PROXY_IPS` 中添加代理实际来源 IP，再重新部署。不要把公网任意来源设为可信代理。

安装器默认让导航和公共文件使用同站点相对路径；下载入口未显式配置时使用当前请求地址，
因此经反代访问时不会固定跳回服务器 IP。`APP_URL` 默认是本机 HTTP 地址，仅用作没有 HTTP
请求上下文时的后备地址；若启用邮件通知等后台生成链接的功能，可在配置文件中设置正式
`APP_URL`。这项应用配置不涉及证书，也不是安装必填项。

内网探测和独立内网下载入口不在安装时询问；有需要时再配置 `INTRANET_PROBE_URL`、
`VISION_LAN_TRANSFER_BASE_URL` 等选项。探测 URL 默认留空，此时前端不会判定为校园网。

## NAS 媒体目录

在宿主机先挂载 SMB/NFS，例如 `/mnt/nas/media`，安装时输入该目录即可。
安装器不会接收或保存 NAS 密码，也不会替你创建 SMB 挂载。

脚本会检查目录的文件写入与重命名。目录读写映射到容器内
`/data/visionsy/managed`，这个名称只是兼容已有代码的容器路径；已有图片和新上传图片共用该目录。
NAS 的开机挂载应先于容器启动，避免 NAS 未挂载时写入同名的本机空目录。
启用 SELinux 的服务器需要让该挂载可被容器读写。

## 配置、密码和重复运行

安装器默认使用独立 Docker 项目 `visionsy-production`：

```text
deploy/docker/.env.install           # 数据库密码、应用密钥、HTTP 监听参数等
deploy/docker/.install/credentials.txt # 安装时的管理员初始凭据
deploy/docker/.install/backups/      # 重复部署前的数据库备份
deploy/docker/.install/config-*.env  # 重复部署前的配置备份
```

这些文件均被 Git 忽略；配置和凭据文件设为 `600`，状态目录设为 `700`。
如果代码目录也位于 SMB 上，最终权限取决于该共享的挂载配置，应通过 NAS 访问权限保护秘密；
完成提示会显示凭据文件的实际权限。
初始凭据文件记录安装时的密码；如果已在网站修改密码，以网站中的新密码为准。
数据库 root 密码保存在 `.env.install`，不会显示在部署结果中。

重复执行同一命令会重新构建镜像，保留已有数据库密钥及 `APP_KEY`，沿用配置作为输入默认值，
先备份数据库并停止旧容器，再启动单容器并执行待执行迁移。已有管理员不会被重设密码，
已有用户组权限不会被初始化程序覆盖。备份或迁移失败时会停止，并保留配置和数据；
迁移失败时网站与后台任务不会启动，修复后再次运行即可继续。安装器不自动恢复或回滚数据库。

`.env.install` 必须随数据库保留；如果发现同名 Docker 项目的数据卷还在但配置丢失，
安装器会拒绝生成新密钥，要求恢复配置。不要删除配置后试图重新初始化旧数据库。
完整迁移服务器时，另行备份数据库、NAS 原件、Laravel storage 卷和 `.env.install`。

可以指定其他独立项目名，但同一个代码目录之后应继续使用同一名称：

```bash
bash install.sh --project visionsy-campus
```

现有开发部署的 `deploy/docker/.env`、`compose.local.yaml` 和 `visionsy` 项目保持独立。
生产和开发如果运行在同一台主机，访问端口与媒体目录应分别选择，避免端口冲突或共用图片。

## 查看服务

从 `deploy/docker/` 运行以下 Bash 函数，然后使用 `dc ps`、`dc logs` 等命令。
如果安装时自定义项目名，将下面的名称替换为同一名称；Docker 需要提权时在 `docker` 前加 `sudo`。

```bash
dc() {
  docker compose --project-name visionsy-production \
    -f compose.prod.yaml -f compose.install.yaml \
    --env-file .env.install "$@"
}

dc ps
dc logs --tail=100 app
dc exec app supervisorctl -c /etc/visionsy/supervisord.conf status
dc exec app php artisan visionsy:health-diagnostics
```

数据库仅监听生产容器内的 `127.0.0.1:3306`，不对宿主机或公网发布。

默认邮件发送到日志。管理员登录不依赖 SMTP，但开放用户注册前，须在 `.env.install`
配置真实邮件服务，例如 `MAIL_MAILER`、`MAIL_HOST`、`MAIL_PORT`、`MAIL_USERNAME`、
`MAIL_PASSWORD` 和发件地址，再重新运行安装器使其生效。手工编辑时保持文件的
`KEY='value'` 格式，值不支持单引号、反斜杠或换行。

## 验证

```bash
bash -n install.sh
python3 -B -m unittest discover -s deploy/tests -v
node deploy/docker/test.mjs --filter=ProductionInstallationTest
```

Python 测试使用模拟 Docker，不修改系统包或真实数据库。PHP 测试仍通过项目统一入口运行，
使用隔离的 `visionsy_test` 数据库。

环境安装参考 [Docker Ubuntu 文档](https://docs.docker.com/engine/install/ubuntu/)、
[Docker Debian 文档](https://docs.docker.com/engine/install/debian/)。

## 从旧多容器部署升级

使用相同项目名重新执行 `bash install.sh`。安装器会从正在运行的旧 `db` 容器备份数据库，
依次停止旧后台任务、网站和数据库，再移除旧容器，启动新的唯一 `app` 容器。
继续使用同名 `db_data`、`storage`、`bootstrap_cache` 数据卷以及原媒体目录，
数据库保持 MariaDB 11.4，不降级、不清库，原有密钥和管理员密码保留。
升级会有短暂停机；不要删除数据卷，尤其不要运行 `down -v`。

生产配置现为独立文件，**不要再叠加 `compose.yaml`**；它和 `compose.dev.yaml` 保留给开发热重载。
生产镜像内置 Caddy 配置，不再依赖宿主机配置文件挂载。使用 Supervisor 管理进程、
转发停止信号并回收子进程；网站、数据库、队列或调度器任一不可用时，健康检查失败。
数据库和媒体均不存放在容器可写层，重建容器保留数据。

生产单容器集成验证（创建随机项目和隔离数据卷，结束时仅删除测试资源）：

```bash
docker build -t visionsy-app:single-container-test web
python3 -B deploy/tests/smoke_single_container.py
```

该验证覆盖旧 MariaDB 11.4 数据卷复用、登录页、后台进程恢复、健康检查，以及重启和重建后的数据保留。
