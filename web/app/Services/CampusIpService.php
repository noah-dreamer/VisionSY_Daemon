<?php

namespace App\Services;

use App\Models\CampusIpRange;

/**
 * 校园网 CIDR 白名单匹配（支持 IPv4 与 IPv6）。
 */
class CampusIpService
{
    /** 当前 IP 是否命中任意一条启用的校园网段。 */
    public function isCampusIp(string $ip): bool
    {
        return $this->matchedRange($ip) !== null;
    }

    /** 返回命中的网段记录；未命中返回 null。 */
    public function matchedRange(string $ip): ?CampusIpRange
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        foreach (CampusIpRange::query()->where('enabled', true)->get() as $range) {
            if (self::cidrMatch($ip, $range->cidr)) {
                return $range;
            }
        }

        return null;
    }

    /** 通用 CIDR 匹配，IPv4 / IPv6 通吃；非法输入一律视为不匹配。 */
    public static function cidrMatch(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            // 允许配置单个 IP（等价 /32 或 /128）
            $cidr .= str_contains($cidr, ':') ? '/128' : '/32';
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        if (! ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // 地址族必须一致（IPv4 vs IPv6）
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainder) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /** 校验一个字符串是否为合法 CIDR（用于后台表单）。 */
    public static function isValidCidr(string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        if (! ctype_digit($bits)) {
            return false;
        }

        $bin = @inet_pton($subnet);
        if ($bin === false) {
            return false;
        }

        return (int) $bits >= 0 && (int) $bits <= strlen($bin) * 8;
    }
}
