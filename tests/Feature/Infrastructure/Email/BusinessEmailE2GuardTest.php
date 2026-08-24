<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Engines\Infrastructure\Email\Reconciliation\ReconciliationFinding;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Fakes\Infrastructure\Email\FakeEmailProvider;
use Tests\TestCase;

/**
 * INFRA888 · E2-K — GUARDS ADDED BY E2.
 *
 * E1's BusinessEmailWhiteLabelGuardTest still runs unchanged and still enforces
 * the vendor-name rule across every surface. These are the guards E2 needs in
 * addition, because E2 introduced two new ways to leak:
 *
 *   1. A FAKE PROVIDER. Test terminology reaching a customer surface would be
 *      as damaging as a vendor name — "fake-email" on an invoice is worse than
 *      a vendor's name on one.
 *   2. AN EXECUTION PATH. Provider references, raw provider errors and provider
 *      diagnostics now genuinely exist at runtime, so the separation between
 *      what an operator sees and what a customer sees has to be structural
 *      rather than notional.
 */
class BusinessEmailE2GuardTest extends TestCase
{
    private const ROOT = '/var/www/levelup-staging';
    private const FAKE = 'tests/Fakes/Infrastructure/Email/FakeEmailProvider.php';

    /** @return array<string,string> */
    private function collect(array $roots, array $extensions = ['php']): array
    {
        $files = [];

        foreach ($roots as $root) {
            $absolute = self::ROOT . '/' . $root;

            if (! is_dir($absolute)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            ) as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $relative = ltrim(str_replace(self::ROOT, '', $file->getPathname()), '/');

                if (preg_match('#(^|/)(vendor|node_modules|storage)(/|$)#', $relative)
                    || preg_match('/\.bak(-|\.|$)/', $relative)) {
                    continue;
                }

                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    // ── the fake cannot escape into production ───────────────────────────────

    public function test_the_fake_lives_only_in_the_test_tree(): void
    {
        $this->assertFileExists(self::ROOT . '/' . self::FAKE);

        // `Tests\` is autoload-dev only, so this class does not exist in a
        // production autoloader at all.
        $this->assertStringStartsWith('tests/', self::FAKE);

        // Was "no adapter directory exists at all". E5 built the first real
        // adapter, so the assertion is retargeted at what it was protecting:
        // whatever lives in the vendor tree, the FAKE is not among it.
        $vendorRoot = self::ROOT . '/app/Connectors/Infrastructure/Email';

        if (is_dir($vendorRoot)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($vendorRoot));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $this->assertSame(0, preg_match('/FakeEmailProvider|Tests\\\\Fakes/', (string) file_get_contents($file->getPathname())),
                    $file->getFilename() . ' references the test double from production code.');
            }
        }
    }

    public function test_no_production_code_references_the_fake(): void
    {
        foreach ($this->collect(['app', 'config', 'routes', 'database/migrations']) as $path => $source) {
            $this->assertSame(
                0,
                preg_match('/FakeEmailProvider|Tests\\\\Fakes/', $source),
                "{$path} references a test double. Production code must not know it exists."
            );
        }
    }

    public function test_the_fake_is_not_registered_as_a_connector(): void
    {
        $config = (string) file_get_contents(self::ROOT . '/config/infrastructure.php');

        $this->assertSame(0, preg_match('/Fake/i', $config));
        $this->assertNull(
            config('infrastructure.connectors.email'),
            'A fake reachable from configuration is one environment variable away from production.'
        );
    }

    public function test_the_fake_refuses_to_construct_in_production(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/' . self::FAKE);

        $this->assertMatchesRegularExpression(
            "/environment\(['\"]production['\"]\)/",
            $source,
            'The fake must refuse production by throwing, not by logging.'
        );
        $this->assertStringContainsString('throw new RuntimeException', $source);
    }

    public function test_the_fake_identifies_itself_in_every_row_it_touches(): void
    {
        $this->assertStringStartsWith(
            'fake-',
            FakeEmailProvider::make()->provider(),
            'The provider key is persisted to infra_operations and infra_provider_resources; it must be '
            . 'obvious in history that a result was simulated.'
        );
    }

    public function test_the_fake_reaches_nothing_outside_the_process(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/' . self::FAKE);

        foreach ([
            'HTTP client'  => '/\bHttp::|GuzzleHttp\\\\Client|curl_(init|exec)\s*\(/',
            'sockets'      => '/\b(fsockopen|stream_socket_client|socket_create)\s*\(/',
            'remote files' => '/\b(file_get_contents|fopen)\s*\(\s*[\'"]https?:/',
            'shell'        => '/\b(shell_exec|passthru|proc_open|system)\s*\(/',
            'dns'          => '/\b(dns_get_record|checkdnsrr|gethostbyname)\s*\(/',
            'mail'         => '/\bmail\s*\(|\bMail::|imap_open\s*\(/',
            'queue'        => '/\bdispatch\s*\(|\bQueue::|\bBus::/',
        ] as $label => $pattern) {
            $this->assertSame(0, preg_match($pattern, $source), "The fake reaches outside the process via {$label}.");
        }
    }

