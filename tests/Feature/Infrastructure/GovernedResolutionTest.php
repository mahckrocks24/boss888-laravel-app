<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Registry\CredentialRoleRegistry;
use App\Engines\Infrastructure\Registry\ProviderEnvironmentResolver as Env;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\States\ProviderLifecycleState as Life;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * INFRA888 - fail-closed behaviour of the ONE governed provider path.
 *
 * Business Email spent E1-E7 reaching its provider through a connector-local
 * query that answered to none of these gates. Each test below is one gate that
 * must refuse, and refuse by returning nothing rather than by falling back.
 */
class GovernedResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const CAP = 'email';

    private function env(): string
    {
        return Env::current();   // 'sandbox' under APP_ENV=testing
    }

    private function provider(array $overrides = []): InfraProvider
    {
        $p = new InfraProvider();
        $p->forceFill(array_merge([
            'provider_key'      => 'testmail',
            'display_name'      => 'Test Mail',
            'provider_type'     => 'email',
            'adapter_class'     => 'App\\Connectors\\Infrastructure\\Email\\Migadu\\MigaduEmailProviderConnector',
            'adapter_version'   => '1.0.0-test',
            'lifecycle_state'   => Life::TESTING,
            'enabled'           => true,
            'production_ready'  => false,
            'sandbox_ready'     => true,
            'priority'          => 100,
            'environments_json' => [$this->env()],
            'regions_json'      => [],
        ], $overrides))->save();

        return $p->refresh();
    }

    private function capability(InfraProvider $p, array $overrides = []): InfraProviderCapability
    {
        $c = new InfraProviderCapability();
        $c->forceFill(array_merge([
            'provider_id'  => $p->id,
            'capability'   => self::CAP,
            'environment'  => $this->env(),
            'supported'    => true,
            'enabled'      => true,
            'health_state' => 'unknown',
            'regions_json' => [],
        ], $overrides))->save();

        return $c->refresh();
    }

    private function credential(InfraProvider $p, array $overrides = []): InfraProviderCredential
    {
        $c = new InfraProviderCredential();
        $c->forceFill(array_merge([
            'provider_id'           => $p->id,
            'credential_key'        => 'test.api.' . $this->env(),
            'environment'           => $this->env(),
            'label'                 => 'test key',
            'purpose'               => 'test',
            'credential_role'       => CredentialRoleRegistry::ROLE_PROVISIONING,
            'capability_scope_json' => [self::CAP],
            'region_scope_json'     => [],
            'account_identifier'    => 'test@example.com',
            'secret_encrypted'      => encrypt('secret'),
            'secret_fingerprint'    => hash('sha256', 'secret'),
            'state'                 => 'active',
            'valid_from'            => now()->subDay(),
            'expires_at'            => now()->addYear(),
            'activated_at'          => now()->subDay(),
        ], $overrides))->save();

        return $c->refresh();
    }

    private function healthy(InfraProvider $p): void
    {
        app(ProviderHealthService::class)->recordSuccess($p, self::CAP, $this->env(), 100);
    }

    private function resolve(?string $env = null): array
    {
        return app(ProviderResolutionService::class)->resolve(self::CAP, $env ?? $this->env());
    }

    private function assertRefused(callable $fn, string $because): void
    {
        try {
            $fn();
            $this->fail("resolution succeeded but should have been refused: {$because}");
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // ---- the happy path, so the negatives mean something -----------------

    public function test_a_fully_governed_provider_resolves(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $cred = $this->credential($p);
        $this->healthy($p);

        $r = $this->resolve();

        $this->assertSame($p->id, $r['provider']->id);
        $this->assertSame(self::CAP, $r['capability']->capability);
        $this->assertSame($cred->id, $r['credential']->id);
    }

    // ---- one gate removed at a time --------------------------------------

    public function test_missing_capability_declaration_refuses(): void
    {
        $p = $this->provider();
        $this->credential($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'no capability row');
    }

    public function test_disabled_capability_refuses(): void
    {
        $p = $this->provider();
        $this->capability($p, ['enabled' => false]);
        $this->credential($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'capability disabled');
    }

    public function test_unsupported_capability_refuses(): void
    {
        $p = $this->provider();
        $this->capability($p, ['supported' => false]);
        $this->credential($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'capability unsupported');
    }

    public function test_missing_health_refuses(): void
    {
        // Never having checked is not evidence of health.
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p);

        $this->assertRefused(fn () => $this->resolve(), 'no health record');
    }

    public function test_disabled_provider_refuses(): void
    {
        $p = $this->provider(['enabled' => false]);
        $this->capability($p);
        $this->credential($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'provider disabled');
    }

    public function test_draft_provider_refuses(): void
    {
        $p = $this->provider(['lifecycle_state' => Life::DRAFT]);
        $this->capability($p);
        $this->credential($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'draft does not accept new work');
    }

    public function test_provider_not_declared_for_the_environment_refuses(): void
    {
        $p = $this->provider(['environments_json' => ['production']]);
        $this->capability($p);
        $this->credential($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'environment not declared');
    }

    public function test_uncertified_provider_is_refused_for_production(): void
    {
        // The gate that matters most: sandbox-ready is not production-approved.
        $p = $this->provider(['environments_json' => [$this->env(), 'production']]);
        $this->capability($p, ['environment' => 'production']);
        $this->credential($p, ['environment' => 'production']);
        app(ProviderHealthService::class)->recordSuccess($p, self::CAP, 'production', 100);

        $this->assertRefused(fn () => $this->resolve('production'), 'production_ready is false');
    }

    // ---- credential gates -------------------------------------------------

    public function test_non_provisioning_credential_is_never_selected(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p, ['credential_role' => CredentialRoleRegistry::ROLE_DIAGNOSTICS]);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'diagnostics credentials never resolve');
    }

    public function test_credential_in_another_environment_is_not_selected(): void
    {
        // This is the exact defect: a credential stamped with a deployment name
        // instead of a provider environment became invisible here.
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p, ['environment' => 'production']);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'credential is in another environment');
    }

    public function test_credential_without_the_capability_in_scope_is_not_selected(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p, ['capability_scope_json' => ['dns']]);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'capability out of credential scope');
    }

    public function test_expired_credential_is_not_selected(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p, ['expires_at' => now()->subDay()]);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'credential expired');
    }

    public function test_not_yet_valid_credential_is_not_selected(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p, ['valid_from' => now()->addWeek()]);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'credential not yet valid');
    }

    public function test_revoked_credential_is_not_selected(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $this->credential($p, ['state' => 'revoked', 'revoked_at' => now()]);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'credential revoked');
    }

    public function test_no_credential_at_all_refuses(): void
    {
        $p = $this->provider();
        $this->capability($p);
        $this->healthy($p);

        $this->assertRefused(fn () => $this->resolve(), 'no credential');
    }

    // ---- vocabulary -------------------------------------------------------

    public function test_an_unknown_capability_fails_loudly(): void
    {
        $this->assertRefused(
            fn () => app(ProviderResolutionService::class)->resolve('not_a_capability', $this->env()),
            'unknown capability is a programming error'
        );
    }
}
