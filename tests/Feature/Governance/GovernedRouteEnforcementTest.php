<?php

namespace Tests\Feature\Governance;

use App\Core\Governance\DomainCapabilityRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * E2 — GOVERNED ROUTE ENFORCEMENT
 *
 * Every mutating domain route must resolve through a governed entry point
 * (a controller extending BaseEngineController, which routes writes through
 * EngineExecutionService -> capability -> plan gate -> credits -> approval ->
 * audit), OR appear in a dated, justified, owned allow-list.
 *
 * This is the test that would have caught Domain Commerce on day one. It
 * shipped 17 routes and 137 passing tests without appearing in any governance
 * artefact, because nothing asked whether it participated in the platform.
 *
 * The allow-list is deliberately hostile to neglect: an entry with no owner,
 * no removal milestone or no expiry fails; an expired entry fails; a route
 * that drifts from its entry fails; a new violation with no entry fails.
 */
class GovernedRouteEnforcementTest extends TestCase
{
    private const ALLOWLIST = __DIR__ . '/fixtures/domain_governance_allowlist.json';

    private const GOVERNED_BASE = \App\Http\Controllers\Api\BaseEngineController::class;

    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const REQUIRED_ENTRY_FIELDS = [
        'route', 'controller', 'method', 'capability_key',
        'reason', 'risk', 'owner', 'removal_milestone', 'expires',
    ];

    private array $allowlist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileExists(self::ALLOWLIST, 'the governance allow-list fixture is missing');

        $raw = json_decode(file_get_contents(self::ALLOWLIST), true);

