<?php

namespace App\Engines\Infrastructure\Services;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Hosting read/list service.
 *
 * Phase 1A is READ-ONLY by design. Provisioning, suspension and termination are
 * deliberately NOT implemented here yet: they are destructive provider operations
 * and must land together with the approval wiring and a proven connector, not
 * before. Shipping a provision() that half-works is exactly how the legacy
 * custom-domain path became unusable.
 *
 * $wsId is required on every method (control C5) and every query runs inside the
 * workspace context so the global scope applies.
 */
class HostingService
{
    /**
     * @return array{items:array,total:int}
     */
    public function list(int $wsId, array $filters = []): array
    {
        return WorkspaceContext::run($wsId, function () use ($filters) {
            $query = InfraHostingAccount::query()->with('subscription:id,state,next_renewal_at,currency');

            if (!empty($filters['state'])) {
                $query->where('state', $filters['state']);
            }

            if (!empty($filters['search'])) {
                $query->where('name', 'like', '%' . $filters['search'] . '%');
            }

            $accounts = $query->orderByDesc('created_at')->limit(100)->get();

            return [
                'items' => $accounts->map(fn ($a) => $this->present($a))->values()->all(),
                'total' => $accounts->count(),
            ];
        });
    }

    /**
     * Workspace-scoped resolution (control C3). Throws ModelNotFoundException on a
     * cross-tenant id, which BaseEngineController maps to 404 — never 403, because
     * 403 would confirm the resource exists in another workspace.
     */
    public function find(int $wsId, int $id): InfraHostingAccount
    {
        return WorkspaceContext::run($wsId, function () use ($id) {
            $account = InfraHostingAccount::query()->find($id);

            if (!$account) {
                throw (new ModelNotFoundException())->setModel(InfraHostingAccount::class, [$id]);
            }

            return $account;
        });
    }

    public function detail(int $wsId, int $id): array
    {
        $account = $this->find($wsId, $id);

        return WorkspaceContext::run($wsId, function () use ($account) {
            $account->load('sites');

            return $this->present($account) + [
                'sites' => $account->sites->map(fn ($s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'primary_hostname' => $s->primary_hostname,
                    'state'            => $s->state,
                    'ssl_state'        => $s->ssl_state,
                    'ssl_expires_at'   => optional($s->ssl_expires_at)->toIso8601String(),
                    'ssl_days_left'    => $s->sslDaysRemaining(),
                    'deployment_state' => $s->deployment_state,
                    'storage_mb'       => (int) $s->storage_mb,
                ])->values()->all(),
            ];
        });
    }

    /**
     * Presentation shape. Provider-specific payloads never appear here
     * (directive §7) — only normalized, customer-facing values.
     */
    private function present(InfraHostingAccount $a): array
    {
        return [
            'id'          => $a->id,
            'name'        => $a->name,
            'state'       => $a->state,
            'region'      => $a->region,
            'environment' => $a->environment,
            'sites_count' => $a->sites()->count(),
            'storage' => [
                'used_mb'    => (int) $a->current_storage_mb,
                'allowed_mb' => $a->allowed_storage_mb ? (int) $a->allowed_storage_mb : null,
                'percent'    => $a->storageUsagePercent(),
            ],
            'bandwidth' => [
                'used_mb'    => (int) $a->current_bandwidth_mb,
                'allowed_mb' => $a->allowed_bandwidth_mb ? (int) $a->allowed_bandwidth_mb : null,
                'percent'    => $a->bandwidthUsagePercent(),
            ],
            'backup' => [
                'state'          => $a->backup_state,
                'last_backup_at' => optional($a->last_backup_at)->toIso8601String(),
            ],
            'health' => [
                'state'      => $a->health_state,
                'checked_at' => optional($a->health_checked_at)->toIso8601String(),
            ],
            'renewal_at'     => optional($a->subscription?->next_renewal_at)->toIso8601String(),
            'provisioned_at' => optional($a->provisioned_at)->toIso8601String(),
        ];
    }
}
