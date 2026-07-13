# 公开部署模板（deploy/）

本目录只保存**脱敏模板**，不保存任何可直接用于生产环境的地址、证书路径、密钥或备份目的地。所有 `.example` 文件都必须先复制为本地文件，再替换占位符；真实副本应保持未跟踪状态。

```text
deploy/
├── nginx/
│   ├── site.conf.example
│   ├── media.conf.example
│   └── snippets/
│       ├── cloudflare-allowlist.conf.example
│       └── cloudflare-real-ip.conf.example
├── reverse-proxy/
│   └── rules.example.md
├── systemd/
│   ├── visionsy-queue.service.example
│   ├── visionsy-scheduler.service.example
│   └── visionsy-site.service.example
└── scripts/
    ├── deploy.sh.example
    └── backup-db.sh.example
```

## 使用方式

1. 将 Nginx 模板复制到 `<NGINX_CONFIG_DIR>`，去掉 `.example` 后缀，并替换 `__NGINX_CONFIG_DIR__`、`__TLS_CERT_DIR__`、`<ORIGIN_PUBLIC_IP>`、`__GATEWAY_PORT__`。
2. 参照 `reverse-proxy/rules.example.md` 在所选反向代理网关中配置 Host 分流和前置鉴权；不要导出含真实凭据的配置到仓库。
3. 将 systemd 模板复制到 `<SYSTEMD_UNIT_DIR>`，去掉 `.example` 后缀，并按实际部署用户与路径修改。
4. 将部署脚本安装为 `/usr/local/sbin/visionsy-deploy`（`chmod 750`），确认 `REPO_DIR=/srv/visionsy/repo` 等示例路径已按实际环境替换。脚本内 git 操作在仓库根目录、composer / npm / artisan 操作在 `web/` 子目录，二者不可混用。
5. 生产 `.env` 建议放在 `/srv/visionsy/shared/.env`，并软链到 `web/.env`（`ln -sfn`），这样 `git reset --hard` 不会影响配置。
6. 日常更新只需在应用容器内执行一次 `sudo /usr/local/sbin/visionsy-deploy`（可经 EasyTier SSH 触发），不需要重建任何容器。
7. `APP_KEY`、`DB_PASSWORD`、`INTERNAL_API_SECRET`、OAuth `client_secret` 等只从 `<SECRET_MANAGER>` 或服务器环境变量注入。

## 公开仓库规则

- 真实 Nginx、systemd、反向代理网关和备份脚本不得提交。
- 证书、私钥、`.env`、数据库转储和带真实值的 `.local` 文件不得提交。
- 发布前执行全文扫描，确认不存在生产域名、内网地址、真实路径及高熵凭据。
