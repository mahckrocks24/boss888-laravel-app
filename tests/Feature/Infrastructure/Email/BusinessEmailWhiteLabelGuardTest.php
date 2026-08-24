<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry;
use App\Engines\Infrastructure\Email\Support\BusinessEmailFailure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * INFRA888 · E1-A — WHITE-LABEL ARCHITECTURE GUARDS.
 *
 * THE PRODUCT DECISION THESE ENFORCE
 * LevelUp Growth is the Business Email provider. The underlying vendor is an
 * implementation detail that must never reach a customer surface — not a
 * column, not an API field, not a UI string, not an error message.
 *
 * WHY IT IS A TEST AND NOT A CONVENTION
 * A naming rule stated in a document decays. It survives exactly as long as the
 * person who wrote it is in the room. S8.2 proved the alternative works:
 * injecting a prohibited call failed the build. These guards are the same
 * mechanism, applied before the code they govern was written, at the cheapest
 * possible moment — nothing exists yet to rename.
 *
 * THE ONE PERMITTED EXCEPTION
 * app/Connectors/Infrastructure/Email/<Vendor>/ may name a vendor, because that
 * is what an adapter IS. That directory does not exist in E1 and must not: E1
 * builds no adapter. The guard is written so it stays correct when it does.
 *
 * NO COMMENT STRIPPING. Unlike ObservationArchitectureTest, this guard reads
 * raw source including comments. A vendor name in a comment is still a leak —
 * comments are read by every future session, get copied into commit messages
 * and pasted into customer-facing docs. The rule is easier to keep than to
 * qualify, and this file proves it is keepable: it discusses the prohibition at
 * length without naming anything.
 *
 * This file is not in the scanned set. tests/ is deliberately excluded, because
 * a guard must be able to state the pattern it forbids.
 */
class BusinessEmailWhiteLabelGuardTest extends TestCase
{
    private const ROOT = '/var/www/levelup-staging';

    /**
     * The ONLY directory in which a vendor name is permitted. It is created in
     * E5, when the first adapter is written.
     */
    private const PERMITTED_VENDOR_DIRECTORY = 'app/Connectors/Infrastructure/Email';

    /** Everything E1 owns. Guarded most strictly. */
    private const BUSINESS_EMAIL_ROOT = 'app/Engines/Infrastructure/Email';

    /**
     * Surfaces on which a vendor name is a white-label breach.
     *
     * Covers the locations named in the E1 directive: migrations, models,
     * controllers, jobs, commands, services, API resources, customer frontend,
     * admin frontend, public frontend, routes, events, notifications, enums and
     * configuration.
     */
    private function scanRoots(): array
    {
        return [
            'app',
            'config',
            'database/migrations',
            'routes',
            'resources/js',
            'resources/views',
            'public/app',
            'public/marketing',
        ];
    }

