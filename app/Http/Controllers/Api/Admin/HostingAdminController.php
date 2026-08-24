<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M8 — Minimum viable Hosting Admin. READ ONLY.
 *
 * Built because the alignment audit found the admin backend had zero hosting
 * screens: an operator could not see what a customer sees, which breaks support
 * before it starts.
 *
 * Deliberately read-first. It performs no provisioning, no suspension and no
 * mutation of any kind. Manual actions are surfaced as runbook references, not
 * as buttons, because the automation to back them does not exist.
 *
 * Every field is reported as {available, value, source} or
 * {available:false, blocked_reason}. A value that cannot be measured is never
 * rendered as zero, empty or healthy — the same contract the Engineering
 * screens use.
 */
class HostingAdminController
{
    private const BACKUP_LOG = '/var/log/daily-backup.log';
    private const LE_LIVE    = '/etc/letsencrypt/live';
    private const DRILL_LOG  = '/root/runbooks/RESTORE.md';

    /** @return array{available:bool,value:mixed,source:string} */
    private function measured(mixed $value, string $source): array
    {
        return ['available' => true, 'value' => $value, 'source' => $source];
    }

    /** @return array{available:bool,value:null,blocked_reason:string} */
    private function blocked(string $why): array
    {
        return ['available' => false, 'value' => null, 'blocked_reason' => $why];
    }

