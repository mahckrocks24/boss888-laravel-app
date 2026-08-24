<?php

namespace App\Engines\Infrastructure\Observation;

use App\Engines\Infrastructure\Observation\Observers\DnsObserver;
use App\Engines\Infrastructure\Observation\Observers\EndpointObserver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8 — CUSTOM ESTATE OBSERVATION.
 *
 * Runs the observers custody permits, records every result in the observation
 * registry, and computes health PER DIMENSION.
 *
 * OBSERVATION ONLY: no registrar write, no renewal, no DNS change, no
 * certificate issuance, no provisioning, no delivery, no scheduling.
 *
 * Per-dimension health exists because a single score hides the thing you need.
 * "Domain: degraded" is useless. "DNS healthy, certificate expires in 4 days,
 * HTTP healthy, WHOIS unknown" tells an operator exactly what to do — and
 * lets each dimension go stale independently, which is how they actually behave.
 */
class EstateObservationService
{
    public const DIMENSIONS = ['dns', 'certificate', 'http', 'whois', 'registrar'];

    public function __construct(
        private ?DnsObserver $dns = null,
        private ?EndpointObserver $endpoint = null,
    ) {
        $this->dns = $dns ?: new DnsObserver();
        $this->endpoint = $endpoint ?: new EndpointObserver();
    }

    /**
     * Observe one subject across every dimension its custody permits.
     *
     * @return array<string,mixed>
     */
    public function observeSubject(array $subject): array
    {
        $host = $subject['subject'];
        $custody = $subject['custody'];
        $desired = $this->desiredFor($subject);
        $results = [];

        foreach (Custody::observableDimensions($custody) as $dim) {
            $r = match ($dim) {
                'dns' => $this->dns->observe($host, $custody, $desired['dns'] ?? []),
                'whois' => $this->dns->whois($host, $custody, $desired['whois'] ?? []),
                'certificate' => $this->endpoint->certificate($host, $custody, $desired['certificate'] ?? []),
                'http' => $this->endpoint->http($host, $custody, $desired['http'] ?? []),
                // The registrar dimension belongs to S6's service and is only
                // meaningful for domains we register. Not duplicated here.
                default => null,
            };

            if ($r === null) {
                continue;
            }

            $results[$dim] = $this->record($subject, $r);
        }

        return ['subject' => $host, 'custody' => $custody, 'dimensions' => $results];
    }

    /** @return array<int,array<string,mixed>> */
    public function observeEstate(?int $workspaceId = null, ?string $only = null): array
    {
        $out = [];

        foreach (Custody::estateSubjects($workspaceId) as $s) {
            if ($only !== null && $s['subject'] !== strtolower($only)) {
                continue;
            }

            $out[] = $this->observeSubject($s);
        }

        return $out;
    }

    /**
     * What the estate believes, per dimension. Only asserted where a real
     * recorded expectation exists — inventing a desired value would manufacture
     * drift out of nothing.
     *
     * @return array<string,array<string,mixed>>
     */
    private function desiredFor(array $subject): array
    {
        $d = ['dns' => [], 'certificate' => [], 'http' => [], 'whois' => []];

        if ($subject['customer_domain_id'] !== null) {
            $cd = DB::table('customer_domains')->where('id', $subject['customer_domain_id'])->first();

            if ($cd !== null) {
                $ns = (array) json_decode((string) $cd->nameservers_json, true);
                $d['dns']['nameservers'] = array_map('strtolower', array_filter($ns));
                $d['whois']['expires_at'] = $cd->expires_at;
            }
        }

        // Sites we serve are expected to point at our origin.
        if ($subject['website_id'] !== null) {
            $origin = trim((string) config('infrastructure.origin_ip', '134.209.93.41'));

            if ($origin !== '') {
                $d['dns']['expected_a'] = [$origin];
            }
        }

        return $d;
    }

