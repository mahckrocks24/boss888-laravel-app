<?php

namespace App\Engines\Infrastructure\Observation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8.1 — OBSERVATION → ALERT BRIDGE.
 *
 * S8 discovers drift across five dimensions. S7 owns alert identity,
 * acknowledgement, suppression, escalation and recovery. Without a join they are
 * two systems that happen to share a database.
 *
 * The join is not plumbing. It is where a monitoring system either becomes
 * usable or becomes noise, because ONE customer problem produces MANY
 * observations. An expired certificate fails the TLS handshake, which fails the
 * HTTPS probe, which may also fail the redirect check. Four observers, four
 * findings, one thing wrong. Paging four times for one problem is how operators
 * learn to ignore alerts.
 *
 * So findings are mapped to a CANONICAL ISSUE first, and alert identity is
 * derived from (subject, canonical issue) — never from the observer that
 * happened to notice.
 *
 * INTEGRATION ONLY: no delivery, no scheduling, no escalation triggered here,
 * no provider mutation. Observation produces findings; findings resolve to
 * alerts; alerts drive health.
 */
class ObservationAlertBridge
{
    // ── canonical issues: what is actually wrong, for the customer ───────────
    public const UNREACHABLE = 'site_unreachable';
    public const TLS_INVALID = 'tls_invalid';
    public const DNS_BROKEN = 'dns_broken';
    public const DELEGATION_CHANGED = 'delegation_changed';
    public const EXPIRY_RISK = 'expiry_risk';
    public const CUSTODY_RISK = 'custody_risk';
    public const MAIL_AT_RISK = 'mail_at_risk';
    public const INSECURE_TRANSPORT = 'insecure_transport';
    public const PERFORMANCE = 'performance_degraded';
    public const OBSERVABILITY_LOST = 'observability_lost';

    /** Human framing for each canonical issue. */
    public static function catalogue(): array
    {
        return [
            self::UNREACHABLE => ['title' => 'The site is unreachable', 'customer' => 'We are investigating a problem reaching this site.'],
            self::TLS_INVALID => ['title' => 'HTTPS/certificate is not valid', 'customer' => 'We are investigating a certificate problem on this domain.'],
            self::DNS_BROKEN => ['title' => 'DNS does not resolve correctly', 'customer' => 'We are investigating a DNS problem on this domain.'],
            self::DELEGATION_CHANGED => ['title' => 'DNS delegation changed unexpectedly', 'customer' => 'The DNS settings for this domain have changed.'],
            self::EXPIRY_RISK => ['title' => 'Domain expiry is at risk', 'customer' => 'We are re-checking this domain\'s renewal date.'],
            self::CUSTODY_RISK => ['title' => 'Ownership or control is in question', 'customer' => 'We are verifying control of this domain.'],
            self::MAIL_AT_RISK => ['title' => 'Email delivery for this domain is at risk', 'customer' => 'We are investigating an email configuration problem.'],
            self::INSECURE_TRANSPORT => ['title' => 'Traffic can travel unencrypted', 'customer' => null],
            self::PERFORMANCE => ['title' => 'Response performance is degraded', 'customer' => null],
            self::OBSERVABILITY_LOST => ['title' => 'We cannot currently observe this subject', 'customer' => null],
        ];
    }

    /**
     * Correlation groups. Operators should see one incident, not isolated alarms.
     * The FIRST issue present in a group is its primary; the rest are symptoms.
     */
    private const CORRELATIONS = [
        'domain_lifecycle' => [self::CUSTODY_RISK, self::EXPIRY_RISK, self::DELEGATION_CHANGED],
        'service_delivery' => [self::DNS_BROKEN, self::TLS_INVALID, self::UNREACHABLE, self::INSECURE_TRANSPORT, self::PERFORMANCE],
        'communications' => [self::MAIL_AT_RISK],
        'observability' => [self::OBSERVABILITY_LOST],
    ];

