<?php

namespace App\Core\Email888\Admin;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\EmailDeliveryEvent;
use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EMAIL888 EM-6 — webhook ingress administration.
 *
 * The delivery ledger answers "what happened to this message". This answers the
 * question underneath it: "is the channel that tells us what happened actually
 * working, and if not, since when and whose fault".
 *
 * DERIVED FROM EVIDENCE, NEVER FROM ASSUMPTION
 * Every number below is a query over rows that exist. Nothing here asks a
 * provider, infers a state, or fills a gap. In particular, health deliberately
 * refuses to say "healthy" when the truthful answer is "nothing has happened
 * lately, so I cannot tell" — reporting green from an absence of evidence is
 * the same defect as reporting a send from an HTTP 200.
 */
class WebhookAdminController
{
    private const PER_PAGE     = 50;
    private const MAX_PER_PAGE = 200;

    /** A provider that has accepted a message owes us an event. This is how long we wait. */
    private const OWED_EVENT_MINUTES = 30;

    public function index(Request $request): JsonResponse
    {
        $q = WebhookReceipt::query();

        foreach ([
            'provider'              => 'provider',
            'authentication_result' => 'authentication_result',
            'validation_result'     => 'validation_result',
            'processing_result'     => 'processing_result',
            'processing_stage'      => 'processing_stage',
            'event_type'            => 'event_type',
            'stream'                => 'stream',
            'provider_message_id'   => 'provider_message_id',
            'request_id'            => 'request_id',
            'http_status'           => 'http_status',
        ] as $param => $column) {
            if (($v = $request->query($param)) !== null && $v !== '') {
                $q->where($column, $v);
            }
        }

        // Operators think in questions, not column values.
        if (filter_var($request->query('refused'), FILTER_VALIDATE_BOOLEAN)) {
            $q->whereIn('authentication_result', WebhookReceipt::refusalReasons());
        }
        if (filter_var($request->query('accepted'), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('authentication_result', WebhookReceipt::AUTH_ACCEPTED);
        }

        if ($from = $request->query('from')) { $q->where('received_at', '>=', $from); }
        if ($to   = $request->query('to'))   { $q->where('received_at', '<=', $to); }

        // Bounded to identifiers an operator pastes in. Never a LIKE over the
        // digest or the endpoint — those are not things anyone searches for.
        if (($s = trim((string) $request->query('search', ''))) !== '') {
            $q->where(function ($w) use ($s) {
                $w->where('provider_message_id', $s)
                  ->orWhere('request_id', $s)
                  ->orWhere('correlation_id', $s)
                  ->orWhere('client_ip', $s)
                  ->orWhere('operator_visible_reason', 'like', '%' . $s . '%');
            });
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('per_page', self::PER_PAGE)));
        $page    = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => array_map(fn (WebhookReceipt $r) => $r->toAdminArray(), $page->items()),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
            // Counts over the WHOLE table, not this page. A page-scoped "0
            // refusals" is a lie an operator has no way to detect.
            'summary' => $this->summary(),
            'facets'  => $this->facets(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $r = WebhookReceipt::find($id);

        if (! $r) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        return response()->json([
            'success'  => true,
            'receipt'  => $r->toAdminArray() + ['client_ip' => $r->client_ip],
            // Only stages with evidence. A refused attempt genuinely has two.
            'timeline' => $r->stages(),
            'delivery' => $this->linkedDelivery($r),
            'replay'   => $this->replayPosture(),
            'guidance' => $this->guidance($r),
        ]);
    }

    /**
     * PHASE 2 — health, derived only from what is recorded.
     */
    public function health(): JsonResponse
    {
        $since = now()->subDay();

        $lastAny      = WebhookReceipt::max('received_at');
        $lastAccepted = WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_ACCEPTED)->max('received_at');
        $lastRefused  = WebhookReceipt::whereIn('authentication_result', WebhookReceipt::refusalReasons())->max('received_at');

        $refusals24h = WebhookReceipt::where('received_at', '>=', $since)
            ->whereIn('authentication_result', WebhookReceipt::refusalReasons())
            ->select('authentication_result', DB::raw('count(*) as n'))
            ->groupBy('authentication_result')
            ->pluck('n', 'authentication_result')
            ->map(fn ($n) => (int) $n)
            ->all();

        // The refusals that mean WE are broken right now, as opposed to someone
        // knocking. Kept separate because they demand different actions.
        $ourFault24h = (int) WebhookReceipt::where('received_at', '>=', $since)
            ->whereIn('authentication_result', [
                WebhookReceipt::AUTH_BAD_BASIC,
                WebhookReceipt::AUTH_UNCONFIGURED,
                WebhookReceipt::AUTH_RATE_LIMITED,
            ])->count();

        // The provider took responsibility for these and never told us how they
        // ended. This is the signal that survives even if no receipt is written
        // at all — it needs no webhook to be true.
        $owed = EmailDelivery::whereIn('state', [DeliveryState::ACCEPTED->value, DeliveryState::DEFERRED->value])
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<', now()->subMinutes(self::OWED_EVENT_MINUTES))
            ->count();

        $unmatched = EmailDeliveryEvent::whereNull('email_delivery_id')->count();

        $sent24h     = EmailDelivery::where('created_at', '>=', $since)->count();
        $accepted24h = (int) WebhookReceipt::where('received_at', '>=', $since)
            ->where('authentication_result', WebhookReceipt::AUTH_ACCEPTED)->count();

        [$verdict, $reasons] = $this->verdict($owed, $ourFault24h, $lastAccepted, $sent24h, $accepted24h);

        return response()->json([
            'success' => true,
            'health'  => [
                'verdict' => $verdict,
                'reasons' => $reasons,

                'last_attempt_at'  => $this->iso($lastAny),
                'last_accepted_at' => $this->iso($lastAccepted),
                'last_refused_at'  => $this->iso($lastRefused),

                'accepted_24h'            => $accepted24h,
                'refusals_24h'            => $refusals24h,
                'refusals_24h_total'      => array_sum($refusals24h),
                'our_misconfiguration_24h' => $ourFault24h,

                'messages_sent_24h'          => $sent24h,
                'deliveries_owed_an_event'   => $owed,
                'owed_after_minutes'         => self::OWED_EVENT_MINUTES,
                'unmatched_events'           => $unmatched,

                'processing_latency_ms' => $this->latency($since),
            ],
            'replay' => $this->replayPosture(),
        ]);
    }

