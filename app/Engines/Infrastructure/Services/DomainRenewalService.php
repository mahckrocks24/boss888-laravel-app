<?php

namespace App\Engines\Infrastructure\Services;

use App\Connectors\Infrastructure\Contracts\DomainRegistrarConnector;
use App\Engines\Infrastructure\States\DomainRenewalState as S;
use App\Models\CustomerDomain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * INFRA888 · S3 — DOMAIN RENEWAL ORCHESTRATION.
 *
 * The registrar connector remains the execution layer. This is only the
 * orchestration around it: who is eligible, when, how many times, what a reply
 * actually proved, and what a human must be told.
 *
 * The governing rule is that renewing a domain SPENDS MONEY and cannot be
 * undone. Every design choice below follows from that:
 *
 *   · one open renewal per domain, enforced in a locked transaction
 *   · a database-unique idempotency key per renewal
 *   · expiry is captured BEFORE the call, so a retry can tell whether the
 *     previous attempt already succeeded
 *   · provider "OK" is never success — success is the registrar's own expiry
 *     date having moved, read back afterwards
 *   · a timeout is neither success nor failure; it is VERIFYING
 *   · when automation cannot prove what happened, it stops and asks
 */
class DomainRenewalService
{
    /** Renewals are scheduled this far before expiry. */
    public const REMINDER_DAYS = 30;

    /** The attempt window opens this far before expiry. */
    public const WINDOW_DAYS = 14;

    /** Attempts before a renewal stops retrying and escalates. */
    public const MAX_ATTEMPTS = 4;

    /** Read-backs before an ambiguous renewal escalates to a human. */
    public const MAX_VERIFY_ATTEMPTS = 6;

    /** Exponential backoff in minutes, indexed by attempt number. */
    private const BACKOFF_MINUTES = [1 => 15, 2 => 120, 3 => 720, 4 => 1440];

    public function __construct(private ?DomainRegistrarConnector $registrar = null)
    {
        // Deliberately NOT resolved here. Eligibility, scheduling and monitoring
        // are pure database work and must remain usable when no registrar
        // credential is configured at all — an operator has to be able to see
        // what is expiring even when the provider is unreachable.
    }

    /** Resolve the registrar only at the moment we genuinely need to talk to it. */
    private function registrar(): DomainRegistrarConnector
    {
        if ($this->registrar !== null) {
            return $this->registrar;
        }

        $class = config('infrastructure.connectors.registrar')
            ?: config('infrastructure.registrar');

        if (! is_string($class) || $class === '') {
            throw new \RuntimeException('No registrar connector is configured (infrastructure.connectors.registrar).');
        }

        // The container CANNOT build these connectors. NamecheapClient takes
        // constructor scalars (environment, endpoint, apiUser, apiKey, username,
        // clientIp) and nothing binds them, so app() throws
        // BindingResolutionException. Every working caller in the codebase uses
        // the connector's own static factory, which reads config/namecheap.php.
        // Discovered during S4 live validation: without this, the renewal service
        // could never have reached the registrar at all.
        if (method_exists($class, 'make')) {
            return $this->registrar = $class::make();
        }

        return $this->registrar = app($class);
    }

    // ── STAGE 1: ELIGIBILITY + SCHEDULING ────────────────────────────────────

    /**
     * A domain is eligible when it is ours, active, has a real expiry, is inside
     * the reminder horizon, and has no renewal already open. Auto-renew off is
     * NOT ineligibility — it changes the mode, not the obligation to plan.
     *
     * @return array{eligible:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}
     */
    public function findEligible(?int $workspaceId = null, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $horizon = $asOf->copy()->addDays(self::REMINDER_DAYS);

        $domains = CustomerDomain::query()
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('expires_at')
            ->get();

        $eligible = [];
        $skipped = [];

        foreach ($domains as $d) {
            $reason = $this->ineligibilityReason($d, $asOf, $horizon);

            if ($reason !== null) {
                $skipped[] = ['domain' => $d->domain, 'reason' => $reason];

                continue;
            }

            $eligible[] = [
                'customer_domain_id' => (int) $d->id,
                'workspace_id' => (int) $d->workspace_id,
                'domain' => $d->domain,
                'expires_at' => $d->expires_at,
                'days_until_expiry' => $asOf->diffInDays($d->expires_at, false),
                'auto_renew' => (bool) $d->auto_renew,
                'mode' => $d->auto_renew ? 'automated' : 'manual',
                'window_opens_at' => Carbon::parse($d->expires_at)->subDays(self::WINDOW_DAYS),
            ];
        }

        return ['eligible' => $eligible, 'skipped' => $skipped];
    }

