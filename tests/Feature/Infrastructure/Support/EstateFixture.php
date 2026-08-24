<?php

namespace Tests\Feature\Infrastructure\Support;

use App\Engines\Infrastructure\Observation\Custody;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8.2 — CANONICAL ESTATE FIXTURE.
 *
 * The S8 suite failed for a mundane reason with an important lesson:
 * `workspaces.created_by` is NOT NULL with no default and is a foreign key to
 * `users`. My fixture inserted a workspace without an owner, so every test that
 * needed an estate died at setup.
 *
 * The failure was in the FIXTURE, not the schema. The constraint is correct — a
 * workspace genuinely must have a creator — so nothing here weakens it. This
 * builds the full chain the platform actually requires:
 *
 *     user -> workspace -> website / customer_domain -> observation -> alert
 *
 * This codebase has no model factories; the established, working pattern in
 * passing suites (SubdomainServiceTest, CustomDomainServiceTest) is a direct
 * table insert of that chain. This consolidates that pattern in one place so the
 * next suite does not rediscover `created_by` the hard way.
 */
class EstateFixture
{
    public int $userId;
    public int $workspaceId;

    private function __construct(int $userId, int $workspaceId)
    {
        $this->userId = $userId;
        $this->workspaceId = $workspaceId;
    }

    /** Build a complete, valid workspace with a real owner. */
    public static function make(string $label = 'estate'): self
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'INFRA888 Fixture',
            'email' => 'infra888-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wsId = (int) DB::table('workspaces')->insertGetId([
            'name' => 'INFRA888 Fixture WS',
            'slug' => 'infra888-' . $label . '-' . uniqid(),
            // The column the original fixture omitted. NOT NULL, FK to users.
            'created_by' => $uid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new self($uid, $wsId);
    }

    /** A second workspace, for tenancy isolation tests. */
    public static function second(string $label = 'other'): self
    {
        return self::make($label);
    }

    // ── estate objects ───────────────────────────────────────────────────────

    /** A domain registered THROUGH us -> custody: managed_by_us. */
    public function registeredDomain(string $host, array $over = []): int
    {
        return (int) DB::table('customer_domains')->insertGetId(array_merge([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'domain' => $host,
            'provider' => 'namecheap',
            'status' => 'active',
            'registered_at' => now()->subYear(),
            'expires_at' => now()->addYear(),
            'auto_renew' => 0,
            'is_locked' => 0,
            'whois_privacy' => 0,
            'nameservers_json' => json_encode(['ns1.example.com', 'ns2.example.com']),
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $over));
    }

    /** A site we SERVE on a domain the customer registered -> custody: customer_held. */
    public function servedWebsite(string $host, array $over = []): int
    {
        return (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $this->workspaceId,
            'name' => 'Fixture Site',
            'custom_domain' => $host,
            'domain_verified' => 1,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ], $over));
    }

    public function hostingAccount(string $name = 'Fixture Hosting'): int
    {
        $assetId = (int) DB::table('infra_assets')->insertGetId([
            'asset_uid' => (string) \Illuminate\Support\Str::uuid(),
            'workspace_id' => $this->workspaceId,
            'asset_type' => 'hosting_account',
            'name' => $name,
            'management_mode' => 'adopted',
            'lifecycle_state' => 'active',
            'health_state' => 'unknown',
            'risk_state' => 'ok',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('infra_hosting_accounts')->insertGetId([
            'asset_id' => $assetId,
            'workspace_id' => $this->workspaceId,
            'name' => $name,
            'state' => 'active',
            'environment' => 'production',
            'health_state' => 'unknown',
            'backup_state' => 'unknown',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── observation + drift ──────────────────────────────────────────────────

    /**
     * One observation-registry row. `$drift` is a list of
     * [class, severity, title] triples.
     */
    public function observation(string $subject, string $dimension, array $opts = []): int
    {
        $drift = $opts['drift'] ?? [];
        $success = $opts['success'] ?? true;

        $rank = ['info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];
        $highest = null;

        foreach ($drift as $d) {
            $s = $d['severity'] ?? 'medium';

            if ($highest === null || ($rank[$s] ?? 0) > ($rank[$highest] ?? 0)) {
                $highest = $s;
            }
        }

        return (int) DB::table('infra_observation_facts')->insertGetId([
            'workspace_id' => $this->workspaceId,
            'subject' => $subject,
            'customer_domain_id' => $opts['customer_domain_id'] ?? null,
            'website_id' => $opts['website_id'] ?? null,
            'dimension' => $dimension,
            'custody' => $opts['custody'] ?? Custody::CUSTOMER_HELD,
            'provider' => $opts['provider'] ?? 'test_probe',
            'success' => $success,
            'confidence' => $success ? ($opts['confidence'] ?? Custody::VERIFIED) : Custody::UNCERTAIN,
            'duration_ms' => 10,
            'error_code' => $opts['error_code'] ?? null,
            'observed_json' => json_encode($opts['observed'] ?? []),
            'desired_json' => json_encode($opts['desired'] ?? []),
            'has_drift' => $drift !== [],
            'drift_count' => count($drift),
            'highest_severity' => $highest,
            'drift_json' => json_encode($drift),
            'observed_at' => $opts['observed_at'] ?? now(),
            'last_success_at' => $success ? now() : null,
            'last_failure_at' => $success ? null : now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Shorthand: one drift finding as the observers emit them. */
    public static function drift(string $severity, string $title, string $class = 'unknown_drift'): array
    {
        return ['class' => $class, 'severity' => $severity, 'title' => $title,
            'recommended_action' => 'fixture action'];
    }
}