    /**
     * The verdict, and every sentence behind it. An operator should never have
     * to reverse-engineer why a badge is amber.
     *
     * @return array{0:string,1:list<string>}
     */
    private function verdict(int $owed, int $ourFault, ?string $lastAccepted, int $sent24h, int $accepted24h): array
    {
        $reasons = [];

        if ($owed > 0) {
            $reasons[] = sprintf(
                '%d delivery/deliveries were accepted by the provider more than %d minutes ago and no terminal event has arrived. Either webhooks are not reaching us, or the provider has not sent them yet.',
                $owed,
                self::OWED_EVENT_MINUTES,
            );
        }

        if ($ourFault > 0) {
            $reasons[] = sprintf(
                '%d attempt(s) in the last 24h were refused for reasons that indict this installation — wrong HTTP Basic credentials, an unset secret, or our own rate limiter. A caller that knows the URL is almost certainly the provider.',
                $ourFault,
            );
        }

        if ($reasons !== []) {
            return ['degraded', $reasons];
        }

        if ($lastAccepted === null) {
            return ['unassessable', [
                'No webhook attempt has ever been accepted, so there is nothing to judge. This is the expected state before the provider is configured, and indistinguishable from a broken one until the first event arrives.',
            ]];
        }

        // The distinction that stops a green badge from being a lie: silence
        // because nothing was sent is not the same as silence because the
        // channel is broken, and only one of them is a problem.
        if ($accepted24h === 0) {
            return $sent24h === 0
                ? ['idle', ['No mail was sent in the last 24 hours and no webhooks arrived. That is consistent, not evidence of health — there is nothing to assess.']]
                : ['degraded', [sprintf('%d message(s) were sent in the last 24 hours but no webhook was accepted in that window.', $sent24h)]];
        }

        return ['healthy', [
            sprintf('%d webhook attempt(s) accepted in the last 24 hours, every accepted delivery has reached a terminal state, and nothing was refused for a reason that indicts this installation.', $accepted24h),
        ]];
    }

    /**
     * PHASE 5 — replay, classified honestly rather than built because the phase
     * has a name.
     *
     * A faithful replay means re-submitting the provider's original bytes. We
     * deliberately never store them: the payload is retained as a SHA-256 digest
     * precisely so that a webhook archive cannot become a second copy of every
     * recipient address, bounce reason and subject line we hold. Storing raw
     * bodies to enable replay would trade a real, permanent privacy liability
     * for a capability we already have a better answer for.
     *
     * The better answer is `email888:reconcile`, which asks Postmark for a
     * message's own event history and applies it through the same idempotent
     * ledger path a webhook uses. That recovers from provider truth rather than
     * from a cached copy of what the provider once said — strictly stronger,
     * and it works even for events we never received at all.
     *
     * The replay_* columns exist and stay empty. They are the lineage a future
     * implementation would need, and an empty column is cheaper than a
     * migration against a table under audit.
     */
    private function replayPosture(): array
    {
        return [
            'supported' => false,
            'reason'    => 'Raw webhook bodies are never stored — only a SHA-256 digest — so no faithful replay is possible by construction.',
            'instead'   => 'Run `php artisan email888:reconcile`, which fetches the message\'s event history from the provider and applies it through the same idempotent ledger path. It recovers events that were never received, which a replay of a stored body could not.',
        ];
    }

