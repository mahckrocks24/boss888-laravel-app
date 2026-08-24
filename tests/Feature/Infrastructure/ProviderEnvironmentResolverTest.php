<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Registry\ProviderEnvironmentResolver as Env;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * INFRA888 - deployment environment to provider execution environment.
 *
 * Pins the decision that 'staging' is NOT a provider environment, so a future
 * change has to argue with a test rather than quietly add a third value.
 */
class ProviderEnvironmentResolverTest extends TestCase
{
    public function test_every_deployment_levelup_uses_is_mapped_explicitly(): void
    {
        foreach (['production', 'staging', 'testing', 'local'] as $deployment) {
            $this->assertTrue(Env::knowsDeployment($deployment), "{$deployment} is unmapped");
            $this->assertContains(
                Env::for($deployment),
                InfraProvider::environments(),
                "{$deployment} maps outside the provider vocabulary"
            );
        }
    }

    public function test_only_production_reaches_the_production_tier(): void
    {
        $this->assertSame(InfraProvider::ENV_PRODUCTION, Env::for('production'));

        foreach (['staging', 'testing', 'local'] as $deployment) {
            $this->assertSame(
                InfraProvider::ENV_SANDBOX,
                Env::for($deployment),
                "{$deployment} must not reach the production provider tier"
            );
        }
    }

    public function test_staging_is_not_a_provider_environment(): void
    {
        // The defect this whole class exists to prevent: 'staging' written into
        // a column whose vocabulary is sandbox|production.
        $this->assertNotContains('staging', InfraProvider::environments());
        $this->assertSame(InfraProvider::ENV_SANDBOX, Env::for('staging'));
    }

    public function test_an_unknown_deployment_throws_rather_than_guessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/rather than defaulting/');
        Env::for('qa-blue');
    }

    public function test_mapping_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(InfraProvider::ENV_SANDBOX, Env::for('  STAGING '));
    }

    public function test_current_resolves_for_the_running_deployment(): void
    {
        // The suite runs as APP_ENV=testing.
        $this->assertSame(InfraProvider::ENV_SANDBOX, Env::current());
        $this->assertFalse(Env::isProductionTier());
    }

    public function test_no_mapping_target_is_outside_the_vocabulary(): void
    {
        foreach (Env::map() as $deployment => $providerEnv) {
            $this->assertContains(
                $providerEnv,
                InfraProvider::environments(),
                "'{$deployment}' maps to '{$providerEnv}', which is not a provider environment"
            );
        }
    }
}