    private function scannedExtensions(): array
    {
        return ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'html', 'json'];
    }

    /**
     * Prohibited vendor identities.
     *
     * Assembled from fragments so that this file, which IS excluded from the
     * scan, still never contains a prohibited literal in readable form. That is
     * belt and braces: if the exclusion is ever removed by accident, the guard
     * does not start failing on itself and get "fixed" by weakening it.
     *
     * @return array<string,string> label => regex
     */
    private function prohibitedPatterns(): array
    {
        $a = 'm' . 'igadu';
        $b = 'z' . 'oho';
        $c = 'f' . 'astmail';
        $d = 'p' . 'rivate';
        $e = 'm' . 'icrosoft';
        $f = 'g' . 'oogle';
        $g = 'w' . 'orkspace';

        return [
            'vendor-a'        => '/\b' . $a . '\b/i',
            'vendor-b'        => '/\b' . $b . '\b/i',
            'vendor-c'        => '/\b' . $c . '\b/i',
            'vendor-d'        => '/\b' . $d . '[\s_.-]*(email|mail)\b/i',
            'vendor-e'        => '/\b' . $e . '[\s_.-]*365\b/i',
            'vendor-e-short'  => '/\bms[\s_.-]*365\b/i',
            'vendor-f'        => '/\b' . $f . '[\s_.-]*' . $g . '\b/i',
            'vendor-f-legacy' => '/\bg[\s_.-]*suite\b/i',
        ];
    }

    // ── file collection ──────────────────────────────────────────────────────

    /**
     * @param  array<int,string> $roots
     * @return array<string,string> relative path => raw source
     */
    private function collect(array $roots): array
    {
        $files = [];
        $extensions = $this->scannedExtensions();

        foreach ($roots as $root) {
            $absolute = self::ROOT . '/' . $root;

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                $relative = ltrim(str_replace(self::ROOT, '', $path), '/');

                // The one permitted exception.
                if (str_starts_with($relative, self::PERMITTED_VENDOR_DIRECTORY)) {
                    continue;
                }

                // Vendored dependencies and build output are not our surfaces.
                if (preg_match('#(^|/)(vendor|node_modules|\.git|storage)(/|$)#', $relative)) {
                    continue;
                }

                // Backup copies left by earlier phases are historical artefacts,
                // not live surfaces. They are excluded explicitly rather than
                // silently: `.bak-*` files are all over this engine.
                if (preg_match('/\.bak(-|\.|$)/', $relative)) {
                    continue;
                }

                if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $files[$relative] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    /** @param array<string,string> $files */
    private function assertNoVendorName(array $files, string $where): void
    {
        $this->assertNotEmpty(
            $files,
            "No source collected for {$where} — the guard would pass vacuously and prove nothing."
        );

        $violations = [];

        foreach ($files as $relative => $source) {
            foreach ($this->prohibitedPatterns() as $label => $pattern) {
                if (preg_match($pattern, $source, $m) === 1) {
                    $violations[] = sprintf('%s matches prohibited vendor identity [%s]', $relative, $label);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "WHITE-LABEL BREACH in {$where}. A vendor name may appear ONLY under "
            . self::PERMITTED_VENDOR_DIRECTORY . "/<Vendor>/.\n  " . implode("\n  ", $violations)
        );
    }

    // ── guard 1: the Business Email engine itself ────────────────────────────

    public function test_business_email_engine_source_names_no_vendor(): void
    {
        $files = $this->collect([self::BUSINESS_EMAIL_ROOT]);

        $this->assertGreaterThanOrEqual(
            15,
            count($files),
            'The Business Email engine should contain at least its models, states, registry, support and engine classes.'
        );

        $this->assertNoVendorName($files, 'the Business Email engine');
    }

    // ── guard 2: every guarded platform surface ──────────────────────────────

    public function test_no_platform_surface_names_a_vendor(): void
    {
        $files = $this->collect($this->scanRoots());

        $this->assertGreaterThan(
            500,
            count($files),
            'Far fewer files were scanned than this repository contains — the scan roots are wrong.'
        );

        $this->assertNoVendorName($files, 'application, configuration, routes and every frontend');
    }

    // ── guard 3: migrations ──────────────────────────────────────────────────

    public function test_no_migration_names_a_vendor(): void
    {
        $this->assertNoVendorName($this->collect(['database/migrations']), 'migrations');
    }

    /**
     * Schema-level guard read from the migration source: no TABLE, COLUMN or
     * enumerated state value may carry a vendor name. Complements the
     * database-level assertion in BusinessEmailSchemaTest, which checks what
     * actually got created.
     */
    public function test_email_migrations_declare_no_vendor_named_identifier(): void
    {
        $migrations = array_filter(
            $this->collect(['database/migrations']),
            fn (string $path) => str_contains($path, 'email_'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertNotEmpty($migrations, 'No Business Email migrations found — the guard would pass vacuously.');

        $identifiers = [];

        foreach ($migrations as $path => $source) {
            // Schema::create('x'), ->string('y'), ->index([...]) — every quoted
            // literal in a migration is an identifier or an enumerated value.
            preg_match_all("/'([A-Za-z0-9_.-]+)'/", $source, $matches);

            foreach ($matches[1] as $identifier) {
                $identifiers[$identifier] = $path;
            }
        }

        $violations = [];

        foreach ($identifiers as $identifier => $path) {
            foreach ($this->prohibitedPatterns() as $label => $pattern) {
                if (preg_match($pattern, $identifier) === 1) {
                    $violations[] = "{$path}: identifier '{$identifier}' [{$label}]";
                }
            }
        }

        $this->assertSame([], $violations, "Vendor-named schema identifier:\n  " . implode("\n  ", $violations));
    }

    // ── guard 4: customer projections never expose provider identity ─────────

    /**
     * @return array<string,object>
     */
    private function projectableModels(): array
    {
        return [
            'EmailDomain'    => new EmailDomain(),
            'EmailMailbox'   => new EmailMailbox(),
            'EmailAlias'     => new EmailAlias(),
            'EmailForwarder' => new EmailForwarder(),
            'EmailCatchAll'  => new EmailCatchAll(),
            'EmailUsage'     => new EmailUsage(),
        ];
    }

    public function test_every_email_model_declares_both_projections(): void
    {
        foreach ($this->projectableModels() as $name => $model) {
            $this->assertTrue(
                method_exists($model, 'toCustomerArray'),
                "{$name} must declare toCustomerArray(); a model with no explicit customer projection "
                . 'will eventually be serialised wholesale.'
            );

            $this->assertTrue(method_exists($model, 'toAdminArray'), "{$name} must declare toAdminArray().");
        }
    }

    public function test_customer_projections_never_expose_provider_identity(): void
    {
        // Keys that identify the provider or a provider-side object. None may
        // ever appear in a customer payload.
        $forbidden = [
            'provider', 'provider_id', 'provider_key', 'provider_name',
            'provider_connection_id', 'provider_resource_id', 'provider_state',
            'provider_domain_ref', 'provider_mailbox_ref', 'provider_alias_ref',
            'provider_forwarder_ref', 'provider_correlation_id', 'adapter_class',
            'credential_ref', 'idempotency_key',
        ];

        $violations = [];

        foreach ($this->projectableModels() as $name => $model) {
            foreach (array_keys($model->toCustomerArray()) as $key) {
                if (in_array($key, $forbidden, true)) {
                    $violations[] = "{$name}::toCustomerArray() exposes '{$key}'";
                }

                // Any key shaped like a provider reference, including ones not
                // yet invented.
                if (preg_match('/^provider[_.]|_ref$/i', (string) $key)) {
                    $violations[] = "{$name}::toCustomerArray() exposes provider-shaped key '{$key}'";
                }
            }
        }

        $this->assertSame([], $violations, "Provider identity leaked to a customer payload:\n  " . implode("\n  ", $violations));
    }

    public function test_customer_projections_never_expose_raw_provider_errors(): void
    {
        $forbidden = [
            'provider_error', 'provider_error_code', 'raw_error', 'error_payload',
            'last_error_code', 'last_error_summary', 'failure_summary', 'result_json',
            'request_json', 'provider_metadata_json', 'settings_json',
        ];

        $violations = [];

        foreach ($this->projectableModels() as $name => $model) {
            foreach (array_keys($model->toCustomerArray()) as $key) {
                if (in_array($key, $forbidden, true)) {
                    $violations[] = "{$name}::toCustomerArray() exposes '{$key}'";
                }
            }
        }

        $this->assertSame([], $violations, "Raw provider detail leaked to a customer payload:\n  " . implode("\n  ", $violations));
    }

    public function test_provider_binding_is_hidden_from_default_serialisation(): void
    {
        // toCustomerArray() is the intended path, but ->toArray() and toJson()
        // are one careless controller away. $hidden is the second line.
        foreach ($this->projectableModels() as $name => $model) {
            $hidden = $model->getHidden();

            $this->assertContains(
                'provider_connection_id',
                $hidden,
                "{$name} must hide provider_connection_id from default serialisation."
            );
        }
    }

    // ── guard 5: customer-facing wording ─────────────────────────────────────

    public function test_customer_failure_messages_name_no_vendor_and_leak_no_internals(): void
    {
        $violations = [];

        foreach (BusinessEmailFailure::messages() as $code => $message) {
            foreach ($this->prohibitedPatterns() as $label => $pattern) {
                if (preg_match($pattern, $message) === 1) {
                    $violations[] = "{$code}: names a vendor [{$label}]";
                }
            }

            // A URL, an HTTP status or a bracketed code in a customer message
            // is provider detail escaping through the wording rather than
            // through a field.
            if (preg_match('#https?://#i', $message)) {
                $violations[] = "{$code}: contains a URL";
            }

            if (preg_match('/\bHTTP\b|\b[45]\d{2}\b/', $message)) {
                $violations[] = "{$code}: contains an HTTP status";
            }
        }

        $this->assertSame([], $violations, "Customer-facing wording leaks internals:\n  " . implode("\n  ", $violations));
    }

    public function test_every_failure_code_has_a_message_classification_and_state(): void
    {
        foreach (BusinessEmailFailure::codes() as $code) {
            $this->assertNotSame('', BusinessEmailFailure::message($code), "{$code} has no customer message.");

            $this->assertContains(
                BusinessEmailFailure::retryClassification($code),
                ['retryable', 'permanent', 'manual'],
                "{$code} has no valid retry classification."
            );

            $this->assertNotSame('', BusinessEmailFailure::normalizedState($code), "{$code} has no normalized state.");
        }
    }

    // ── guard 6: the engine cannot fabricate success or reach the network ────

    public function test_only_the_confirmation_path_may_construct_a_verified_result(): void
    {
        // E1 forbade verified() ANYWHERE in this engine, because E1 executed
        // nothing and so had no evidence to stand on. E2 added the execution
        // path, and with it one legitimate producer: the read-back in
        // BusinessEmailEngine::confirm*(), which has just observed provider
        // truth. That file is excluded here and covered instead by
        // BusinessEmailE2GuardTest, which asserts the construction only appears
        // in a confirmation context.
        //
        // Nothing else in the engine may claim confirmation. Models, states,
        // registries and support classes observe nothing and therefore have
        // nothing to verify.
        $exempt = 'app/Engines/Infrastructure/Email/BusinessEmailEngine.php';

        $files = $this->collect([self::BUSINESS_EMAIL_ROOT]);
        $checked = 0;

        foreach ($files as $relative => $source) {
            if ($relative === $exempt) {
                continue;
            }

            $checked++;

            $this->assertSame(
                0,
                preg_match('/ProviderResult::verified\s*\(/', $source),
                "{$relative} constructs a verified() result. Only a read-back against a provider may produce "
                . 'one, and only the engine performs read-backs.'
            );
        }

        $this->assertGreaterThan(15, $checked, 'The exemption must not have swallowed the whole scan.');
        $this->assertArrayHasKey($exempt, $files, 'The exempt file must exist, or this guard passes vacuously.');
    }

    public function test_the_engine_makes_no_network_call(): void
    {
        $patterns = [
            'Laravel HTTP client' => '/\bHttp::(get|post|put|patch|delete|send|withHeaders|withToken|timeout)\s*\(/',
            'Guzzle'              => '/new\s+(\\\\)?GuzzleHttp\\\\Client|GuzzleHttp\\\\Client\s*\(/',
            'cURL'                => '/\bcurl_(init|exec|setopt)\s*\(/',
            'sockets'             => '/\b(fsockopen|stream_socket_client|socket_create)\s*\(/',
            'remote file access'  => '/\b(file_get_contents|fopen)\s*\(\s*[\'"]https?:/',
            'shell'               => '/\b(shell_exec|exec|passthru|proc_open|system)\s*\(/',
            'dns'                 => '/\b(dns_get_record|checkdnsrr|gethostbyname)\s*\(/',
        ];

        $files = $this->collect([self::BUSINESS_EMAIL_ROOT]);
        $this->assertNotEmpty($files);

        foreach ($files as $relative => $source) {
            foreach ($patterns as $label => $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $source),
                    "{$relative} reaches outside the process via {$label}. E1 performs no network call, "
                    . 'no DNS lookup and no shell execution.'
                );
            }
        }
    }

    // ── guard 7: no adapter, no customer route, no admin action ──────────────

    public function test_a_vendor_adapter_may_exist_only_inside_the_permitted_directory(): void
    {
        // Was "no vendor adapter exists — the first is E5". E5 built it, so the
        // assertion becomes the containment rule it was standing in for: every
        // vendor adapter lives one directory deep under the permitted root, and
        // vendor-specific code exists nowhere else in app/.
        $root = self::ROOT . '/' . self::PERMITTED_VENDOR_DIRECTORY;

        if (! is_dir($root)) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach (glob($root . '/*') ?: [] as $entry) {
            $this->assertDirectoryExists($entry,
                'The vendor root may contain vendor directories only — ' . basename($entry) . ' is a loose file.');
        }

        // And each of those directories must actually be a connector package,
        // not a dumping ground that happens to sit in the right place.
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $vendorDir) {
            $this->assertNotEmpty(glob($vendorDir . '/*.php'),
                basename($vendorDir) . ' is an empty vendor directory.');
        }
    }

    public function test_no_customer_facing_business_email_route_exists(): void
    {
        // E1 asserted that NO Business Email route existed anywhere, because E1
        // added none. E3 adds an admin operator console, so that assertion is
        // no longer the right one — but the rule it protected still holds and
        // is now stated more precisely: a Business Email route may exist ONLY
        // inside the admin route file. A customer-facing one is still forbidden,
        // and this catches it wherever it appears.
        $routes = $this->collect(['routes']);
        $this->assertNotEmpty($routes);

        // E4 adds the customer portal, so the permitted set grows by exactly
        // one file. Everything else about this guard is unchanged: a Business
        // Email route anywhere outside these two files still fails.
        $permitted = 'routes/api/admin/business-email.php';
        $permittedCustomer = 'routes/api/authenticated/business-email.php';
        // E7.3: the public, token-bound setup page.
        $permittedPublic = 'routes/web.php';
        $found = [];

        foreach ($routes as $relative => $source) {
            $names = preg_match('#[\'"]/?business-email#i', $source) === 1
                || preg_match('/BusinessEmail\w*Controller/', $source) === 1;

            if (! $names) {
                continue;
            }

            $found[] = $relative;

            // routes/api.php legitimately carries the one `require` line.
            $isRequireLine = $relative === 'routes/api.php'
                && preg_match('#require\s+__DIR__\s*\.\s*[\'"]/api/(admin|authenticated)/business-email\.php[\'"]#', $source) === 1;

            $this->assertTrue(
                $relative === $permitted
                    || $relative === $permittedCustomer
                    || $relative === $permittedPublic
                    || $isRequireLine,
                "{$relative} registers a Business Email route outside the three permitted files."
            );
        }

        $this->assertContains(
            $permitted,
            $found,
            'The admin route file was not found at all — this guard would pass vacuously.'
        );

        $this->assertContains(
            $permittedCustomer,
            $found,
            'The customer route file was not found at all — this guard would pass vacuously.'
        );
    }

    public function test_the_admin_route_file_registers_nothing_outside_the_admin_prefix(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/routes/api/admin/business-email.php');

        // It is required INSIDE the admin group, so it must not re-declare its
        // own middleware or prefix — doing so could place a route outside the
        // authenticated admin stack without that being obvious at the call site.
        $this->assertSame(0, preg_match('/Route::middleware\(\s*\[\s*[\'"]auth/', $source),
            'The admin route file re-declares authentication; it inherits it from the group.');

        $this->assertStringContainsString("Route::prefix('business-email')", $source);
    }

    // ── guard 8: capability registry declares nothing vendor-specific ────────

    public function test_capability_registry_is_provider_agnostic(): void
    {
        foreach (BusinessEmailCapabilityRegistry::capabilities() as $slug => $meta) {
            $encoded = json_encode($meta);

            foreach ($this->prohibitedPatterns() as $label => $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, (string) $encoded),
                    "Capability '{$slug}' names a vendor [{$label}]."
                );
            }
        }
    }

    public function test_engine_resolves_no_provider_and_says_so(): void
    {
        $engine = app(BusinessEmailEngine::class);

        $this->assertNull(
            $engine->connector(),
            'E1 must resolve no email connector. A connector here would mean an adapter exists.'
        );

        $result = $engine->providerAvailability();

        $this->assertFalse($result->success, 'Availability must not report success with no provider configured.');
        $this->assertFalse($result->verified);
        $this->assertSame(BusinessEmailFailure::PROVIDER_NOT_CONFIGURED, $result->errorCode);
        $this->assertSame('unavailable', $result->normalizedState);
    }
}