    /**
     * PHASE 6 — what an operator should do about THIS row.
     */
    private function guidance(WebhookReceipt $r): string
    {
        return match ($r->authentication_result) {
            WebhookReceipt::AUTH_UNCONFIGURED =>
                'Set EMAIL888_WEBHOOK_SECRET, then run `php artisan config:clear`. Until it is set the endpoint refuses everything, which is the correct default but means no delivery events are being recorded at all.',

            WebhookReceipt::AUTH_BAD_BASIC =>
                'The caller had the correct path secret, so it almost certainly is the provider. Compare the HTTP Basic credentials on both webhooks in the provider console against EMAIL888_WEBHOOK_BASIC_USER / _PASSWORD. Delivery events are being lost while this persists.',

            WebhookReceipt::AUTH_BAD_SECRET =>
                'The path secret did not match. If the provider is still delivering events normally, this is an unrelated caller and no action is needed beyond noting the source address.',

            WebhookReceipt::AUTH_MALFORMED_PATH =>
                'The URL did not match the webhook route. The provider always uses the exact configured URL, so this is background noise unless it correlates with missing events.',

            WebhookReceipt::AUTH_RATE_LIMITED =>
                'Our own rate limiter refused this, not authentication. The provider retries, so a small number is harmless; a sustained count means the limit is below real volume and should be raised.',

            default => match ($r->processing_result) {
                'error' =>
                    'Storage failed while applying this event. The provider retries non-2xx responses, so it should return. Check the application log around this timestamp.',
                'unmatched' =>
                    'The event carried a MessageID that no delivery in the ledger holds. Usual causes: the message was sent before the ledger existed, or it was sent from a different installation sharing this provider server.',
                'duplicate', 'out_of_order' =>
                    'Nothing to do. The ledger already held this state, and it correctly refused to move backwards or apply it twice.',
                default =>
                    'Accepted and applied. No action needed.',
            },
        };
    }

    /**
     * The ledger row this attempt was about, if we hold one. Answers the
     * operator's real next question without a second search.
     */
    private function linkedDelivery(WebhookReceipt $r): ?array
    {
        if (! $r->provider_message_id) {
            return null;
        }

        $d = EmailDelivery::where('provider_message_id', $r->provider_message_id)->first();

        if (! $d) {
            return [
                'found'   => false,
                'message' => 'No delivery in the ledger carries this MessageID. The event was kept as evidence rather than discarded.',
            ];
        }

        return [
            'found'             => true,
            'id'                => $d->id,
            'state'             => $d->state,
            'purpose'           => $d->purpose,
            'recipient_address' => $d->recipient_address,
        ];
    }

    private function latency(\DateTimeInterface $since): array
    {
        $rows = WebhookReceipt::where('received_at', '>=', $since)
            ->whereNotNull('processing_latency_ms')
            ->where('authentication_result', WebhookReceipt::AUTH_ACCEPTED)
            ->orderBy('processing_latency_ms')
            ->pluck('processing_latency_ms')
            ->all();

        if ($rows === []) {
            // No samples is not "0ms". Reporting a fast channel from an empty
            // set is the fabricated-success shape in miniature.
            return ['samples' => 0, 'p50' => null, 'max' => null];
        }

        return [
            'samples' => count($rows),
            'p50'     => (int) $rows[(int) floor((count($rows) - 1) / 2)],
            'max'     => (int) max($rows),
        ];
    }

    private function summary(): array
    {
        $byAuth = WebhookReceipt::select('authentication_result', DB::raw('count(*) as n'))
            ->groupBy('authentication_result')->pluck('n', 'authentication_result');

        $out = [
            'total'    => (int) WebhookReceipt::count(),
            'accepted' => (int) ($byAuth[WebhookReceipt::AUTH_ACCEPTED] ?? 0),
            'refused'  => 0,
        ];

        foreach (WebhookReceipt::refusalReasons() as $reason) {
            $n = (int) ($byAuth[$reason] ?? 0);
            $out[$reason] = $n;
            $out['refused'] += $n;
        }

        $out['fully_processed'] = (int) WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_ACCEPTED)
            ->where('validation_result', WebhookReceipt::VALID)
            ->where('processing_stage', WebhookReceipt::STAGE_COMPLETE)
            ->whereIn('processing_result', ['applied', 'duplicate', 'out_of_order', 'ignored'])
            ->count();

        return $out;
    }

    private function facets(): array
    {
        return [
            'authentication_results' => array_merge([WebhookReceipt::AUTH_ACCEPTED], WebhookReceipt::refusalReasons()),
            'event_types'            => WebhookReceipt::whereNotNull('event_type')->distinct()->orderBy('event_type')->pluck('event_type')->all(),
            'processing_results'     => WebhookReceipt::whereNotNull('processing_result')->distinct()->orderBy('processing_result')->pluck('processing_result')->all(),
            'streams'                => WebhookReceipt::whereNotNull('stream')->distinct()->orderBy('stream')->pluck('stream')->all(),
        ];
    }

    private function iso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($v)->toIso8601String();
    }
}
