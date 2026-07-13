<?php

namespace Tests\Unit;

use App\Services\CampusIpService;
use PHPUnit\Framework\TestCase;

class CampusIpServiceTest extends TestCase
{
    public function test_ipv4_cidr_match(): void
    {
        $this->assertTrue(CampusIpService::cidrMatch('10.20.1.2', '10.20.0.0/16'));
        $this->assertTrue(CampusIpService::cidrMatch('10.20.255.255', '10.20.0.0/16'));
        $this->assertFalse(CampusIpService::cidrMatch('10.21.0.1', '10.20.0.0/16'));
        $this->assertFalse(CampusIpService::cidrMatch('192.168.1.1', '10.20.0.0/16'));
    }

    public function test_ipv4_non_byte_aligned_prefix(): void
    {
        $this->assertTrue(CampusIpService::cidrMatch('192.168.50.10', '192.168.50.0/26'));
        $this->assertFalse(CampusIpService::cidrMatch('192.168.50.100', '192.168.50.0/26'));
    }

    public function test_single_ip_without_prefix(): void
    {
        $this->assertTrue(CampusIpService::cidrMatch('10.0.0.5', '10.0.0.5'));
        $this->assertFalse(CampusIpService::cidrMatch('10.0.0.6', '10.0.0.5'));
    }

    public function test_ipv6_cidr_match(): void
    {
        $this->assertTrue(CampusIpService::cidrMatch('2001:db8::1', '2001:db8::/32'));
        $this->assertTrue(CampusIpService::cidrMatch('2001:db8:ffff::1', '2001:db8::/32'));
        $this->assertFalse(CampusIpService::cidrMatch('2001:db9::1', '2001:db8::/32'));
    }

    public function test_address_family_mismatch_never_matches(): void
    {
        $this->assertFalse(CampusIpService::cidrMatch('10.0.0.1', '2001:db8::/32'));
        $this->assertFalse(CampusIpService::cidrMatch('2001:db8::1', '10.0.0.0/8'));
    }

    public function test_invalid_input_never_matches(): void
    {
        $this->assertFalse(CampusIpService::cidrMatch('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(CampusIpService::cidrMatch('10.0.0.1', 'garbage/8'));
        $this->assertFalse(CampusIpService::cidrMatch('10.0.0.1', '10.0.0.0/abc'));
        $this->assertFalse(CampusIpService::cidrMatch('10.0.0.1', '10.0.0.0/33'));
    }

    public function test_is_valid_cidr(): void
    {
        $this->assertTrue(CampusIpService::isValidCidr('10.20.0.0/16'));
        $this->assertTrue(CampusIpService::isValidCidr('2001:db8::/32'));
        $this->assertTrue(CampusIpService::isValidCidr('10.0.0.1'));
        $this->assertFalse(CampusIpService::isValidCidr('10.20.0.0/33'));
        $this->assertFalse(CampusIpService::isValidCidr('foo/16'));
        $this->assertFalse(CampusIpService::isValidCidr('10.0.0.0/-1'));
    }
}
