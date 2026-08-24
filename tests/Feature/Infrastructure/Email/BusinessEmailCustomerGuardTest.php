<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Engines\Infrastructure\Email\Customer\BusinessEmailCustomerGate as Gate;
use App\Engines\Infrastructure\Email\Customer\CustomerEntitlements;
use App\Engines\Infrastructure\Email\Customer\CustomerHealth;
use App\Engines\Infrastructure\Email\Customer\CustomerStatus;
use App\Engines\Infrastructure\Email\Customer\CustomerTimeline;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Http\Controllers\Api\BusinessEmailCustomerActionController;
use ReflectionClass;
use Tests\TestCase;

/**
 * INFRA888 · E4-Q — CUSTOMER-SURFACE GUARDS.
 *
 * E1's guards cover vendor names everywhere. E2's cover the fake. E3's cover the
 * operator console. These cover the one surface none of them could: a customer
 * screen, where the cost of a leak is highest and the reader is least equipped
 * to recognise one.
 */
class BusinessEmailCustomerGuardTest extends TestCase
{
    private const ROOT = '/var/www/levelup-staging';
    private const SPA = 'public/app/js/business-email.js';

    /** Files that render or serve the customer surface. */
    private function customerSources(): array
    {
        $paths = [
            'app/Http/Controllers/Api/BusinessEmailCustomerController.php',
            'app/Http/Controllers/Api/BusinessEmailCustomerActionController.php',
            'app/Engines/Infrastructure/Email/Customer/CustomerStatus.php',
            'app/Engines/Infrastructure/Email/Customer/CustomerHealth.php',
            'app/Engines/Infrastructure/Email/Customer/CustomerTimeline.php',
            'app/Engines/Infrastructure/Email/Customer/CustomerEntitlements.php',
            'app/Engines/Infrastructure/Email/Customer/BusinessEmailCustomerGate.php',
            'routes/api/authenticated/business-email.php',
            self::SPA,
        ];

        $out = [];

        foreach ($paths as $p) {
            $full = self::ROOT . '/' . $p;
            $this->assertFileExists($full, "{$p} is missing — the guard would pass vacuously.");
            $out[$p] = (string) file_get_contents($full);
        }

        return $out;
    }

