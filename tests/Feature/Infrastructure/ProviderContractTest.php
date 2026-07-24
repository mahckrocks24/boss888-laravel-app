<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\Contracts\CustomHostnameConnector;
use App\Connectors\Infrastructure\Contracts\HostingProviderConnector;
use App\Connectors\Infrastructure\Null\NullCustomHostnameConnector;
use App\Connectors\Infrastructure\Null\NullHostingConnector;
use App\Connectors\Infrastructure\ProviderResult;
use Tests\TestCase;

/**
 * Connector-contract tests. Pure — no app, no DB, no Mockery.
 *
 * The central assertion here is the house doctrine from
 * Connectors/Contracts/ConnectorInterface.php:47-49 — "Connector 'success' is NOT
 * trusted until verified" — expressed as an executable invariant rather than a
 * comment. The social-publishing facade removed on 2026-07-15 existed precisely
 * because success and verification were conflated.
 */
class ProviderContractTest extends TestCase
{
    public function test_null_connectors_satisfy_their_contracts(): void
    {
        $this->assertInstanceOf(HostingProviderConnector::class, new NullHostingConnector());
        $this->assertInstanceOf(CustomHostnameConnector::class, new NullCustomHostnameConnector());
    }

    public function test_connectors_declare_provider_and_capability(): void
    {
        $hosting = new NullHostingConnector();
        $this->assertSame('null', $hosting->provider());
        $this->assertSame('hosting', $hosting->capability());

        $hostname = new NullCustomHostnameConnector();
        $this->assertSame('null', $hostname->provider());
        $this->assertSame('custom_hostname', $hostname->capability());
    }

    public function test_accepted_result_is_success_but_not_verified(): void
    {
        $result = ProviderResult::accepted('provisioning', 'abc123');

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified, 'accepted() must never claim verification');
        $this->assertSame('provisioning', $result->normalizedState);
        $this->assertSame('abc123', $result->providerResourceId);
    }

    public function test_verified_result_is_both(): void
    {
        $result = ProviderResult::verified('active', 'abc123');

        $this->assertTrue($result->success);
        $this->assertTrue($result->verified);
    }

    public function test_failed_result_carries_code_summary_and_retry_class(): void
    {
        $result = ProviderResult::failed('rate_limited', 'Provider is rate limiting.', 'retryable');

        $this->assertFalse($result->success);
        $this->assertFalse($result->verified);
        $this->assertSame('rate_limited', $result->errorCode);
        $this->assertTrue($result->isRetryable());

        $permanent = ProviderResult::failed('invalid_domain', 'Not a valid hostname.');
        $this->assertFalse($permanent->isRetryable(), 'default classification must be permanent');
    }

    public function test_null_hosting_provision_never_claims_verification(): void
    {
        $result = (new NullHostingConnector())->provisionHosting(['name' => 'Test'], 'idem-key-1');

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified);
        $this->assertNotNull($result->providerResourceId);
        $this->assertSame('provisioning', $result->normalizedState);
    }

    public function test_null_connector_provision_is_idempotent_on_key(): void
    {
        $connector = new NullHostingConnector();

        $a = $connector->provisionHosting(['name' => 'Test'], 'same-key');
        $b = $connector->provisionHosting(['name' => 'Different name'], 'same-key');

        // Same idempotency key must yield the same resource identity.
        $this->assertSame($a->providerResourceId, $b->providerResourceId);
    }

    public function test_null_connector_verify_refuses_to_confirm(): void
    {
        // A null connector has nothing real to confirm and must say so.
        $this->assertFalse((new NullHostingConnector())->verify('abc')->verified);
        $this->assertFalse((new NullCustomHostnameConnector())->verify('abc')->verified);
    }

    public function test_validation_instructions_return_empty_records_when_no_provider(): void
    {
        $result = (new NullCustomHostnameConnector())->getValidationInstructions('abc');

        // Must NOT invent DNS instructions. The legacy CustomDomainService shipped
        // instructions that were assumed rather than provider-derived.
        $this->assertSame([], $result->data['records']);
    }

    public function test_result_to_array_contains_no_unexpected_keys(): void
    {
        $keys = array_keys(ProviderResult::verified('active', 'x')->toArray());

        $this->assertEqualsCanonicalizing([
            'success', 'verified', 'normalized_state', 'provider_state',
            'provider_resource_id', 'correlation_id', 'error_code',
            'error_summary', 'retry_classification', 'data',
        ], $keys);
    }
}
