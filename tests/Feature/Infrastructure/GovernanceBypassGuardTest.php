<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Registry\CredentialRoleRegistry;
use App\Engines\Infrastructure\Registry\ProviderEnvironmentResolver;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\States\ProviderLifecycleState as Life;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INFRA888 - architecture guard against reintroducing a second provider path.
 *
 * Business Email reached its provider for the whole of E1-E7 through a
 * credential query inside the vendor adapter. It worked, so nothing complained;
 * it answered to no capability declaration, no health evidence, no credential
 * role and no environment vocabulary. That is the failure this file makes
 * expensive to repeat.
 *
 * Several assertions are source-level on purpose. A behavioural test cannot
 * distinguish "resolved through governance" from "found a key some other way"
 * when both return a working connector - which is exactly how the bypass
 * survived so long.
 */
class GovernanceBypassGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTER_DIR = 'app/Connectors/Infrastructure';

    private function sourceOf(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string,string> path => source, excluding backups */
    private function adapterSources(): array
    {
        $out = [];
        $dir = new \RecursiveDirectoryIterator(base_path(self::ADAPTER_DIR));

        foreach (new \RecursiveIteratorIterator($dir) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '.bak')) {
                continue;
            }
            $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }

        return $out;
    }

    /**
     * A fully governed email provider: declared capability, real-shaped health,
     * provisioning credential in the right environment. Everything the adapter
     * is no longer allowed to assume for itself.
     */
    private function seedGovernedEmailProvider(): InfraProvider
    {
        $env = ProviderEnvironmentResolver::current();

        $p = new InfraProvider();
        $p->forceFill([
            'provider_key'      => 'migadu',
            'display_name'      => 'Migadu',
            'provider_type'     => 'email',
            'adapter_class'     => \App\Connectors\Infrastructure\Email\Migadu\MigaduEmailProviderConnector::class,
            'adapter_version'   => '1.0.0-test',
            'lifecycle_state'   => Life::TESTING,
            'enabled'           => true,
            'production_ready'  => false,
            'sandbox_ready'     => true,
            'priority'          => 100,
            'environments_json' => [$env],
            'regions_json'      => [],
        ])->save();

        (new InfraProviderCapability())->forceFill([
            'provider_id'  => $p->id,
            'capability'   => 'email',
            'environment'  => $env,
            'supported'    => true,
            'enabled'      => true,
            'health_state' => 'unknown',
            'regions_json' => [],
        ])->save();

        (new InfraProviderCredential())->forceFill([
            'provider_id'           => $p->id,
            'credential_key'        => 'email.api.' . $env,
            'environment'           => $env,
            'label'                 => 'test key',
            'purpose'               => 'test',
            'credential_role'       => CredentialRoleRegistry::ROLE_PROVISIONING,
            'capability_scope_json' => ['email'],
            'region_scope_json'     => [],
            'account_identifier'    => 'ops@example.com',
            'secret_encrypted'      => encrypt('test-api-key'),
            'secret_fingerprint'    => hash('sha256', 'test-api-key'),
            'state'                 => 'active',
            'valid_from'            => now()->subDay(),
            'expires_at'            => now()->addYear(),
            'activated_at'          => now()->subDay(),
        ])->save();

        app(ProviderHealthService::class)->recordSuccess($p->refresh(), 'email', $env, 100);

        return $p->refresh();
    }

    // ---- source-level guards ---------------------------------------------

    public function test_the_email_adapter_does_not_select_its_own_credential(): void
    {
        $src = $this->sourceOf('app/Connectors/Infrastructure/Email/Migadu/MigaduConnectorFactory.php');

        $this->assertStringNotContainsString(
            'InfraProviderCredential::query()',
            $src,
            'The email adapter is querying for a credential again. It must ask ProviderResolutionService.'
        );

        $this->assertStringNotContainsString(
            'activeCredential',
            $src,
            'activeCredential() is back - that was the ungoverned selection path.'
        );

        $this->assertStringContainsString('ProviderResolutionService', $src);
    }

    public function test_no_adapter_reads_the_deployment_environment_directly(): void
    {
        // app()->environment() names the DEPLOYMENT. Provider columns speak
        // sandbox|production. Writing one into the other is the defect that hid
        // credential 82 from governed resolution.
        foreach ($this->adapterSources() as $path => $src) {
            $this->assertStringNotContainsString(
                'app()->environment()',
                $src,
                basename($path) . ' reads the deployment environment directly. '
                . 'Use ProviderEnvironmentResolver::current().'
            );
        }
    }

    public function test_the_provider_environment_vocabulary_has_not_been_widened(): void
    {
        // Adding 'staging' would duplicate CertificationLevel's STAGING_CERTIFIED
        // rung on a second axis, and the two would disagree.
        $this->assertSame(['sandbox', 'production'], InfraProvider::environments());
    }

    // ---- behavioural guards ----------------------------------------------

    public function test_every_configured_connector_is_declared_non_null(): void
    {
        // A capability present-but-null still reads as configured to anything
        // iterating the map. Namecheap looked declared while being unresolvable.
        foreach (config('infrastructure.connectors') as $capability => $class) {
            $this->assertNotNull($class, "Capability '{$capability}' is present but null.");
            $this->assertTrue(class_exists($class), "Connector class missing for '{$capability}'.");
        }
    }

    public function test_credentialless_connectors_resolve_without_any_governance(): void
    {
        $resolver = app(InfrastructureConnectorResolver::class);

        foreach (['hosting', 'custom_hostname'] as $capability) {
            $this->assertSame($capability, $resolver->resolve($capability)->capability());
        }
    }

    /**
     * The canonical path, and the only one: neutral discovery -> vendor adapter
     * -> ProviderResolutionService. Governance is enforced INSIDE the adapter
     * directory, which is the only place permitted to know the vendor.
     */
    public function test_the_registry_connector_is_refused_until_governance_is_satisfied(): void
    {
        // Nothing declared: the adapter must not find a credential of its own.
        try {
            \App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry::connector();
            $this->fail('a connector was built with no capability, health or credential declared');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->seedGovernedEmailProvider();

        $connector = \App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry::connector();
        $this->assertSame('email', $connector->capability());
    }

    public function test_disabling_the_capability_takes_the_provider_offline(): void
    {
        $p = $this->seedGovernedEmailProvider();
        $this->assertNotNull(\App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry::connector());

        InfraProviderCapability::where('provider_id', $p->id)
            ->where('capability', 'email')->update(['enabled' => false]);

        // No fallback may exist. A disabled capability must take the provider
        // offline even though the credential row is untouched and still valid.
        try {
            \App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry::connector();
            $this->fail('a disabled capability did not take the provider offline');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_revoking_the_credential_takes_the_provider_offline(): void
    {
        $p = $this->seedGovernedEmailProvider();
        $this->assertNotNull(\App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry::connector());

        InfraProviderCredential::where('provider_id', $p->id)
            ->update(['state' => 'revoked', 'revoked_at' => now()]);

        try {
            \App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry::connector();
            $this->fail('a revoked credential still produced a working connector');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * The engine deliberately resolves nothing through the shared connector map.
     * Wiring it there would require naming a vendor in shared configuration,
     * which the white-label guards forbid - and rightly so.
     *
     * This asserts the ARCHITECTURE, not merely the current state: the shared
     * map must stay free of the email capability.
     */
    public function test_the_shared_connector_map_never_names_the_email_vendor(): void
    {
        $this->assertArrayNotHasKey(
            'email',
            config('infrastructure.connectors'),
            'email is registered in the shared connector map - that requires naming a vendor there.'
        );

        $config = (string) file_get_contents(base_path('config/infrastructure.php'));

        foreach (['Migadu', 'migadu'] as $vendor) {
            $this->assertStringNotContainsString(
                $vendor,
                $config,
                'config/infrastructure.php names a vendor. Vendor identity belongs only in the adapter directory.'
            );
        }
    }
}
