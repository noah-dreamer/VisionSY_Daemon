<?php

namespace App\Services;

/**
 * 大文件 307 直连目标判定：
 *   内网  -> https://files-internal.example.com:8443
 *   外网  -> https://files.example.com:8443
 */
class RedirectDecisionService
{
    /**
     * @return array{target_host:string,target_base_url:string,reason:string}
     */
    public function decide(bool $isCampus): array
    {
        if ($isCampus) {
            return [
                'target_host' => config('visionsy.transfer_hosts.lan'),
                'target_base_url' => config('visionsy.urls.lan_transfer'),
                'reason' => 'campus_intranet_probe',
            ];
        }

        return [
            'target_host' => config('visionsy.transfer_hosts.external'),
            'target_base_url' => config('visionsy.urls.external_transfer'),
            'reason' => 'external_network',
        ];
    }
}