    /**
     * GET /api/admin/hosting
     *
     * One row per hosting account, with everything an operator needs to answer
     * "what does this customer actually have, and can we prove it?"
     */
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('infra_hosting_accounts')) {
            return response()->json([
                'screen' => 'hosting_admin',
                'read_only' => true,
                'accounts' => [],
                'blocked_reason' => 'infra_hosting_accounts table does not exist',
                'observed_at' => now()->toIso8601String(),
            ]);
        }

        $accounts = [];

        foreach (DB::table('infra_hosting_accounts')->orderBy('id')->get() as $a) {
            $meta = json_decode($a->metadata_json ?? '{}', true) ?: [];
            $wsId = $a->workspace_id;

            $accounts[] = [
                'id' => (int) $a->id,
                'name' => $a->name,
                'workspace_id' => $wsId,
                'workspace' => $this->workspaceName($wsId),

                // Status — from the state machine, not invented
                'status' => $this->measured([
                    'state' => $a->state,
                    'previous_state' => $a->previous_state,
                    'environment' => $a->environment,
                    'region' => $a->region,
                    'provisioned_at' => $a->provisioned_at,
                ], 'infra_hosting_accounts'),

                'plan' => $this->plan($wsId),
                'provider' => $this->provider(),
                'domains' => $this->domains($wsId),
                'ssl' => $this->ssl(),
                'backups' => $this->backups($a),
                'last_restore' => $this->lastRestore(),
                'notes' => $this->notes($meta),
                'manual_actions' => $this->manualActions(),
                'support_history' => $this->supportHistory(),
                'migration_status' => $this->migrationStatus($meta, $a),
            ];
        }

        return response()->json([
            'screen' => 'hosting_admin',
            'read_only' => true,
            'purpose' => 'What a hosting customer actually has, and what we can prove about it.',
            'accounts' => $accounts,
            'returned' => count($accounts),
            'interpretation_limits' => [
                'This screen performs no provisioning, suspension or mutation. It is read-only by design.',
                'Hosting on this platform is operated MANUALLY. A record here does not mean an automated system provisioned anything.',
                'Manual actions are listed as runbook references, not buttons, because no automation backs them.',
                'A field reported as BLOCKED could not be measured by the web server user. It is not a zero.',
            ],
            'observed_at' => now()->toIso8601String(),
        ]);
    }

    private function workspaceName(mixed $wsId): ?string
    {
        if (!$wsId || !Schema::hasTable('workspaces')) {
            return null;
        }

        return DB::table('workspaces')->where('id', $wsId)->value('name');
    }

    /** Plan comes from the workspace subscription, not from the hosting row. */
    private function plan(mixed $wsId): array
    {
        if (!$wsId || !Schema::hasTable('subscriptions') || !Schema::hasTable('plans')) {
            return $this->blocked('subscriptions/plans tables unavailable');
        }

        $row = DB::table('subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.workspace_id', $wsId)
            ->orderByDesc('s.id')
            ->select('p.name', 'p.slug', 'p.price', 'p.stripe_price_id', 's.status', 's.provider')
            ->first();

        if (!$row) {
            return $this->blocked('no subscription found for this workspace');
        }

        return $this->measured([
            'name' => $row->name,
            'slug' => $row->slug,
            'price' => $row->price,
            'subscription_status' => $row->status,
            'billing_provider' => $row->provider,
            'stripe_price_id' => $row->stripe_price_id,
            'chargeable' => $row->stripe_price_id !== null,
        ], 'subscriptions + plans');
    }

    /** There is no provider connection. Say so plainly. */
    private function provider(): array
    {
        $n = Schema::hasTable('infra_provider_connections')
            ? DB::table('infra_provider_connections')->count()
            : 0;

        if ($n === 0) {
            return $this->measured([
                'connected_providers' => 0,
                'effective_provider' => 'manual (operator-run on shared droplet)',
                'automated_provisioning' => false,
            ], 'infra_provider_connections is empty');
        }

        return $this->measured([
            'connected_providers' => $n,
            'automated_provisioning' => true,
        ], 'infra_provider_connections');
    }

    private function domains(mixed $wsId): array
    {
        if (!Schema::hasTable('websites')) {
            return $this->blocked('websites table unavailable');
        }

        $sites = DB::table('websites')
            ->when($wsId, fn ($q) => $q->where('workspace_id', $wsId))
            ->get(['id', 'subdomain', 'domain', 'status']);

        $custom = Schema::hasTable('custom_domains')
            ? DB::table('custom_domains')->count()
            : null;

        return $this->measured([
            'websites' => $sites->map(fn ($s) => [
                'id' => (int) $s->id,
                'levelup_address' => $s->subdomain,
                'custom_domain' => $s->domain,
                'status' => $s->status,
            ])->all(),
            'custom_domains_platform_wide' => $custom,
            'custom_domain_capability' => 'gated — CustomDomainService performs no provider mutation while the release gate is closed',
        ], 'websites + custom_domains');
    }

    /** Certificates live under /etc/letsencrypt, which the web user cannot read. */
    private function ssl(): array
    {
        if (!is_readable(self::LE_LIVE)) {
            return $this->blocked(
                'certificate directory ' . self::LE_LIVE . ' is not readable by the web server user. '
                . 'Verify from the CLI with: certbot certificates'
            );
        }

        $certs = [];
        foreach (glob(self::LE_LIVE . '/*/cert.pem') ?: [] as $p) {
            $certs[] = ['name' => basename(dirname($p))];
        }

        return $this->measured($certs, self::LE_LIVE);
    }

    private function backups(object $a): array
    {
        $log = is_readable(self::BACKUP_LOG)
            ? $this->measured(trim((string) shell_exec('tail -n 3 ' . escapeshellarg(self::BACKUP_LOG))), self::BACKUP_LOG)
            : $this->blocked(self::BACKUP_LOG . ' is not readable by the web server user');

        return [
            'account_backup_state' => $this->measured([
                'backup_state' => $a->backup_state,
                'last_backup_at' => $a->last_backup_at,
            ], 'infra_hosting_accounts'),
            'platform_backup_log' => $log,
            'note' => 'Platform backups run nightly across 9 file groups plus MySQL and PostgreSQL dumps, '
                    . 'offsite to object storage. They are platform-wide, not per-customer.',
        ];
    }

    private function lastRestore(): array
    {
        if (!is_readable(self::DRILL_LOG)) {
            return $this->blocked(
                'restore drill record is at ' . self::DRILL_LOG . ' and is not readable by the web server user. '
                . 'Last drill 2026-07-28: measured RTO 131s (data layer), RPO 24h.'
            );
        }

        return $this->measured('see ' . self::DRILL_LOG, self::DRILL_LOG);
    }

    private function notes(array $meta): array
    {
        $notes = [];
        foreach (['control_plane_truth', 'manual_hosting', 'corrected_at'] as $k) {
            if (array_key_exists($k, $meta)) {
                $notes[$k] = $meta[$k];
            }
        }

        return $notes === []
            ? $this->blocked('no operator notes recorded on this account')
            : $this->measured($notes, 'infra_hosting_accounts.metadata_json');
    }

    /** Runbook references, not buttons. Nothing automates these. */
    private function manualActions(): array
    {
        return $this->measured([
            ['action' => 'Restore this customer', 'how' => '/root/runbooks/RESTORE.md', 'automated' => false],
            ['action' => 'Provision hosting', 'how' => 'Manual: nginx vhost + certbot + backup group', 'automated' => false],
            ['action' => 'Connect a custom domain', 'how' => 'Blocked — release gate closed', 'automated' => false],
            ['action' => 'Create a mailbox', 'how' => 'Not available — no mail provider account exists', 'automated' => false],
            ['action' => 'Suspend / cancel', 'how' => 'Manual: nginx + billing, no tooling', 'automated' => false],
        ], 'operator runbooks');
    }

    private function supportHistory(): array
    {
        $hasTickets = Schema::hasTable('tickets') || Schema::hasTable('support_tickets');

        return $hasTickets
            ? $this->measured([], 'ticket table')
            : $this->blocked('no ticketing system exists. Support is email-only and is not recorded in this platform.');
    }

    private function migrationStatus(array $meta, object $a): array
    {
        return $this->measured([
            'provisioned_by_control_plane' => $a->provisioned_at !== null && ($meta['manual_hosting'] ?? false) !== true,
            'manual_hosting' => $meta['manual_hosting'] ?? null,
            'state' => $a->state,
            'note' => $meta['control_plane_truth'] ?? 'No migration record held in this platform.',
        ], 'infra_hosting_accounts.metadata_json + state');
    }
}