    /**
     * Map one observation-registry row into canonical findings.
     *
     * Deterministic and total: every drift finding maps to exactly one canonical
     * issue, and an unmapped one falls through to a named default rather than
     * being silently dropped.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findingsFor(object $fact): array
    {
        $out = [];
        $drift = (array) json_decode((string) $fact->drift_json, true);

        // A failed observation is itself a finding: we have lost visibility.
        if (! (bool) $fact->success && $drift === []) {
            $out[] = $this->finding($fact, self::OBSERVABILITY_LOST, 'medium',
                'Observation failed: ' . ($fact->error_code ?? 'unknown'));
        }

        foreach ($drift as $d) {
            $out[] = $this->finding(
                $fact,
                $this->canonicalise($fact->dimension, (string) ($d['title'] ?? ''), (string) ($d['class'] ?? '')),
                (string) ($d['severity'] ?? 'medium'),
                (string) ($d['title'] ?? 'drift'),
                $d
            );
        }

        return $out;
    }

    /**
     * The heart of deduplication: many symptoms, one cause.
     *
     * Matching is on the observed TITLE because that is what the observers
     * actually assert; dimension alone is too coarse (a certificate dimension
     * can report both an expiry risk and a coverage failure, which are different
     * customer problems).
     */
    private function canonicalise(string $dimension, string $title, string $class): string
    {
        $t = strtolower($title);

        // Ownership and lifecycle first — these outrank symptoms.
        if (str_contains($t, 'no longer reports this domain') || str_contains($t, 'custody')
            || str_contains($t, 'not under our management') || str_contains($t, 'hold or deletion')) {
            return self::CUSTODY_RISK;
        }

        if (str_contains($t, 'expire') || str_contains($t, 'expiry')) {
            // A certificate expiring is a TLS problem; a DOMAIN expiring is a
            // lifecycle problem. Same word, different incident.
            return $dimension === 'certificate' ? self::TLS_INVALID : self::EXPIRY_RISK;
        }

        if (str_contains($t, 'nameserver') || str_contains($t, 'delegation')) {
            return self::DELEGATION_CHANGED;
        }

        if (str_contains($t, 'mx')) {
            return self::MAIL_AT_RISK;
        }

        if (str_contains($t, 'does not resolve') || str_contains($t, 'apex a record')) {
            return self::DNS_BROKEN;
        }

        // TLS handshake failure and certificate faults are one issue.
        if (str_contains($t, 'tls') || str_contains($t, 'certificate') || str_contains($t, 'self-signed')
            || str_contains($t, 'signature algorithm')) {
            return self::TLS_INVALID;
        }

        if (str_contains($t, 'not answering') || str_contains($t, 'server error') || str_contains($t, 'returns ')) {
            return self::UNREACHABLE;
        }

        if (str_contains($t, 'redirect to https')) {
            return self::INSECURE_TRANSPORT;
        }

        if (str_contains($t, 'slow') || str_contains($t, 'redirect chain')) {
            return self::PERFORMANCE;
        }

        return match ($dimension) {
            'dns' => self::DNS_BROKEN,
            'certificate' => self::TLS_INVALID,
            'http' => self::UNREACHABLE,
            'whois', 'registrar' => self::EXPIRY_RISK,
            default => self::OBSERVABILITY_LOST,
        };
    }

    /** @return array<string,mixed> */
    private function finding(object $fact, string $issue, string $severity, string $title, array $raw = []): array
    {
        return [
            'subject' => $fact->subject,
            'canonical_issue' => $issue,
            'severity' => $severity,
            'title' => $title,
            'dimension' => $fact->dimension,
            'provider' => $fact->provider,
            'custody' => $fact->custody,
            'confidence' => $fact->confidence,
            'observation_id' => (int) $fact->id,
            'observed_at' => $fact->observed_at,
            'evidence' => $raw,
            // IDENTITY: subject + canonical issue. Never the observer.
            'alert_key' => $this->identity($fact->subject, $issue),
        ];
    }

