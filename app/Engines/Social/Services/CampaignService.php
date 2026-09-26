<?php

namespace App\Engines\Social\Services;

use App\Core\Publisher\ConnectionHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SOCIAL-LEADS-4 (RFC-0016 P4). Comment-keyword campaigns: approved ONCE by the Owner (protected social_create_campaign
 * task); a matching comment is answered by the campaign itself — public reply + private message with a tracked link —
 * with no model call and no per-comment approval. Every responder becomes (or extends) a lead tagged with the campaign.
 */
class CampaignService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    /** Handler of the APPROVED social_create_campaign task. */
    public function create(int $wsId, array $p): array
    {
        $kw = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', (string) ($p['keyword'] ?? ''))));
        $dm = trim((string) ($p['dm_message'] ?? ''));
        if ($kw === '' || mb_strlen($kw) > 30 || $dm === '') return ['success' => false, 'error' => 'A campaign needs a one-word keyword and the private message to send.', 'code' => 'INVALID_INPUT', 'no_charge' => true];
        if (DB::table('social_campaigns')->where('workspace_id', $wsId)->where('keyword', $kw)->where('status', 'active')->exists()) {
            return ['success' => false, 'error' => "A campaign for {$kw} is already running.", 'code' => 'DUPLICATE', 'no_charge' => true];
        }
        $id = DB::table('social_campaigns')->insertGetId([
            'workspace_id' => $wsId, 'business_id' => $p['business_id'] ?? null, 'name' => mb_substr((string) ($p['name'] ?? "Comment {$kw}"), 0, 120), 'keyword' => $kw,
            'public_reply' => mb_substr(trim((string) ($p['public_reply'] ?? 'Sent you a message, {first_name}!')), 0, 500), 'dm_message' => mb_substr($dm, 0, 1000),
            'include_link' => (bool) ($p['include_link'] ?? true), 'status' => 'active', 'ends_at' => ! empty($p['ends_at']) ? \Illuminate\Support\Carbon::parse($p['ends_at']) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['success' => true, 'message' => "Campaign live: comments with {$kw} get the reply and the private message.", 'data' => ['campaign_id' => $id, 'keyword' => $kw]];
    }

    /** The active campaign a comment triggers, if any (whole-word, case-insensitive). */
    public function match(int $wsId, string $text): ?object
    {
        $t = strtoupper($text);
        foreach (DB::table('social_campaigns')->where('workspace_id', $wsId)->where('status', 'active')->where(function ($q) { $q->whereNull('ends_at')->orWhere('ends_at', '>', now()); })->get() as $c) {
            if (preg_match('/(^|[^A-Z0-9])' . preg_quote($c->keyword, '/') . '([^A-Z0-9]|$)/', $t)) return $c;
        }
        return null;
    }

    /** Answer a comment under the campaign's approval. Returns true when handled. */
    public function respond(object $campaign, int $commentId): bool
    {
        $c = DB::table('social_comments')->where('id', $commentId)->first();
        if (! $c || $c->status !== 'new') return false;
        $first = $c->author_name ? explode(' ', trim($c->author_name))[0] : 'there';
        $link = $campaign->include_link ? app(TrackedLinkService::class)->create((int) $c->workspace_id, $c->business_id ? (int) $c->business_id : null, 'facebook_comment', (int) $c->id, 'campaign-' . $campaign->keyword) : null;
        $public = str_replace('{first_name}', $first, (string) $campaign->public_reply);
        $dm = str_replace(['{first_name}', '{link}'], [$first, $link['url'] ?? ''], (string) $campaign->dm_message);
        if ($link && strpos((string) $campaign->dm_message, '{link}') === false) $dm = rtrim($dm) . "\n" . $link['url'];
        DB::table('social_comments')->where('id', $c->id)->update(['category' => 'enquiry', 'intent' => 'warm', 'campaign_id' => $campaign->id, 'draft_reply' => $public, 'dm_draft' => $dm,
            'link_code' => $link['code'] ?? null, 'triage_note' => "Answered by the campaign \"{$campaign->name}\" you approved.", 'status' => 'awaiting_approval', 'updated_at' => now()]);
        try {
            $leadId = app(CommentInboxService::class)->upsertSocialLead(DB::table('social_comments')->where('id', $c->id)->first(), ['campaign' => $campaign->keyword]);
            if ($leadId && $link) DB::table('tracked_links')->where('code', $link['code'])->update(['lead_id' => $leadId]);
        } catch (\Throwable $e) { Log::warning('[SOCIAL-LEADS-4] lead not created', ['comment' => $c->id, 'error' => $e->getMessage()]); }
        // the campaign's approval covers this reply: post it now (reply() also sends the private message)
        $res = app(CommentInboxService::class)->reply((int) $c->workspace_id, ['comment_row_id' => (int) $c->id, 'reply' => $public, 'dm' => $dm]);
        DB::table('social_campaigns')->where('id', $campaign->id)->update(['responses' => DB::raw('responses + 1'), 'updated_at' => now()]);
        return (bool) ($res['success'] ?? false);
    }
}
