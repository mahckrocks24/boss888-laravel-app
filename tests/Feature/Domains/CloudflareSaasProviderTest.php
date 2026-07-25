<?php

namespace Tests\Feature\Domains;

use App\Services\Domains\Providers\CloudflareSaasProvider;
use App\Services\Domains\ProviderException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Maps real Cloudflare Custom Hostnames v4 payloads onto the neutral DTO.
 * Uses Http::fake — no live calls.
 */
class CloudflareSaasProviderTest extends TestCase
{
    private function provider(): CloudflareSaasProvider
    {
        return new CloudflareSaasProvider('zone123', 'token123', 'routing.levelupgrowth.io', 'txt');
    }

    private function createEnvelope(string $status = 'pending', string $sslStatus = 'pending_validation'): array
    {
        return [
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => [
                'id' => 'ch_abc', 'hostname' => 'www.acme.com', 'status' => $status,
                'ssl' => [
                    'status' => $sslStatus, 'method' => 'txt', 'type' => 'dv',
                    'validation_records' => [[
                        'txt_name' => '_acme-challenge.www.acme.com', 'txt_value' => 'dcv-token-xyz',
                    ]],
                    'validation_errors' => [],
                ],
                'ownership_verification' => [
                    'type' => 'txt', 'name' => '_cf-custom-hostname.www.acme.com', 'value' => 'own-token',
                ],
                'verification_errors' => [],
                'created_at' => '2026-07-24T00:00:00Z',
            ],
        ];
    }

    public function test_create_maps_response_to_dto(): void
    {
        Http::fake(['*/custom_hostnames' => Http::response($this->createEnvelope(), 200)]);

        $r = $this->provider()->createCustomHostname('www.acme.com');

        $this->assertSame('ch_abc', $r->providerHostnameId);
        $this->assertSame('www.acme.com', $r->hostname);
        $this->assertSame('pending', $r->ownershipStatus);
        $this->assertSame('pending_validation', $r->sslStatus);
        $this->assertSame('txt', $r->validationMethod);
        $this->assertFalse($r->active);

        $purposes = array_column($r->validationRecords, 'purpose');
        $this->assertContains('ownership', $purposes);
        $this->assertContains('ssl', $purposes);
    }

    public function test_active_hostname_is_active(): void
    {
        Http::fake(['*/custom_hostnames' => Http::response($this->createEnvelope('active', 'active'), 200)]);
        $r = $this->provider()->createCustomHostname('www.acme.com');
        $this->assertTrue($r->active);
    }

    public function test_get_returns_null_on_404(): void
    {
        Http::fake(['*/custom_hostnames/*' => Http::response(['success' => false, 'errors' => [['code' => 1436, 'message' => 'not found']]], 404)]);
        $this->assertNull($this->provider()->getCustomHostname('missing'));
    }

    public function test_delete_is_idempotent_on_404(): void
    {
        Http::fake(['*/custom_hostnames/*' => Http::response(['success' => false, 'errors' => [['code' => 1436, 'message' => 'not found']]], 404)]);
        $this->assertTrue($this->provider()->deleteCustomHostname('gone'));
    }

    public function test_hard_error_throws_provider_exception(): void
    {
        Http::fake(['*/custom_hostnames' => Http::response(['success' => false, 'errors' => [['code' => 1414, 'message' => 'hostname already exists']]], 409)]);
        $this->expectException(ProviderException::class);
        $this->provider()->createCustomHostname('www.acme.com');
    }

    public function test_validation_instructions_include_routing_cname(): void
    {
        Http::fake(['*/custom_hostnames' => Http::response($this->createEnvelope(), 200)]);
        $r = $this->provider()->createCustomHostname('www.acme.com');
        $instructions = $this->provider()->getValidationInstructions($r);

        $routing = array_values(array_filter($instructions, fn ($i) => $i['purpose'] === 'routing'));
        $this->assertNotEmpty($routing);
        $this->assertSame('routing.levelupgrowth.io', $routing[0]['value']);
    }
}