    /** Deterministic. Same finding → same alert, for all time. */
    public function identity(string $subject, string $canonicalIssue): string
    {
        return substr(hash('sha256', 'infra888|' . strtolower($subject) . '|' . $canonicalIssue), 0, 40);
    }

    // ── BRIDGE ───────────────────────────────────────────────────────────────

    /**
     * Reconcile alerts from the LATEST observation of every dimension for one
     * subject. Runs across the whole subject at once, because deduplication and
     * recovery are only correct with the full picture — reconciling one
     * dimension at a time would recover an alert another dimension still proves.
     *
     * @return array<string,int>
     */
    public function bridgeSubject(string $subject): array
    {
        $facts = $this->latestFactsFor($subject);

        if ($facts === []) {
            return ['raised' => 0, 'updated' => 0, 'recovered' => 0, 'skipped_unreachable' => 0];
        }

        // Findings from every dimension, grouped by canonical identity.
        $byKey = [];
        $anySuccessful = false;

        foreach ($facts as $f) {
            $anySuccessful = $anySuccessful || (bool) $f->success;

            foreach ($this->findingsFor($f) as $find) {
                $byKey[$find['alert_key']][] = $find;
            }
        }

        // ── CAUSAL COLLAPSE (Stage 5) ───────────────────────────────────
        // One customer problem, one alert. When DNS does not resolve, the TLS
        // handshake and HTTP probe CANNOT succeed — their failures are
        // consequences, not independent faults. Raising three criticals for one
        // broken thing is how an operator learns to ignore the third one.
        //
        // The root cause keeps its alert; the consequences are folded into it as
        // evidence, so nothing is lost — the operator still sees that all three
        // observers agree, under one identity.
        $byKey = $this->collapseConsequences($byKey);

        $raised = 0;
        $updated = 0;

        foreach ($byKey as $key => $group) {
            $resolved = $this->resolveGroup($group);
            $existing = DB::table('infra_estate_alerts')->where('alert_key', $key)->first();

            if ($existing === null) {
                $this->raise($subject, $key, $resolved);
                $raised++;
            } else {
                $this->update($existing, $resolved);
                $updated++;
            }
        }

        // ── RECOVERY ────────────────────────────────────────────────────────
        // Only a fresh SUCCESSFUL observation may close an alert. If nothing
        // succeeded this round we learned nothing, so nothing closes — an
        // unreachable provider must never be mistaken for a resolved problem.
        $recovered = 0;
        $skipped = 0;

        if ($anySuccessful) {
            $open = DB::table('infra_estate_alerts')
                ->where('domain', $subject)
                ->where('state', '!=', 'recovered')->get();

            foreach ($open as $a) {
                if (isset($byKey[$a->alert_key])) {
                    continue;
                }

                // Only recover an alert whose issue is covered by a dimension we
                // successfully observed this round. An alert raised by DNS must
                // not be closed because HTTP happened to succeed.
                if (! $this->coveredBySuccessfulObservation($a, $facts)) {
                    $skipped++;

                    continue;
                }

                $this->recover($a, $this->proofObservationId($a, $facts));
                $recovered++;
            }
        } else {
            $skipped = DB::table('infra_estate_alerts')->where('domain', $subject)
                ->where('state', '!=', 'recovered')->count();
        }

        return ['raised' => $raised, 'updated' => $updated, 'recovered' => $recovered, 'skipped_unreachable' => $skipped];
    }

    /** @return array<int,object> latest fact per dimension */
    private function latestFactsFor(string $subject): array
    {
        $rows = DB::table('infra_observation_facts')
            ->where('subject', $subject)->orderBy('id')->get();

        $latest = [];

        foreach ($rows as $r) {
            $latest[$r->dimension] = $r;
        }

        return array_values($latest);
    }

