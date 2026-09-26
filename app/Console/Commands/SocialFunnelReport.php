<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SOCIAL-LEADS-1 (RFC-0016 P1): Sarah's weekly social funnel for every workspace with a connected Facebook Page —
 * comments → buyers → replies posted → clicks to the site → leads (and how many completed the form). Told by Sarah in her
 * chat (LLM first, facts only; fixed text fallback). Silent for a workspace with no activity that week.
 */
class SocialFunnelReport extends Command
{
    protected $signature = 'social:funnel-report {--ws= : only this workspace} {--days=7}';
    protected $description = "Sarah's weekly social funnel: comments, buyers, replies, clicks, leads";

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days')); $since = now()->subDays($days);
        $ws = $this->option('ws') ? [(int) $this->option('ws')] : DB::table('social_accounts')->whereIn('platform', ['facebook', 'instagram'])->where('status', 'connected')->distinct()->pluck('workspace_id')->all();
        foreach ($ws as $w) {
            $f = [
                'days' => $days,
                'comments' => DB::table('social_comments')->where('workspace_id', $w)->where('created_at', '>=', $since)->count(),
                'buyers' => DB::table('social_comments')->where('workspace_id', $w)->where('created_at', '>=', $since)->where('intent', 'hot')->count(),
                'interested' => DB::table('social_comments')->where('workspace_id', $w)->where('created_at', '>=', $since)->where('intent', 'warm')->count(),
                'replies_posted' => DB::table('social_comments')->where('workspace_id', $w)->where('replied_at', '>=', $since)->count(),
                'replies_waiting_for_you' => DB::table('social_comments')->where('workspace_id', $w)->where('status', 'awaiting_approval')->count(),
                'clicks_to_site' => (int) DB::table('tracked_link_clicks as k')->join('tracked_links as l', 'l.id', '=', 'k.tracked_link_id')->where('l.workspace_id', $w)->where('k.clicked_at', '>=', $since)->count(),
                'new_social_leads' => DB::table('leads')->where('workspace_id', $w)->whereIn('source', ['facebook_comment', 'facebook_messenger', 'facebook_lead_ad', 'instagram_comment', 'instagram_dm'])   /* SOCIAL-LEADS-5 */->where('created_at', '>=', $since)->count(),
                'leads_who_completed_the_form' => DB::table('leads')->where('workspace_id', $w)->whereIn('source', ['facebook_comment', 'facebook_messenger', 'instagram_comment', 'instagram_dm'])->where('updated_at', '>=', $since)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.converted_via')) = 'website_form'")->count(),
            ];
            if ($f['comments'] === 0 && $f['clicks_to_site'] === 0 && $f['new_social_leads'] === 0) { $this->line("ws {$w}: quiet week"); continue; }
            $fallback = "Your social week: {$f['comments']} comment" . ($f['comments'] === 1 ? '' : 's') . ", {$f['buyers']} from buyers, {$f['replies_posted']} repl" . ($f['replies_posted'] === 1 ? 'y' : 'ies') . " posted, "
                . "{$f['clicks_to_site']} click" . ($f['clicks_to_site'] === 1 ? '' : 's') . " to your contact section, {$f['new_social_leads']} new lead" . ($f['new_social_leads'] === 1 ? '' : 's') . " ({$f['leads_who_completed_the_form']} filled in the form)."
                . ($f['replies_waiting_for_you'] ? " {$f['replies_waiting_for_you']} repl" . ($f['replies_waiting_for_you'] === 1 ? 'y is' : 'ies are') . ' waiting for your approval.' : '');
            $msg = $fallback;
            try {
                $rt = app(\App\Connectors\RuntimeClient::class);
                if ($rt->isConfigured()) {
                    $r = $rt->chatJson("You are Sarah, the owner's digital marketing manager. Write a short weekly update (3-4 sentences) on their Facebook comments funnel from the FACTS only: "
                        . "what came in, the buyers, what you replied, clicks to their contact section, the leads and who completed the form, and one concrete suggestion. No headings, no emojis, no invented numbers. "
                        . 'Return ONLY JSON {"message":"..."}.', 'FACTS: ' . json_encode($f), ['task' => 'social_funnel_report', 'workspace_id' => (string) $w], 350);
                    $m = trim((string) (($r['success'] ?? false) ? ($r['parsed']['message'] ?? '') : ''));
                    if ($m !== '') $msg = mb_substr($m, 0, 1200);
                }
            } catch (\Throwable $e) { Log::info('[SOCIAL-LEADS-1] funnel wording fell back', ['ws' => $w]); }
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent((int) $w, 'sarah', $msg, ['kind' => 'social_funnel_weekly', 'facts' => $f]);
            $this->line("ws {$w}: " . json_encode($f));
        }
        return self::SUCCESS;
    }
}
