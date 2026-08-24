<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operational health of the platform event system.
 *
 * Read-only. Reports counts, ages and flag states — never payloads, never
 * personal data, never secrets. An operator must be able to answer "is the
 * chain healthy?" without being shown what a customer bought.
 *
 * Severity is derived from the processing interval rather than from arbitrary
 * numbers: lag shorter than one interval is normal, not an alert.
 */
class EventSystemHealth
{
    public const OK       = 'ok';
    public const INFO     = 'info';
    public const WARNING  = 'warning';
    public const CRITICAL = 'critical';

    public function report(): array
    {
        $interval = (int) config('platform_events.processing.interval_minutes', 5);
        $warnAge = (int) config('platform_events.monitoring.warning_age_minutes', 15);
        $critAge = (int) config('platform_events.monitoring.critical_age_minutes', 30);
        $critPerm = (int) config('platform_events.monitoring.critical_permanent_failures', 3);
        $critStale = (int) config('platform_events.monitoring.critical_stale_claims', 1);
        $staleAfter = (int) config('platform_events.delivery_worker.stale_claim_minutes', 15);

        $outbox = new Outbox();

        $flags = [
            'foundation_enabled' => config('platform_events.enabled') === true,
            'producer_enabled' => ((array) config('platform_events.producers', []))['domain.order.created'] ?? false,
            'producer_workspace_allowlist' => $outbox->allowedWorkspaces(),
            'fanout_enabled' => config('platform_events.fanout.enabled') === true,
            'delivery_enabled' => config('platform_events.delivery_worker.enabled') === true,
            'audit_subscriber_enabled' => ((array) config('platform_events.subscribers', []))['platform.audit'] ?? false,
            // Eligibility and execution are separate concerns and are reported
            // separately. A subscriber can be eligible (accruing obligations) while
            // paused (running nothing) — one list could not express that.
            'eligible_subscribers' => array_keys(SubscriberRegistry::eligible()),
            'executable_subscribers' => array_keys(SubscriberRegistry::executable()),
            'subscriber_states' => SubscriberRegistry::states(),
        ];

        $backlog = $this->backlog($staleAfter);
        $timestamps = $this->timestamps();
        $findings = [];

        // ── un-fanned events ───────────────────────────────────────────────
        if ($backlog['oldest_unfanned_age_minutes'] !== null) {
            $age = $backlog['oldest_unfanned_age_minutes'];

            if ($age >= $critAge) {
                $findings[] = [self::CRITICAL, "oldest un-fanned event is {$age}m old (>= {$critAge}m)"];
            } elseif ($age >= $warnAge) {
                $findings[] = [self::WARNING, "oldest un-fanned event is {$age}m old (>= {$warnAge}m)"];
            } elseif ($age > $interval) {
                $findings[] = [self::INFO, "oldest un-fanned event is {$age}m old — within normal delay"];
            }
        }

        // ── pending deliveries ─────────────────────────────────────────────
        if ($backlog['oldest_pending_delivery_age_minutes'] !== null) {
            $age = $backlog['oldest_pending_delivery_age_minutes'];

            if ($age >= $critAge) {
                $findings[] = [self::CRITICAL, "oldest pending delivery is {$age}m old (>= {$critAge}m)"];
            } elseif ($age >= $warnAge) {
                $findings[] = [self::WARNING, "oldest pending delivery is {$age}m old (>= {$warnAge}m)"];
            }
        }

        // ── obligations held for a paused subscriber ───────────────────────
        if (($backlog['pending_awaiting_paused_subscriber'] ?? 0) > 0) {
            $paused = array_keys(array_filter(
                SubscriberRegistry::states(),
                static fn (string $st): bool => $st === SubscriberRegistry::STATE_EXECUTION_PAUSED
            ));

            $findings[] = [
                self::INFO,
                $backlog['pending_awaiting_paused_subscriber']
                . ' delivery obligation(s) preserved for paused subscriber(s) '
                . ($paused === [] ? '(execution disabled)' : implode(', ', $paused))
                . ' — these will process when execution resumes',
            ];
        }

        // ── domain-payment webhook incidents ───────────────────────────────
        $webhook = $this->webhookState();

        if ($webhook['unacknowledged_failures'] > 0) {
            $findings[] = [
                self::CRITICAL,
                sprintf(
                    '%d unacknowledged domain-payment webhook failure(s) (%d retryable, %d permanent), '
                    . 'oldest %s minutes old, last event %s — a customer may have been charged without '
                    . 'the order being updated',
                    $webhook['unacknowledged_failures'],
                    $webhook['retryable_failures'],
                    $webhook['permanent_failures'],
                    $webhook['oldest_unresolved_age_minutes'] ?? '?',
                    $webhook['last_failure']['stripe_event_id'] ?? 'unknown'
                ),
            ];
        }

        if ($webhook['fulfilment_enabled'] !== true) {
            $findings[] = [
                self::INFO,
                'domain fulfilment is DISABLED — payments will commit and record an event '
                . 'but no registration will be queued',
            ];
        }

        // ── failures ───────────────────────────────────────────────────────
        if ($backlog['permanently_failed'] > 0) {
            $n = $backlog['permanently_failed'];
            $findings[] = [
                $n >= $critPerm ? self::CRITICAL : self::WARNING,
                "{$n} permanently failed delivery(ies) — each needs investigation",
            ];
        }

        if ($backlog['retryable_failures'] > 0) {
            $findings[] = [self::INFO, $backlog['retryable_failures'] . ' delivery(ies) awaiting retry'];
        }

        // ── stale claims: a worker died mid-delivery ────────────────────────
        if ($backlog['stale_processing'] >= max(1, $critStale)) {
            $findings[] = [
                self::CRITICAL,
                $backlog['stale_processing'] . " claim(s) stuck in processing beyond {$staleAfter}m — a worker died",
            ];
        }

        $infra = $this->infrastructure();
        $processing = $this->processingFailures();

        // A pass that threw returned non-zero and logged twice — but before Phase 1D
        // nothing survived for anyone LOOKING at health to see, so the next clean
        // cycle made the system read as healthy. A recorded failure now degrades the
        // report until an operator acknowledges it with --ack-failures.
        if (($processing['count'] ?? 0) > 0) {
            $recent = $processing['last_at'] !== null
                && strtotime((string) $processing['last_at']) >= strtotime('-1 hour');

            $findings[] = [
                $recent ? self::CRITICAL : self::WARNING,
                sprintf(
                    '%d unacknowledged processing failure(s), last at %s: %s — clear with '
                    . '`platform-events:process --ack-failures` once investigated',
                    $processing['count'],
                    $processing['last_at'] ?? 'unknown',
                    $processing['last_error'] ?? 'no detail recorded'
                ),
            ];
        }

        if ($flags['fanout_enabled'] && $infra['scheduler_registered'] === null) {
            $findings[] = [
                self::INFO,
                'fan-out is enabled but the schedule cannot be verified from this context; '
                . 'run `platform-events:process --health` to confirm',
            ];
        }

        if ($flags['fanout_enabled'] && $infra['scheduler_registered'] === false) {
            $findings[] = [self::CRITICAL, 'fan-out is enabled but no schedule invokes the processing command'];
        }

        if ($flags['delivery_enabled'] && ! $infra['queue_workers_running']) {
            $findings[] = [self::WARNING, 'delivery is enabled but no queue worker was detected'];
        }

        return [
            'severity' => $this->worst($findings),
            'findings' => array_map(static fn ($f) => ['severity' => $f[0], 'message' => $f[1]], $findings),
            'flags' => $flags,
            'backlog' => $backlog,
            'timestamps' => $timestamps,
            'infrastructure' => $infra,
            'processing_failures' => $processing,
            'domain_payment_webhook' => $this->webhookState(),
            'thresholds' => [
                'interval_minutes' => $interval,
                'warning_age_minutes' => $warnAge,
                'critical_age_minutes' => $critAge,
                'critical_permanent_failures' => $critPerm,
                'stale_claim_minutes' => $staleAfter,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function backlog(int $staleAfter): array
    {
        $oldestUnfanned = DB::table('platform_events')->whereNull('fanned_out_at')->min('recorded_at');

        // A delivery waiting on a PAUSED subscriber is not a backlog — it is the
        // system correctly preserving an obligation. Ageing it would raise a
        // CRITICAL for a deliberate operational decision, so the age thresholds
        // apply only to deliveries whose subscriber may actually run.
        $executableKeys = array_keys(SubscriberRegistry::executable());

        $waiting = [DeliveryWorker::STATUS_PENDING, DeliveryWorker::STATUS_RETRYABLE_FAILURE];

        $oldestPending = $executableKeys === []
            ? null
            : DB::table('platform_event_deliveries')
                ->whereIn('status', $waiting)
                ->whereIn('subscriber_key', $executableKeys)
                ->min('created_at');

        $pausedPending = DB::table('platform_event_deliveries')->whereIn('status', $waiting);

        if ($executableKeys !== []) {
            $pausedPending->whereNotIn('subscriber_key', $executableKeys);
        }

        $pausedPending = (int) $pausedPending->count();

        return [
            'events_total' => (int) DB::table('platform_events')->count(),
            'events_awaiting_fanout' => (int) DB::table('platform_events')->whereNull('fanned_out_at')->count(),
            'oldest_unfanned_age_minutes' => $this->ageMinutes($oldestUnfanned),

            'deliveries_total' => (int) DB::table('platform_event_deliveries')->count(),
            'pending_deliveries' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_PENDING)->count(),
            'oldest_pending_delivery_age_minutes' => $this->ageMinutes($oldestPending),
            'pending_awaiting_paused_subscriber' => $pausedPending,

            'retryable_failures' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_RETRYABLE_FAILURE)->count(),
            'permanently_failed' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_PERMANENTLY_FAILED)->count(),
            'skipped' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_SKIPPED)->count(),
            'delivered' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_DELIVERED)->count(),

