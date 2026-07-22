<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IpWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // avoid throttle bleed between requests
    }

    private function healthFrom(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson('/api/internal/health');
    }

    public function test_a_whitelisted_ip_reaches_the_internal_endpoint(): void
    {
        config(['internal.ip_whitelist' => ['127.0.0.1']]);

        $this->healthFrom('127.0.0.1')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_a_non_whitelisted_ip_is_forbidden(): void
    {
        config(['internal.ip_whitelist' => ['127.0.0.1']]);

        $this->healthFrom('8.8.8.8')->assertForbidden();
    }

    public function test_a_cidr_range_is_honoured(): void
    {
        config(['internal.ip_whitelist' => ['10.0.0.0/8']]);

        $this->healthFrom('10.5.20.30')->assertOk();
        $this->healthFrom('192.168.1.1')->assertForbidden();
    }

    public function test_an_empty_whitelist_blocks_everyone(): void
    {
        config(['internal.ip_whitelist' => []]);

        $this->healthFrom('127.0.0.1')->assertForbidden();
    }

    public function test_the_forbidden_response_is_json_for_the_api(): void
    {
        config(['internal.ip_whitelist' => ['127.0.0.1']]);

        $this->healthFrom('8.8.8.8')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }
}
