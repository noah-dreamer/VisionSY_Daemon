# 部署（deploy/）

## 默认部署：Docker Compose + FrankenPHP

当前默认开发与生产部署入口为 **[`docker/`](docker/)**：

- `docker/compose.yaml` + `compose.dev.yaml` + `compose.prod.yaml`：app / worker / scheduler / db（+ 开发 vite）。
- `docker/README.md`：快速开始、DAM 挂载（Windows/Linux）、生产发布、验证清单、备份恢复、安全配置。
- `scripts/deploy-docker.sh.example`：生产发布脚本（首次部署 / 日常更新 / 备份）。
- 应用镜像定义在 `../web/Dockerfile` 与 `../web/docker/Caddyfile`。

## 公开仓库规则

- `APP_KEY`、`DB_PASSWORD`、`INTERNAL_API_SECRET`、OAuth `client_secret` 等只从 `<SECRET_MANAGER>` 或服务器环境变量注入。
- 证书、私钥、`.env`、数据库转储和带真实值的 `.local` 文件不得提交。
- 发布前执行全文扫描，确认不存在生产域名、内网地址、真实路径及高熵凭据。