    /** Comments stripped, so prose describing a prohibition cannot trip it. */
    private function strip(string $php): string
    {
        $out = '';

        foreach (token_get_all($php) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    // ── vendor identity ──────────────────────────────────────────────────────

    public function test_no_customer_source_names_a_vendor(): void
    {
        $patterns = [
            '/\b' . 'm' . 'igadu\b/i', '/\b' . 'z' . 'oho\b/i', '/\b' . 'f' . 'astmail\b/i',
            '/' . 'g' . 'oogle[\s_.-]*workspace/i', '/' . 'm' . 'icrosoft[\s_.-]*365/i',
            '/' . 'p' . 'rivate[\s_.-]*(email|mail)\b/i',
        ];

        foreach ($this->customerSources() as $path => $source) {
            foreach ($patterns as $pattern) {
                $this->assertSame(0, preg_match($pattern, $source), "{$path} names a vendor.");
            }
        }
    }

    public function test_no_customer_source_exposes_a_provider_concept(): void
    {
        // The controller legitimately READS provider capability to decide what
        // to offer; what it must never do is put the concept in a payload. So
        // this checks the OUTPUT vocabulary, not the code's internals.
        $forbiddenKeys = [
            'provider_ref', 'provider_resource_id', 'provider_connection_id', 'provider_id',
            'provider_state', 'credential_ref', 'secret_encrypted', 'idempotency_key',
            'retry_classification', 'failure_code', 'provider_correlation_id',
            'normalized_state', 'drift_class', 'confidence', 'custody',
        ];

        foreach ($this->customerSources() as $path => $source) {
            foreach ($forbiddenKeys as $key) {
                $this->assertSame(
                    0,
                    preg_match("/['\"]" . preg_quote($key, '/') . "['\"]\s*=>/", $source),
                    "{$path} emits '{$key}' in a customer payload."
                );
            }
        }
    }

    public function test_the_customer_controllers_never_touch_a_connector_method(): void
    {
        foreach ([
            'app/Http/Controllers/Api/BusinessEmailCustomerController.php',
            'app/Http/Controllers/Api/BusinessEmailCustomerActionController.php',
        ] as $path) {
            $code = $this->strip((string) file_get_contents(self::ROOT . '/' . $path));

            foreach ([
                'createMailbox', 'deleteMailbox', 'suspendMailbox', 'restoreMailbox', 'updateMailbox',
                'createAlias', 'deleteAlias', 'createForwarder', 'deleteForwarder',
                'configureCatchAll', 'clearCatchAll', 'onboardDomain', 'verifyDomain',
                'requestPasswordReset', 'getInventory', 'getUsage', 'getDnsRequirements',
            ] as $method) {
                $this->assertSame(0, preg_match('/->' . $method . '\s*\(/', $code),
                    "{$path} calls the connector method {$method}() directly, bypassing the engine.");
            }
        }
    }

    public function test_no_customer_source_constructs_a_verified_result(): void
    {
        foreach ($this->customerSources() as $path => $source) {
            $this->assertSame(0, preg_match('/ProviderResult::verified\s*\(/', $source),
                "{$path} manufactures a confirmed result.");
        }
    }

    // ── tenancy ──────────────────────────────────────────────────────────────

    public function test_no_customer_endpoint_reads_a_workspace_from_the_request(): void
    {
        foreach ([
            'app/Http/Controllers/Api/BusinessEmailCustomerController.php',
            'app/Http/Controllers/Api/BusinessEmailCustomerActionController.php',
        ] as $path) {
            $code = $this->strip((string) file_get_contents(self::ROOT . '/' . $path));

            // The single most damaging thing a multi-tenant endpoint can do.
            foreach ([
                "/input\(\s*['\"]workspace_id['\"]/",
                "/query\(\s*['\"]workspace_id['\"]/",
                "/request->workspace_id/",
            ] as $pattern) {
                $this->assertSame(0, preg_match($pattern, $code),
                    "{$path} takes a workspace id from the request instead of the verified token claim.");
            }

            $this->assertStringContainsString("attributes->get('workspace_id'", $code,
                "{$path} must resolve the workspace from the authenticated request attributes.");
        }
    }

    public function test_the_customer_route_file_declares_no_workspace_parameter(): void
    {
        $routes = (string) file_get_contents(self::ROOT . '/routes/api/authenticated/business-email.php');

        $this->assertSame(0, preg_match('/\{workspace/i', $routes));
        $this->assertStringContainsString("Route::prefix('business-email')", $routes);

        // It inherits auth.jwt + DenyApiKeyAuth from the group it is required
        // into; re-declaring them here could place a route outside that stack.
        $this->assertSame(0, preg_match('/Route::middleware\(\s*\[\s*[\'"]auth/', $routes));
    }

    // ── customer/admin separation ────────────────────────────────────────────

    public function test_no_customer_source_imports_an_admin_component(): void
    {
        foreach ($this->customerSources() as $path => $source) {
            foreach ([
                'BusinessEmailAdminController', 'BusinessEmailAdminActionController',
                'BusinessEmailAdminAccess', 'BusinessEmailAdminGate',
                'EmailReconciliationService', 'ReconciliationFinding', 'ReconciliationReport',
                'InfraProviderResource', 'InfraOperation',
            ] as $admin) {
                $this->assertSame(0, preg_match('/\b' . $admin . '\b/', $source),
                    "{$path} pulls in the operator plane.");
            }
        }
    }

    public function test_the_spa_calls_shared_helpers_with_their_real_signatures(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/' . self::SPA);

        // card(inner, extra) splices `extra` INSIDE a style attribute. Calling
        // it as card(title, body) pushed every panel's markup into that
        // attribute; the browser bailed out at the first quote and a stray `">`
        // rendered as visible text. Only panel() may touch ctx.card.
        preg_match_all('/ctx\.card\s*\(/', $spa, $calls);
        $this->assertCount(1, $calls[0],
            'ctx.card must be reached through panel() alone — it takes (inner, extra), not (title, body).');

        $this->assertMatchesRegularExpression('/function panel\s*\(\s*title\s*,\s*inner\s*\)/', $spa);
        $this->assertStringContainsString('ctx.card(head + inner)', $spa);
    }

    public function test_the_spa_builds_api_paths_without_a_leading_slash(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/' . self::SPA);

        // apiUrl() already supplies '/api/'. A leading slash produced
        // '/api//infrastructure/business-email/...' and every request 404'd.
        $this->assertSame(0, preg_match("#ctx\.req\(\s*'[A-Z]+'\s*,\s*'/#", $spa),
            'A leading slash in an API path becomes /api//… and 404s.');

        $this->assertStringContainsString("ctx.req('GET', 'infrastructure/business-email'", $spa);
    }

    public function test_the_admin_console_is_not_reachable_from_the_customer_spa(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/' . self::SPA);

        $this->assertSame(0, preg_match('#/api/admin#', $spa));
        $this->assertSame(0, preg_match('#/admin/#', $spa));
    }

    // ── wording ──────────────────────────────────────────────────────────────

    public function test_no_customer_wording_exposes_an_internal_concept(): void
    {
        $wordings = [];

        foreach (CustomerStatus::presentation() as $p) {
            $wordings[] = $p['label'] . ' ' . $p['detail'];
        }

        foreach (CustomerHealth::labels() as $l) { $wordings[] = $l; }
        foreach (CustomerHealth::details() as $d) { $wordings[] = $d; }
        foreach (CustomerTimeline::allowed() as $a) { $wordings[] = $a['message']; }

        foreach (CustomerHealth::checks() as $c) {
            $wordings[] = $c['label'] . ' ' . $c['why'];
        }

        $this->assertGreaterThan(30, count($wordings));

        foreach ($wordings as $text) {
            foreach ([
                'provider', 'connector', 'adapter', 'idempoten', 'reconcil', 'compensat',
                'drift', 'custody', 'confidence', 'capability', 'operation id', 'workspace id',
                'fake', 'stub', 'mock', 'simulated',
            ] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text,
                    "Customer wording exposes an internal concept: \"{$text}\"");
            }
        }
    }

    public function test_no_customer_wording_exposes_a_price_or_plan_name(): void
    {
        $texts = array_merge(
            array_map(fn ($p) => $p['detail'], CustomerStatus::presentation()),
            array_values(CustomerHealth::details())
        );

        foreach ($texts as $text) {
            foreach (['$', '£', '€', 'per month', 'per mailbox', 'upgrade to', 'tier', 'plan name'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
            }
        }
    }

    // ── entitlements are LevelUp's, not the provider's ───────────────────────

    public function test_entitlements_are_never_read_from_provider_capacity(): void
    {
        $code = $this->strip((string) file_get_contents(
            self::ROOT . '/app/Engines/Infrastructure/Email/Customer/CustomerEntitlements.php'
        ));

        // A customer's allowance must not change when we change vendor.
        foreach (['capabilitySet', 'connector', 'ProviderResult', 'storage_options_mb'] as $forbidden) {
            $this->assertSame(0, preg_match('/\b' . $forbidden . '\b/', $code),
                "CustomerEntitlements reads provider capacity.");
        }

        $this->assertStringContainsString("config('business_email.customer_entitlements'", $code);
    }

    public function test_no_plan_name_is_hard_coded(): void
    {
        $config = (string) file_get_contents(self::ROOT . '/config/business_email.php');

        // Commercial plans are not approved yet; inventing public names here
        // would put unapproved commercial language into the product.
        foreach (['starter', 'professional', 'enterprise', 'basic', 'premium', 'ptaa'] as $forbidden) {
            $this->assertSame(0, preg_match("/'" . $forbidden . "'/i", $config),
                "config/business_email.php hard-codes a plan or tenant name.");
        }
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    public function test_the_customer_gate_is_closed_by_default_and_is_not_an_environment_check(): void
    {
        $source = (string) file_get_contents(
            self::ROOT . '/app/Engines/Infrastructure/Email/Customer/BusinessEmailCustomerGate.php'
        );

        // APP_ENV here is `staging` and this Laravel serves live customer
        // domains, so an environment test would leave the portal switched ON.
        $this->assertSame(0, preg_match('/environment\s*\(/', $this->strip($source)));

        $this->assertFalse((bool) config('business_email.customer_enabled'));
        $this->assertFalse(Gate::isEnabled());
    }

    public function test_customer_capabilities_come_from_the_registry_not_a_second_list(): void
    {
        $this->assertSame(Registry::customerFacing(), Gate::customerCapabilities());

        // Admin-only capabilities stay admin-only.
        foreach ([Registry::USAGE_SYNC, Registry::HEALTH_OBSERVE] as $adminOnly) {
            $this->assertFalse(Gate::allowsCapability($adminOnly), "{$adminOnly} must not be customer-invokable.");
        }
    }

    public function test_the_customer_action_map_names_only_registered_capabilities(): void
    {
        foreach (BusinessEmailCustomerActionController::actionMap() as $action => $capability) {
            $this->assertTrue(Registry::has($capability), "{$action} names an unregistered capability.");
            $this->assertTrue(Gate::allowsCapability($capability),
                "{$action} maps to a capability the registry marks admin-only.");
        }
    }

    public function test_a_capability_cannot_be_named_by_the_caller(): void
    {
        $code = $this->strip((string) file_get_contents(
            self::ROOT . '/app/Http/Controllers/Api/BusinessEmailCustomerActionController.php'
        ));

        $this->assertSame(0, preg_match("/input\(\s*['\"]capability['\"]/", $code));
    }

    // ── no second navigation item ────────────────────────────────────────────

    public function test_exactly_one_email_accounts_navigation_item_exists(): void
    {
        $index = (string) file_get_contents(self::ROOT . '/public/app/index.html');

        $this->assertSame(1, substr_count($index, 'ni-infra-email'));
        $this->assertSame(1, substr_count($index, 'business-email.js'));
    }

    public function test_the_spa_delegates_rather_than_duplicating_the_shell(): void
    {
        $infra = (string) file_get_contents(self::ROOT . '/public/app/js/infrastructure.js');

        $this->assertStringContainsString('window.luBusinessEmail', $infra);
        // The existing coming-soon copy is retained as the fallback, so a
        // closed gate changes nothing observable.
        $this->assertStringContainsString('Business email is coming soon', $infra);
    }

    // ── projections are declared, not derived ────────────────────────────────

    public function test_every_customer_projection_class_is_pure(): void
    {
        foreach ([CustomerStatus::class, CustomerHealth::class, CustomerTimeline::class, CustomerEntitlements::class] as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isFinal(), "{$class} must be final.");

            foreach ($reflection->getMethods() as $method) {
                $this->assertTrue($method->isStatic(),
                    "{$class}::{$method->getName()}() is not static — these carry no state by design.");
            }
        }
    }
}
