<?php

namespace App\Engines\Infrastructure\Observation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8.3 — ALERT ELIGIBILITY POLICY.
 *
 * Decides whether an alert is ALLOWED TO LEAVE INFRA888. It does not deliver,
 * schedule, or change anything — it evaluates and records why.
 *
 * The governing constraint is that this is the last gate before a real human is
 * interrupted. Two failure modes matter, and they pull in opposite directions:
 * paging someone about a sandbox artefact teaches them to ignore the channel,
 * and silently withholding a real customer outage is worse than any amount of
 * noise. So every rule states which way it fails, and the default is to let
 * something through rather than swallow it.
 *
 * NO HARD-CODED HOSTNAMES. Internal, sandbox and rehearsal assets are identified
 * by what the estate knows about them — never by a list of names, which would
 * silently stop protecting us the moment someone registers a new test domain.
 */
class AlertEligibility
{
    // ── verdicts ─────────────────────────────────────────────────────────────
    public const ELIGIBLE = 'eligible';
    public const INELIGIBLE = 'ineligible';
    public const SUPPRESSED = 'suppressed';
    public const DEFERRED = 'deferred';
    public const MANUAL_REVIEW = 'manual_review';

    /** Severity at or above which an alert may leave. Below this: digest only. */
    public const DELIVERABLE_SEVERITIES = ['critical', 'high'];

    /** How long the same alert must wait before it may leave again. */
    public const REDELIVERY_COOLDOWN_MINUTES = 240;

    /**
     * Rules run in order. The FIRST non-eligible verdict wins and stops the
     * chain — order therefore encodes precedence, and safety rules come first
     * so that nothing about a sandbox asset can be overridden by severity.
     *
     * @return array<int,string>
     */
    public static function rulePipeline(): array
    {
        return [
            'alert_state',            // already handled?
            'asset_retired',          // does the thing still exist?
            'asset_ownership',        // is it ours to speak about?
            'internal_asset',         // never-served, never-resolved: our own scaffolding
            'sandbox_asset',          // registered against a non-production provider
            'observation_confidence', // do we actually know this?
            'maintenance_window',     // did we cause it on purpose?
            'duplicate_cooldown',     // have we just said this?
            'severity_threshold',     // is it worth a human?
            'customer_visibility',    // is there anything sayable?
        ];
    }

