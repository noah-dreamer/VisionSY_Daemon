<?php

namespace App\Services;

/**
 * 大文件 307 直连目标判定：
 * 默认使用当前请求的站点地址，也可显式配置内外网下载入口。
 */
class RedirectDecisionService
{
    /**
     * @return array{target_host:string,target_base_url:string,reason:string}
     */
    public function decide(bool $isCampus): array
    {
        $key = $isCampus ? 'lan_transfer' : 'external_transfer';
        $baseUrl = rtrim((string) (config("visionsy.urls.{$key}") ?: url('/')), '/');

        return [
            'target_host' => (string) parse_url($baseUrl, PHP_URL_HOST),
            'target_base_url' => $baseUrl,
            'reason' => $isCampus ? 'campus_intranet_probe' : 'external_network',
        ];
    }
}
