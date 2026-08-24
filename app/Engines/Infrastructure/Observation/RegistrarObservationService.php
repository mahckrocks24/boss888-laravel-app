<?php

namespace App\Engines\Infrastructure\Observation;

use App\Connectors\Infrastructure\Contracts\DomainRegistrarConnector;
use App\Models\CustomerDomain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S6 — REGISTRAR OBSERVATION & ESTATE RECONCILIATION.
 *
 * The estate knows what it believes. This learns what actually exists.
 *
 * STRICTLY READ-ONLY with respect to both sides:
 *   · never mutates the registrar
 *   · never repairs the estate
 *   · never renews
 *   · writes ONLY observation rows
 *
 * `customer_domains` is desired state and is never touched here — not even to
 * "helpfully" correct an expiry we can plainly see is wrong. Silent repair
 * destroys the very signal this system exists to raise: if observation quietly
 * fixed the estate, S5's year-long expiry divergence would have vanished
 * without anyone learning that a renewal had happened outside the ledger.
 *
 * Records facts. Operators decide.
 */
class RegistrarObservationService
{
    /** An observation older than this is not evidence of health. */
    public const STALE_AFTER_HOURS = 24;

    public function __construct(private ?DomainRegistrarConnector $registrar = null) {}

    private function registrar(): DomainRegistrarConnector
    {
        if ($this->registrar !== null) {
            return $this->registrar;
        }

        $class = config('infrastructure.connectors.registrar') ?: config('infrastructure.registrar');

        if (! is_string($class) || $class === '') {
            throw new \RuntimeException('No registrar connector is configured.');
        }

        // The container cannot build these (constructor scalars are unbound);
        // every working caller uses the static factory. See S4 defect D1.
        return $this->registrar = method_exists($class, 'make') ? $class::make() : app($class);
    }

    // ── OBSERVE ──────────────────────────────────────────────────────────────