            'stale_processing' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_PROCESSING)
                ->where('claimed_at', '<', now()->subMinutes($staleAfter))
                ->count(),
            'processing_now' => (int) DB::table('platform_event_deliveries')
                ->where('status', DeliveryWorker::STATUS_PROCESSING)->count(),
        ];
    }

    private function timestamps(): array
    {
        return [
            'last_event_recorded_at' => DB::table('platform_events')->max('recorded_at'),
            'last_fanout_at' => DB::table('platform_events')->max('fanned_out_at'),
            'last_successful_delivery_at' => DB::table('platform_event_deliveries')->max('delivered_at'),
            'last_failure_at' => DB::table('platform_event_deliveries')->max('failed_at'),
            // Audit rows this system produced — the parity anchor.
            'audit_rows_from_events' => (int) DB::table('audit_logs')
                ->whereRaw("JSON_EXTRACT(metadata_json, '$.source') = 'platform_event'")
                ->count(),
        ];
    }

    /**
     * Read the recorded processing-failure state. READ-ONLY: never clears it, because
     * clearing is an explicit operator action, not a side effect of looking.
     *
     * @return array{count:int,first_at:?string,last_at:?string,last_error:?string}
     */
    /**
     * Domain-payment webhook visibility (Phase 1E.1).
     *
     * READ-ONLY. A later clean webhook records its own success marker and never
     * erases the failure history — the two live under separate keys precisely so a
     * good delivery cannot quietly close an unresolved incident.
     */
    private function webhookState(): array
    {
        $empty = [
            'last_success' => null,
            'last_failure' => null,
            'unacknowledged_failures' => 0,
            'retryable_failures' => 0,
            'permanent_failures' => 0,
            'oldest_unresolved_age_minutes' => null,
            'fulfilment_enabled' => config('domains.fulfilment.enabled') === true,
            'queued_registration_jobs' => $this->queuedRegistrationJobs(),
        ];

        try {
            $fail = Cache::get(\App\Core\Billing\StripeService::WEBHOOK_FAILURE_STATE_KEY);
            $ok = Cache::get(\App\Core\Billing\StripeService::WEBHOOK_SUCCESS_STATE_KEY);
        } catch (Throwable) {
            return $empty;
        }

        $out = $empty;
        $out['last_success'] = is_array($ok) ? $ok : null;

        if (is_array($fail)) {
            $out['unacknowledged_failures'] = (int) ($fail['count'] ?? 0);
            $out['retryable_failures'] = (int) ($fail['retryable_count'] ?? 0);
            $out['permanent_failures'] = (int) ($fail['permanent_count'] ?? 0);
            $out['oldest_unresolved_age_minutes'] = $this->ageMinutes($fail['first_at'] ?? null);

            // Identifiers only — never a raw body, a signature or a secret.
            $last = (array) ($fail['last'] ?? []);
            $out['last_failure'] = [
                'stripe_event_id' => $last['stripe_event_id'] ?? null,
                'stripe_event_type' => $last['stripe_event_type'] ?? null,
                'order_id' => $last['order_id'] ?? null,
                'classification' => $last['classification'] ?? null,
                'retryable' => $last['retryable'] ?? null,
                'exception_class' => $last['exception_class'] ?? null,
                'at' => $last['at'] ?? null,
            ];
        }

        return $out;
    }

    private function queuedRegistrationJobs(): ?int
    {
        try {
            return (int) app('redis')->connection()->llen('queues:tasks-high');
        } catch (Throwable) {
            return null;
        }
    }

    private function processingFailures(): array
    {
        $empty = ['count' => 0, 'first_at' => null, 'last_at' => null, 'last_error' => null];

        try {
            $rec = Cache::get(\App\Console\Commands\PlatformEventsProcessCommand::FAILURE_STATE_KEY);
        } catch (Throwable) {
            // Cache unreachable: report nothing rather than inventing a failure.
            return $empty;
        }

        if (! is_array($rec)) {
            return $empty;
        }

        return [
            'count' => (int) ($rec['count'] ?? 0),
            'first_at' => $rec['first_at'] ?? null,
            'last_at' => $rec['last_at'] ?? null,
            'last_error' => $rec['last_error'] ?? null,
        ];
    }

    private function infrastructure(): array
    {
        // TRUE / FALSE / NULL, where NULL means UNDETERMINED.
        //
        // withSchedule() is wired to Artisan::starting, so the Schedule instance is
        // only populated inside a console command. Outside one (an HTTP health
        // endpoint) events() is legitimately empty, and reporting that as "not
        // scheduled" would raise a false CRITICAL. An empty schedule therefore
        // means "cannot tell from here", and only a POPULATED schedule that lacks
        // our command is a real negative — which is the dangerous case (someone
        // removed the entry) and is still detected.
        $scheduled = null;

        try {
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $events = $schedule->events();

            if ($events !== []) {
                $scheduled = false;

                foreach ($events as $e) {
                    if (str_contains((string) $e->command, 'platform-events:process')) {
                        $scheduled = true;
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $scheduled = null;
        }

        return [
            'scheduler_registered' => $scheduled,
            'queue_workers_running' => $this->queueWorkersRunning(),
            'queue_connection' => (string) config('queue.default'),
        ];
    }

    /**
     * Best-effort worker detection. Never throws: a health report that dies
     * because it could not inspect the process table is worse than one that
     * reports "unknown".
     */
    private function queueWorkersRunning(): ?bool
    {
        try {
            if (! function_exists('shell_exec')) {
                return null;
            }

            $out = @shell_exec("pgrep -fc 'artisan queue:work' 2>/dev/null");

            return $out === null ? null : ((int) trim((string) $out)) > 0;
        } catch (Throwable) {
            return null;
        }
    }

    private function ageMinutes(?string $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            return (int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($timestamp), absolute: true);
        } catch (Throwable) {
            return null;
        }
    }

    private function worst(array $findings): string
    {
        $rank = [self::OK => 0, self::INFO => 1, self::WARNING => 2, self::CRITICAL => 3];
        $worst = self::OK;

        foreach ($findings as [$sev, $_]) {
            if ($rank[$sev] > $rank[$worst]) {
                $worst = $sev;
            }
        }

        return $worst;
    }
}