    /** Which dimensions can legitimately close this canonical issue. */
    private function dimensionsFor(string $issue): array
    {
        return match ($issue) {
            self::TLS_INVALID => ['certificate', 'http'],
            self::UNREACHABLE, self::INSECURE_TRANSPORT, self::PERFORMANCE => ['http'],
            self::DNS_BROKEN, self::DELEGATION_CHANGED, self::MAIL_AT_RISK => ['dns'],
            self::EXPIRY_RISK => ['whois', 'registrar'],
            self::CUSTODY_RISK => ['whois', 'registrar', 'dns'],
            default => ['dns', 'certificate', 'http', 'whois', 'registrar'],
        };
    }

    private function coveredBySuccessfulObservation(object $alert, array $facts): bool
    {
        $issue = $alert->drift_class;
        $need = $this->dimensionsFor($issue);

        foreach ($facts as $f) {
            if ((bool) $f->success && in_array($f->dimension, $need, true)) {
                return true;
            }
        }

        return false;
    }

    private function proofObservationId(object $alert, array $facts): int
    {
        $need = $this->dimensionsFor($alert->drift_class);

        foreach ($facts as $f) {
            if ((bool) $f->success && in_array($f->dimension, $need, true)) {
                return (int) $f->id;
            }
        }

        return 0;
    }

