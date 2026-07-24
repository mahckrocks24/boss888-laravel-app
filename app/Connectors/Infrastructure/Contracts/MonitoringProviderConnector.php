<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Monitoring capability contract (Phase 2B-1).
 *
 * `CAPABILITY_MONITORING` was declared in Phase 1A with nothing behind it, and
 * the Phase 0 audit found the platform has NO uptime monitoring and NO health
 * endpoint in code at all — which is why the PTAA runbook could not honestly
 * commit to an SLA.
 *
 * PROVIDER-NEUTRAL BY CONSTRUCTION
 * A monitoring provider is anything that observes and reports: UptimeRobot,
 * Pingdom, a provider's built-in checks, or our own scheduler. Nothing here
 * assumes a hosted third party.
 *
 * `getIncidents()` is separate from `getStatus()` on purpose. Current state
 * answers "is it up right now"; incident history answers "has it been reliable"
 * — the second is what an SLA claim rests on, and it cannot be reconstructed
 * from repeated point-in-time checks after the fact.
 */
interface MonitoringProviderConnector extends InfrastructureConnector
{
    /**
     * @param array{type:string,target:string,interval_seconds?:int,
     *              expected_status?:int,timeout_seconds?:int} $check
     */
    public function createCheck(array $check, string $idempotencyKey): ProviderResult;

    public function updateCheck(string $checkId, array $check, string $idempotencyKey): ProviderResult;

    public function deleteCheck(string $checkId, string $idempotencyKey): ProviderResult;

    /**
     * @return ProviderResult data: ['status'=>'up'|'down'|'degraded'|'unknown',
     *                               'last_checked_at'=>?string,'response_ms'=>?int]
     */
    public function getStatus(string $checkId): ProviderResult;

    /**
     * @return ProviderResult data: ['incidents'=>[['started_at'=>string,'resolved_at'=>?string,
     *                               'duration_seconds'=>?int,'cause'=>?string], ...]]
     */
    public function getIncidents(string $checkId, ?string $since = null): ProviderResult;

    /**
     * Uptime over a window — the number an SLA is actually written against.
     *
     * @return ProviderResult data: ['uptime_pct'=>float,'window_start'=>string,
     *                               'window_end'=>string,'checks'=>int,'failures'=>int]
     */
    public function getUptime(string $checkId, string $windowStart, string $windowEnd): ProviderResult;
}
