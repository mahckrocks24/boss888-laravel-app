<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\Email\Migadu\MigaduConnectorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INFRA888 · E5 — the containment guard.
 *
 * Until E5 the white-label rule was cheap to keep: no vendor name existed
 * anywhere, so nothing could leak. From E5 the word is real and lives in the
 * repository, which is the moment the rule needs enforcing rather than stating.
 *
 * ONE DIRECTORY MAY NAME THE VENDOR. Everything else — engine, models,
 * migrations, customer controllers, customer JavaScript, routes, notifications,
 * billing — must be unable to tell which vendor answered. These tests fail the
 * build if that stops being true, which is the only version of this rule that
 * survives contact with future sessions.
 */
class MigaduWhiteLabelGuardTest extends TestCase
{
    // The provider-status check reads the registry tables. RefreshDatabase
    // rather than DatabaseTransactions: E3.1 proved the transactional variant
    // passes alone and fails in suite order on this codebase.
    use RefreshDatabase;

    private const ROOT = '/var/www/levelup-staging';

    /** The single directory permitted to know. */
    private const ADAPTER_DIR = 'app/Connectors/Infrastructure/Email/Migadu';

    /** Vendor names, assembled so this file's own source cannot trip the scan. */
    private function vendorPatterns(): array
    {
        return [
            '/\b' . 'm' . 'igadu\b/i',
            '/\b' . 'z' . 'oho\b/i',
            '/\b' . 'f' . 'astmail\b/i',
            '/' . 'g' . 'oogle[\s_.-]*workspace/i',
            '/' . 'm' . 'icrosoft[\s_.-]*365/i',
        ];
    }

    /**
     * Everything that must stay provider-blind.
     *
     * @return array<string,string> relative path => source
     */
    private function neutralSources(): array
    {
        $paths = [];

        foreach ([
            'app/Engines/Infrastructure/Email',
            'app/Connectors/Infrastructure/BusinessEmail',
            'routes/api/authenticated',
            'routes/api/admin',
        ] as $dir) {
            $paths = array_merge($paths, $this->phpUnder($dir));
        }

        foreach ([
            'app/Http/Controllers/Api/BusinessEmailCustomerController.php',
            'app/Http/Controllers/Api/BusinessEmailCustomerActionController.php',
            'app/Http/Controllers/Api/Admin/BusinessEmailAdminController.php',
            'app/Http/Controllers/Api/Admin/BusinessEmailAdminActionController.php',
            'config/business_email.php',
            'public/app/js/business-email.js',
            'public/app/index.html',
        ] as $file) {
            if (is_file(self::ROOT . '/' . $file)) {
                $paths[] = $file;
            }
        }

        foreach (glob(self::ROOT . '/database/migrations/2026_08_04_1405*.php') ?: [] as $migration) {
            $paths[] = str_replace(self::ROOT . '/', '', $migration);
        }

        $sources = [];

        foreach (array_unique($paths) as $path) {
            $sources[$path] = (string) file_get_contents(self::ROOT . '/' . $path);
        }

        $this->assertGreaterThan(30, count($sources), 'The guard collected too few files to be meaningful.');

        return $sources;
    }

