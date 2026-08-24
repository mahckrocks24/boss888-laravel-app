<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INFRA888 · PHASE S1 — HOSTING RECONCILIATION
 *
 * Makes INFRA888 understand every website we already host.
 *
 * The platform has been hosting live customer websites through the Builder while
 * INFRA888 recorded almost none of it. An infrastructure layer that cannot answer
 * "what do we run, for whom, and is it healthy?" is a records system, not an
 * operating layer — so this reconciles the estate against the truth.
 *
 * TRUTH ONLY. This command provisions nothing, contacts no provider, changes no
 * live hosting, and never touches the `websites` table. It reads the platform's
 * source of truth and records what it finds.
 *
 * IDEMPOTENT. Re-running reconciles again; already-represented sites are skipped
 * by their source linkage, never duplicated.
 *
 * CUSTODY IS RECORDED HONESTLY. These sites were provisioned by the Builder, not
 * by INFRA888. They are adopted into the estate as managed-but-not-provisioned,
 * and the metadata says so. Recording them as INFRA888-provisioned would be the
 * kind of convenient fiction this reconciliation exists to remove.
 */
class ReconcileHostingCommand extends Command
{
    protected $signature = 'infra:reconcile-hosting
                            {--dry-run : report the plan and change nothing}
                            {--confirm= : token printed by --dry-run}
                            {--workspace= : limit to one workspace}';

    protected $description = 'INFRA888 S1 — reconcile the hosting estate against the platform source of truth (read-only by default)';

    /** The account every Builder-hosted site belongs to until real provisioning exists. */
    private const PLATFORM_ACCOUNT_NAME = 'LevelUp Platform Hosting';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $wsFilter = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $before = $this->counts();