    /** Persist one observation. Registry rows are append-only. */
    private function record(array $subject, array $r): array
    {
        $prev = DB::table('infra_observation_facts')
            ->where('subject', $subject['subject'])
            ->where('dimension', $r['dimension'])
            ->orderByDesc('id')->first();

        $drift = $r['drift'] ?? [];
        $sev = $this->highestSeverity($drift);

        $id = DB::table('infra_observation_facts')->insertGetId([
            'workspace_id' => $subject['workspace_id'],
            'subject' => $subject['subject'],
            'customer_domain_id' => $subject['customer_domain_id'],
            'website_id' => $subject['website_id'],
            'dimension' => $r['dimension'],
            'custody' => $subject['custody'],
            'provider' => $r['provider'],
            'success' => (bool) $r['success'],
            'confidence' => $r['success'] ? $r['confidence'] : Custody::UNCERTAIN,
            'duration_ms' => $r['duration_ms'] ?? null,
            'error_code' => $r['error_code'] ?? null,
            'error_summary' => $r['error_summary'] ?? null,
            'observed_json' => json_encode($r['observed'] ?? [], JSON_UNESCAPED_SLASHES),
            'desired_json' => json_encode($r['desired'] ?? [], JSON_UNESCAPED_SLASHES),
            'has_drift' => $drift !== [],
            'drift_count' => count($drift),
            'highest_severity' => $sev,
            'drift_json' => json_encode($drift, JSON_UNESCAPED_SLASHES),
            'observed_at' => now(),
            // Carried forward so one row answers "when did we last actually know?"
            'last_success_at' => $r['success'] ? now() : ($prev->last_success_at ?? null),
            'last_failure_at' => $r['success'] ? ($prev->last_failure_at ?? null) : now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'observation_id' => $id, 'success' => (bool) $r['success'],
            'confidence' => $r['success'] ? $r['confidence'] : Custody::UNCERTAIN,
            'drift' => $drift, 'highest_severity' => $sev,
            'error_code' => $r['error_code'] ?? null,
        ];
    }

    private function highestSeverity(array $drift): ?string
    {
        $rank = ['info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];
        $best = null;

        foreach ($drift as $d) {
            $s = $d['severity'] ?? 'info';

            if ($best === null || ($rank[$s] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $s;
            }
        }

        return $best;
    }

    // ── HEALTH, PER DIMENSION ────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public function health(string $subject, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $custody = Custody::resolve($subject);
        $dims = [];

        foreach (self::DIMENSIONS as $dim) {
            $dims[$dim] = $this->dimensionHealth($subject, $dim, $custody['custody'], $asOf);
        }

        // Overall is the worst OBSERVABLE dimension. A dimension custody forbids
        // is `not_applicable` and must never drag health down — we are not
        // failing to observe it, we are correctly not attempting it.
        $rank = ['healthy' => 1, 'unknown' => 2, 'degraded' => 3, 'at_risk' => 4, 'critical' => 5];
        $worst = 'healthy';

        foreach ($dims as $d) {
            if ($d['state'] === 'not_applicable') {
                continue;
            }

            if (($rank[$d['state']] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $d['state'];
            }
        }

        return [
            'subject' => $subject,
            'custody' => $custody['custody'],
            'custody_reason' => $custody['reason'],
            'overall' => $worst,
            'dimensions' => $dims,
        ];
    }

    /** @return array<string,mixed> */
    private function dimensionHealth(string $subject, string $dim, string $custody, Carbon $asOf): array
    {
        if (! in_array($dim, Custody::observableDimensions($custody), true)) {
            return [
                'state' => 'not_applicable',
                'reason' => "custody '{$custody}' does not permit observing {$dim}",
                'confidence' => Custody::UNCERTAIN,
                'observed_at' => null, 'age_minutes' => null,
            ];
        }

        $o = DB::table('infra_observation_facts')
            ->where('subject', $subject)->where('dimension', $dim)
            ->orderByDesc('id')->first();

        if ($o === null) {
            return ['state' => 'unknown', 'reason' => 'never observed',
                'confidence' => Custody::UNCERTAIN, 'observed_at' => null, 'age_minutes' => null];
        }

        $age = Carbon::parse($o->observed_at)->diffInMinutes($asOf);
        $stale = ObservationCadence::staleAfterMinutes($this->cadenceKind($dim));

        // Health never survives on an old or failed reading (S7 rule, per dimension).
        if (! (bool) $o->success) {
            return ['state' => 'unknown', 'reason' => 'last observation failed: ' . ($o->error_code ?? 'unknown'),
                'confidence' => Custody::UNCERTAIN, 'observed_at' => $o->observed_at, 'age_minutes' => $age];
        }

        if ($age > $stale) {
            return ['state' => 'unknown', 'reason' => "last successful observation is {$age} min old (stale after {$stale})",
                'confidence' => Custody::UNCERTAIN, 'observed_at' => $o->observed_at, 'age_minutes' => $age];
        }

        $state = match ($o->highest_severity) {
            'critical' => 'critical',
            'high' => 'at_risk',
            'medium', 'low' => 'degraded',
            default => 'healthy',
        };

        return [
            'state' => $state,
            'reason' => $o->drift_count > 0
                ? $o->drift_count . ' drift finding(s), highest ' . $o->highest_severity
                : 'fresh successful observation, no drift',
            'confidence' => $o->confidence,
            'observed_at' => $o->observed_at,
            'age_minutes' => $age,
        ];
    }

    private function cadenceKind(string $dim): string
    {
        return match ($dim) {
            'dns' => ObservationCadence::DNS,
            'certificate' => ObservationCadence::CERTIFICATE,
            'http' => ObservationCadence::DNS,      // availability moves as fast as DNS
            'whois' => ObservationCadence::EXPIRY,
            default => ObservationCadence::REGISTRAR,
        };
    }

    /** Operator rollup across the whole estate. */
    public function dashboard(?int $workspaceId = null): array
    {
        $rows = [];

        foreach (Custody::estateSubjects($workspaceId) as $s) {
            $rows[] = $this->health($s['subject']);
        }

        return [
            'subjects' => count($rows),
            'by_custody' => collect($rows)->countBy('custody')->all(),
            'by_overall' => collect($rows)->countBy('overall')->all(),
            'health' => $rows,
        ];
    }
}