    /**
     * Severity resolution: highest wins, ALL evidence preserved. The whole point
     * of collapsing four symptoms into one alert is lost if three of them
     * disappear — an operator needs to see that the certificate, the handshake
     * and the HTTP probe all agree.
     *
     * @return array<string,mixed>
     */
    private function resolveGroup(array $group): array
    {
        $rank = ['info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];
        $best = 'info';

        foreach ($group as $g) {
            if (($rank[$g['severity']] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $g['severity'];
            }
        }

        $primary = null;

        foreach ($group as $g) {
            if ($g['severity'] === $best) {
                $primary = $g;
                break;
            }
        }

        $issue = $primary['canonical_issue'];

        return [
            'canonical_issue' => $issue,
            'severity' => $best,
            'title' => self::catalogue()[$issue]['title'] ?? $primary['title'],
            'customer_message' => self::catalogue()[$issue]['customer'] ?? null,
            'correlation_group' => $this->correlationGroupOf($issue),
            'custody' => $primary['custody'],
            'workspace_id' => null,
            'evidence' => array_map(fn ($g) => [
                'dimension' => $g['dimension'],
                'provider' => $g['provider'],
                'confidence' => $g['confidence'],
                'severity' => $g['severity'],
                'title' => $g['title'],
                'observation_id' => $g['observation_id'],
                'observed_at' => $g['observed_at'],
            ], $group),
            'observed_at' => $primary['observed_at'],
        ];
    }


    /**
     * Precedence within a causal chain: earlier entries cause later ones.
     * Only the earliest issue present survives as an alert; the rest become
     * evidence attached to it.
     */
    private const CAUSAL_CHAINS = [
        // A domain we do not control, or that has lapsed, explains everything below it.
        [self::CUSTODY_RISK, self::EXPIRY_RISK, self::DELEGATION_CHANGED,
            self::DNS_BROKEN, self::TLS_INVALID, self::UNREACHABLE,
            self::INSECURE_TRANSPORT, self::PERFORMANCE],
    ];

    /**
     * @param array<string,array<int,array<string,mixed>>> $byKey
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function collapseConsequences(array $byKey): array
    {
        // Which canonical issues are present this round?
        $present = [];

        foreach ($byKey as $key => $group) {
            $present[$group[0]['canonical_issue']] = $key;
        }

        foreach (self::CAUSAL_CHAINS as $chain) {
            $rootIssue = null;
            $rootKey = null;

            foreach ($chain as $issue) {
                if (isset($present[$issue])) {
                    $rootIssue = $issue;
                    $rootKey = $present[$issue];
                    break;
                }
            }

            if ($rootIssue === null) {
                continue;
            }

            // Fold every downstream consequence into the root cause.
            foreach ($chain as $issue) {
                if ($issue === $rootIssue || ! isset($present[$issue])) {
                    continue;
                }

                $consequenceKey = $present[$issue];

                foreach ($byKey[$consequenceKey] as $f) {
                    $f['consequence_of'] = $rootIssue;
                    $byKey[$rootKey][] = $f;
                }

                unset($byKey[$consequenceKey]);
            }
        }

        return $byKey;
    }

    public function correlationGroupOf(string $issue): string
    {
        foreach (self::CORRELATIONS as $group => $issues) {
            if (in_array($issue, $issues, true)) {
                return $group;
            }
        }

        return 'other';
    }

    private function raise(string $subject, string $key, array $r): void
    {
        $ws = DB::table('infra_observation_facts')->where('subject', $subject)->value('workspace_id');
        $cd = DB::table('infra_observation_facts')->where('subject', $subject)->value('customer_domain_id');

        DB::table('infra_estate_alerts')->insert([
            'workspace_id' => $ws,
            'customer_domain_id' => $cd,
            'domain' => $subject,
            'alert_key' => $key,
            'drift_class' => $r['canonical_issue'],
            'severity' => $r['severity'],
            'state' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
            'detail_json' => json_encode($r, JSON_UNESCAPED_SLASHES),
            'history_json' => json_encode([[
                'at' => now()->toDateTimeString(), 'event' => 'raised',
                'severity' => $r['severity'], 'issue' => $r['canonical_issue'],
                'evidence_count' => count($r['evidence']),
            ]], JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Identity never changes; severity and evidence do. A worsening problem must
     * update the alert an operator is already looking at, not open a new one
     * beside it.
     */
    private function update(object $a, array $r): void
    {
        $h = (array) json_decode((string) ($a->history_json ?? '[]'), true);

        if ($a->severity !== $r['severity']) {
            $h[] = ['at' => now()->toDateTimeString(), 'event' => 'severity_changed',
                'from' => $a->severity, 'to' => $r['severity']];
        }

        $update = [
            'severity' => $r['severity'],
            'last_seen_at' => now(),
            'occurrence_count' => $a->occurrence_count + 1,
            'detail_json' => json_encode($r, JSON_UNESCAPED_SLASHES),
            'history_json' => json_encode($h, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        // An expired suppression returns the alert to open — the problem is
        // demonstrably still here.
        if ($a->state === 'suppressed' && $a->suppressed_until !== null
            && Carbon::parse($a->suppressed_until)->isPast()) {
            $update['state'] = 'open';
            $update['previous_state'] = 'suppressed';
            $update['suppressed_until'] = null;
        }

        DB::table('infra_estate_alerts')->where('id', $a->id)->update($update);
    }

    private function recover(object $a, int $observationId): void
    {
        $h = (array) json_decode((string) ($a->history_json ?? '[]'), true);
        $h[] = ['at' => now()->toDateTimeString(), 'event' => 'recovered', 'observation_id' => $observationId];

        DB::table('infra_estate_alerts')->where('id', $a->id)->update([
            'state' => 'recovered',
            'previous_state' => $a->state,
            'recovered_at' => now(),
            'recovered_by_observation_id' => $observationId,
            'next_escalation_at' => null,
            'history_json' => json_encode($h, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,int> */
    public function bridgeEstate(?int $workspaceId = null): array
    {
        $tot = ['raised' => 0, 'updated' => 0, 'recovered' => 0, 'skipped_unreachable' => 0];

        foreach (Custody::estateSubjects($workspaceId) as $s) {
            foreach ($this->bridgeSubject($s['subject']) as $k => $v) {
                $tot[$k] += $v;
            }
        }

        return $tot;
    }

    // ── INCIDENTS (correlation view) ─────────────────────────────────────────

    /**
     * Correlated view: one incident per (subject, correlation group), so an
     * operator sees "domain lifecycle problem, 3 observations" rather than three
     * unrelated-looking alarms.
     *
     * @return array<int,array<string,mixed>>
     */
    public function incidents(?int $workspaceId = null): array
    {
        $alerts = DB::table('infra_estate_alerts')->where('state', '!=', 'recovered')
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('domain')->get();

        $rank = ['info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];
        $groups = [];

        foreach ($alerts as $a) {
            $g = $this->correlationGroupOf($a->drift_class);
            $k = $a->domain . '|' . $g;

            $groups[$k] ??= ['subject' => $a->domain, 'correlation_group' => $g,
                'severity' => 'info', 'alerts' => [], 'evidence_count' => 0];

            if (($rank[$a->severity] ?? 0) > ($rank[$groups[$k]['severity']] ?? 0)) {
                $groups[$k]['severity'] = $a->severity;
            }

            $detail = (array) json_decode((string) $a->detail_json, true);
            $groups[$k]['evidence_count'] += count($detail['evidence'] ?? []);
            $groups[$k]['alerts'][] = [
                'id' => $a->id, 'issue' => $a->drift_class, 'severity' => $a->severity,
                'state' => $a->state, 'occurrences' => $a->occurrence_count,
                'evidence' => $detail['evidence'] ?? [],
            ];
        }

        return array_values($groups);
    }

    // ── HEALTH, DERIVED FROM ALERTS ONLY ─────────────────────────────────────

    /**
     * Health is now a function of alerts, not an independent calculation.
     *
     * S8 computed health directly from observation rows, which meant health and
     * alerts could disagree — a suppressed alert would still drag health down,
     * and an acknowledged one would look identical to an unhandled one. One
     * pipeline, one answer.
     *
     * @return array<string,mixed>
     */
    public function health(string $subject, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $facts = $this->latestFactsFor($subject);

        if ($facts === []) {
            return ['subject' => $subject, 'state' => 'unknown',
                'reasons' => ['never observed'], 'alerts' => 0, 'derived_from' => 'alerts'];
        }

        // Freshness gate first: stale or failed evidence cannot assert health.
        $anyFresh = false;

        foreach ($facts as $f) {
            if (! (bool) $f->success) {
                continue;
            }

            $age = Carbon::parse($f->observed_at)->diffInMinutes($asOf);

            if ($age <= ObservationCadence::staleAfterMinutes(ObservationCadence::DNS)) {
                $anyFresh = true;
            }
        }

        if (! $anyFresh) {
            return ['subject' => $subject, 'state' => 'unknown',
                'reasons' => ['no fresh successful observation'], 'alerts' => 0, 'derived_from' => 'alerts'];
        }

        $open = DB::table('infra_estate_alerts')->where('domain', $subject)
            ->where('state', '!=', 'recovered')->get()
            ->filter(fn ($a) => ! ($a->state === 'suppressed' && $a->suppressed_until !== null
                && Carbon::parse($a->suppressed_until)->isFuture()));

        $state = 'healthy';
        $reasons = ['fresh observation, no unresolved alerts'];

        if ($open->where('severity', 'critical')->isNotEmpty()) {
            $state = 'critical';
            $reasons = [$open->where('severity', 'critical')->count() . ' unresolved critical alert(s)'];
        } elseif ($open->where('severity', 'high')->isNotEmpty()) {
            $state = 'at_risk';
            $reasons = [$open->where('severity', 'high')->count() . ' unresolved high alert(s)'];
        } elseif ($open->isNotEmpty()) {
            $state = 'degraded';
            $reasons = [$open->count() . ' unresolved alert(s)'];
        }

        return ['subject' => $subject, 'state' => $state, 'reasons' => $reasons,
            'alerts' => $open->count(), 'derived_from' => 'alerts'];
    }
}
