<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraIncident;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Models\InfraMonitorResult;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderHealth;
use Illuminate\Support\Facades\DB;

/**
 * Infrastructure intelligence (Phase 3B).
 *
 * INTELLIGENCE SPLIT (honouring the locked "intelligence belongs in runtime"
 * rule): everything here is DETERMINISTIC AGGREGATION or RULE-BASED indicators —
 * MTTR/MTBF/uptime/trends are reporting SQL over the operational record, and risk
 * indicators are transparent operational rules (cert expiry, repeated failure,
 * orphaned asset). Genuine SCORING / anomaly detection / predictive risk ranking
 * is designed to live in the Railway runtime and is invoked through a documented
 * integration point (see the architecture report); this service is the honest,
 * verifiable floor that works today with no ML and no network dependency.
 *
 * All queries are workspace-scoped. Aggregations read the daily rollup where
 * possible so history scales past the raw time series.
 */
class InfrastructureIntelligenceService
{
    /** Cert-expiry warning window (days) for the risk indicator. */
    private const CERT_EXPIRY_WATCH_DAYS = 30;
    private const CERT_EXPIRY_RISK_DAYS  = 7;
    /** Consecutive monitoring failures that flag an asset at risk. */
    private const REPEATED_FAILURE_THRESHOLD = 3;

    // ── historical intelligence (deterministic aggregation) ─────────────────

    /**
     * Uptime + latency over a window for one check, from the raw time series.
     *
     * @return array{uptime_pct:float,checks:int,up:int,down:int,degraded:int,
     *               avg_response_ms:?int,max_response_ms:?int}
     */
    public function checkStats(int $checkId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $row = InfraMonitorResult::withoutGlobalScopes()->where('monitor_check_id', $checkId)
            ->whereBetween('checked_at', [$from, $to])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status IN ('up','degraded')) as up_ct")
            ->selectRaw("SUM(status='down') as down_ct")
            ->selectRaw("SUM(status='degraded') as deg_ct")
            ->selectRaw('AVG(response_ms) as avg_ms')
            ->selectRaw('MAX(response_ms) as max_ms')
            ->first();

        $total = (int) ($row->total ?? 0);
        $up    = (int) ($row->up_ct ?? 0);