    private function ineligibilityReason(CustomerDomain $d, Carbon $asOf, Carbon $horizon): ?string
    {
        if ($d->expires_at === null) {
            return 'no expiry recorded — cannot plan a renewal blind';
        }

        if ($d->status !== CustomerDomain::STATUS_ACTIVE) {
            return "status is {$d->status}, not active";
        }

        if (Carbon::parse($d->expires_at)->gt($horizon)) {
            return 'outside the ' . self::REMINDER_DAYS . '-day horizon';
        }

        if ($this->openRenewalFor((int) $d->id) !== null) {
            return 'a renewal is already open for this domain';
        }

        return null;
    }

    /** @return object|null */
    public function openRenewalFor(int $customerDomainId)
    {
        return DB::table('infra_domain_renewals')
            ->where('customer_domain_id', $customerDomainId)
            ->whereIn('state', S::open())
            ->first();
    }

    /**
     * Create the renewal rows. Idempotent: a domain that already has an open
     * renewal is skipped, and the insert is guarded by a unique idempotency key
     * so two schedulers racing cannot both win.
     *
     * @return array<string,int>
     */
    public function schedule(?int $workspaceId = null, bool $dryRun = true): array
    {
        $found = $this->findEligible($workspaceId);
        $created = 0;
        $skipped = count($found['skipped']);

        foreach ($found['eligible'] as $e) {
            if ($dryRun) {
                continue;
            }

            $done = DB::transaction(function () use ($e) {
                // Re-check under a lock: another scheduler may have inserted
                // between our read and this write.
                $existing = DB::table('infra_domain_renewals')
                    ->where('customer_domain_id', $e['customer_domain_id'])
                    ->whereIn('state', S::open())
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    return false;
                }

                DB::table('infra_domain_renewals')->insert([
                    'workspace_id' => $e['workspace_id'],
                    'customer_domain_id' => $e['customer_domain_id'],
                    'domain' => $e['domain'],
                    'state' => S::SCHEDULED,
                    'renewal_mode' => $e['mode'],
                    'years' => 1,
                    'scheduled_for' => $e['expires_at'],
                    'window_opens_at' => $e['window_opens_at'],
                    'expiry_before' => $e['expires_at'],
                    'provider' => 'namecheap',
                    'idempotency_key' => $this->idempotencyKey($e['customer_domain_id'], $e['expires_at']),
                    'metadata_json' => json_encode([
                        'scheduled_by' => 'infra:domain-renewals schedule',
                        'scheduled_at' => now()->toDateTimeString(),
                        'days_until_expiry_at_scheduling' => $e['days_until_expiry'],
                        'auto_renew_at_scheduling' => $e['auto_renew'],
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            });

            $created += $done ? 1 : 0;
        }

        return [
            'eligible' => count($found['eligible']),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Deterministic per (domain, term). A retry of the same renewal reuses the
     * same key; a genuinely new term produces a new one.
     */
    private function idempotencyKey(int $domainId, $expiresAt): string
    {
        return 'dr_' . substr(hash('sha256', $domainId . '|' . Carbon::parse($expiresAt)->toDateString()), 0, 40);
    }

    /** Move scheduled renewals into `due` once their window opens. */
    public function markDue(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?: now();

        $rows = DB::table('infra_domain_renewals')
            ->where('state', S::SCHEDULED)
            ->where('window_opens_at', '<=', $asOf)
            ->get();

        $n = 0;

        foreach ($rows as $r) {
            $this->transition($r->id, S::SCHEDULED, S::DUE, ['due_at' => $asOf->toDateTimeString()]);
            $n++;
        }

        return $n;
    }

    // ── STAGE 2: EXECUTION ───────────────────────────────────────────────────

    /**
     * Attempt one renewal. Never called in bulk without an explicit confirmation
     * upstream — this spends money.
     *
     * @return array<string,mixed>
     */
    public function execute(int $renewalId, bool $force = false): array
    {
        if (! config('infrastructure.renewals.execution_enabled', false) && ! $force) {
            return $this->outcome($renewalId, 'BLOCKED', 'Renewal execution is disabled (infrastructure.renewals.execution_enabled=false).');
        }

        $r = DB::table('infra_domain_renewals')->where('id', $renewalId)->first();

        if ($r === null) {
            return $this->outcome($renewalId, 'NOT_FOUND', 'No such renewal.');
        }

        if (in_array($r->state, S::inFlight(), true)) {
            return $this->outcome($renewalId, 'IN_FLIGHT',
                "Renewal is {$r->state}: money may already have moved. Verify before re-attempting.");
        }

        if (! in_array($r->state, S::actionable(), true)) {
            return $this->outcome($renewalId, 'NOT_ACTIONABLE', "State {$r->state} is not actionable.");
        }

        if ($r->attempt_count >= self::MAX_ATTEMPTS) {
            $this->transition($r->id, $r->state, S::NEEDS_MANUAL, ['reason' => 'attempts exhausted']);

            return $this->outcome($renewalId, 'ATTEMPTS_EXHAUSTED', 'Escalated to manual recovery.');
        }

        // Capture the registrar's CURRENT expiry immediately before spending.
        // This is what makes a later retry safe: if expiry has already moved, a
        // previous ambiguous attempt actually succeeded.
        $before = $this->observedExpiry($r->domain);

        // A renewal may be executed straight from `scheduled` by an operator, but
        // the machine only allows SCHEDULED -> DUE -> ATTEMPTING. Previously a
        // direct write bypassed the machine entirely and hid this; stepping
        // through DUE keeps both the invariant and the audit trail intact.
        $from = $r->state;

        if ($from === S::SCHEDULED) {
            $this->transition($r->id, S::SCHEDULED, S::DUE, ['reason' => 'execution requested before the window opened']);
            $from = S::DUE;
        }

        // Routed through transition() so the attempt itself is auditable. A direct
        // update here left a hole in the trail: S4 validation showed the ledger
        // jumping from `due` to `verifying` with no record of the attempt.
        $this->transition($r->id, $from, S::ATTEMPTING, [
            'attempt' => $r->attempt_count + 1,
            'expiry_before' => (string) ($before ?: $r->expiry_before),
        ], [
            'attempted_at' => now(),
            'attempt_count' => $r->attempt_count + 1,
            'expiry_before' => $before ?: $r->expiry_before,
        ]);

        try {
            $result = $this->registrar()->renewDomain($r->domain, (int) $r->years, $r->idempotency_key);
        } catch (\Throwable $e) {
            // A thrown exception on a BILLABLE call is the ambiguous case: the
            // request may well have reached the registrar. Never treat as failure.
            Log::warning('infra888.renewal.ambiguous', ['renewal' => $r->id, 'domain' => $r->domain]);

            $this->transition($r->id, S::ATTEMPTING, S::VERIFYING, [
                'ambiguity' => 'transport exception on a billable call',
                'error' => Str::limit($e->getMessage(), 300),
            ], ['failure_class' => 'ambiguous', 'failure_code' => 'TRANSPORT_AMBIGUOUS']);

            return $this->outcome($renewalId, 'AMBIGUOUS', 'Provider call failed in a way that may still have charged us. Verifying.');
        }

        // Even an explicit success goes to VERIFYING. The provider saying "OK" is
        // a claim; the expiry date moving is the evidence.
        if ($result->success) {
            DB::table('infra_domain_renewals')->where('id', $r->id)->update([
                'provider_order_id' => (string) ($result->data['order_id'] ?? '') ?: null,
                'provider_transaction_id' => (string) ($result->data['transaction_id'] ?? '') ?: null,
                'amount_minor' => isset($result->data['charged_amount'])
                    ? (int) round(((float) $result->data['charged_amount']) * 100) : null,
                'updated_at' => now(),
            ]);

            $this->transition($r->id, S::ATTEMPTING, S::VERIFYING, [
                'provider_claim' => 'renewal reported successful',
                'provider_reported_expiry' => $result->data['expiry_after'] ?? null,
                'note' => 'Provider OK recorded as a claim; RENEWED requires read-back.',
            ]);

            return $this->outcome($renewalId, 'ACCEPTED', 'Provider reported success. Verifying against the registrar.');
        }

        // Explicit, classified failure.
        //
        // S5 validation showed two ways the naive mapping was wrong:
        //
        // 1. The connector marks an ambiguous BILLABLE failure as 'permanent' on
        //    purpose — "never auto-retry an ambiguous billable failure" — and
        //    signals it through normalizedState `needs_reconciliation`. Reading
        //    only isRetryable() turned "we may have been charged, a human must
        //    check" into ABANDONED, which is the single most expensive possible
        //    misreading: the money is gone and the domain still lapses.
        //
        // 2. NamecheapClient emits the retry class 'transient', while
        //    ProviderResult::isRetryable() tests for the literal 'retryable'.
        //    Trusting isRetryable() alone made every recoverable transport
        //    failure look permanent.
        $ambiguous = $result->normalizedState === 'needs_reconciliation';
        $retryable = $result->isRetryable()
            || in_array($result->retryClassification, ['retryable', 'transient'], true);

        if ($ambiguous) {
            $class = 'ambiguous';
            $next = S::NEEDS_MANUAL;
        } else {
            $class = $retryable ? 'transient' : 'permanent';
            $next = $retryable ? S::FAILED : S::ABANDONED;
        }

        $this->transition($r->id, S::ATTEMPTING, $next, [
            'failure' => $result->errorCode ?? 'UNKNOWN',
        ], [
            'failure_class' => $class,
            'failure_code' => Str::limit((string) ($result->errorCode ?? 'UNKNOWN'), 64, ''),
            'failure_summary' => Str::limit((string) ($result->errorSummary ?? ''), 1000),
            'next_attempt_at' => $class === 'transient' ? $this->backoff((int) $r->attempt_count + 1) : null,
        ]);

        if ($ambiguous) {
            return $this->outcome($renewalId, 'AMBIGUOUS_BILLABLE',
                'Provider outcome ambiguous — the charge may have succeeded. Escalated for reconciliation; NOT retried.');
        }

        return $this->outcome($renewalId, strtoupper($class) . '_FAILURE', (string) ($result->errorSummary ?? 'Renewal failed.'));
    }

    private function backoff(int $attempt): Carbon
    {
        return now()->addMinutes(self::BACKOFF_MINUTES[$attempt] ?? 1440);
    }

    // ── STAGE 3: VERIFICATION (read-back) ────────────────────────────────────

    /**
     * The only path to RENEWED. Reads the registrar's real expiry and proves it
     * moved past what we recorded before spending.
     *
     * @return array<string,mixed>
     */
    public function verify(int $renewalId): array
    {
        $r = DB::table('infra_domain_renewals')->where('id', $renewalId)->first();

        if ($r === null || $r->state !== S::VERIFYING) {
            return $this->outcome($renewalId, 'NOT_VERIFYING', 'Only renewals in `verifying` can be verified.');
        }

        $observed = $this->observedExpiry($r->domain);

        DB::table('infra_domain_renewals')->where('id', $r->id)->update([
            'verify_attempts' => $r->verify_attempts + 1,
            'expiry_observed' => $observed,
            'updated_at' => now(),
        ]);

        if ($observed === null) {
            if ($r->verify_attempts + 1 >= self::MAX_VERIFY_ATTEMPTS) {
                $this->transition($r->id, S::VERIFYING, S::NEEDS_MANUAL, [
                    'reason' => 'could not read expiry from the registrar after ' . self::MAX_VERIFY_ATTEMPTS . ' attempts',
                ], ['failure_class' => 'ambiguous', 'failure_code' => 'VERIFY_UNREADABLE']);

                return $this->outcome($renewalId, 'ESCALATED', 'Registrar expiry unreadable. Escalated — do NOT re-attempt blindly.');
            }

            return $this->outcome($renewalId, 'UNKNOWN', 'Registrar did not return an expiry. Still verifying.');
        }

        $before = $r->expiry_before ? Carbon::parse($r->expiry_before) : null;

        if ($before !== null && Carbon::parse($observed)->gt($before)) {
            $this->transition($r->id, S::VERIFYING, S::RENEWED, [
                'evidence' => 'registrar expiry read back and confirmed moved',
                'expiry_before' => $before->toDateTimeString(),
                'expiry_observed' => (string) $observed,
                'verify_attempt' => $r->verify_attempts + 1,
            ], [
                'completed_at' => now(),
                'verified_at' => now(),
            ]);

            // The estate learns the new truth only once it is proven.
            DB::table('customer_domains')->where('id', $r->customer_domain_id)->update([
                'expires_at' => $observed,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->outcome($renewalId, 'RENEWED',
                "Verified: expiry moved {$before->toDateString()} -> " . Carbon::parse($observed)->toDateString());
        }

        if ($r->verify_attempts + 1 >= self::MAX_VERIFY_ATTEMPTS) {
            $this->transition($r->id, S::VERIFYING, S::NEEDS_MANUAL, [
                'reason' => 'expiry never moved after ' . self::MAX_VERIFY_ATTEMPTS . ' read-backs',
                'expiry_before' => (string) $r->expiry_before,
                'expiry_observed' => (string) $observed,
            ], ['failure_class' => 'ambiguous', 'failure_code' => 'EXPIRY_UNCHANGED']);

            return $this->outcome($renewalId, 'ESCALATED', 'Expiry did not move. A human must confirm whether we were charged.');
        }

        return $this->outcome($renewalId, 'PENDING', 'Expiry has not moved yet. Registrars can lag; will re-check.');
    }

    /** Read the registrar's actual expiry. Null means "we could not establish it". */
    private function observedExpiry(string $domain): ?string
    {
        try {
            $status = $this->registrar()->getDomainStatus($domain);
        } catch (\Throwable) {
            return null;
        }

        if (! $status->success) {
            return null;
        }

        $raw = $status->data['expires_at'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── STAGE 4: MONITORING ──────────────────────────────────────────────────

    /**
     * Every check answers a question an operator would actually ask.
     *
     * @return array<int,array<string,mixed>>
     */
    public function monitor(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $f = [];

        $add = function (string $check, string $severity, string $detail, $subject = null) use (&$f) {
            $f[] = ['check' => $check, 'severity' => $severity, 'detail' => $detail, 'subject' => $subject];
        };

        // 1. upcoming expiry with nothing planned
        foreach (CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)->get() as $d) {
            if ($d->expires_at === null) {
                $add('missing_expiry', 'warning', 'Active domain has no expiry recorded — renewal cannot be planned.', $d->domain);

                continue;
            }

            $days = (int) $asOf->diffInDays($d->expires_at, false);

            if ($days < 0) {
                $add('overdue_renewal', 'critical', "Expired {$days} days ago and still marked active.", $d->domain);
            } elseif ($days <= self::REMINDER_DAYS && $this->openRenewalFor((int) $d->id) === null) {
                $add('upcoming_expiry_unplanned', 'warning', "Expires in {$days} days with no renewal scheduled.", $d->domain);
            }
        }

        // 2. renewals stuck in flight — money may have moved
        foreach (DB::table('infra_domain_renewals')->whereIn('state', S::inFlight())->get() as $r) {
            $age = $r->attempted_at ? Carbon::parse($r->attempted_at)->diffInHours($asOf) : 0;

            if ($age >= 2) {
                $add('verification_stuck', 'critical',
                    "In `{$r->state}` for {$age}h — unresolved billable outcome.", $r->domain);
            }
        }

        // 3. escalations waiting on a human
        foreach (DB::table('infra_domain_renewals')->where('state', S::NEEDS_MANUAL)->get() as $r) {
            $add('needs_manual', 'critical', "Awaiting manual recovery ({$r->failure_code}).", $r->domain);
        }

        // 4. retry backlog
        foreach (DB::table('infra_domain_renewals')->whereIn('state', [S::FAILED, S::DUNNING])
            ->where('next_attempt_at', '<=', $asOf)->get() as $r) {
            $add('retry_due', 'warning', "Retry due (attempt {$r->attempt_count}).", $r->domain);
        }

        // 5. drift: recorded expiry vs the registrar's
        // Only the MOST RECENT renewal per domain describes current truth. Comparing
        // superseded historical renewals to the estate reported drift that was
        // simply history — surfaced by running two validation renewals back to back.
        $latest = DB::table('infra_domain_renewals')
            ->where('state', S::RENEWED)
            ->orderBy('id')
            ->get()
            ->keyBy('customer_domain_id');

        foreach ($latest as $r) {
            $d = CustomerDomain::find($r->customer_domain_id);

            if ($d && $r->expiry_observed && $d->expires_at
                && ! Carbon::parse($d->expires_at)->equalTo(Carbon::parse($r->expiry_observed))) {
                $add('renewal_drift', 'warning',
                    'Recorded expiry differs from the verified renewal expiry.', $r->domain);
            }
        }

        // 6. stale observation — never let old data read as healthy
        foreach (CustomerDomain::all() as $d) {
            if ($d->last_synced_at === null || Carbon::parse($d->last_synced_at)->diffInDays($asOf) > 7) {
                $add('stale_verification', 'warning',
                    'Registrar state not observed in over 7 days — treat as unknown, not healthy.', $d->domain);
            }
        }

        return $f;
    }

    // ── plumbing ─────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $note @param array<string,mixed> $extra */
    private function transition(int $id, string $from, string $to, array $note = [], array $extra = []): void
    {
        \App\Engines\Infrastructure\States\DomainRenewalState::assertTransition($from, $to);

        $row = DB::table('infra_domain_renewals')->where('id', $id)->first();
        $meta = json_decode((string) ($row->metadata_json ?? '{}'), true) ?: [];
        $meta['history'][] = ['at' => now()->toDateTimeString(), 'from' => $from, 'to' => $to] + $note;

        DB::table('infra_domain_renewals')->where('id', $id)->update(array_merge([
            'state' => $to,
            'previous_state' => $from,
            'metadata_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ], $extra));
    }

    /** @return array<string,mixed> */
    private function outcome(int $id, string $code, string $message): array
    {
        return ['renewal_id' => $id, 'outcome' => $code, 'message' => $message];
    }

    // ── CUSTOMER / ADMIN PROJECTIONS ─────────────────────────────────────────

    /**
     * What the customer may see. No registrar name, no provider identifiers, no
     * wholesale amounts — the customer's supplier is LevelUp Growth.
     *
     * @return array<string,mixed>
     */
    public function customerView(object $r): array
    {
        $map = [
            S::SCHEDULED => 'Renewal scheduled',
            S::DUE => 'Renewing soon',
            S::ATTEMPTING => 'Renewal in progress',
            S::VERIFYING => 'Confirming renewal',
            S::RENEWED => 'Renewed',
            S::FAILED => 'Renewal did not complete — we are retrying',
            S::DUNNING => 'Renewal needs attention',
            S::NEEDS_MANUAL => 'Our team is reviewing this renewal',
            S::ABANDONED => 'Renewal was not completed',
            S::CANCELLED => 'Renewal cancelled',
        ];

        return [
            'domain' => $r->domain,
            'status' => $map[$r->state] ?? 'Unknown',
            'renews_on' => $r->scheduled_for ? Carbon::parse($r->scheduled_for)->toDateString() : null,
            'expires_on' => $r->expiry_observed
                ? Carbon::parse($r->expiry_observed)->toDateString()
                : ($r->expiry_before ? Carbon::parse($r->expiry_before)->toDateString() : null),
            'action_required' => in_array($r->state, [S::DUNNING, S::NEEDS_MANUAL], true),
            'provider' => 'LevelUp Growth',
        ];
    }

    /** Full operational truth, including provider identity. Admin only. */
    public function adminView(object $r): array
    {
        return [
            'id' => $r->id, 'domain' => $r->domain, 'workspace_id' => $r->workspace_id,
            'state' => $r->state, 'previous_state' => $r->previous_state,
            'mode' => $r->renewal_mode, 'attempts' => $r->attempt_count,
            'verify_attempts' => $r->verify_attempts, 'next_attempt_at' => $r->next_attempt_at,
            'provider' => $r->provider,
            'provider_order_id' => $r->provider_order_id,
            'provider_transaction_id' => $r->provider_transaction_id,
            'idempotency_key' => $r->idempotency_key,
            'expiry_before' => $r->expiry_before, 'expiry_observed' => $r->expiry_observed,
            'verified_at' => $r->verified_at,
            'failure_code' => $r->failure_code, 'failure_class' => $r->failure_class,
            'failure_summary' => $r->failure_summary,
            'amount_minor' => $r->amount_minor,
            'history' => json_decode((string) ($r->metadata_json ?? '{}'), true)['history'] ?? [],
        ];
    }
}