        $this->assertIsArray($raw, 'the allow-list is not valid JSON');
        $this->allowlist = $raw;
    }

    /** Live mutating routes whose URI belongs to the domain surface. */
    private function mutatingDomainRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_contains($uri, 'domains') && ! str_contains($uri, 'registrar')) {
                continue;
            }

            $methods = array_values(array_intersect($route->methods(), self::MUTATING));

            if ($methods === []) {
                continue;
            }

            $action = $route->getActionName();               // Controller@method or Closure
            [$controller, $method] = str_contains($action, '@')
                ? explode('@', $action, 2)
                : [$action, null];

            $out[] = [
                'signature'  => $methods[0] . ' ' . $uri,
                'uri'        => $uri,
                'http'       => $methods[0],
                'controller' => ltrim($controller, '\\'),
                'method'     => $method,
            ];
        }

        usort($out, static fn ($a, $b) => strcmp($a['signature'], $b['signature']));

        return $out;
    }

    private function isGoverned(string $controller): bool
    {
        return class_exists($controller) && is_subclass_of($controller, self::GOVERNED_BASE);
    }

    private function entryFor(string $signature): ?array
    {
        foreach ($this->allowlist['route_exceptions'] ?? [] as $entry) {
            if (($entry['route'] ?? null) === $signature) {
                return $entry;
            }
        }

        return null;
    }

    // ──────────────────────────────────── the core assertion ──

    public function test_every_mutating_domain_route_is_governed_or_explicitly_excepted(): void
    {
        $routes = $this->mutatingDomainRoutes();

        $this->assertNotEmpty($routes, 'expected to find mutating domain routes; the discovery is broken');

        $ungoverned = [];

        foreach ($routes as $r) {
            if ($this->isGoverned($r['controller'])) {
                continue;
            }

            if ($this->entryFor($r['signature']) === null) {
                $ungoverned[] = $r['signature'] . '  ->  ' . class_basename($r['controller']) . '@' . $r['method'];
            }
        }

        $this->assertSame([], $ungoverned,
            "These mutating domain routes are neither governed nor allow-listed:\n  - "
            . implode("\n  - ", $ungoverned)
            . "\n\nA new mutating route must either extend BaseEngineController or be added to "
            . "the allow-list with an owner, a removal milestone and an expiry date. "
            . "Do NOT add to the allow-list to make new work pass.");
    }

    // ──────────────────────────── allow-list hygiene ──

    public function test_every_allowlist_entry_is_complete(): void
    {
        foreach ($this->allowlist['route_exceptions'] ?? [] as $i => $entry) {
            foreach (self::REQUIRED_ENTRY_FIELDS as $field) {
                $this->assertArrayHasKey($field, $entry, "route_exceptions[{$i}] is missing '{$field}'");
                $this->assertNotEmpty($entry[$field], "route_exceptions[{$i}].{$field} is empty");
            }
        }

        foreach ($this->allowlist['direct_provider_access'] ?? [] as $i => $entry) {
            foreach (['file', 'symbol', 'capability_key', 'reason', 'risk', 'owner', 'removal_milestone', 'expires'] as $field) {
                $this->assertArrayHasKey($field, $entry, "direct_provider_access[{$i}] is missing '{$field}'");
                $this->assertNotEmpty($entry[$field], "direct_provider_access[{$i}].{$field} is empty");
            }
        }
    }

    public function test_no_exception_has_expired(): void
    {
        $expired = [];
        $today = date('Y-m-d');

        foreach (array_merge($this->allowlist['route_exceptions'] ?? [], $this->allowlist['direct_provider_access'] ?? []) as $entry) {
            $label = $entry['route'] ?? ($entry['file'] . '::' . $entry['symbol']);

            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $entry['expires'],
                "{$label} has a malformed expiry date");

            if ($entry['expires'] < $today) {
                $expired[] = $label . ' (expired ' . $entry['expires'] . ', owner ' . $entry['owner'] . ')';
            }
        }

        $this->assertSame([], $expired,
            "These governance exceptions have EXPIRED and must be resolved or explicitly re-dated:\n  - "
            . implode("\n  - ", $expired));
    }

    public function test_every_exception_names_a_capability_that_actually_exists(): void
    {
        foreach (array_merge($this->allowlist['route_exceptions'] ?? [], $this->allowlist['direct_provider_access'] ?? []) as $entry) {
            $key = $entry['capability_key'];

            $this->assertTrue(DomainCapabilityRegistry::has($key),
                "exception names capability '{$key}', which is not declared. "
                . 'An exception must point at the capability it will eventually use.');
        }
    }

    /**
     * Staleness. If a route is renamed, or its controller/method changes, the
     * allow-list entry silently stops describing reality — and a stale
     * exception is indistinguishable from a permanent one.
     */
    public function test_no_allowlist_entry_is_stale(): void
    {
        $live = [];

        foreach ($this->mutatingDomainRoutes() as $r) {
            $live[$r['signature']] = $r;
        }

        $stale = [];

        foreach ($this->allowlist['route_exceptions'] ?? [] as $entry) {
            $sig = $entry['route'];

            if (! isset($live[$sig])) {
                $stale[] = "{$sig} — no such route exists any more (remove the entry)";
                continue;
            }

            if ($live[$sig]['controller'] !== ltrim($entry['controller'], '\\')) {
                $stale[] = "{$sig} — controller changed to {$live[$sig]['controller']}";
                continue;
            }

            if ($live[$sig]['method'] !== $entry['method']) {
                $stale[] = "{$sig} — method changed to {$live[$sig]['method']}";
                continue;
            }

            // If the route became governed, the exception is obsolete.
            if ($this->isGoverned($live[$sig]['controller'])) {
                $stale[] = "{$sig} — now governed; delete this exception";
            }
        }

        $this->assertSame([], $stale,
            "Stale allow-list entries:\n  - " . implode("\n  - ", $stale));
    }

    public function test_direct_provider_access_entries_still_describe_reality(): void
    {
        $base = base_path();
        $stale = [];

        foreach ($this->allowlist['direct_provider_access'] ?? [] as $entry) {
            $path = $base . '/' . $entry['file'];

            if (! is_file($path)) {
                $stale[] = $entry['file'] . ' — file no longer exists';
                continue;
            }

            $src = file_get_contents($path);

            if (! str_contains($src, 'Connectors\\Infrastructure')) {
                $stale[] = $entry['file'] . ' — no longer references a connector; delete this exception';
            }
        }

        $this->assertSame([], $stale,
            "Stale direct-provider exceptions:\n  - " . implode("\n  - ", $stale));
    }

    // ───────────────────── the violation must stay visible ──

    /**
     * CustomerDomainController::setAutoRenew() resolves the registrar connector
     * directly from a controller. Mark's instruction is explicit: it must remain
     * visible as a violation and must NOT be grandfathered as acceptable
     * architecture. This test fails the day someone quietly deletes the entry
     * without fixing the code.
     */
    public function test_known_direct_provider_violation_remains_declared(): void
    {
        $entries = array_column($this->allowlist['direct_provider_access'] ?? [], 'symbol', 'file');

        $this->assertArrayHasKey('app/Http/Controllers/Api/CustomerDomainController.php', $entries,
            'the setAutoRenew direct-provider violation must remain declared until the code is fixed');

        $src = file_get_contents(base_path('app/Http/Controllers/Api/CustomerDomainController.php'));

        $this->assertStringContainsString('Connectors\\Infrastructure', $src,
            'if this no longer holds, the violation is FIXED — remove the allow-list entry and this assertion');
    }

    // ───────────────────────────── reporting ──

    public function test_report_current_governance_position(): void
    {
        $routes = $this->mutatingDomainRoutes();
        $governed = $excepted = 0;

        foreach ($routes as $r) {
            $this->isGoverned($r['controller']) ? $governed++ : $excepted++;
        }

        fwrite(STDERR, sprintf(
            "\n  [E2] mutating domain routes: %d — governed: %d, allow-listed exceptions: %d, direct-provider violations: %d\n",
            count($routes), $governed, $excepted, count($this->allowlist['direct_provider_access'] ?? [])
        ));

        $this->assertSame(count($routes), $governed + $excepted, 'every route must be accounted for');
    }
}