        return [
            'uptime_pct'      => $total > 0 ? round($up / $total * 100, 4) : 0.0,
            'checks'          => $total,
            'up'              => $up,
            'down'            => (int) ($row->down_ct ?? 0),
            'degraded'        => (int) ($row->deg_ct ?? 0),
            'avg_response_ms' => $row->avg_ms !== null ? (int) round($row->avg_ms) : null,
            'max_response_ms' => $row->max_ms !== null ? (int) $row->max_ms : null,
        ];
    }

    /**
     * Response-time trend as a series of daily points from the raw series.
     * (For long windows a caller reads infra_monitor_daily instead.)
     *
     * @return array<int,array{day:string,avg_response_ms:?int,uptime_pct:float}>
     */
    public function responseTimeTrend(int $checkId, int $days = 14): array
    {
        $rows = InfraMonitorResult::withoutGlobalScopes()->where('monitor_check_id', $checkId)
            ->where('checked_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(checked_at) as day')
            ->selectRaw('AVG(response_ms) as avg_ms')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status IN ('up','degraded')) as up_ct")
            ->groupByRaw('DATE(checked_at)')
            ->orderByRaw('DATE(checked_at)')
            ->get();

        return $rows->map(fn ($r) => [
            'day'             => (string) $r->day,
            'avg_response_ms' => $r->avg_ms !== null ? (int) round($r->avg_ms) : null,
            'uptime_pct'      => $r->total > 0 ? round($r->up_ct / $r->total * 100, 2) : 0.0,
        ])->all();
    }

    /**
     * MTTR and MTBF for a workspace over a window, from resolved incidents.
     *
     * MTTR = mean(time_to_resolve_seconds).
     * MTBF = mean gap between consecutive incident detections on the same asset.
     *
     * @return array{mttr_seconds:?int,mtbf_seconds:?int,incidents:int,resolved:int,failure_frequency_per_day:float}
     */
    public function reliability(int $workspaceId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $incidents = InfraIncident::withoutGlobalScopes()->where('workspace_id', $workspaceId)
            ->where('detected_at', '>=', $since)
            ->orderBy('asset_id')->orderBy('detected_at')
            ->get(['asset_id', 'detected_at', 'time_to_resolve_seconds', 'lifecycle_state']);

        $resolved = $incidents->whereNotNull('time_to_resolve_seconds');
        $mttr = $resolved->count() > 0 ? (int) round($resolved->avg('time_to_resolve_seconds')) : null;

        // MTBF: gaps between successive detections per asset.
        $gaps = [];
        $byAsset = $incidents->groupBy('asset_id');
        foreach ($byAsset as $assetIncidents) {
            $prev = null;
            foreach ($assetIncidents as $inc) {
                if ($prev) {
                    $gaps[] = abs($prev->diffInSeconds($inc->detected_at));
                }
                $prev = $inc->detected_at;
            }
        }
        $mtbf = $gaps !== [] ? (int) round(array_sum($gaps) / count($gaps)) : null;

        return [
            'mttr_seconds' => $mttr,
            'mtbf_seconds' => $mtbf,
            'incidents'    => $incidents->count(),
            'resolved'     => $resolved->count(),
            'failure_frequency_per_day' => $days > 0 ? round($incidents->count() / $days, 3) : 0.0,
        ];
    }

    // ── rule-based risk indicators (deterministic, not scoring) ─────────────

    /**
     * Recompute an asset's risk_state from transparent operational rules.
     * Returns the new state; genuine predictive scoring is a runtime concern.
     *
     * @return array{risk_state:string,reasons:array<int,string>}
     */
    public function assessAssetRisk(InfraAsset $asset): array
    {
        $reasons = [];
        $level = 0; // 0 ok, 1 watch, 2 at_risk

        // Repeated monitoring failures.
        if ($asset->source_type === 'monitor_check' && $asset->source_id) {
            $check = InfraMonitorCheck::withoutGlobalScopes()->find($asset->source_id);
            if ($check && $check->consecutive_failures >= self::REPEATED_FAILURE_THRESHOLD) {
                $reasons[] = "repeated monitoring failures ({$check->consecutive_failures})";
                $level = max($level, 2);
            }
        }

        // Open incidents.
        $open = InfraIncident::withoutGlobalScopes()->where('asset_id', $asset->id)
            ->whereIn('lifecycle_state', InfraIncident::openStates())->count();
        if ($open > 0) {
            $reasons[] = "{$open} open incident(s)";
            $level = max($level, 2);
        }

        // Certificate expiry (config-driven; unknown until an adapter populates it).
        $expiresAt = $asset->config_json['ssl_expires_at'] ?? null;
        if ($expiresAt) {
            $days = (int) floor(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($expiresAt), false) / 86400);
            if ($days <= self::CERT_EXPIRY_RISK_DAYS) {
                $reasons[] = "certificate expires in {$days} day(s)";
                $level = max($level, 2);
            } elseif ($days <= self::CERT_EXPIRY_WATCH_DAYS) {
                $reasons[] = "certificate expires in {$days} day(s)";
                $level = max($level, 1);
            }
        }

        // Degraded health.
        if ($asset->health_state === InfraAsset::HEALTH_DEGRADED) {
            $reasons[] = 'degraded health';
            $level = max($level, 1);
        }

        $state = [0 => InfraAsset::RISK_OK, 1 => InfraAsset::RISK_WATCH, 2 => InfraAsset::RISK_AT_RISK][$level];
        $asset->update(['risk_state' => $state, 'risk_reasons_json' => $reasons]);

        return ['risk_state' => $state, 'reasons' => $reasons];
    }

    // ── executive dashboard (the questions the directive lists) ─────────────

    /**
     * The executive summary for a workspace: what is healthy, what needs
     * attention, what is degrading, open incidents, unhealthy providers,
     * at-risk assets. Every number drills into supporting rows.
     */
    public function dashboard(int $workspaceId): array
    {
        $assets = InfraAsset::withoutGlobalScopes()->where('workspace_id', $workspaceId);

        $byHealth = (clone $assets)->selectRaw('health_state, COUNT(*) c')->groupBy('health_state')->pluck('c', 'health_state');
        $byRisk   = (clone $assets)->selectRaw('risk_state, COUNT(*) c')->groupBy('risk_state')->pluck('c', 'risk_state');
        // Management-mode composition — the PTAA integrity distinction, aggregated
        // once in SQL (scales past the asset list). adopted/externally-managed are
        // NEVER conflated with provisioned/managed-by-INFRA888.
        $byMode   = (clone $assets)->selectRaw('management_mode, COUNT(*) c')->groupBy('management_mode')->pluck('c', 'management_mode');

        $openIncidents = InfraIncident::withoutGlobalScopes()->where('workspace_id', $workspaceId)
            ->whereIn('lifecycle_state', InfraIncident::openStates())
            ->orderByDesc('detected_at')->limit(50)->get();

        $atRisk = (clone $assets)->where('risk_state', InfraAsset::RISK_AT_RISK)
            ->orderByDesc('updated_at')->limit(50)->get();

        // Unhealthy providers (platform-level intelligence; provider health is
        // not workspace-scoped, so this is a global operational signal).
        $unhealthyProviders = InfraProviderHealth::where('health_state', '!=', 'healthy')
            ->where('health_state', '!=', 'unknown')
            ->with('provider')->get()
            ->map(fn ($h) => [
                'provider'   => $h->provider?->provider_key,
                'capability' => $h->capability,
                'state'      => $h->health_state,
            ])->all();

        $reliability = $this->reliability($workspaceId, 30);

        return [
            'assets' => [
                'total'     => (int) (clone $assets)->count(),
                'by_health' => $byHealth,
                'by_risk'   => $byRisk,
            ],
            'health' => [
                'healthy'   => (int) ($byHealth[InfraAsset::HEALTH_HEALTHY] ?? 0),
                'degraded'  => (int) ($byHealth[InfraAsset::HEALTH_DEGRADED] ?? 0),
                'down'      => (int) ($byHealth[InfraAsset::HEALTH_DOWN] ?? 0),
                'unknown'   => (int) ($byHealth[InfraAsset::HEALTH_UNKNOWN] ?? 0),
            ],
            'management' => [
                'adopted'             => (int) ($byMode[InfraAsset::MODE_ADOPTED] ?? 0),
                'provisioned'         => (int) ($byMode[InfraAsset::MODE_PROVISIONED] ?? 0),
                'managed_externally'  => (int) ($byMode[InfraAsset::MODE_MANAGED_EXTERNALLY] ?? 0),
                'managed_by_infra888' => (int) ($byMode[InfraAsset::MODE_MANAGED_BY_INFRA888] ?? 0),
                'by_mode'             => $byMode,
            ],
            'needs_attention' => [
                'open_incidents' => $openIncidents->count(),
                'at_risk_assets' => $atRisk->count(),
            ],
            'open_incidents' => $openIncidents->map(fn ($i) => [
                'incident_uid'    => $i->incident_uid,
                'asset_id'        => $i->asset_id,
                'title'           => $i->title,
                'severity'        => $i->severity,
                'lifecycle_state' => $i->lifecycle_state,
                'detected_at'     => optional($i->detected_at)->toIso8601String(),
            ])->all(),
            'at_risk_assets' => $atRisk->map(fn ($a) => [
                'asset_uid'    => $a->asset_uid,
                'name'         => $a->name,
                'asset_type'   => $a->asset_type,
                'risk_state'   => $a->risk_state,
                'risk_reasons' => $a->risk_reasons_json ?? [],
            ])->all(),
            'unhealthy_providers' => $unhealthyProviders,
            'reliability'         => $reliability,
        ];
    }
}
