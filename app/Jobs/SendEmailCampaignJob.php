<?php

namespace App\Jobs;

use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\EmailDispatcher;
use App\Engines\Marketing\Services\EmailBuilderService;
use App\Engines\Marketing\Support\CampaignDispatchOutcome;
use App\Engines\Marketing\Support\CampaignLogColumns;
use App\Engines\Marketing\Support\CampaignDispatchPlan;
use App\Engines\Marketing\Support\CampaignPlanner;
use App\Engines\Marketing\Support\CampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * SendEmailCampaignJob — the ONE campaign fan-out, for both campaign shapes.
 *
 * Flow:
 *   1. Normalise the campaign into a CampaignDispatchPlan (CampaignPlanner)
 *   2. For each recipient:
 *        a. templated shape only: insert a log row with a tracking token, then
 *           render with merge tags + open pixel + link rewriting against it
 *        b. body_html shape: the plan already carries the rendered body
 *        c. Dispatch through Email888 (purpose 'campaign' -> broadcast)
 *        d. Record the outcome on the shared aggregate
 *   3. Compute the campaign's final state from what actually happened
 *
 * Progress is readable in real time via the send-status endpoint, which
 * aggregates email_campaigns_log.
 *
 * WHY THE TWO SHAPES SHARE THIS LOOP
 * They used to have three send loops between them, and each of the three
 * independently wrote `status = 'sent'` after its loop finished — the same
 * fabricated-success defect, written three times. One loop is the fix.
 */
class SendEmailCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min — enough for ~2000 emails at 1s each

    /**
     * EM-7/12 — retries became SAFE only once per-recipient idempotency existed.
     * This was deliberately 1 before: a retry would have re-sent to everyone the
     * first attempt had already reached, duplicating provider spend and putting
     * a second copy in real inboxes. The dispatcher now claims a deterministic
     * key per recipient BEFORE sending, so a re-run resolves each already-sent
     * recipient to `duplicate` and sends nothing. A transient failure part-way
     * through a large campaign no longer abandons the remainder.
     */
    public int $tries = 3;

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public int $campaignId)
    {
    }

    public function handle(
        EmailBuilderService $svc,
        EmailDispatcher $dispatcher,
        CampaignPlanner $planner,
    ): void {
        try {
            $plan = $planner->plan($this->campaignId);
        } catch (Throwable $e) {
            // A campaign that cannot even be normalised has not been sent, and
            // must not be left sitting in 'pending' forever looking in-flight.
            Log::warning('email.campaign.plan_failed', [
                'campaign_id' => $this->campaignId, 'error' => $e->getMessage(),
            ]);
            $this->markFailed($e->getMessage());

            return;
        }

        DB::table('campaigns')->where('id', $this->campaignId)->update([
            'status'     => 'sending',
            'updated_at' => now(),
        ]);

        $total   = $plan->count();
        $outcome = new CampaignDispatchOutcome();

        Log::info('email.campaign.send.start', [
            'campaign_id' => $this->campaignId,
            'total'       => $total,
            'templated'   => $plan->isTemplated(),
        ]);

        foreach ($plan->recipients as $idx => $r) {
            $this->one($plan, $r, $svc, $dispatcher, $outcome);

            // Heartbeat: roll stats every 10 recipients so polling stays live.
            if ((($idx + 1) % 10) === 0 || ($idx + 1) === $total) {
                DB::table('campaigns')->where('id', $this->campaignId)->update([
                    'stats_json' => json_encode($outcome->stats($total) + [
                        'progress_pct' => $total > 0 ? (int) round(($idx + 1) * 100 / $total) : 100,
                    ]),
                    'updated_at' => now(),
                ]);
            }
        }

        // EM-7: status is COMPUTED from dispatch outcomes. It was previously
        // written as 'sent' unconditionally, so a campaign in which every single
        // recipient failed still reported success.
        DB::table('campaigns')->where('id', $this->campaignId)->update([
            'status'     => $outcome->status(),
            'sent_at'    => $outcome->sentAt(),
            'stats_json' => json_encode($outcome->stats($total) + ['progress_pct' => 100]),
            'updated_at' => now(),
        ]);

        Log::info('email.campaign.send.done', [
            'campaign_id' => $this->campaignId,
            'status'      => $outcome->status(),
            'accepted'    => $outcome->acceptedCount(),
            'duplicate'   => $outcome->duplicateCount(),
            'refused'     => $outcome->refusedCount(),
        ]);
    }

    private function one(
        CampaignDispatchPlan $plan,
        CampaignRecipient $r,
        EmailBuilderService $svc,
        EmailDispatcher $dispatcher,
        CampaignDispatchOutcome $outcome,
    ): void {
        $to = $r->address;

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $outcome->refused($to !== '' ? $to : '(blank)', 'invalid_address');

            return;
        }

        $logId = null;
        $html  = $r->html;

        try {
            if ($plan->isTemplated()) {
                $logId = $this->openLogRow($plan, $r);
                $this->ensureUnsubscribeToken($to);

                // Rendering needs the log row: it injects the open pixel and
                // rewrites links through per-recipient tracking URLs.
                $html = $svc->renderWithVariables(
                    (int) $plan->templateId,
                    $r->variables,
                    (object) [
                        'first_name' => $r->firstName(),
                        'last_name'  => '',
                        'email'      => $to,
                        'company'    => $r->company,
                    ],
                    (int) $logId,
                );
            }

            // The producer declares INTENT only. Sender identity and the
            // broadcast stream come from the registries; this job names no
            // provider, no address and no stream.
            $result = $dispatcher->send(new SendEmailCommand(
                purpose:        'campaign',
                recipients:     [$to],
                subject:        $plan->subject,
                html:           (string) $html,
                workspaceId:    $plan->workspaceId,
                metadata:       array_filter([
                    'campaign_id' => $plan->campaignId,
                    'log_id'      => $logId,
                ], fn ($v) => $v !== null),
                idempotencyKey: $plan->idempotencyKeyFor($to),
            ));
        } catch (Throwable $e) {
            $outcome->refused($to, class_basename($e));
            Log::error('email.campaign.send.error', [
                'campaign_id' => $this->campaignId, 'recipient' => $to, 'error' => $e->getMessage(),
            ]);
            $this->closeLogRow($logId, 'failed');

            return;
        }

        if ($result->accepted) {
            $result->deduplicated ? $outcome->duplicated() : $outcome->accepted();
            $this->closeLogRow($logId, 'sent', $result->providerMessageId);
        } else {
            $outcome->refused($to, $result->failureCategory ?? 'refused');
            // NOT 'bounced' — a bounce is the receiving server's verdict and
            // cannot be known at hand-off. Recording one here would write a
            // fictional bounce into the campaign's own history.
            $this->closeLogRow($logId, 'failed');
        }
    }

    private function openLogRow(CampaignDispatchPlan $plan, CampaignRecipient $r): int
    {
        return (int) DB::table('email_campaigns_log')->insertGetId([
            'campaign_id'     => $plan->campaignId,
            'workspace_id'    => $plan->workspaceId,
            'recipient_email' => $r->address,
            'recipient_name'  => $r->name,
            'subject'         => $plan->subject,
            'subject_variant' => $r->subjectVariant,
            'status'          => 'pending',
            'tracking_token'  => Str::random(48),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function closeLogRow(?int $logId, string $status, ?string $messageId = null): void
    {
        if ($logId === null) {
            return;   // the body_html shape keeps no per-recipient log, as before
        }

        DB::table('email_campaigns_log')->where('id', $logId)->update(array_filter([
            'status'              => $status,
            'sent_at'             => $status === 'sent' ? now() : null,
            // Legacy column; the domain concept is provider_message_id.
            CampaignLogColumns::PROVIDER_MESSAGE_ID => $messageId,
            'updated_at'          => now(),
        ], fn ($v) => $v !== null));
    }

    private function ensureUnsubscribeToken(string $to): void
    {
        if (! Schema::hasTable('leads')) {
            return;
        }

        $lead = DB::table('leads')->where('email', $to)->first();

        if ($lead && empty($lead->unsubscribe_token)) {
            DB::table('leads')->where('id', $lead->id)->update([
                'unsubscribe_token' => Str::random(48),
                'updated_at'        => now(),
            ]);
        }
    }

    private function markFailed(string $reason): void
    {
        DB::table('campaigns')->where('id', $this->campaignId)->update([
            'status'     => 'failed',
            'sent_at'    => null,
            'stats_json' => json_encode([
                'recipients' => 0, 'accepted' => 0, 'duplicate' => 0, 'refused' => 0,
                'sent' => 0, 'delivered' => 0,
                'delivered_source' => 'email888_delivery_ledger',
                'failures' => [['recipient' => '(campaign)', 'reason' => $reason]],
            ]),
            'updated_at' => now(),
        ]);
    }
}