    /**
     * Look at one domain and record what we saw. Returns the observation row id
     * and its findings. Nothing else changes.
     *
     * @return array<string,mixed>
     */
    public function observe(CustomerDomain $d): array
    {
        $started = microtime(true);
        $reachable = false;
        $err = [null, null];
        $obs = [];
        $raw = [];

        try {
            $r = $this->registrar()->getDomainStatus($d->domain);

            if ($r->success) {
                $reachable = true;
                $raw = $r->data;
                $obs = $this->mapObserved($r->data);
            } else {
                $err = [(string) $r->errorCode, (string) $r->errorSummary];
                // A registrar that answers "not found" IS reachable — that is a
                // meaningful observation, not a failure to observe.
                $reachable = $this->looksLikeNotFound($r->errorCode, $r->errorSummary);
                $raw = ['error_code' => $r->errorCode];
            }
        } catch (\Throwable $e) {
            $err = ['PROBE_EXCEPTION', class_basename($e) . ': ' . substr($e->getMessage(), 0, 200)];
        }

        $desired = $this->desiredOf($d);
        $drift = $this->classify($d, $obs, $desired, $reachable, $err);

        $id = DB::table('infra_domain_observations')->insertGetId([
            'workspace_id' => $d->workspace_id,
            'customer_domain_id' => $d->id,
            'domain' => $d->domain,
            'provider' => $d->provider ?: 'namecheap',

            'reachable' => $reachable,
            'probe_error_code' => $err[0],
            'probe_error_summary' => $err[1],
            'probe_duration_ms' => (int) round((microtime(true) - $started) * 1000),

            'observed_expires_at' => $obs['expires_at'] ?? null,
            'observed_status' => $obs['status'] ?? null,
            'observed_owned' => $obs['owned'] ?? null,
            'observed_managed_by_us' => $obs['managed_by_us'] ?? null,
            'observed_auto_renew' => $obs['auto_renew'] ?? null,
            'observed_is_locked' => $obs['is_locked'] ?? null,
            'observed_nameservers' => isset($obs['nameservers']) ? json_encode($obs['nameservers']) : null,
            'observed_dns_provider' => $obs['dns_provider'] ?? null,
            'observed_uses_our_dns' => $obs['uses_our_dns'] ?? null,
            'observed_whois_privacy' => $obs['whois_privacy'] ?? null,

            'desired_expires_at' => $desired['expires_at'],
            'desired_status' => $desired['status'],
            'desired_auto_renew' => $desired['auto_renew'],
            'desired_is_locked' => $desired['is_locked'],
            'desired_nameservers' => json_encode($desired['nameservers']),

            'has_drift' => $drift !== [],
            'highest_severity' => DomainDrift::highest($drift),
            'drift_count' => count($drift),
            'drift_json' => json_encode($drift, JSON_UNESCAPED_SLASHES),

            'raw_json' => json_encode($raw, JSON_UNESCAPED_SLASHES),
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'observation_id' => $id,
            'domain' => $d->domain,
            'reachable' => $reachable,
            'drift' => $drift,
            'highest_severity' => DomainDrift::highest($drift),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function observeAll(?int $workspaceId = null): array
    {
        $out = [];

        foreach (CustomerDomain::query()
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('id')->get() as $d) {
            $out[] = $this->observe($d);
        }

        return $out;
    }

    // ── MAPPING ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function mapObserved(array $data): array
    {
        return [
            'expires_at' => $this->parseDate($data['expires_at'] ?? null),
            'status' => isset($data['status']) ? (string) $data['status'] : null,
            'owned' => array_key_exists('owned', $data) ? (bool) $data['owned'] : null,
            'managed_by_us' => array_key_exists('managed_by_us', $data) ? (bool) $data['managed_by_us'] : null,
            'auto_renew' => array_key_exists('auto_renew', $data) ? (bool) $data['auto_renew'] : null,
            'is_locked' => array_key_exists('is_locked', $data) ? (bool) $data['is_locked'] : null,
            'nameservers' => isset($data['nameservers']) && is_array($data['nameservers'])
                ? array_values(array_map('strtolower', $data['nameservers'])) : null,
            'dns_provider' => isset($data['dns_provider']) ? (string) $data['dns_provider'] : null,
            'uses_our_dns' => array_key_exists('uses_our_dns', $data) ? (bool) $data['uses_our_dns'] : null,
            'whois_privacy' => isset($data['whois_guard']) ? (string) $data['whois_guard'] : null,
            'found' => array_key_exists('found', $data) ? (bool) $data['found'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function desiredOf(CustomerDomain $d): array
    {
        $ns = [];

        if (is_array($d->nameservers_json)) {
            $ns = array_values(array_map('strtolower', $d->nameservers_json));
        } elseif (is_string($d->nameservers_json)) {
            $ns = array_values(array_map('strtolower', (array) json_decode($d->nameservers_json, true)));
        }

        return [
            'expires_at' => $d->expires_at ? Carbon::parse($d->expires_at)->toDateTimeString() : null,
            'status' => $d->status,
            'auto_renew' => (bool) $d->auto_renew,
            'is_locked' => (bool) $d->is_locked,
            'nameservers' => $ns,
        ];
    }

    private function parseDate($raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function looksLikeNotFound(?string $code, ?string $summary): bool
    {
        $hay = strtolower((string) $code . ' ' . (string) $summary);

        foreach (['not available', 'not found', 'no such domain', '2019166'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ── DRIFT CLASSIFICATION ─────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $obs
     * @param array<string,mixed> $desired
     * @param array{0:?string,1:?string} $err
     * @return array<int,array<string,mixed>>
     */
    private function classify(CustomerDomain $d, array $obs, array $desired, bool $reachable, array $err): array
    {
        $drift = [];

        $add = function (string $class, $observed = null, $desiredVal = null, ?string $detail = null) use (&$drift) {
            $meta = DomainDrift::describe($class);
            $drift[] = [
                'class' => $class,
                'severity' => $meta['severity'],
                'title' => $meta['title'],
                'observed' => $observed,
                'desired' => $desiredVal,
                'detail' => $detail,
                'operator_guidance' => $meta['operator'],
                'recommended_action' => $meta['action'],
                'customer_message' => $meta['customer'],
            ];
        };

        // Could we see anything at all?
        if ($obs === [] && ! $reachable) {
            $add(DomainDrift::REGISTRAR_UNAVAILABLE, null, null,
                trim((string) $err[0] . ' ' . (string) $err[1]) ?: 'no response');

            return $drift;
        }

        // Registrar answered, but does not hold this domain.
        if ($obs === [] || ($obs['found'] ?? null) === false) {
            $add(DomainDrift::ORPHANED_ESTATE, 'not present at registrar', $d->domain);

            return $drift;
        }

        // Custody.
        if (($obs['owned'] ?? null) === false) {
            $add(DomainDrift::CUSTODY_LOST, false, true);
        } elseif (($obs['managed_by_us'] ?? null) === false) {
            $add(DomainDrift::NOT_MANAGED_BY_US, false, true);
        }

        // Expiry — direction matters, so they are separate classes.
        if (! empty($obs['expires_at']) && ! empty($desired['expires_at'])) {
            $o = Carbon::parse($obs['expires_at']);
            $w = Carbon::parse($desired['expires_at']);

            if (! $o->isSameDay($w)) {
                $add($o->lt($w) ? DomainDrift::EXPIRY_EARLIER : DomainDrift::EXPIRY_LATER,
                    $o->toDateString(), $w->toDateString(),
                    'differs by ' . $w->diffInDays($o) . ' day(s)');
            }
        } elseif (! empty($obs['expires_at']) && empty($desired['expires_at'])) {
            $add(DomainDrift::UNKNOWN, $obs['expires_at'], null, 'estate holds no expiry to compare');
        }

        // Nameservers — order-insensitive.
        $on = $obs['nameservers'];

        if (is_array($on)) {
            $a = $on;
            $b = $desired['nameservers'];
            sort($a);
            sort($b);

            if ($a !== $b) {
                $add(DomainDrift::NAMESERVER_DRIFT, $on, $desired['nameservers']);
            }
        }

        if ($obs['auto_renew'] !== null && $obs['auto_renew'] !== $desired['auto_renew']) {
            $add(DomainDrift::AUTO_RENEW_DRIFT, $obs['auto_renew'], $desired['auto_renew']);
        }

        if ($obs['is_locked'] !== null && $obs['is_locked'] !== $desired['is_locked']) {
            $add(DomainDrift::LOCK_DRIFT, $obs['is_locked'], $desired['is_locked']);
        }

        // Registrar "Ok" is its healthy state; the estate calls that "active".
        if (! empty($obs['status'])) {
            $normalised = strtolower((string) $obs['status']) === 'ok' ? 'active' : strtolower((string) $obs['status']);

            if ($normalised !== strtolower((string) $desired['status'])) {
                $add(DomainDrift::STATUS_DRIFT, $obs['status'], $desired['status']);
            }
        }

        return $drift;
    }

    // ── MONITORING ───────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function monitor(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $f = [];

        $latest = $this->latestPerDomain();

        foreach (CustomerDomain::all() as $d) {
            $o = $latest[$d->id] ?? null;

            if ($o === null) {
                $f[] = $this->finding(DomainDrift::STALE_OBSERVATION, $d->domain, 'never observed');

                continue;
            }

            if (Carbon::parse($o->observed_at)->diffInHours($asOf) > self::STALE_AFTER_HOURS) {
                $f[] = $this->finding(DomainDrift::STALE_OBSERVATION, $d->domain,
                    'last observed ' . Carbon::parse($o->observed_at)->diffForHumans($asOf));
            }

            foreach ((array) json_decode((string) $o->drift_json, true) as $x) {
                $f[] = [
                    'check' => $x['class'],
                    'severity' => $x['severity'],
                    'subject' => $d->domain,
                    'detail' => $x['title'],
                    'observed' => $x['observed'] ?? null,
                    'desired' => $x['desired'] ?? null,
                    'action' => $x['recommended_action'] ?? null,
                ];
            }
        }

        return $f;
    }

    /** @return array<int,object> keyed by customer_domain_id */
    public function latestPerDomain(): array
    {
        $rows = DB::table('infra_domain_observations')->orderBy('id')->get();
        $out = [];

        foreach ($rows as $r) {
            $out[(int) $r->customer_domain_id] = $r;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function finding(string $class, string $subject, string $detail): array
    {
        $m = DomainDrift::describe($class);

        return [
            'check' => $class, 'severity' => $m['severity'], 'subject' => $subject,
            'detail' => $detail, 'observed' => null, 'desired' => null,
            'action' => $m['action'],
        ];
    }

    // ── PROJECTIONS ──────────────────────────────────────────────────────────

    /**
     * What the customer may see. Confidence, not internals: no registrar name,
     * no raw status codes, no nameserver hostnames unless the drift class is
     * explicitly customer-safe.
     *
     * @return array<string,mixed>
     */
    public function customerView(CustomerDomain $d): array
    {
        $o = $this->latestPerDomain()[$d->id] ?? null;

        if ($o === null) {
            return [
                'domain' => $d->domain,
                'last_verified' => null,
                'verification_confidence' => 'unknown',
                'issues' => [],
                'provider' => 'LevelUp Growth',
            ];
        }

        $ageHours = Carbon::parse($o->observed_at)->diffInHours(now());

        $confidence = match (true) {
            ! $o->reachable => 'unknown',
            $ageHours > self::STALE_AFTER_HOURS => 'stale',
            $o->highest_severity === DomainDrift::CRITICAL => 'needs_attention',
            (bool) $o->has_drift => 'checking',
            default => 'verified',
        };

        $issues = [];

        foreach ((array) json_decode((string) $o->drift_json, true) as $x) {
            $msg = DomainDrift::customerMessage((string) ($x['class'] ?? ''));

            if ($msg !== null) {
                $issues[] = $msg;
            }
        }

        return [
            'domain' => $d->domain,
            'last_verified' => Carbon::parse($o->observed_at)->toDateTimeString(),
            'verification_confidence' => $confidence,
            'issues' => array_values(array_unique($issues)),
            'provider' => 'LevelUp Growth',
        ];
    }

    /** Full operational truth, including provider internals. Admin only. */
    public function adminView(CustomerDomain $d, int $historyLimit = 10): array
    {
        $rows = DB::table('infra_domain_observations')
            ->where('customer_domain_id', $d->id)
            ->orderByDesc('id')->limit($historyLimit)->get();

        $latest = $rows->first();

        return [
            'domain' => $d->domain,
            'provider' => $d->provider,
            'latest' => $latest === null ? null : [
                'observed_at' => $latest->observed_at,
                'reachable' => (bool) $latest->reachable,
                'probe_error_code' => $latest->probe_error_code,
                'probe_duration_ms' => $latest->probe_duration_ms,
                'observed' => [
                    'expires_at' => $latest->observed_expires_at,
                    'status' => $latest->observed_status,
                    'owned' => $latest->observed_owned,
                    'managed_by_us' => $latest->observed_managed_by_us,
                    'auto_renew' => $latest->observed_auto_renew,
                    'is_locked' => $latest->observed_is_locked,
                    'nameservers' => json_decode((string) $latest->observed_nameservers, true),
                    'dns_provider' => $latest->observed_dns_provider,
                ],
                'desired' => [
                    'expires_at' => $latest->desired_expires_at,
                    'status' => $latest->desired_status,
                    'auto_renew' => $latest->desired_auto_renew,
                    'is_locked' => $latest->desired_is_locked,
                    'nameservers' => json_decode((string) $latest->desired_nameservers, true),
                ],
                'drift' => json_decode((string) $latest->drift_json, true),
                'raw' => json_decode((string) $latest->raw_json, true),
            ],
            'history' => $rows->map(fn ($r) => [
                'observed_at' => $r->observed_at,
                'reachable' => (bool) $r->reachable,
                'drift_count' => $r->drift_count,
                'highest_severity' => $r->highest_severity,
            ])->all(),
        ];
    }
}
