<?php

namespace App\Services;

/**
 * 大文件 307 直连目标判定：
 *   校园 IP  -> https://files-internal.example.com:8443
 *   非校园 IP -> https://files.example.com:8443
 */
class RedirectDecisionService
{
    public function __construct(private CampusIpService $campusIpService)
    {
    }

    /**
     * @return array{target_host:string,target_base_url:string,reason:string}
     */
    public function decide(string $clientIp): array
    {
        if ($this->campusIpService->isCampusIp($clientIp)) {
            return [
                'target_host' => config('visionsy.transfer_hosts.lan'),
                'target_base_url' => config('visionsy.urls.lan_transfer'),
                'reason' => 'campus_ip_matched',
            ];
        }

        return [
            'target_host' => config('visionsy.transfer_hosts.external'),
            'target_base_url' => config('visionsy.urls.external_transfer'),
            'reason' => 'external_ip',
        ];
    }
}