    /**
     * Evaluate one alert. Pure: reads the estate, writes only an audit row.
     *
     * @return array<string,mixed>
     */
    public function evaluate(object $alert, bool $record = true): array
    {
        $ctx = $this->context($alert);
        $trail = [];
        $verdict = self::ELIGIBLE;
        $deciding = null;
        $reason = 'passed every eligibility rule';

        foreach (self::rulePipeline() as $rule) {
            $r = $this->{'rule' . str_replace(' ', '', ucwords(str_replace('_', ' ', $rule)))}($alert, $ctx);

            $trail[] = [
                'rule' => $rule,
                'result' => $r['verdict'],
                'reason' => $r['reason'],
                'at' => now()->toDateTimeString(),
            ];

            if ($r['verdict'] !== self::ELIGIBLE) {
                $verdict = $r['verdict'];
                $deciding = $rule;
                $reason = $r['reason'];
                break;
            }
        }

        $decision = [
            'alert_id' => (int) $alert->id,
            'subject' => $alert->domain,
            'alert_key' => $alert->alert_key,
            'verdict' => $verdict,
            'deciding_rule' => $deciding,
            'reason' => $reason,
            'trail' => $trail,
            'custody' => $ctx['custody'],
            'severity' => $alert->severity,
            'confidence' => $ctx['confidence'],
            'environment' => $ctx['environment'],
        ];

        if ($record) {
            DB::table('infra_alert_eligibility_decisions')->insert([
                'alert_id' => $decision['alert_id'],
                'subject' => $decision['subject'],
                'alert_key' => $decision['alert_key'],
                'verdict' => $verdict,
                'deciding_rule' => $deciding,
                'reason' => $reason,
                'trail_json' => json_encode($trail, JSON_UNESCAPED_SLASHES),
                'custody' => $ctx['custody'],
                'severity' => $alert->severity,
                'confidence' => $ctx['confidence'],
                'environment' => $ctx['environment'],
                'decided_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $decision;
    }

    /** @return array<string,mixed> */
    private function context(object $alert): array
    {
        $custody = Custody::resolve($alert->domain);

        $facts = DB::table('infra_observation_facts')
            ->where('subject', $alert->domain)->orderByDesc('id')->limit(20)->get();

        $everResolved = DB::table('infra_observation_facts')
            ->where('subject', $alert->domain)
            ->where('dimension', 'dns')
            ->where('success', true)
            ->exists();

        $cd = $custody['customer_domain_id'] !== null
            ? DB::table('customer_domains')->where('id', $custody['customer_domain_id'])->first()
            : null;

        $servedByUs = DB::table('websites')->whereNull('deleted_at')
            ->whereRaw('LOWER(custom_domain) = ?', [strtolower($alert->domain)])->exists()
            || DB::table('custom_domains')->whereRaw('LOWER(hostname) = ?', [strtolower($alert->domain)])->exists();

        return [
            'custody' => $custody['custody'],
            'customer_domain' => $cd,
            'ever_resolved' => $everResolved,
            'served_by_us' => $servedByUs,
            'confidence' => (string) ($facts->first()->confidence ?? 'unknown'),
            'environment' => (string) config('namecheap.environment', 'unknown'),
            'app_env' => (string) config('app.env', 'production'),
        ];
    }

    // ── RULES ────────────────────────────────────────────────────────────────
    // Each returns ['verdict' => ..., 'reason' => ...].

    /** Already acknowledged or suppressed by a human: they know. */
    private function ruleAlertState(object $a, array $c): array
    {
        if ($a->state === 'recovered') {
            return $this->r(self::INELIGIBLE, 'alert has recovered — nothing to say');
        }

        if ($a->state === 'suppressed' && $a->suppressed_until !== null
            && Carbon::parse($a->suppressed_until)->isFuture()) {
            return $this->r(self::SUPPRESSED, 'an operator suppressed this until ' . $a->suppressed_until);
        }

        if ($a->state === 'acknowledged') {
            return $this->r(self::SUPPRESSED, 'already acknowledged — a human is on it');
        }

        return $this->r(self::ELIGIBLE, 'alert is open');
    }

    /** A retired or released asset should not generate customer-facing noise. */
    private function ruleAssetRetired(object $a, array $c): array
    {
        $cd = $c['customer_domain'];

        if ($cd !== null && in_array($cd->status, ['released', 'expired', 'suspended'], true)) {
            return $this->r(self::INELIGIBLE, "asset status is '{$cd->status}' — retired, not an incident");
        }

        return $this->r(self::ELIGIBLE, 'asset is live');
    }

    /** We must not speak about something we cannot place in a workspace. */
    private function ruleAssetOwnership(object $a, array $c): array
    {
        if ($c['custody'] === Custody::UNKNOWN) {
            return $this->r(self::MANUAL_REVIEW,
                'no platform record associates this hostname with a workspace — an operator must classify it before anyone is told');
        }

        if ($a->workspace_id === null) {
            return $this->r(self::MANUAL_REVIEW, 'alert has no workspace — recipient cannot be determined safely');
        }

        return $this->r(self::ELIGIBLE, 'ownership established: ' . $c['custody']);
    }

    /**
     * Internal scaffolding, identified by BEHAVIOUR not by name.
     *
     * An asset we registered, that no website serves, and that has never once
     * resolved in DNS, has never carried customer traffic. That is our own
     * scaffolding — a validation or rehearsal artefact — regardless of what it
     * is called. A real customer domain resolves at some point in its life.
     */
    private function ruleInternalAsset(object $a, array $c): array
    {
        $l = AssetLifecycle::resolve($a->domain);

        // Only a PROVEN-scaffolding lifecycle blocks. S8.3 asserted this from
        // "never resolved" alone, which describes a brand-new customer domain
        // for its first few hours — and would have dropped its alerts silently.
        if (in_array($l['state'], AssetLifecycle::blockedStates(), true)) {
            return $this->r(self::INELIGIBLE,
                "lifecycle '{$l['state']}': {$l['reason']}");
        }

        // Cold start and other un-established states are DEFERRED, never
        // dropped: the alert is real, we are simply not confident enough yet to
        // interrupt a human with it.
        if (in_array($l['state'], AssetLifecycle::deferredStates(), true)) {
            return $this->r(self::DEFERRED,
                "lifecycle '{$l['state']}': {$l['reason']}");
        }

        return $this->r(self::ELIGIBLE, "lifecycle '{$l['state']}' — asset is established");
    }

    /**
     * Sandbox assets, identified by the provider environment they were created
     * against rather than by hostname.
     */
    private function ruleSandboxAsset(object $a, array $c): array
    {
        $cd = $c['customer_domain'];

        // Only assert sandbox for an asset that is otherwise established; a
        // brand-new asset is handled by lifecycle above, not condemned here.
        if ($cd !== null && $c['environment'] !== 'production') {
            return $this->r(self::INELIGIBLE,
                "asset is registrar-managed while the registrar connector is in '{$c['environment']}' — not a production asset");
        }

        $meta = $cd !== null ? (array) json_decode((string) $cd->provider_metadata_json, true) : [];

        if (($meta['sandbox'] ?? false) === true || ($meta['environment'] ?? '') === 'sandbox') {
            return $this->r(self::INELIGIBLE, 'asset metadata marks it as a sandbox registration');
        }

        return $this->r(self::ELIGIBLE, 'asset is a production registration');
    }

    /**
     * We must not page a human on a guess.
     *
     * `inferred` evidence (WHOIS) is allowed through only at critical severity,
     * because a possibly-expired customer domain is worth a conversation even on
     * imperfect data — but a medium-severity inference is not.
     */
    private function ruleObservationConfidence(object $a, array $c): array
    {
        if ($c['confidence'] === 'unknown') {
            return $this->r(self::DEFERRED,
                'latest observation carries no confidence — re-observe before telling anyone');
        }

        if ($c['confidence'] === Custody::INFERRED && $a->severity !== 'critical') {
            return $this->r(self::DEFERRED,
                'evidence is inferred rather than verified; below critical this waits for corroboration');
        }

        return $this->r(self::ELIGIBLE, 'evidence confidence: ' . $c['confidence']);
    }

    /** Planned work is not an incident. Absent config means no window. */
    private function ruleMaintenanceWindow(object $a, array $c): array
    {
        $w = (array) config('infrastructure.maintenance_windows', []);

        foreach ($w as $win) {
            $from = isset($win['from']) ? Carbon::parse($win['from']) : null;
            $to = isset($win['to']) ? Carbon::parse($win['to']) : null;
            $scope = $win['subject'] ?? null;

            if ($from === null || $to === null || ! now()->between($from, $to)) {
                continue;
            }

            if ($scope === null || strtolower((string) $scope) === strtolower($a->domain)) {
                return $this->r(self::SUPPRESSED, 'inside a declared maintenance window until ' . $to->toDateTimeString());
            }
        }

        return $this->r(self::ELIGIBLE, 'no active maintenance window');
    }

    /** Do not say the same thing twice in quick succession. */
    private function ruleDuplicateCooldown(object $a, array $c): array
    {
        $last = DB::table('infra_alert_eligibility_decisions')
            ->where('alert_key', $a->alert_key)
            ->where('verdict', self::ELIGIBLE)
            ->orderByDesc('id')->first();

        if ($last === null) {
            return $this->r(self::ELIGIBLE, 'not previously released');
        }

        $mins = Carbon::parse($last->decided_at)->diffInMinutes(now());

        if ($mins < self::REDELIVERY_COOLDOWN_MINUTES) {
            return $this->r(self::DEFERRED,
                "released {$mins} min ago; cooldown is " . self::REDELIVERY_COOLDOWN_MINUTES . ' min');
        }

        return $this->r(self::ELIGIBLE, 'outside redelivery cooldown');
    }

    /** Below the threshold an alert still exists and is still visible — it just does not interrupt anyone. */
    private function ruleSeverityThreshold(object $a, array $c): array
    {
        if (! in_array($a->severity, self::DELIVERABLE_SEVERITIES, true)) {
            return $this->r(self::DEFERRED,
                "severity '{$a->severity}' is below the interrupt threshold — digest only, not withheld");
        }

        return $this->r(self::ELIGIBLE, 'severity warrants a human');
    }

    /**
     * Fails OPEN by design. If there is no customer-safe wording we still let
     * the alert through for the operator channel — withholding a real outage
     * because we lack a polite sentence would be the worse error.
     */
    private function ruleCustomerVisibility(object $a, array $c): array
    {
        $msg = DomainDrift::customerMessage($a->drift_class)
            ?? (ObservationAlertBridge::catalogue()[$a->drift_class]['customer'] ?? null);

        if ($msg === null) {
            return $this->r(self::ELIGIBLE, 'no customer-safe wording — operator channel only');
        }

        return $this->r(self::ELIGIBLE, 'customer-safe wording available');
    }

    /** @return array<string,string> */
    private function r(string $verdict, string $reason): array
    {
        return ['verdict' => $verdict, 'reason' => $reason];
    }

    // ── batch ────────────────────────────────────────────────────────────────

    /**
     * Evaluate every open alert.
     *
     * @return array<int,array<string,mixed>>
     */
    public function evaluateAll(?int $workspaceId = null, bool $record = true): array
    {
        $alerts = DB::table('infra_estate_alerts')
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('id')->get();

        $out = [];

        foreach ($alerts as $a) {
            $out[] = $this->evaluate($a, $record);
        }

        return $out;
    }

    /** @return array<string,int> */
    public function summary(array $decisions): array
    {
        $s = [self::ELIGIBLE => 0, self::INELIGIBLE => 0, self::SUPPRESSED => 0,
            self::DEFERRED => 0, self::MANUAL_REVIEW => 0];

        foreach ($decisions as $d) {
            $s[$d['verdict']] = ($s[$d['verdict']] ?? 0) + 1;
        }

        return $s;
    }
}
