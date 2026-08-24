<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\Contracts\CustomHostnameConnector;
use App\Connectors\Infrastructure\Contracts\HostingProviderConnector;
use App\Connectors\Infrastructure\Contracts\InfrastructureConnector;
use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\Policies\InfrastructurePolicyRegistrar;
use ReflectionClass;
use Tests\TestCase;

/**
 * Registration-drift test.
 *
 * The Phase 0 audit found a new action must be registered in NINE separate
 * hardcoded arrays, that the two Orchestrator maps (72 vs 86 entries) are
 * hand-synced with no test enforcing it, and that drift is already live —
 * `keyword_research` is registered in the capability map but returns
 * `not_implemented`.
 *
 * This test exists so INFRA888 cannot accumulate the same class of silent drift.
 * It boots the application but touches NO database, so it runs on this install
 * despite dev dependencies (Mockery) being absent.
 */
class RegistrationDriftTest extends TestCase
{
    public function test_infrastructure_config_is_loaded(): void
    {
        // config/services.php is MISSING on this install and CustomDomainService
        // reads from it, surviving only on env() fallback. Assert our config is
        // genuinely present rather than silently absent.
        $this->assertIsArray(config('infrastructure'));
        $this->assertIsArray(config('infrastructure.connectors'));
        $this->assertNotNull(config('infrastructure.feature_key'));
    }

    public function test_every_configured_connector_class_exists_and_implements_the_contract(): void
    {
        foreach (config('infrastructure.connectors') as $capability => $class) {
            $this->assertTrue(
                class_exists($class),
                "Connector class for '{$capability}' does not exist: {$class}"
            );

            $this->assertTrue(
                is_subclass_of($class, InfrastructureConnector::class),
                "{$class} must implement InfrastructureConnector"
            );
        }
    }

    public function test_resolver_returns_a_connector_whose_capability_matches_its_key(): void
    {
        $resolver = app(InfrastructureConnectorResolver::class);

        foreach (array_keys(config('infrastructure.connectors')) as $capability) {
            // 2026-08-11. Not every connector can be built out of thin air any
            // more. A GOVERNED adapter (email) obtains its credential from
            // ProviderResolutionService, so with no provider declared, healthy
            // and credentialled it MUST refuse - and this class touches no
            // database by design, so it cannot declare one.
            //
            // Refusing is therefore a pass, but only in the right shape. A
            // RuntimeException means the wiring is sound and governance said no.
            // A BindingResolutionException means the class is registered but
            // unconstructible - exactly the drift this file exists to catch,
            // and exactly what the Namecheap registrar default was doing.
            try {
                $connector = $resolver->resolve($capability);
            } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
                $this->fail(
                    "Connector for '{$capability}' is registered but cannot be constructed: "
                    . $e->getMessage()
                );
            } catch (\RuntimeException $e) {
                continue;
            }

            $this->assertSame(
                $capability,
                $connector->capability(),
                "Connector registered under '{$capability}' reports '{$connector->capability()}'"
            );
        }
    }

    public function test_unconfigured_capability_throws_rather_than_silently_no_ops(): void
    {
        // registrar/email are deliberately absent until Phase 1C/1D. Resolving one
        // must fail loudly — a silent no-op would look like a working feature.
        $this->expectException(\RuntimeException::class);
        app(InfrastructureConnectorResolver::class)->resolve('registrar');
    }

    public function test_every_policy_registered_maps_to_an_existing_model_and_policy(): void
    {
        foreach (InfrastructurePolicyRegistrar::policies() as $model => $policy) {
            $this->assertTrue(class_exists($model), "Model missing: {$model}");
            $this->assertTrue(class_exists($policy), "Policy missing: {$policy}");
        }
    }

    public function test_policies_are_actually_registered_with_the_gate(): void
    {
        // Laravel's convention-based discovery looks in App\Policies and will NOT
        // find engine-namespaced policies. If registration is ever dropped, every
        // policy silently fails open — this catches that.
        foreach ([InfraHostingAccount::class, InfraSubscription::class] as $model) {
            $this->assertNotNull(
                app(\Illuminate\Contracts\Auth\Access\Gate::class)->getPolicyFor($model),
                "No policy resolved for {$model} — authorization would fail open."
            );
        }
    }

    public function test_hosting_contract_declares_the_expected_capabilities(): void
    {
        $methods = array_map(
            fn ($m) => $m->getName(),
            (new ReflectionClass(HostingProviderConnector::class))->getMethods()
        );

        foreach ([
            'provisionHosting', 'suspendHosting', 'restoreHosting',
            'terminateHosting', 'getHostingStatus', 'getUsage',
        ] as $expected) {
            $this->assertContains($expected, $methods);
        }
    }

    public function test_custom_hostname_contract_declares_the_expected_capabilities(): void
    {
        $methods = array_map(
            fn ($m) => $m->getName(),
            (new ReflectionClass(CustomHostnameConnector::class))->getMethods()
        );

        foreach ([
            'createHostname', 'getHostname', 'getValidationInstructions',
            'synchronizeHostname', 'disconnectHostname',
        ] as $expected) {
            $this->assertContains($expected, $methods);
        }
    }

    public function test_all_infra_models_declaring_workspace_id_use_the_workspace_trait(): void
    {
        // Guards the C2 control: a new workspace-owned model that forgets the trait
        // would be globally unscoped. InfraProviderConnection and InfraCostEntry
        // are deliberately excluded (nullable workspace_id, platform-level rows).
        $mustBeScoped = [
            \App\Engines\Infrastructure\Models\InfraSubscription::class,
            \App\Engines\Infrastructure\Models\InfraHostingAccount::class,
            \App\Engines\Infrastructure\Models\InfraHostedSite::class,
            \App\Engines\Infrastructure\Models\InfraOperation::class,
            \App\Engines\Infrastructure\Models\InfraEvent::class,
            \App\Engines\Infrastructure\Models\InfraProviderResource::class,
            \App\Engines\Infrastructure\Models\InfraUsageRecord::class,
        ];

        foreach ($mustBeScoped as $model) {
            $this->assertContains(
                \App\Core\Tenancy\BelongsToWorkspace::class,
                class_uses_recursive($model),
                "{$model} must use BelongsToWorkspace"
            );
        }
    }
}