        // ── SOURCE OF TRUTH: published, non-deleted websites ────────────────
        $sites = DB::table('websites')
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->when($wsFilter !== null, fn ($q) => $q->where('workspace_id', $wsFilter))
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'name', 'domain', 'subdomain', 'custom_domain', 'published_at', 'created_at']);

        if ($sites->isEmpty()) {
            $this->warn('No published websites found. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $plan = [];

        foreach ($sites as $site) {
            $hostname = $this->hostname($site);

            $existing = DB::table('infra_hosted_sites')
                ->where('website_id', $site->id)
                ->whereNull('deleted_at')
                ->first();

            $account = DB::table('infra_hosting_accounts')
                ->where('workspace_id', $site->workspace_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->first();

            $plan[] = [
                'website_id' => (int) $site->id,
                'workspace_id' => (int) $site->workspace_id,
                'name' => (string) $site->name,
                'hostname' => $hostname,
                'already_reconciled' => $existing !== null,
                'needs_account' => $account === null,
                'account_id' => $account->id ?? null,
                'custom_domain' => $site->custom_domain ?: null,
            ];
        }

        // ── report ──────────────────────────────────────────────────────────
        $this->line('');
        $this->line('INFRA888 S1 — HOSTING RECONCILIATION' . ($dryRun ? '  [DRY RUN]' : ''));
        $this->line('');
        $this->line(sprintf('  %-4s %-6s %-28s %-34s %-10s %s',
            'WS', 'SITE', 'NAME', 'HOSTNAME', 'STATE', 'ACTION'));

        $toCreate = 0;
        $accountsNeeded = [];

        foreach ($plan as $p) {
            if ($p['already_reconciled']) {
                $action = 'skip — already in estate';
            } else {
                $action = 'ADOPT into estate';
                $toCreate++;

                if ($p['needs_account']) {
                    $accountsNeeded[$p['workspace_id']] = true;
                }
            }

            $this->line(sprintf('  %-4d %-6d %-28s %-34s %-10s %s',
                $p['workspace_id'], $p['website_id'],
                Str::limit($p['name'], 27, ''), Str::limit($p['hostname'] ?? '(none)', 33, ''),
                $p['already_reconciled'] ? 'known' : 'MISSING', $action));
        }

        $this->line('');
        $this->line(sprintf('  websites examined      : %d', count($plan)));
        $this->line(sprintf('  already in the estate  : %d', count($plan) - $toCreate));
        $this->line(sprintf('  to adopt               : %d', $toCreate));
        $this->line(sprintf('  hosting accounts to add: %d', count($accountsNeeded)));
        $this->line('');
        $this->line('  no provisioning · no provider call · no change to websites · custody recorded as adopted');
        $this->line('');

        if ($toCreate === 0) {
            $this->info('Estate already reconciled. Nothing to do.');

            return self::SUCCESS;
        }

        $token = substr(hash('sha256', 'reconcile-hosting|' . implode(',', array_column($plan, 'website_id'))), 0, 24);

        if ($dryRun) {
            $this->info("DRY RUN — nothing written. Confirmation token: {$token}");

            return self::SUCCESS;
        }

        if (! hash_equals($token, (string) ($this->option('confirm') ?? ''))) {
            $this->error('REFUSED: confirmation token does not match this plan. Re-run with --dry-run.');

            return self::FAILURE;
        }

        // ── execute ─────────────────────────────────────────────────────────
        $created = ['accounts' => 0, 'assets' => 0, 'sites' => 0, 'relationships' => 0];

        foreach ($plan as $p) {
            if ($p['already_reconciled']) {
                continue;
            }

            DB::transaction(function () use ($p, &$created) {
                $accountId = $p['account_id'];

                if ($accountId === null) {
                    $accountAssetId = $this->createAsset(
                        $p['workspace_id'],
                        'hosting_account',
                        self::PLATFORM_ACCOUNT_NAME,
                        null,
                        null,
                        'The shared LevelUp platform that serves Builder websites. Adopted by reconciliation; not provisioned by INFRA888.'
                    );
                    $created['assets']++;

                    $accountId = DB::table('infra_hosting_accounts')->insertGetId([
                        'asset_id' => $accountAssetId,
                        'workspace_id' => $p['workspace_id'],
                        'name' => self::PLATFORM_ACCOUNT_NAME,
                        'state' => 'active',
                        'environment' => 'production',
                        'health_state' => 'unknown',
                        'backup_state' => 'unknown',
                        'metadata_json' => json_encode([
                            'adopted' => true,
                            'adopted_by' => 'infra:reconcile-hosting',
                            'adopted_at' => now()->toDateTimeString(),
                            'provisioned_by_infra888' => false,
                            'provisioning_source' => 'builder_platform',
                            'custody' => 'managed',
                            'note' => 'Adopted during INFRA888 S1 reconciliation. INFRA888 records and monitors '
                                . 'this hosting; it did not provision it and holds no provider binding for it.',
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created['accounts']++;
                }

                $siteAssetId = $this->createAsset(
                    $p['workspace_id'],
                    'hosted_site',
                    $p['name'],
                    'website',
                    $p['website_id'],
                    'Builder website adopted into the estate by S1 reconciliation.'
                );
                $created['assets']++;

                DB::table('infra_hosted_sites')->insert([
                    'asset_id' => $siteAssetId,
                    'workspace_id' => $p['workspace_id'],
                    'hosting_account_id' => $accountId,
                    'website_id' => $p['website_id'],
                    'name' => $p['name'],
                    'primary_hostname' => $p['hostname'],
                    'state' => 'active',
                    // Honest: we have not verified SSL or deployment during reconciliation.
                    'ssl_state' => 'unknown',
                    'deployment_state' => 'deployed',
                    'metadata_json' => json_encode([
                        'adopted' => true,
                        'adopted_by' => 'infra:reconcile-hosting',
                        'adopted_at' => now()->toDateTimeString(),
                        'provisioned_by_infra888' => false,
                        'provisioning_source' => 'builder_platform',
                        'custody' => 'managed',
                        'source_website_id' => $p['website_id'],
                        'custom_domain' => $p['custom_domain'],
                        'health_note' => 'Health and SSL are unverified at adoption; monitoring establishes truth.',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created['sites']++;
            });
        }

        $after = $this->counts();

        $this->line('');
        $this->line('RESULT');
        foreach ($before as $k => $v) {
            $this->line(sprintf('  %-28s %d -> %d  (%+d)', $k, $v, $after[$k], $after[$k] - $v));
        }
        $this->line('');
        $this->info(sprintf('Adopted %d site(s), %d hosting account(s), %d asset(s).',
            $created['sites'], $created['accounts'], $created['assets']));

        return self::SUCCESS;
    }

    /** Resolve the hostname a site actually answers on. */
    private function hostname(object $site): ?string
    {
        foreach ([$site->custom_domain ?? null, $site->domain ?? null, $site->subdomain ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function createAsset(int $wsId, string $type, string $name, ?string $sourceType, ?int $sourceId, string $note): int
    {
        return DB::table('infra_assets')->insertGetId([
            'asset_uid' => (string) Str::uuid(),
            'workspace_id' => $wsId,
            'asset_type' => $type,
            'name' => $name,
            // Adopted, not provisioned by us. Recording otherwise would be a fiction.
            'management_mode' => 'adopted',
            'lifecycle_state' => 'active',
            'health_state' => 'unknown',
            'risk_state' => 'ok',
            'provider_id' => null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'metadata_json' => json_encode([
                'adopted_by' => 'infra:reconcile-hosting',
                'adopted_at' => now()->toDateTimeString(),
                'custody' => 'managed',
                'provisioned_by_infra888' => false,
                'note' => $note,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return [
            'infra_assets' => (int) DB::table('infra_assets')->whereNull('deleted_at')->count(),
            'infra_hosting_accounts' => (int) DB::table('infra_hosting_accounts')->whereNull('deleted_at')->count(),
            'infra_hosted_sites' => (int) DB::table('infra_hosted_sites')->whereNull('deleted_at')->count(),
            'websites (source, untouched)' => (int) DB::table('websites')->whereNull('deleted_at')->count(),
        ];
    }
}
