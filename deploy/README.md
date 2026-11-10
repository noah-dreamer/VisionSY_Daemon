# 分体部署入口

当前新增 `visionsy-daemon` 与 `visionsy-dashboard`：分别运行；Dashboard 只提供静态界面，管理员浏览器直接连接 daemon。
优先参阅[分体部署说明](SPLIT-DEPLOYMENT.md)。`install.sh` 仍保留原单容器安装流程。

# 部署（deploy/）

## 默认部署：Docker Compose + FrankenPHP

Linux 生产服务器从仓库根目录运行 `bash install.sh`，自动检测和补齐环境后交互安装，
详见 [Linux 一键安装](INSTALL-LINUX.md)。该入口使用独立的 `.env.install` 和 Docker 项目，
包含密钥生成、数据库迁移、生产默认数据、管理员创建及部署结果展示。

当前默认开发与生产部署入口为 **[`docker/`](docker/)**：

- `docker/compose.prod.yaml`：单个生产容器，内置网站、MariaDB、队列和调度器；开发使用 `compose.yaml` + `compose.dev.yaml`。
- `docker/README.md`：快速开始、DAM 挂载（Windows/Linux）、生产发布、验证清单、备份恢复、安全配置。
- `scripts/deploy-docker.sh.example`：旧手动发布示例，新部署使用根目录 `install.sh`。
- 应用镜像定义在 `../web/Dockerfile` 与 `../web/docker/Caddyfile`。

## 公开仓库规则

- 真实密钥只保存在服务器受控配置中；安装器自动生成的秘密存入 Git 忽略的 `.env.install`，不进入代码或镜像。
- 证书、私钥、`.env`、数据库转储和带真实值的 `.local` 文件不得提交。
- 发布前执行全文扫描，确认不存在生产域名、内网地址、真实路径及高熵凭据。
