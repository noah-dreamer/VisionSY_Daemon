# PUBLIC TEMPLATE ONLY
# Replace every <PLACEHOLDER> or __PLACEHOLDER__ before deployment.
# Never commit production addresses, credentials, certificate paths, or backup destinations.

# 反向代理网关（RP 容器 10.0.0.30:<GATEWAY_PORT>）反代规则

反向代理网关 是学校内网唯一业务入口，监听 `<GATEWAY_PORT>`，开启 TLS（`*.example.com` 泛域名证书），按 Host 头分发到各业务容器。本文件描述需要在 反向代理网关 Web 管理界面配置的全部规则；反向代理网关 管理凭据见`<SECRET_MANAGER>`。

## 一、Host 分发规则

| # | 匹配 Host | 后端目标 | 说明 |
| --- | --- | --- | --- |
| 1 | `media.example.com` | `http://10.0.0.20:80` | ResourceSpace 控制流（来自 边缘服务器 回源） |
| 2 | `files.example.com` | `http://10.0.0.20:80` | 校外大文件 307 直连（来自学校公网 <GATEWAY_PORT>） |
| 3 | `files-internal.example.com` | `http://10.0.0.20:80` | 校内大文件 307 直连（局域网直接到达） |
| 4 | `example.com`、`www.example.com` | `http://10.0.0.10:80` | VisionSY 官网容器 |
| 5 | （预留）`service.example.com` | `http://10.0.0.x:80` | 后续扩展服务 |

每条规则统一开启：

- **传递真实 IP**：转发 `X-Real-IP`（保持上游值不覆盖）、追加 `X-Forwarded-For`、传递 `X-Forwarded-Proto: https`。
  - 校外链路：边缘服务器 Nginx 已把 `CF-Connecting-IP` 写入 `X-Real-IP`，反向代理网关 原样传递即可。
  - 校内直连链路（规则 3）：请求不带 `X-Real-IP`，反向代理网关 必须把 **TCP 对端地址** 写入 `X-Real-IP`，业务侧才能拿到真实校园 IP。
- **WebSocket / 大请求体**：规则 2、3 关闭请求体大小限制（分片上传单片可达数十 MB），关闭缓冲（流式转发）。

## 二、大文件直连的 token 校验（无 token 返回 403）

规则 2（`files.example.com`）与规则 3（`files-internal.example.com`）必须先验 token 再放行，**无 token 或校验失败直接 403**。在 反向代理网关 中用「前置鉴权（子请求 / Webhook 鉴权）」实现：

- 鉴权地址：`http://10.0.0.10/api/transfer-tokens/validate`
- 请求方式：GET，附加查询参数：
  - `token`：取自原始请求查询串中的 `token` 参数
  - `host`：原始请求的 Host（`files.example.com` 或 `files-internal.example.com`）
  - `action`：下载路径（`/filestore/`）填 `download`，上传路径（`/pages/ajax/chunk_upload.php`）填 `upload`
  - `method`：原始请求方法（GET / POST）
  - `client_ip`：真实客户端 IP（见上节）
- 请求头：`X-Internal-Secret: <INTERNAL_API_SECRET>`
  - 真实值来自`<SECRET_MANAGER>`，配置在 反向代理网关 的鉴权请求头中，不写入任何导出文件。
- 判定：鉴权响应 200 放行；403/401 时 反向代理网关 向客户端返回 **403**。
- 一次性消费：若启用 `TRANSFER_TOKEN_ONE_TIME=true` 且希望由网关消费 token，可把鉴权地址改为 `POST /api/transfer-tokens/consume`（参数相同，放请求体）。默认建议由 ResourceSpace 侧在传输完成后消费，网关只做 validate。

仅对以下路径启用鉴权（其余路径在 2、3 两条规则下一律 403）：

- `GET /filestore/*`（下载）
- `POST /pages/ajax/chunk_upload.php`（分片上传）

## 三、ResourceSpace 后台路径限制

规则 1（`media.example.com`）追加访问控制：

- 路径 `/pages/admin/*`：仅允许来源 IP 段 `10.0.0.0/24`、`10.0.1.0/24`（即内网 / 管理网络 管理轨经跳板访问），其余 403。

## 四、安全与监控（README《安全加固要点》）

- 开启 反向代理网关 动态封禁：同一 IP 登录失败（上游返回 401/403）超过 5 次，封禁 24 小时。
- 开启流量统计，设置月流量预警阈值，告警 Webhook 推送。
- TLS：上传 `*.example.com` 泛域名证书（acme.sh 自动续期后同步更新）。

## 五、新增服务时

按 README《后续扩展新服务》：新容器 -> DNS 服务商解析 + CF Custom Hostname + `cf_dns_updater.py` 的 `SUBDOMAINS` -> 本表加一条 Host 规则 + 边缘服务器 Nginx 加一个 server 块。无需新增公网端口。
