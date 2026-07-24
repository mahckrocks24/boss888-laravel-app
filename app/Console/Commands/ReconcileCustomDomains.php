<?php

namespace App\Console\Commands;

use App\Core\Tenancy\WorkspaceContext;
use App\Models\CustomDomain;
use App\Services\CustomDomainService;
use App\Services\Domains\Providers\CloudflareSaasProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile in-flight custom domains against the provider. Recovers partial
 * failures: syncs non-terminal hostnames, retries compensating deletes for
 * orphaned provider hostnames, and ages-out never-created rows. Safe to run
 * repeatedly (idempotent). No-op unless the SaaS gate is open.
 */
class ReconcileCustomDomains extends Command
{
    protected $signature = 'domains:reconcile {--limit=200}';
    protected $description = 'Sync in-flight Cloudflare-for-SaaS custom domains and recover partial failures';

    public function handle(): int
    {
        if (!config('cloudflare.saas_enabled', false)) {
            $this->info('SaaS gate closed — nothing to reconcile.');
            return self::SUCCESS;
        }

        $provider = new CloudflareSaasProvider();
        $svc = new CustomDomainService($provider);

        // Cross-tenant sweep: bypass the workspace scope to enumerate, then act
        // inside each row's own WorkspaceContext.
        $rows = CustomDomain::withoutGlobalScopes()
            ->whereIn('state', [
                CustomDomain::STATE_PENDING_SETUP, CustomDomain::STATE_AWAITING_DNS,
                CustomDomain::STATE_VALIDATING, CustomDomain::STATE_SSL_PENDING,
                CustomDomain::STATE_FAILED,
            ])
            ->orderBy('last_checked_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $synced = 0; $recovered = 0; $aged = 0;

        foreach ($rows as $row) {
            WorkspaceContext::run((int) $row->workspace_id, function () use ($row, $provider, $svc, &$synced, &$recovered, &$aged) {
                try {
                    // Orphan recovery: provider hostname created but local persist failed.
                    if (str_starts_with((string) $row->last_error, 'orphaned_provider_hostname:')) {
                        $pid = substr($row->last_error, strlen('orphaned_provider_hostname:'));
                        if ($pid && $provider->deleteCustomHostname($pid)) {
                            $row->state = CustomDomain::STATE_DISCONNECTED;
                            $row->disconnected_at = now();
                            $row->last_error = null;
                            $row->save();
                            $recovered++;
                        }
                        return;
                    }

                    // Age out rows that never reached the provider.
                    if (!$row->provider_hostname_id) {
                        if ($row->created_at && $row->created_at->lt(now()->subHours(24))) {
                            $row->state = CustomDomain::STATE_FAILED;
                            $row->last_error = 'never_created';
                            $row->save();
                            $aged++;
                        }
                        return;
                    }

                    // Normal sync via the service (re-uses its state mapping).
                    $svc->verify((int) $row->website_id);
                    $synced++;
                } catch (\Throwable $e) {
                    Log::warning('domains.reconcile_error', [
                        'custom_domain_id' => $row->id,
                        'workspace_id'     => $row->workspace_id,
                        'error'            => $e->getMessage(),
                    ]);
                }
            });
        }

        $this->info("Reconciled: synced={$synced} recovered={$recovered} aged={$aged} scanned={$rows->count()}");
        return self::SUCCESS;
    }
}
