<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * 可信真实 IP 解析。
 *
 * 规则（见 README《账号注册与激活流程》IP 透传链路）：
 *   真实客户端 IP 经 CF -> 边缘服务器 Nginx（CF-Connecting-IP 写入 X-Real-IP）
 *   -> 反向代理网关（XFF 传递）-> 官网容器。
 * 官网后端仅信任来自 反向代理网关 内网 IP（TRUSTED_PROXY_IPS）的转发头，
 * 其余来源一律使用 TCP 对端地址，防止伪造 X-Real-IP 激活账号。
 */
class TrustedProxyIpResolver
{
    /** @var string[] */
    private array $trustedProxies;

    public function __construct(?array $trustedProxies = null)
    {
        $this->trustedProxies = $trustedProxies ?? (array) config('visionsy.trusted_proxy_ips', []);
    }

    public function resolve(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR', '');

        if (! $this->isTrustedProxy($remote)) {
            return $remote;
        }

        foreach ($this->candidateIps($request) as $candidate) {
            if ($this->isValidIp($candidate)) {
                return $candidate;
            }
        }

        return $remote;
    }

    private function isTrustedProxy(string $remote): bool
    {
        return $remote !== '' && in_array($remote, $this->trustedProxies, true);
    }

    /**
     * 按优先级返回候选 IP：
     *   X-Real-IP > CF-Connecting-IP > X-Forwarded-For 第一个。
     *
     * @return string[]
     */
    private function candidateIps(Request $request): array
    {
        $candidates = [];

        if ($realIp = trim((string) $request->headers->get('X-Real-IP', ''))) {
            $candidates[] = $realIp;
        }

        if ($cfIp = trim((string) $request->headers->get('CF-Connecting-IP', ''))) {
            $candidates[] = $cfIp;
        }

        $xff = (string) $request->headers->get('X-Forwarded-For', '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '') {
                $candidates[] = $first;
            }
        }

        return $candidates;
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
