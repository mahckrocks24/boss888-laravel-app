<?php

namespace App\Jobs;

use App\Connectors\EmailConnector;
use App\Engines\Marketing\Services\EmailBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SendEmailCampaignJob — queue-based fan-out for a single campaign.
 * Runs on 'tasks' queue alongside the Studio render jobs.
 *
 * Flow:
 *   1. Load campaign + recipients (from campaign.recipients_json)
 *   2. For each recipient:
 *        a. Insert a pending log row with tracking_token
 *        b. Render with merge tags + inject open pixel + rewrite links
 *        c. Send via EmailConnector
 *        d. Update the log row status
 *   3. After the loop: stamp campaign.status=sent + sent_at + stats_json
 *
 * Progress is readable in real time via the send-status endpoint, which
 * aggregates email_campaigns_log.
 */
class SendEmailCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min — enough for ~2000 emails at 1s each
    public int $tries   = 1;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(EmailBuilderService $svc, EmailConnector $email): void
    {
        $campaign = DB::table('campaigns')->where('id', $this->campaignId)->first();
        if (!$campaign) { Log::warning('SendEmailCampaignJob: campaign not found', ['id' => $this->campaignId]); return; }
        if (!$campaign->template_id) { Log::warning('SendEmailCampaignJob: no template', ['id' => $this->campaignId]); return; }

        // Mark as running
        DB::table('campaigns')->where('id', $this->campaignId)->update([
            'status'     => 'sending',
            'updated_at' => now(),
        ]);

        $recipients = json_decode($campaign->recipients_json ?: '[]', true) ?: [];
        $total = count($recipients);
        $sent = 0; $failed = 0;

        Log::info('email.campaign.send.start', ['campaign_id' => $this->campaignId, 'total' => $total]);

        foreach ($recipients as $idx => $r) {
            $to   = (string) ($r['email'] ?? '');
            $name = (string) ($r['name']  ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }

            // Per-recipient tracking_token
            $logId = DB::table('email_campaigns_log')->insertGetId([
                'campaign_id'     => $this->campaignId,
                'workspace_id'    => $campaign->workspace_id,
                'recipient_email' => $to,
                'recipient_name'  => $name,
                'subject'         => $campaign->subject,
                'subject_variant' => $r['variant'] ?? null,
                'status'          => 'pending',
                'tracking_token'  => Str::random(48),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Ensure recipient has an unsubscribe token if they live in leads
            if (Schema::hasTable('leads') && !empty($to)) {
                $lead = DB::table('leads')->where('email', $to)->first();
                if ($lead && empty($lead->unsubscribe_token)) {
                    DB::table('leads')->where('id', $lead->id)->update([
                        'unsubscribe_token' => Str::random(48),
                        'updated_at' => now(),
                    ]);
                }
            }

            try {
                // Render via EmailBuilderService — injects tracking automatically when $logId is passed
                $contact = (object) [
                    'first_name' => explode(' ', $name)[0] ?? '',
                    'last_name'  => '',
                    'email'      => $to,
                    'company'    => (string) ($r['company'] ?? ''),
                ];
                $html = $svc->renderWithVariables(
                    (int) $campaign->template_id,
                    (array) ($r['variables'] ?? []),
                    $contact,
                    (int) $logId
                );

                $result = $email->execute('send_email', [
                    'to'      => $to,
                    'subject' => $campaign->subject,
                    'body'    => $html,
                    'html'    => true,
                ]);

                if (!empty($result['success'])) {
                    $sent++;
                    DB::table('email_campaigns_log')->where('id', $logId)->update([
                        'status'              => 'sent',
                        'sent_at'             => now(),
                        'postmark_message_id' => $result['data']['message_id'] ?? null,
                        'updated_at'          => now(),
                    ]);
                } else {
                    $failed++;
                    DB::table('email_campaigns_log')->where('id', $logId)->update([
                        'status'     => 'bounced',
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('email.campaign.send.error', [
                    'campaign_id' => $this->campaignId, 'recipient' => $to, 'error' => $e->getMessage(),
                ]);
                DB::table('email_campaigns_log')->where('id', $logId)->update([
                    'status' => 'bounced', 'updated_at' => now(),
                ]);
            }

            // Heartbeat: roll stats_json every 10 recipients so polling is live
            if ((($idx + 1) % 10) === 0 || ($idx + 1) === $total) {
                DB::table('campaigns')->where('id', $this->campaignId)->update([
                    'stats_json' => json_encode([
                        'sent' => $sent, 'failed' => $failed, 'total' => $total,
                        'progress_pct' => $total > 0 ? (int) round(($idx + 1) * 100 / $total) : 100,
                    ]),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('campaigns')->where('id', $this->campaignId)->update([
            'status'     => 'sent',
            'sent_at'    => now(),
            'stats_json' => json_encode([
                'sent' => $sent, 'failed' => $failed, 'total' => $total, 'progress_pct' => 100,
            ]),
            'updated_at' => now(),
        ]);

        Log::info('email.campaign.send.done', ['campaign_id' => $this->campaignId, 'sent' => $sent, 'failed' => $failed]);
    }
}
