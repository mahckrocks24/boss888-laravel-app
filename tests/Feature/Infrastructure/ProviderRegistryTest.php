<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\States\CredentialState;
use App\Engines\Infrastructure\States\ProviderHealthState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 2B-2: provider registry and capability resolution.
 *
 * The theme running through these tests is FAIL CLOSED. Almost every assertion
 * checks that something is NOT selected — because the dangerous failure mode for
 * a provider registry is not "refused to pick a provider", it is "picked one it
 * should not have".
 */
class ProviderRegistryTest extends TestCase
{
    use DatabaseTransactions;

    private ProviderRegistryService $registry;
    private ProviderResolutionService $resolution;
    private ProviderHealthService $health;
    private ProviderCredentialService $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry    = app(ProviderRegistryService::class);
        $this->resolution  = app(ProviderResolutionService::class);
        $this->health      = app(ProviderHealthService::class);
        $this->credentials = app(ProviderCredentialService::class);
    }

    private function makeProvider(string $key, array $attrs = []): InfraProvider
    {
        return $this->registry->register(array_merge([
            'provider_key'      => $key,
            'display_name'      => strtoupper($key),
            'provider_type'     => 'dns',
            'environments_json' => [InfraProvider::ENV_SANDBOX, InfraProvider::ENV_PRODUCTION],
            'sandbox_ready'     => true,
        ], $attrs), 1);
    }

    /**
     * Fully wire a provider so it IS resolvable: capability enabled, health
     * healthy, credential active. Used as the baseline that the negative tests
     * then break one condition at a time.
     */
    private function makeResolvable(string $key, string $capability = 'dns', array $attrs = []): InfraProvider
    {
        $p = $this->makeProvider($key, $attrs);
        $this->registry->declareCapability($p, $capability, InfraProvider::ENV_SANDBOX, [], 1);
        $this->registry->transition($p, ProviderLifecycleState::TESTING, 1);
        $this->registry->setEnabled($p, true, 1);
        $this->registry->enableCapability($p->fresh(), $capability, InfraProvider::ENV_SANDBOX, 1);
        $this->health->recordSuccess($p, $capability, InfraProvider::ENV_SANDBOX, 42);

        $cred = InfraProviderCredential::create([
            'provider_id'           => $p->id,
            'credential_key'        => "{$key}-cred",
            'environment'           => InfraProvider::ENV_SANDBOX,
            'capability_scope_json' => [$capability],
            'secret_encrypted'      => 'secret-for-' . $key,
            'secret_fingerprint'    => InfraProviderCredential::fingerprint('secret-for-' . $key),
            'state'                 => CredentialState::ACTIVE,
            'activated_at'          => now(),
        ]);
        $this->assertNotNull($cred->id);

        return $p->fresh();
    }

    public function test_registration_lands_in_draft_and_is_never_enabled(): void
    {
        $p = $this->makeProvider('reg-alpha');

        $this->assertSame(ProviderLifecycleState::DRAFT, $p->lifecycle_state);
        $this->assertFalse($p->enabled, 'A newly registered provider must never be enabled.');
        $this->assertFalse($p->production_ready, 'Production readiness must never be implicit.');
    }

    public function test_provider_key_is_immutable(): void
    {
        $p = $this->makeProvider('reg-immutable');

        $this->expectExceptionMessageMatches('/immutable/i');
        $p->update(['provider_key' => 'renamed']);
    }

    public function test_duplicate_provider_key_is_refused(): void
    {
        $this->makeProvider('reg-dupe');

        $this->expectExceptionMessageMatches('/already registered/i');
        $this->makeProvider('reg-dupe');
    }

    public function test_declaring_a_capability_does_not_enable_it(): void
    {
        $p   = $this->makeProvider('reg-declare');
        $cap = $this->registry->declareCapability($p, 'dns', InfraProvider::ENV_SANDBOX, [], 1);

        $this->assertTrue($cap->supported);
        $this->assertFalse($cap->enabled, 'Registry presence must not imply enablement.');
        $this->assertSame(ProviderHealthState::UNKNOWN, $cap->health_state);
    }

    public function test_unsupported_capability_cannot_be_enabled(): void
    {
        $p = $this->makeProvider('reg-unsupported');
        $this->registry->declareCapability($p, 'dns', InfraProvider::ENV_SANDBOX, ['supported' => false], 1);
        $this->registry->transition($p, ProviderLifecycleState::TESTING, 1);

        $this->expectExceptionMessageMatches('/does not support/i');
        $this->registry->enableCapability($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 1);
    }

    public function test_unknown_capability_is_refused(): void
    {
        $p = $this->makeProvider('reg-unknown');

        $this->expectExceptionMessageMatches('/Unknown capability/i');
        $this->registry->declareCapability($p, 'teleportation', InfraProvider::ENV_SANDBOX, [], 1);
    }

    public function test_illegal_lifecycle_transition_is_refused(): void
    {
        $p = $this->makeProvider('reg-illegal');

        // draft -> active is deliberately not permitted; testing comes first.
        $this->expectExceptionMessageMatches('/illegal transition/i');
        $this->registry->transition($p, ProviderLifecycleState::ACTIVE, 1);
    }

    public function test_leaving_a_serviceable_state_disables_capabilities(): void
    {
        $p = $this->makeResolvable('reg-disable');

        $this->assertTrue($this->registry->capabilityRow($p, 'dns', InfraProvider::ENV_SANDBOX)->enabled);

        $this->registry->transition($p, ProviderLifecycleState::DISABLED, 1, 'operator action');

        $this->assertFalse(
            $this->registry->capabilityRow($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX)->enabled,
            'Disabling a provider must not leave live capabilities behind.'
        );
    }

    public function test_a_fully_wired_provider_resolves(): void
    {
        $p = $this->makeResolvable('res-happy');

        $r = $this->resolution->resolve('dns', InfraProvider::ENV_SANDBOX);

        $this->assertSame($p->id, $r['provider']->id);
        $this->assertNotNull($r['credential']);
    }

    public function test_resolution_is_deterministic_by_priority_then_id(): void
    {
        $low  = $this->makeResolvable('res-low',  'dns', ['priority' => 10]);
        $high = $this->makeResolvable('res-high', 'dns', ['priority' => 90]);

        // Ten identical calls must select the same provider every time.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(
                $low->id,
                $this->resolution->resolve('dns', InfraProvider::ENV_SANDBOX)['provider']->id,
                'Resolution must be deterministic, not dependent on row order.'
            );
        }

        $candidates = $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX);
        $this->assertGreaterThanOrEqual(2, count($candidates));
        $this->assertSame($low->id,  $candidates[0]['provider']->id);
        $this->assertSame($high->id, $candidates[1]['provider']->id);
    }

    public function test_disabled_provider_is_excluded(): void
    {
        $p = $this->makeResolvable('res-disabled');
        $this->registry->setEnabled($p, false, 1, 'test');

        $this->assertSame([], $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX));
    }

    public function test_capability_disabled_excludes_provider_even_when_healthy(): void
    {
        $p = $this->makeResolvable('res-capoff');
        $this->registry->disableCapability($p, 'dns', InfraProvider::ENV_SANDBOX, 'scope withdrawn', 1);

        $this->assertSame([], $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX));
    }

    /**
     * The `testing` lifecycle state exists precisely to prevent this. A provider
     * being exercised must never take production work.
     */
    public function test_testing_provider_is_never_selected_for_production(): void
    {
        $p = $this->makeResolvable('res-testing');
        $this->assertSame(ProviderLifecycleState::TESTING, $p->lifecycle_state);

        $this->registry->declareCapability($p, 'dns', InfraProvider::ENV_PRODUCTION, [], 1);
        $this->registry->enableCapability($p->fresh(), 'dns', InfraProvider::ENV_PRODUCTION, 1);
        $this->health->recordSuccess($p, 'dns', InfraProvider::ENV_PRODUCTION, 10);

        $this->assertSame(
            [],
            $this->resolution->candidates('dns', InfraProvider::ENV_PRODUCTION),
            'A provider in `testing` must never be selectable for production.'
        );
    }

    public function test_environment_filtering_is_enforced(): void
    {
        $this->makeResolvable('res-sandboxonly');

        // Declared and enabled only for sandbox.
        $this->assertNotEmpty($this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX));
        $this->assertSame([], $this->resolution->candidates('dns', InfraProvider::ENV_PRODUCTION));
    }

    public function test_unhealthy_provider_is_excluded(): void
    {
        $p = $this->makeResolvable('res-unhealthy');
        $this->health->recordFailure($p, 'dns', InfraProvider::ENV_SANDBOX, 'unauthorized', 'Token rejected.');

        $this->assertSame([], $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX));
    }

    /** FAIL CLOSED: never having checked is not evidence of health. */
    public function test_provider_with_no_health_record_is_excluded(): void
    {
        $p = $this->makeProvider('res-nohealth');
        $this->registry->declareCapability($p, 'dns', InfraProvider::ENV_SANDBOX, [], 1);
        $this->registry->transition($p, ProviderLifecycleState::TESTING, 1);
        $this->registry->setEnabled($p, true, 1);
        $this->registry->enableCapability($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 1);

        $this->assertSame([], $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX));
    }

    public function test_provider_with_no_active_credential_is_excluded(): void
    {
        $p = $this->makeProvider('res-nocred');
        $this->registry->declareCapability($p, 'dns', InfraProvider::ENV_SANDBOX, [], 1);
        $this->registry->transition($p, ProviderLifecycleState::TESTING, 1);
        $this->registry->setEnabled($p, true, 1);
        $this->registry->enableCapability($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 1);
        $this->health->recordSuccess($p, 'dns', InfraProvider::ENV_SANDBOX, 5);

        $this->assertSame(
            [],
            $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX),
            'Healthy and enabled is not enough without a usable credential.'
        );
    }

    public function test_unresolvable_capability_explains_why(): void
    {
        $p = $this->makeResolvable('res-explain');
        $this->registry->disableCapability($p, 'dns', InfraProvider::ENV_SANDBOX, 'test', 1);

        try {
            $this->resolution->resolve('dns', InfraProvider::ENV_SANDBOX);
            $this->fail('Expected resolution to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('res-explain', $e->getMessage());
            $this->assertStringContainsString('NOT enabled', $e->getMessage());
        }
    }

    public function test_unknown_capability_in_resolution_fails_closed(): void
    {
        $this->expectExceptionMessageMatches('/Unknown capability/i');
        $this->resolution->candidates('teleportation', InfraProvider::ENV_SANDBOX);
    }

    public function test_region_filtering_excludes_non_serving_provider(): void
    {
        $p = $this->makeResolvable('res-region', 'dns', ['regions_json' => ['eu-west']]);

        $this->assertNotEmpty($this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX, 'eu-west'));
        $this->assertSame([], $this->resolution->candidates('dns', InfraProvider::ENV_SANDBOX, 'ap-south'));
    }

    public function test_adapter_class_is_never_serialized(): void
    {
        $p = $this->makeProvider('res-hidden', ['adapter_class' => 'App\\Secret\\InternalAdapter']);

        $this->assertArrayNotHasKey('adapter_class', $p->toArray());
        $this->assertStringNotContainsString('InternalAdapter', json_encode($p));
    }
}
