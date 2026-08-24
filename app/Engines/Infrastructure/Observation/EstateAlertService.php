<?php

namespace App\Engines\Infrastructure\Observation;

use App\Models\CustomerDomain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S7 — HEALTH COMPUTATION AND ALERT LIFECYCLE.
 *
 * OBSERVATION ONLY. This class raises, acknowledges, suppresses, escalates and
 * recovers alerts, and computes health. It repairs nothing, renews nothing,
 * writes nothing to the registrar, and never touches desired state.
 *
 * Two rules do most of the work here, and both exist because the failure mode
 * of monitoring is false comfort:
 *
 *   1. HEALTH NEVER IMPROVES BECAUSE TIME PASSED. Only a fresh successful
 *      observation can raise it. A domain nobody has looked at decays to
 *      `unknown` — it does not stay `healthy` on the strength of a reading
 *      taken last month.
 *
 *   2. AN ALERT RECOVERS ONLY BY FRESH OBSERVATION. `recovered_by_observation_id`
 *      is mandatory. An alert can never close because it aged out, because
 *      nobody looked, or because the registrar went unreachable. Timing alerts
 *      out is how a real problem becomes an invisible one.
 */
class EstateAlertService
{
    // ── severity ladder ──────────────────────────────────────────────────────
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';
    public const INFO = 'info';

    // ── alert states ─────────────────────────────────────────────────────────
    public const OPEN = 'open';
    public const ACKNOWLEDGED = 'acknowledged';
    public const SUPPRESSED = 'suppressed';
    public const ESCALATED = 'escalated';
    public const RECOVERED = 'recovered';

    // ── health states, worst to best ─────────────────────────────────────────
    public const H_CRITICAL = 'critical';
    public const H_AT_RISK = 'at_risk';
    public const H_DEGRADED = 'degraded';
    public const H_UNKNOWN = 'unknown';
    public const H_HEALTHY = 'healthy';

    /** Minutes before an unacknowledged alert escalates, by severity. */
    private const ESCALATE_AFTER = [
        self::CRITICAL => 30,
        self::HIGH => 120,
        self::MEDIUM => 720,
        self::LOW => 2880,
        self::INFO => 0, // never escalates
    ];

    /** Suppression is always time-boxed. Permanent silence is how outages get missed. */
    public const MAX_SUPPRESSION_HOURS = 168; // 7 days

    public function __construct(private ?RegistrarObservationService $observer = null)
    {
        $this->observer = $observer ?: new RegistrarObservationService();
    }

    /** S6's taxonomy speaks critical/warning/info; the alert ladder is finer. */
    private function severityFor(string $driftClass): string
    {
        return match ($driftClass) {
            DomainDrift::EXPIRY_EARLIER,
            DomainDrift::CUSTODY_LOST,
            DomainDrift::ORPHANED_ESTATE => self::CRITICAL,

            DomainDrift::REGISTRAR_UNAVAILABLE,
            DomainDrift::NAMESERVER_DRIFT => self::HIGH,

            DomainDrift::EXPIRY_LATER,
            DomainDrift::LOCK_DRIFT,
            DomainDrift::NOT_MANAGED_BY_US,
            DomainDrift::STALE_OBSERVATION => self::MEDIUM,

            DomainDrift::AUTO_RENEW_DRIFT,
            DomainDrift::STATUS_DRIFT,
            DomainDrift::UNKNOWN => self::LOW,

            default => self::INFO,
        };
    }

    // ── ALERT LIFECYCLE ──────────────────────────────────────────────────────

    /**
     * Reconcile alerts against ONE observation.
     *
     * Raises what is newly true, re-arms what is still true, and recovers what
     * the observation proves is gone. An unreachable observation recovers
     * NOTHING — we learned nothing, so nothing may be closed.
     *
     * @param object $observation row from infra_domain_observations
     * @return array<string,int>
     */
    public function reconcileFromObservation(object $observation): array
    {
        $drift = (array) json_decode((string) $observation->drift_json, true);
        $seen = [];
        $raised = 0;
        $rearmed = 0;

        foreach ($drift as $d) {
            $class = (string) ($d['class'] ?? DomainDrift::UNKNOWN);
            $key = $this->key($observation->domain, $class);
            $seen[] = $key;

            $existing = DB::table('infra_estate_alerts')->where('alert_key', $key)->first();

            if ($existing === null) {
                $this->raise($observation, $class, $d);
                $raised++;

                continue;
            }

            $this->touch($existing, $d);
            $rearmed++;
        }

        // Recovery — ONLY from a successful observation.
        $recovered = 0;

        if ((bool) $observation->reachable) {
            $open = DB::table('infra_estate_alerts')
                ->where('domain', $observation->domain)
                ->where('state', '!=', self::RECOVERED)
                ->get();

            foreach ($open as $a) {
                if (in_array($a->alert_key, $seen, true)) {
                    continue;
                }

                // A stale-observation alert is only really gone once we have a
                // fresh reading — which is exactly what we are holding.
                $this->recover($a, (int) $observation->id);
                $recovered++;
            }
        }

        return ['raised' => $raised, 'rearmed' => $rearmed, 'recovered' => $recovered];
    }