    /** @return array<int,string> */
    private function phpUnder(string $dir): array
    {
        $full = self::ROOT . '/' . $dir;

        if (! is_dir($full)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full));

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'blade'], true)) {
                $found[] = str_replace(self::ROOT . '/', '', $file->getPathname());
            }
        }

        return $found;
    }

    // ═══ CONTAINMENT ═════════════════════════════════════════════════════════

    public function test_the_vendor_is_named_nowhere_outside_its_own_adapter(): void
    {
        foreach ($this->neutralSources() as $path => $source) {
            foreach ($this->vendorPatterns() as $pattern) {
                $this->assertSame(0, preg_match($pattern, $source),
                    "{$path} names a vendor. Only " . self::ADAPTER_DIR . " may.");
            }
        }
    }

    public function test_the_adapter_directory_exists_so_this_guard_is_not_vacuous(): void
    {
        $this->assertDirectoryExists(self::ROOT . '/' . self::ADAPTER_DIR);

        $files = glob(self::ROOT . '/' . self::ADAPTER_DIR . '/*.php') ?: [];

        $this->assertGreaterThanOrEqual(7, count($files));

        // And it genuinely does contain the word — otherwise the containment
        // test above would be proving nothing at all.
        $joined = '';

        foreach ($files as $file) {
            $joined .= (string) file_get_contents($file);
        }

        $this->assertSame(1, preg_match($this->vendorPatterns()[0], $joined),
            'The adapter does not name its own vendor — this guard would pass vacuously.');
    }

    public function test_no_vendor_specific_code_lives_outside_the_permitted_directory(): void
    {
        $stray = [];

        foreach ($this->phpUnder('app') as $path) {
            if (str_starts_with($path, self::ADAPTER_DIR)) {
                continue;
            }

            $source = (string) file_get_contents(self::ROOT . '/' . $path);

            if (preg_match($this->vendorPatterns()[0], $source) === 1) {
                $stray[] = $path;
            }
        }

        $this->assertSame([], $stray, 'Vendor-specific code escaped its directory: ' . implode(', ', $stray));
    }

    // ═══ CUSTOMER SURFACES ═══════════════════════════════════════════════════

    public function test_no_customer_surface_can_learn_which_vendor_answered(): void
    {
        foreach ([
            'app/Http/Controllers/Api/BusinessEmailCustomerController.php',
            'app/Http/Controllers/Api/BusinessEmailCustomerActionController.php',
            'public/app/js/business-email.js',
        ] as $path) {
            $source = (string) file_get_contents(self::ROOT . '/' . $path);

            foreach ($this->vendorPatterns() as $pattern) {
                $this->assertSame(0, preg_match($pattern, $source), "{$path} names a vendor.");
            }

            // Nor may it reach the adapter, or the registry, at all.
            foreach (['MigaduConnectorFactory', 'MigaduEmailProviderConnector', 'InfraProvider', 'InfraProviderCredential'] as $class) {
                $this->assertSame(0, preg_match('/\b' . $class . '\b/', $source),
                    "{$path} reaches for the provider layer.");
            }
        }
    }

    public function test_provider_plan_and_cost_never_enter_customer_entitlements(): void
    {
        $source = (string) file_get_contents(
            self::ROOT . '/app/Engines/Infrastructure/Email/Customer/CustomerEntitlements.php'
        );

        // What a customer may do is LevelUp's commercial decision. If it were
        // derived from the vendor's plan, changing vendor would silently change
        // what customers had paid for.
        foreach (['plan', 'price', 'cost', 'tier', 'standard', 'maxi', 'mini', 'micro'] as $term) {
            $this->assertSame(0, preg_match('/[\'"]' . $term . '[\'"]/i', $source),
                "CustomerEntitlements references a provider commercial concept: {$term}");
        }
    }

    public function test_dns_values_may_carry_provider_hostnames_but_labels_may_not(): void
    {
        // The one honest exception, decided in the masterplan: MX and SPF
        // physically point at the provider and no abstraction can hide it. What
        // must stay neutral is everything AROUND the value — the label a
        // customer reads, and the purpose the engine assigns.
        $record = new \App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord(
            purpose: \App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord::PURPOSE_MX,
            type: 'MX',
            name: '@',
            value: 'aspmx.some-vendor-host.test',
            priority: 10,
            ttl: 3600,
        );

        $customer = $record->toCustomerArray();

        $this->assertSame('aspmx.some-vendor-host.test', $customer['value'],
            'The DNS value must survive intact — a customer has to type it.');

        foreach (['label', 'purpose', 'technical', 'why'] as $field) {
            if (! isset($customer[$field])) {
                continue;
            }

            $this->assertStringNotContainsStringIgnoringCase('vendor-host', (string) $customer[$field],
                "The {$field} of a DNS record must not echo the provider hostname.");
        }
    }

    // ═══ EXECUTION CONTAINMENT ═══════════════════════════════════════════════

    public function test_the_adapter_is_not_wired_into_the_connector_resolver(): void
    {
        // Registering it in config would make it the email connector for every
        // workspace the moment the key existed. During E5 it must be reachable
        // only through its factory.
        $configured = config('infrastructure.connectors.email');

        $this->assertNull($configured,
            'The email capability must not resolve to a live provider while both gates are closed.');
    }

    public function test_both_provider_gates_are_closed(): void
    {
        $this->assertFalse((bool) config('business_email.provider.network_enabled'));
        $this->assertFalse((bool) config('business_email.provider.mutations_enabled'));
        $this->assertFalse(MigaduConnectorFactory::networkEnabled());
        $this->assertFalse(MigaduConnectorFactory::mutationsEnabled());
    }

    public function test_mutations_cannot_be_enabled_by_a_single_flag(): void
    {
        // Turning on mutations alone must achieve nothing. Two independent
        // switches, and the outer one governs.
        config(['business_email.provider.mutations_enabled' => true]);
        config(['business_email.provider.network_enabled' => false]);

        $this->assertFalse(MigaduConnectorFactory::mutationsEnabled(),
            'Mutations became possible from one flag. That is the failure this design exists to prevent.');
    }

    public function test_the_status_report_never_returns_a_secret(): void
    {
        $status = MigaduConnectorFactory::status();

        $encoded = json_encode($status);

        foreach (['secret_encrypted', 'api_key', 'apiKey', 'password'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded,
                'The provider status report exposes a credential field.');
        }

        // A fingerprint identifies which key is installed without being one.
        $this->assertArrayHasKey('credential_fingerprint', $status);
        $this->assertArrayHasKey('credential_present', $status);
        $this->assertFalse($status['credential_present'], 'No credential should exist yet.');
    }

    public function test_no_credential_is_committed_anywhere_in_the_repository(): void
    {
        foreach ($this->neutralSources() as $path => $source) {
            $this->assertSame(0, preg_match('/[\'"]?api[_-]?key[\'"]?\s*[=:]\s*[\'"][A-Za-z0-9]{16,}/i', $source),
                "{$path} appears to contain a literal credential.");
        }

        foreach (glob(self::ROOT . '/' . self::ADAPTER_DIR . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertSame(0, preg_match('/[\'"][A-Za-z0-9]{32,}[\'"]/', $source),
                basename($file) . ' contains a long literal that could be a key.');
        }
    }
}