    // ── test terminology must not reach a customer ───────────────────────────

    public function test_no_customer_wording_contains_test_or_fake_terminology(): void
    {
        $wordings = array_merge(
            array_values(\App\Engines\Infrastructure\Email\Support\BusinessEmailFailure::messages()),
            array_values(ReconciliationFinding::customerMessages()),
        );

        $this->assertNotEmpty($wordings);

        foreach ($wordings as $message) {
            foreach (['fake', 'stub', 'mock', 'dummy', 'simulated', 'test double', 'null connector'] as $term) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $term,
                    $message,
                    "Customer wording contains test terminology: \"{$message}\""
                );
            }
        }
    }

    public function test_customer_wording_names_no_provider_concept(): void
    {
        foreach (ReconciliationFinding::customerMessages() as $kind => $message) {
            foreach (['provider', 'adapter', 'connector', 'api', 'endpoint', 'binding'] as $term) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $term,
                    $message,
                    "{$kind} exposes an internal concept to the customer: \"{$message}\""
                );
            }
        }
    }

    public function test_no_customer_wording_exposes_a_plan_or_price(): void
    {
        foreach (\App\Engines\Infrastructure\Email\Support\BusinessEmailFailure::messages() as $code => $message) {
            foreach (['tier', 'seat', 'per mailbox', '$', '£', '€', 'per month'] as $term) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $term,
                    $message,
                    "{$code} leaks a commercial concept from the provider: \"{$message}\""
                );
            }
        }
    }

    // ── admin and customer projections are structurally separate ─────────────

    public function test_every_projection_pair_is_declared_separately(): void
    {
        foreach ([
            ReconciliationFinding::class,
            \App\Engines\Infrastructure\Email\Reconciliation\ReconciliationReport::class,
        ] as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->hasMethod('toCustomerArray'), "{$class} has no customer projection.");
            $this->assertTrue($reflection->hasMethod('toAdminArray'), "{$class} has no admin projection.");
        }
    }

    public function test_remote_objects_have_no_customer_projection_at_all(): void
    {
        // These describe PROVIDER state. There is no customer-safe view of a
        // provider object, so offering one would be an invitation to leak.
        foreach ([
            \App\Connectors\Infrastructure\BusinessEmail\Values\RemoteMailbox::class,
            \App\Connectors\Infrastructure\BusinessEmail\Values\RemoteAlias::class,
            \App\Connectors\Infrastructure\BusinessEmail\Values\RemoteForwarder::class,
            \App\Connectors\Infrastructure\BusinessEmail\Values\RemoteCatchAll::class,
            \App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory::class,
        ] as $class) {
            $this->assertFalse(
                (new ReflectionClass($class))->hasMethod('toCustomerArray'),
                "{$class} offers a customer projection of provider state."
            );

            $this->assertTrue((new ReflectionClass($class))->hasMethod('toAdminArray'));
        }
    }

    public function test_the_call_context_carries_no_credential(): void
    {
        $properties = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionClass(\App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext::class))->getProperties()
        );

        foreach ($properties as $property) {
            $this->assertSame(
                0,
                preg_match('/secret|token|password|credential/i', $property),
                "ProviderCallContext::\${$property} could carry a credential to a provider."
            );

            // Anything named "...Key" must be the idempotency key and nothing
            // else. An API key travelling in a call context is precisely the
            // leak this guard exists to catch.
            if (preg_match('/key$/i', $property)) {
                $this->assertSame(
                    'idempotencyKey',
                    $property,
                    "ProviderCallContext::\${$property} looks like a credential, not an idempotency key."
                );
            }
        }

        $this->assertContains('idempotencyKey', $properties);
    }

    // ── DNS requirements stay neutral ────────────────────────────────────────

    public function test_a_dns_record_customer_projection_carries_no_provider_prose(): void
    {
        $record = new MailDnsRecord(MailDnsRecord::PURPOSE_SPF, 'TXT', '@', 'v=spf1 -all');
        $projection = $record->toCustomerArray();

        foreach (['instructions', 'help_url', 'provider', 'documentation', 'note', 'description'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $projection,
                'A DNS record must be structured fields only. Provider prose is how a vendor name reaches a screen.'
            );
        }
    }

    // ── the engine still cannot fabricate ────────────────────────────────────

    public function test_the_engine_never_constructs_a_verified_result_from_nothing(): void
    {
        $engine = $this->collect(['app/Engines/Infrastructure/Email']);

        $this->assertNotEmpty($engine);

        foreach ($engine as $path => $source) {
            // The engine may return a provider's verified() result, and may
            // construct one after a READ-BACK confirmed something. It may never
            // construct one on a code path with no provider evidence.
            preg_match_all('/ProviderResult::verified\s*\(/', $source, $matches);

            if ($matches[0] === []) {
                continue;
            }

            $this->assertStringContainsString(
                'confirmed',
                $source,
                "{$path} constructs a verified() result. That is only legitimate immediately after a read-back, "
                . 'and this file shows no confirmation context.'
            );
        }
    }

    public function test_every_admin_page_entry_is_capability_gated(): void
    {
        // E2 asserted that NO admin page was registered, because E2 added none.
        // E3 adds eight. The rule that actually protects the customer survives
        // and is now stated where it belongs: a Business Email admin page may
        // exist, but ONLY if it is capability-gated — otherwise it would appear
        // in the sidebar of every platform admin on an installation where the
        // feature is switched off.
        $pages = (array) config('admin_pages', []);
        $businessEmail = array_filter(
            $pages,
            fn (string $key) => str_starts_with($key, 'businessEmail'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertNotEmpty($businessEmail, 'No Business Email admin page is registered.');

        foreach ($businessEmail as $key => $entry) {
            $this->assertSame(
                'business_email.read',
                $entry['capability'] ?? null,
                "Admin page '{$key}' is not capability-gated and would be visible with the area switched off."
            );

            $this->assertStringStartsWith('business-email/', (string) ($entry['slug'] ?? ''),
                "Admin page '{$key}' does not live under the Business Email area.");
        }
    }

    public function test_no_business_email_route_exists_outside_its_two_route_files(): void
    {
        // Was "no customer-facing surface exists until E4". E4 built that
        // surface, so the assertion is retargeted at the rule underneath it:
        // Business Email routes live in exactly two files — the admin console
        // and the authenticated customer file — and nowhere else. A route
        // appearing in a public, webhook or unauthenticated file still fails.
        $permitted = [
            'routes/api/admin/business-email.php',
            'routes/api/authenticated/business-email.php',
            'routes/api.php',
            // INFRA888 · E7.3 — the customer mailbox setup page. Necessarily
            // public: the recipient has no LevelUp account. Authorised by a
            // single-use 64-hex token, not by a session, and it exists because
            // the provider's own invitation email names the vendor.
            'routes/web.php',
        ];

        foreach ($this->collect(['routes']) as $path => $source) {
            if (in_array($path, $permitted, true)) {
                continue;
            }

            $this->assertSame(0, preg_match('#[\'"]/?business-email#i', $source),
                "{$path} registers a Business Email route outside the admin console.");
            $this->assertSame(0, preg_match('/BusinessEmail\w*Controller/', $source),
                "{$path} names a Business Email controller outside the admin console.");
        }

        // And no customer-facing view renders one.
        foreach ($this->collect(['resources/views'], ['php']) as $path => $source) {
            if (str_contains($path, 'resources/views/admin/')) {
                continue;
            }

            $this->assertSame(0, preg_match('/business-email|BusinessEmail/i', $source),
                "{$path} is a non-admin view referencing Business Email.");
        }
    }

    public function test_no_scheduler_entry_or_job_was_registered_for_business_email(): void
    {
        // Was "nothing under app/Console names the BusinessEmail namespace",
        // used as a proxy for "nothing runs Business Email work automatically".
        // E6 added two operator-invoked console commands — install a credential,
        // validate against the live API — and the proxy fired on their imports.
        // A command somebody has to type is not a schedule and not a job.
        //
        // The assertion now tests the rule itself, and still fails the moment
        // anything is scheduled, queued or dispatched.
        $automation = [
            'scheduled'  => '/Schedule::\s*(command|call|job)|->\s*(everyMinute|everyFiveMinutes|hourly|daily|dailyAt|weekly|cron)\s*\(/',
            'queued'     => '/\bdispatch(Sync|AfterResponse)?\s*\(|->\s*onQueue\s*\(|implements\s+ShouldQueue/',
        ];

        foreach ($this->collect(['app/Console', 'app/Providers', 'bootstrap']) as $path => $source) {
            if (! preg_match('/BusinessEmail|EmailReconciliationService|EmailProviderRegistry/', $source)) {
                continue;
            }

            foreach ($automation as $kind => $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $source),
                    "{$path} touches Business Email AND is {$kind}. Business Email runs only when an "
                    . 'operator asks it to.'
                );
            }
        }

        // And nothing anywhere schedules the Business Email commands by name.
        foreach ($this->collect(['app/Console', 'app/Providers', 'bootstrap', 'routes']) as $path => $source) {
            $this->assertSame(
                0,
                preg_match("/Schedule::\s*command\s*\(\s*['\"]business-email/", $source),
                "{$path} schedules a Business Email command."
            );
        }
    }

    // ── the capability registry stayed honest ────────────────────────────────

    public function test_the_registry_still_declares_no_executable_platform_operation(): void
    {
        $executable = \App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry::actions();

        foreach (Registry::slugs() as $slug) {
            $this->assertNotContains(
                $slug,
                $executable,
                "{$slug} appears in the production operation registry. Business Email has a proven engine but "
                . 'no vendor adapter; merging is an E3 exit criterion, not an E2 side effect.'
            );
        }
    }
}
