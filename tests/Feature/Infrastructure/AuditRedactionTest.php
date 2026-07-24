<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Services\InfraEventRecorder;
use Tests\TestCase;

/**
 * Audit-event redaction tests. Pure — redact() touches no facades.
 *
 * Directive §9 requires secret redaction. This asserts that requirement is real
 * rather than aspirational, because an audit table is exactly the place a leaked
 * provider token would sit unnoticed and be replicated into every backup.
 */
class AuditRedactionTest extends TestCase
{
    private InfraEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new InfraEventRecorder();
    }

    public function test_redacts_obvious_secret_keys(): void
    {
        $clean = $this->recorder->redact([
            'api_token'     => 'super-secret-value',
            'password'      => 'hunter2',
            'client_secret' => 'abc',
            'hostname'      => 'example.com',
        ]);

        $this->assertSame('[redacted]', $clean['api_token']);
        $this->assertSame('[redacted]', $clean['password']);
        $this->assertSame('[redacted]', $clean['client_secret']);
        $this->assertSame('example.com', $clean['hostname'], 'non-secrets must survive');
    }

    public function test_redaction_is_case_insensitive_and_partial(): void
    {
        $clean = $this->recorder->redact([
            'API_KEY'             => 'x',
            'CloudflareApiToken'  => 'x',
            'my_access_token_v2'  => 'x',
            'Authorization'       => 'Bearer abc',
        ]);

        foreach ($clean as $key => $value) {
            $this->assertSame('[redacted]', $value, "{$key} should have been redacted");
        }
    }

    public function test_redaction_recurses_into_nested_arrays(): void
    {
        $clean = $this->recorder->redact([
            'request' => [
                'headers' => ['authorization' => 'Bearer abc'],
                'body'    => ['hostname' => 'example.com', 'secret' => 'x'],
            ],
        ]);

        $this->assertSame('[redacted]', $clean['request']['headers']['authorization']);
        $this->assertSame('[redacted]', $clean['request']['body']['secret']);
        $this->assertSame('example.com', $clean['request']['body']['hostname']);
    }

    public function test_non_secret_payloads_pass_through_unchanged(): void
    {
        $payload = [
            'provider'             => 'null',
            'provider_resource_id' => 'null-abc123',
            'from_state'           => 'requested',
            'to_state'             => 'approved',
            'count'                => 3,
            'nested'               => ['ok' => true],
        ];

        $this->assertSame($payload, $this->recorder->redact($payload));
    }

    public function test_auth_code_is_redacted(): void
    {
        // Domain transfer auth codes are transfer-authorizing secrets.
        $clean = $this->recorder->redact(['auth_code' => 'ABC-123', 'domain' => 'example.com']);

        $this->assertSame('[redacted]', $clean['auth_code']);
        $this->assertSame('example.com', $clean['domain']);
    }
}
