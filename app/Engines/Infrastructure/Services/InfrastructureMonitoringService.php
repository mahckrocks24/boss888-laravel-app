<?php

namespace App\Engines\Infrastructure\Services;

use App\Connectors\Infrastructure\SelfHosted\SelfHostedMonitoringConnector;
use App\Engines\Infrastructure\Models\InfraMonitorCheck as MonitorCheckModel;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Models\InfraMonitorIncident;
use App\Engines\Infrastructure\Models\InfraMonitorResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Infrastructure monitoring — the brain (Phase 3A).
 *
 * Owns check configuration, the results time series, incident open/close, health
 * denormalisation and audit. The connector (hands) performs the HTTP probe; this
 * service decides what the observation MEANS and records it.
 *
 * TENANT ISOLATION: every check, result and incident is workspace-scoped. Running
 * a check enters that check WorkspaceContext, so PTAA monitoring can only ever
 * read and write PTAA rows — the same global-scope guarantee as every other
 * INFRA888 entity.
 *
 * NO CUSTOMER MUTATION: the only outward action is an HTTP GET. This service
 * cannot and does not change any customer infrastructure.
 */
class InfrastructureMonitoringService
{
    public function __construct(
        private readonly SelfHostedMonitoringConnector $connector,
        private readonly IncidentService $incidents,
    ) {
    }

    /** Create a monitoring check for a target, scoped to the current workspace. */
    public function createCheck(array $attrs): InfraMonitorCheck
    {
        $url = trim((string) ($attrs['target_url'] ?? ''));

        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('target_url must be a valid http(s) URL.');
        }

        return InfraMonitorCheck::create([
            'hosted_site_id'   => $attrs['hosted_site_id'] ?? null,
            'name'             => $attrs['name'] ?? $url,
            'check_type'       => str_starts_with($url, 'https') ? 'https' : 'http',
            'target_url'       => $url,
            'expected_status'  => (int) ($attrs['expected_status'] ?? 200),
            'interval_seconds' => (int) ($attrs['interval_seconds'] ?? 300),
            'timeout_seconds'  => (int) ($attrs['timeout_seconds'] ?? 15),
            'enabled'          => $attrs['enabled'] ?? true,
            'monitor_provider' => 'selfhosted',
            'metadata_json'    => $attrs['metadata_json'] ?? [],
        ]);
    }

    /**
     * Run one check: real HTTP probe -> record result -> open/close incident ->
     * update denormalised health. Returns the recorded result.
     */
    public function runCheck(InfraMonitorCheck $check, string $probeType = 'scheduled'): InfraMonitorResult
    {
        $obs = $this->connector->probe(
            $check->target_url,
            $check->expected_status,
            $check->timeout_seconds
        );

        return DB::transaction(function () use ($check, $obs, $probeType) {
            $result = InfraMonitorResult::create([
                'monitor_check_id' => $check->id,
                'status'           => $obs['status'],
                'http_code'        => $obs['http_code'],
                'response_ms'      => $obs['response_ms'],
                'error_code'       => $obs['error_code'],
                'error_summary'    => $obs['error_summary'],
                'probe_type'       => $probeType,
                'checked_at'       => now(),
            ]);

            $isDown  = $obs['status'] === InfraMonitorCheck::STATUS_DOWN;
            $wasDown = $check->isDown();

            $update = [
                'last_status'          => $obs['status'],
                'last_http_code'       => $obs['http_code'],
                'last_response_ms'     => $obs['response_ms'],
                'last_checked_at'      => now(),
                'consecutive_failures' => $isDown ? $check->consecutive_failures + 1 : 0,
            ];

            // CANONICAL PATH (Phase 3B): a check attached to an asset drives
            // first-class incidents through the one IncidentService that every
            // capability shares. A bare check keeps the legacy monitor_incidents
            // path (transition bridge), so nothing that predates the graph breaks.
            $canonical = $check->asset_id !== null;

            if ($isDown && !$wasDown) {
                $update['down_since'] = now();
                if ($canonical) {
                    $this->incidents->openForMonitorCheck($check, $obs['error_summary'] ?? $obs['error_code']);
                } else {
                    InfraMonitorIncident::create([
                        'monitor_check_id' => $check->id,
                        'started_at'       => now(),
                        'cause'            => $obs['error_summary'] ?? $obs['error_code'],
                        'failure_count'    => 1,
                        'state'            => InfraMonitorIncident::STATE_OPEN,
                    ]);
                }
            } elseif ($isDown && $wasDown) {
                if (!$canonical) {
                    InfraMonitorIncident::where('monitor_check_id', $check->id)
                        ->where('state', InfraMonitorIncident::STATE_OPEN)
                        ->increment('failure_count');
                }
                // Canonical incidents remain open; no per-observation bump needed.
            } elseif (!$isDown && $wasDown) {
                $update['down_since'] = null;
                if ($canonical) {
                    $this->incidents->resolveForMonitorCheck($check);
                } else {
                    $incident = InfraMonitorIncident::where('monitor_check_id', $check->id)
                        ->where('state', InfraMonitorIncident::STATE_OPEN)
                        ->orderByDesc('id')->first();
                    if ($incident) {
                        $incident->update([
                            'resolved_at'      => now(),
                            'duration_seconds' => max(0, (int) $incident->started_at->diffInSeconds(now())),
                            'state'            => InfraMonitorIncident::STATE_RESOLVED,
                        ]);
                    }
                }
            }

            $check->update($update);

            return $result;
        });
    }

    /**
     * Uptime over a window, computed from the results time series — the number an
     * SLA is written against.
     *
     * @return array{uptime_pct:float,checks:int,up:int,down:int,degraded:int}
     */
    public function uptime(InfraMonitorCheck $check, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = InfraMonitorResult::where('monitor_check_id', $check->id)
            ->whereBetween('checked_at', [$from, $to])
            ->get(['status']);

        $total    = $rows->count();
        $up       = $rows->whereIn('status', [InfraMonitorCheck::STATUS_UP, InfraMonitorCheck::STATUS_DEGRADED])->count();
        $down     = $rows->where('status', InfraMonitorCheck::STATUS_DOWN)->count();
        $degraded = $rows->where('status', InfraMonitorCheck::STATUS_DEGRADED)->count();

        return [
            'uptime_pct' => $total > 0 ? round($up / $total * 100, 4) : 0.0,
            'checks'     => $total,
            'up'         => $up,
            'down'       => $down,
            'degraded'   => $degraded,
        ];
    }

    /** Run every enabled check across all workspaces (for the scheduler). */
    public function runAllDue(): array
    {
        $counts = ['ran' => 0, 'up' => 0, 'down' => 0, 'degraded' => 0];

        // Cross-workspace read: bypass the tenant scope to enumerate, then enter
        // each check own context to run it (so writes stay tenant-isolated).
        $checks = InfraMonitorCheck::withoutGlobalScopes()
            ->where('enabled', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($checks as $check) {
            WorkspaceContext::run((int) $check->workspace_id, function () use ($check, &$counts) {
                $fresh = InfraMonitorCheck::find($check->id);
                if (!$fresh) {
                    return;
                }
                $result = $this->runCheck($fresh);
                $counts['ran']++;
                $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
            });
        }

        return $counts;
    }
}
