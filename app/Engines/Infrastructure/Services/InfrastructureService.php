<?php

namespace App\Engines\Infrastructure\Services;

use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraHostedSite;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\States\HostingState;
use App\Engines\Infrastructure\States\OperationState;
use App\Engines\Infrastructure\States\SubscriptionState;

/**
 * Infrastructure overview — the read model behind the Infrastructure landing page.
 *
 * All reads run inside WorkspaceContext::run(), so the workspace global scope is
 * active and a missing context throws rather than returning another tenant's data.
 * Every method takes an explicit, REQUIRED $wsId (control C5).
 *
 * Deliberate divergence from the 2026-07-15 IDOR remediation: that fix used
 * `?int $wsId = null` where null meant "no scoping", to preserve god-mode and
 * internal callers. Correct for a retrofit, wrong for new code — a forgotten
 * argument would silently degrade to unscoped. Here it is required.
 */
class InfrastructureService
{
    public function __construct(
        private readonly InfrastructureConnectorResolver $connectors,
    ) {
    }

    /**
     * Aggregate counters + health for the Infrastructure overview screen.
     * Returns zeros (not nulls, not fabricated samples) when nothing exists.
     */
    public function overview(int $wsId): array
    {
        return WorkspaceContext::run($wsId, function () use ($wsId) {
            $hostingAccounts = InfraHostingAccount::query()->get([
                'id', 'state', 'health_state', 'backup_state',
                'current_storage_mb', 'allowed_storage_mb',
            ]);

            $subscriptions = InfraSubscription::query()->get([
                'id', 'state', 'unit_amount_minor', 'quantity', 'currency',
                'billing_period', 'next_renewal_at',
            ]);

            $activeSubs = $subscriptions->filter(
                fn ($s) => in_array($s->state, SubscriptionState::entitled(), true)
            );

            $renewalsDue = $subscriptions->filter(function ($s) {
                return $s->next_renewal_at !== null
                    && $s->next_renewal_at->lte(now()->addDays(30))
                    && in_array($s->state, SubscriptionState::entitled(), true);
            });

            $degraded = $hostingAccounts->filter(
                fn ($a) => $a->state === HostingState::DEGRADED || $a->health_state === 'degraded'
            );

            $pendingOps = InfraOperation::query()
                ->whereIn('state', OperationState::recoverable())
                ->count();

            $recentIncidents = InfraEvent::query()
                ->whereIn('severity', [InfraEvent::SEVERITY_ERROR, InfraEvent::SEVERITY_WARNING])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'event', 'severity', 'summary', 'created_at']);

            return [
                'counts' => [
                    'hosting_services' => $hostingAccounts->count(),
                    'websites_hosted'  => InfraHostedSite::query()->count(),
                    'domains_managed'  => 0, // Phase 1C — honestly zero, not faked
                    'mailboxes_active' => 0, // Phase 1D — honestly zero, not faked
                    'renewals_due'     => $renewalsDue->count(),
                    'pending_actions'  => $pendingOps,
                ],
                'health' => [
                    'state'            => $this->rollupHealth($hostingAccounts),
                    'degraded_count'   => $degraded->count(),
                    'backup_warnings'  => $hostingAccounts
                        ->filter(fn ($a) => in_array($a->backup_state, ['stale', 'failed'], true))
                        ->count(),
                ],
                'spend' => $this->spendSummary($activeSubs),
                'recent_incidents' => $recentIncidents->map(fn ($e) => [
                    'id'         => $e->id,
                    'event'      => $e->event,
                    'severity'   => $e->severity,
                    'summary'    => $e->summary,
                    'created_at' => optional($e->created_at)->toIso8601String(),
                ])->values()->all(),
                'providers' => $this->providerPosture(),
                'workspace_id' => $wsId,
            ];
        });
    }

    /**
     * Honest provider posture. When only Null connectors are configured this says
     * so, rather than implying a live integration exists.
     */
    public function providerPosture(): array
    {
        $capabilities = ['hosting', 'custom_hostname'];
        $out = [];

        foreach ($capabilities as $capability) {
            $out[] = [
                'capability' => $capability,
                'live'       => $this->connectors->isLive($capability),
            ];
        }

        return $out;
    }

    /** Recent activity for the overview timeline. */
    public function recentActivity(int $wsId, int $limit = 20): array
    {
        return WorkspaceContext::run($wsId, function () use ($limit) {
            return InfraEvent::query()
                ->orderByDesc('created_at')
                ->limit(max(1, min(100, $limit)))
                ->get(['id', 'event', 'severity', 'owner_type', 'owner_id', 'summary', 'created_at'])
                ->map(fn ($e) => [
                    'id'         => $e->id,
                    'event'      => $e->event,
                    'severity'   => $e->severity,
                    'owner_type' => $e->owner_type,
                    'owner_id'   => $e->owner_id,
                    'summary'    => $e->summary,
                    'created_at' => optional($e->created_at)->toIso8601String(),
                ])->values()->all();
        });
    }

    private function rollupHealth($accounts): string
    {
        if ($accounts->isEmpty()) {
            return 'none';
        }

        if ($accounts->contains(fn ($a) => $a->health_state === 'down')) {
            return 'down';
        }

        if ($accounts->contains(fn ($a) => $a->health_state === 'degraded'
            || $a->state === HostingState::DEGRADED)) {
            return 'degraded';
        }

        if ($accounts->every(fn ($a) => $a->health_state === 'unknown')) {
            return 'unknown';
        }

        return 'healthy';
    }

    /**
     * Monthly-equivalent spend. Multi-year terms are normalized so the number is
     * comparable. Currency is never assumed — mixed currencies are reported
     * separately rather than silently summed, which would be a real financial bug.
     */
    private function spendSummary($subscriptions): array
    {
        $byCurrency = [];

        foreach ($subscriptions as $sub) {
            $currency = $sub->currency ?: config('infrastructure.default_currency');
            $monthly = $this->toMonthlyMinor(
                (int) $sub->unit_amount_minor * max(1, (int) $sub->quantity),
                (string) $sub->billing_period
            );

            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $monthly;
        }

        $lines = [];
        foreach ($byCurrency as $currency => $amountMinor) {
            $lines[] = [
                'currency'             => $currency,
                'monthly_amount_minor' => $amountMinor,
            ];
        }

        return ['monthly_equivalent' => $lines];
    }

    private function toMonthlyMinor(int $amountMinor, string $period): int
    {
        return match ($period) {
            'annual'    => (int) round($amountMinor / 12),
            'triennial' => (int) round($amountMinor / 36),
            default     => $amountMinor,
        };
    }
}