    private function key(string $domain, string $driftClass): string
    {
        return substr(hash('sha256', strtolower($domain) . '|' . $driftClass), 0, 40);
    }

    /** @param array<string,mixed> $d */
    private function raise(object $obs, string $class, array $d): void
    {
        $sev = $this->severityFor($class);
        $escalateIn = self::ESCALATE_AFTER[$sev] ?? 0;

        DB::table('infra_estate_alerts')->insert([
            'workspace_id' => $obs->workspace_id,
            'customer_domain_id' => $obs->customer_domain_id,
            'domain' => $obs->domain,
            'alert_key' => $this->key($obs->domain, $class),
            'drift_class' => $class,
            'severity' => $sev,
            'state' => self::OPEN,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
            'next_escalation_at' => $escalateIn > 0 ? now()->addMinutes($escalateIn) : null,
            'detail_json' => json_encode($d, JSON_UNESCAPED_SLASHES),
            'history_json' => json_encode([[
                'at' => now()->toDateTimeString(), 'event' => 'raised', 'severity' => $sev,
                'observation_id' => $obs->id,
            ]], JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Still true. Bump the evidence; do not resurrect an acknowledgement. */
    private function touch(object $a, array $d): void
    {
        $update = [
            'last_seen_at' => now(),
            'occurrence_count' => $a->occurrence_count + 1,
            'detail_json' => json_encode($d, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        // A suppression that has expired returns the alert to open, because the
        // problem is demonstrably still here.
        if ($a->state === self::SUPPRESSED && $a->suppressed_until !== null
            && Carbon::parse($a->suppressed_until)->isPast()) {
            $update['state'] = self::OPEN;
            $update['previous_state'] = self::SUPPRESSED;
            $update['suppressed_until'] = null;
            $update = $this->withHistory($a, $update, 'suppression_expired');
        }

        DB::table('infra_estate_alerts')->where('id', $a->id)->update($update);
    }

    /** The ONLY path to recovery. Requires the observation that proved it. */
    private function recover(object $a, int $observationId): void
    {
        DB::table('infra_estate_alerts')->where('id', $a->id)->update(
            $this->withHistory($a, [
                'state' => self::RECOVERED,
                'previous_state' => $a->state,
                'recovered_at' => now(),
                'recovered_by_observation_id' => $observationId,
                'next_escalation_at' => null,
                'updated_at' => now(),
            ], 'recovered', ['observation_id' => $observationId])
        );
    }

    public function acknowledge(int $alertId, ?int $userId, string $note): bool
    {
        $a = DB::table('infra_estate_alerts')->where('id', $alertId)->first();

        if ($a === null || $a->state === self::RECOVERED) {
            return false;
        }

        DB::table('infra_estate_alerts')->where('id', $alertId)->update(
            $this->withHistory($a, [
                'state' => self::ACKNOWLEDGED,
                'previous_state' => $a->state,
                'acknowledged_at' => now(),
                'acknowledged_by' => $userId,
                'acknowledgement_note' => $note,
                // Acknowledgement stops the escalation clock. It does NOT close
                // the alert — a human knowing is not the problem being gone.
                'next_escalation_at' => null,
                'updated_at' => now(),
            ], 'acknowledged', ['by' => $userId, 'note' => $note])
        );

        return true;
    }

    public function suppress(int $alertId, ?int $userId, string $reason, int $hours): bool
    {
        $a = DB::table('infra_estate_alerts')->where('id', $alertId)->first();

        if ($a === null || $a->state === self::RECOVERED) {
            return false;
        }

        $hours = max(1, min($hours, self::MAX_SUPPRESSION_HOURS));

        DB::table('infra_estate_alerts')->where('id', $alertId)->update(
            $this->withHistory($a, [
                'state' => self::SUPPRESSED,
                'previous_state' => $a->state,
                'suppressed_until' => now()->addHours($hours),
                'suppression_reason' => $reason,
                'suppressed_by' => $userId,
                'next_escalation_at' => null,
                'updated_at' => now(),
            ], 'suppressed', ['hours' => $hours, 'reason' => $reason])
        );

        return true;
    }

    /**
     * Advance escalation clocks. Raises nothing new and closes nothing — it only
     * promotes alerts nobody has answered.
     */
    public function escalateDue(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?: now();

        $due = DB::table('infra_estate_alerts')
            ->whereIn('state', [self::OPEN, self::ESCALATED])
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', $asOf)
            ->get();

        $n = 0;

        foreach ($due as $a) {
            $level = min(3, $a->escalation_level + 1);
            $interval = self::ESCALATE_AFTER[$a->severity] ?? 0;

            DB::table('infra_estate_alerts')->where('id', $a->id)->update(
                $this->withHistory($a, [
                    'state' => self::ESCALATED,
                    'previous_state' => $a->state,
                    'escalated_at' => $asOf,
                    'escalation_level' => $level,
                    // Level 3 is the ceiling: keep shouting, stop counting.
                    'next_escalation_at' => $level < 3 && $interval > 0
                        ? $asOf->copy()->addMinutes($interval) : null,
                    'updated_at' => now(),
                ], 'escalated', ['level' => $level])
            );
            $n++;
        }

        return $n;
    }

    /** @param array<string,mixed> $update @param array<string,mixed> $extra */
    private function withHistory(object $a, array $update, string $event, array $extra = []): array
    {
        $h = (array) json_decode((string) ($a->history_json ?? '[]'), true);
        $h[] = ['at' => now()->toDateTimeString(), 'event' => $event] + $extra;
        $update['history_json'] = json_encode($h, JSON_UNESCAPED_SLASHES);

        return $update;
    }

    // ── HEALTH ───────────────────────────────────────────────────────────────

    /**
     * Health is derived from evidence, never from the passage of time.
     *
     * @return array<string,mixed>
     */
    public function health(CustomerDomain $d, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $reasons = [];

        $obs = DB::table('infra_domain_observations')
            ->where('customer_domain_id', $d->id)
            ->orderByDesc('id')->first();

        if ($obs === null) {
            return $this->healthResult(self::H_UNKNOWN, ['never observed — no evidence of any state'], null, 0);
        }

        $ageMin = Carbon::parse($obs->observed_at)->diffInMinutes($asOf);
        $staleAfter = ObservationCadence::staleAfterMinutes(ObservationCadence::REGISTRAR);
        $fresh = $ageMin <= $staleAfter;

        // The last look failed: we know nothing current. Unknown, not healthy.
        if (! (bool) $obs->reachable) {
            $reasons[] = 'last observation could not reach the provider — current state is unknown';

            return $this->healthResult(self::H_UNKNOWN, $reasons, $obs->observed_at, $ageMin);
        }

        if (! $fresh) {
            $reasons[] = "last successful observation is {$ageMin} min old (stale after {$staleAfter})";

            return $this->healthResult(self::H_UNKNOWN, $reasons, $obs->observed_at, $ageMin);
        }

        // Fresh and reachable — now unresolved alerts decide how bad it is.
        $open = DB::table('infra_estate_alerts')
            ->where('customer_domain_id', $d->id)
            ->whereNotIn('state', [self::RECOVERED])
            ->get()
            ->filter(fn ($a) => ! ($a->state === self::SUPPRESSED
                && $a->suppressed_until !== null
                && Carbon::parse($a->suppressed_until)->isFuture()));

        $state = self::H_HEALTHY;

        if ($open->where('severity', self::CRITICAL)->isNotEmpty()) {
            $state = self::H_CRITICAL;
            $reasons[] = $open->where('severity', self::CRITICAL)->count() . ' unresolved critical alert(s)';
        } elseif ($open->where('severity', self::HIGH)->isNotEmpty()) {
            $state = self::H_AT_RISK;
            $reasons[] = $open->where('severity', self::HIGH)->count() . ' unresolved high alert(s)';
        } elseif ($open->isNotEmpty()) {
            $state = self::H_DEGRADED;
            $reasons[] = $open->count() . ' unresolved alert(s)';
        } else {
            $reasons[] = 'fresh successful observation, no unresolved alerts';
        }

        return $this->healthResult($state, $reasons, $obs->observed_at, $ageMin);
    }

    /** @return array<string,mixed> */
    private function healthResult(string $state, array $reasons, $observedAt, int $ageMin): array
    {
        return [
            'state' => $state,
            'reasons' => $reasons,
            'last_observed_at' => $observedAt,
            'observation_age_minutes' => $ageMin,
            'evidence_based' => true,
        ];
    }

    // ── PROJECTIONS ──────────────────────────────────────────────────────────

    /**
     * Customer-facing. Age and confidence, never diagnostics: no registrar
     * errors, no API failures, no provider implementation.
     *
     * @return array<string,mixed>
     */
    public function customerView(CustomerDomain $d): array
    {
        $h = $this->health($d);

        $warnings = [];

        foreach (DB::table('infra_estate_alerts')
            ->where('customer_domain_id', $d->id)
            ->whereNotIn('state', [self::RECOVERED, self::SUPPRESSED])->get() as $a) {
            $msg = DomainDrift::customerMessage($a->drift_class);

            if ($msg !== null) {
                $warnings[] = $msg;
            }
        }

        return [
            'domain' => $d->domain,
            'last_verified' => $h['last_observed_at'],
            'verification_age_minutes' => $h['observation_age_minutes'],
            'confidence' => match ($h['state']) {
                self::H_HEALTHY => 'verified',
                self::H_DEGRADED => 'checking',
                self::H_AT_RISK, self::H_CRITICAL => 'needs_attention',
                default => 'unknown',
            },
            'active_warnings' => array_values(array_unique($warnings)),
            'provider' => 'LevelUp Growth',
        ];
    }

    /** Everything must be explainable. Admin only. */
    public function adminView(CustomerDomain $d): array
    {
        $alerts = DB::table('infra_estate_alerts')
            ->where('customer_domain_id', $d->id)->orderByDesc('id')->get();

        return [
            'domain' => $d->domain,
            'health' => $this->health($d),
            'observation_history' => DB::table('infra_domain_observations')
                ->where('customer_domain_id', $d->id)
                ->orderByDesc('id')->limit(20)
                ->get(['id', 'observed_at', 'reachable', 'probe_duration_ms',
                    'probe_error_code', 'drift_count', 'highest_severity',
                    'observed_expires_at', 'desired_expires_at'])->all(),
            'alerts' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'drift_class' => $a->drift_class,
                'severity' => $a->severity,
                'state' => $a->state,
                'occurrences' => $a->occurrence_count,
                'first_seen_at' => $a->first_seen_at,
                'last_seen_at' => $a->last_seen_at,
                'acknowledged_by' => $a->acknowledged_by,
                'acknowledgement_note' => $a->acknowledgement_note,
                'suppressed_until' => $a->suppressed_until,
                'escalation_level' => $a->escalation_level,
                'next_escalation_at' => $a->next_escalation_at,
                'recovered_at' => $a->recovered_at,
                'recovered_by_observation_id' => $a->recovered_by_observation_id,
                'recommended_action' => DomainDrift::describe($a->drift_class)['action'],
                'operator_guidance' => DomainDrift::describe($a->drift_class)['operator'],
                'history' => json_decode((string) $a->history_json, true),
            ])->all(),
        ];
    }

    /** Operator dashboard rollup. */
    public function dashboard(): array
    {
        $alerts = DB::table('infra_estate_alerts')->get();
        $domains = CustomerDomain::all();
        $health = [];

        foreach ($domains as $d) {
            $health[] = ['domain' => $d->domain] + $this->health($d);
        }

        return [
            'domains' => count($domains),
            'health_counts' => collect($health)->countBy('state')->all(),
            'alerts' => [
                'open' => $alerts->where('state', self::OPEN)->count(),
                'acknowledged' => $alerts->where('state', self::ACKNOWLEDGED)->count(),
                'suppressed' => $alerts->where('state', self::SUPPRESSED)->count(),
                'escalated' => $alerts->where('state', self::ESCALATED)->count(),
                'recovered' => $alerts->where('state', self::RECOVERED)->count(),
            ],
            'by_severity' => $alerts->whereNotIn('state', [self::RECOVERED])->countBy('severity')->all(),
            'health' => $health,
        ];
    }
}
