<?php

namespace Tests\Unit;

use App\Services\TrustedProxyIpResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class TrustedProxyIpResolverTest extends TestCase
{
    private function makeRequest(string $remoteAddr, array $headers = []): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/dashboard', 'GET', server: $server);
    }

    public function test_untrusted_source_headers_are_ignored(): void
    {
        $resolver = new TrustedProxyIpResolver(['10.0.0.30']);

        $request = $this->makeRequest('203.0.113.50', [
            'X-Real-IP' => '10.20.0.8',
            'CF-Connecting-IP' => '10.20.0.8',
            'X-Forwarded-For' => '10.20.0.8',
        ]);

        $this->assertSame('203.0.113.50', $resolver->resolve($request));
    }

    public function test_trusted_proxy_x_real_ip_wins(): void
    {
        $resolver = new TrustedProxyIpResolver(['10.0.0.30']);

        $request = $this->makeRequest('10.0.0.30', [
            'X-Real-IP' => '10.20.0.8',
            'CF-Connecting-IP' => '198.51.100.7',
            'X-Forwarded-For' => '192.0.2.1, 10.0.0.30',
        ]);

        $this->assertSame('10.20.0.8', $resolver->resolve($request));
    }

    public function test_cf_connecting_ip_used_when_no_x_real_ip(): void
    {
        $resolver = new TrustedProxyIpResolver(['10.0.0.30']);

        $request = $this->makeRequest('10.0.0.30', [
            'CF-Connecting-IP' => '198.51.100.7',
            'X-Forwarded-For' => '192.0.2.1',
        ]);

        $this->assertSame('198.51.100.7', $resolver->resolve($request));
    }

    public function test_xff_first_ip_used_as_last_resort(): void
    {
        $resolver = new TrustedProxyIpResolver(['10.0.0.30']);

        $request = $this->makeRequest('10.0.0.30', [
            'X-Forwarded-For' => '192.0.2.1, 172.16.0.1',
        ]);

        $this->assertSame('192.0.2.1', $resolver->resolve($request));
    }

    public function test_invalid_header_value_falls_back_to_remote_addr(): void
    {
        $resolver = new TrustedProxyIpResolver(['10.0.0.30']);

        $request = $this->makeRequest('10.0.0.30', [
            'X-Real-IP' => 'not-an-ip',
        ]);

        $this->assertSame('10.0.0.30', $resolver->resolve($request));
    }

    public function test_no_headers_returns_remote_addr(): void
    {
        $resolver = new TrustedProxyIpResolver(['10.0.0.30']);

        $this->assertSame('10.0.0.30', $resolver->resolve($this->makeRequest('10.0.0.30')));
        $this->assertSame('8.8.8.8', $resolver->resolve($this->makeRequest('8.8.8.8')));
    }
}
