<?php

namespace App\Core\Campaigns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CAMPAIGNS-1 (RFC-0018): the life of a campaign.
 *
 *   idea ──Launch (the owner's one approval)──▶ launching ──mandate live──▶ active ──dates pass──▶ completed
 *     └─Not now (+ reason, learned)──▶ declined            └─Pause / Resume
 *
 * Launch is the plan-level approval (DEC-0018): a mandate lists every dated item and a spend ceiling, and the owner's
 * click approves it. Items are then released on their dates under that mandate — drafts are created ahead of time so the
 * owner sees each post/article/email before it goes out. Publishing, sending and anything else PROTECTED keeps its own
 * one-tap approval (the "Post it" card), exactly as DEC-0018 requires. Results are measured honestly ("during the
 * campaign", not "caused by") and are what Sarah's next ideas learn from.
 */
final class CampaignService
{
    /** How early each kind of work is released before its date (hours), so the owner can review it in time. */
    private const LEAD_HOURS = ['post' => 20, 'article' => 72, 'email' => 48, 'image' => 24, 'video' => 24, 'event' => 0, 'owner_task' => 0];

    /** Save planner ideas as campaigns in the "idea" state. @return int[] campaign ids */
    public function saveIdeas(int $wsId, ?int $businessId, array $ideas, string $source): array
    {
        $tz = $this->tz($wsId);
        $ids = [];
        foreach ($ideas as $c) {
            $start = Carbon::now($tz)->startOfDay()->addDays((int) $c['starts_in_days']);
            $end = $start->copy()->addDays((int) $c['duration_days'] - 1);
            $id = DB::table('marketing_campaigns')->insertGetId([
                'workspace_id' => $wsId, 'business_id' => $businessId, 'title' => $c['title'], 'objective' => $c['objective'] ?: null, 'why_now' => $c['why_now'] ?: null,
                'audience' => $c['audience'] ?: null, 'offer' => $c['offer'] ?: null, 'channels_json' => json_encode($c['channels']),
                'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'status' => 'idea', 'kpi_json' => json_encode($c['kpi']),
                'source' => $source, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $sort = 0;
            foreach ($c['phases'] as $p) {
                foreach ($p['items'] as $it) {
                    $at = $start->copy()->addDays((int) $it['day_offset'])->setTime(10, 0)->utc();
                    DB::table('campaign_items')->insert([
                        'campaign_id' => $id, 'workspace_id' => $wsId, 'phase' => $p['name'], 'phase_order' => $p['order'], 'kind' => $it['kind'], 'channel' => $it['channel'],
                        'title' => $it['title'], 'brief' => $it['brief'] ?: null, 'scheduled_at' => $at, 'status' => 'planned', 'sort' => $sort++, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('marketing_campaigns')->where('id', $id)->update(['credit_estimate' => $this->estimate($id)]);
            $ids[] = $id;
        }
        return $ids;
    }

    /** The plan task an item becomes on its date (engine/action/params) — null for work the owner does. */
    public function planTask(object $c, object $it): ?array
    {
        $biz = $c->business_id ? DB::table('businesses')->where('id', $c->business_id)->value('name') : null;
        $ctx = 'Part of the campaign "' . $c->title . '"' . ($biz ? ' for ' . $biz : '') . ($c->offer ? '. Offer: ' . $c->offer : '') . '.';
        $brief = trim($it->title . '. ' . ($it->brief ?? ''));
        $siteId = null;
        try {
            $sq = DB::table('websites')->where('workspace_id', $c->workspace_id)->whereNull('deleted_at');
            $siteId = ($c->business_id ? (clone $sq)->where('business_id', $c->business_id)->orderByDesc('published_at')->value('id') : null) ?: ((clone $sq)->count() === 1 ? (clone $sq)->value('id') : null);
        } catch (\Throwable $e) {}
        $common = array_filter(['business_id' => $c->business_id ? (int) $c->business_id : null, 'campaign_id' => (int) $c->id, 'campaign_item_id' => (int) $it->id]);
        return match ($it->kind) {
            'post' => ['engine' => 'social', 'action' => 'social_create_post', 'agent' => 'marcus', 'description' => 'Draft the ' . ucfirst((string) $it->channel) . ' post with a banner: ' . $it->title,
                'params' => $common + ['platform' => in_array($it->channel, ['facebook', 'instagram', 'linkedin'], true) ? $it->channel : 'facebook', 'topic' => $brief, 'title' => $it->title,
                    'description' => $brief . ' — with a banner image in our brand. ' . $ctx, 'created_via' => 'campaign']],
            'article' => ['engine' => 'write', 'action' => 'write_article', 'agent' => 'priya', 'description' => 'Write the article: ' . $it->title,
                'params' => $common + array_filter(['topic' => $it->title, 'brief' => $brief . ' ' . $ctx, 'website_id' => $siteId])],
            'email' => null,   // email marketing is out of launch scope: the owner sends it personally (a "You" step)
            'image' => ['engine' => 'creative', 'action' => 'generate_image', 'agent' => 'studio', 'description' => 'Design: ' . $it->title,
                'params' => $common + ['prompt' => $brief . ' ' . $ctx, 'platform' => in_array($it->channel, ['facebook', 'instagram', 'linkedin'], true) ? $it->channel : 'instagram', 'asset_type' => 'social_post', 'source' => 'campaign']],
            'video' => ['engine' => 'creative', 'action' => 'generate_video', 'agent' => 'studio', 'description' => 'Make the video: ' . $it->title,   // VIDEO-2
                'params' => $common + ['prompt' => $brief . ' ' . $ctx, 'duration' => 6, 'aspect_ratio' => $it->channel === 'website' ? '16:9' : '9:16', 'title' => 'Video: ' . $it->title, 'created_via' => 'campaign']],
            'event' => ['engine' => 'calendar', 'action' => 'create_event', 'agent' => 'sarah', 'description' => 'Put on the calendar: ' . $it->title,
                'params' => $common + ['title' => $it->title, 'description' => $brief, 'starts_at' => Carbon::parse($it->scheduled_at)->toIso8601String()]],
            default => null,
        };
    }

    public function estimate(int $campaignId): int
    {
        $c = DB::table('marketing_campaigns')->where('id', $campaignId)->first();
        $tasks = [];
        foreach (DB::table('campaign_items')->where('campaign_id', $campaignId)->get() as $it) { if ($t = $this->planTask($c, $it)) $tasks[] = ['engine' => $t['engine'], 'action' => $t['action']]; }
        // VIDEO-2: the estimate is what the kernel actually charges (CapabilityMap), so the plan's spend ceiling never blocks
        // a step it listed — the blueprint table under-stated some tools (a video estimated at 5, charged 8).
        $cm = app(\App\Core\EngineKernel\CapabilityMapService::class);
        $total = 0;
        foreach ($tasks as $t) {
            $cap = $cm->resolve((string) $t['action']);
            if ($cap && isset($cap['credit_cost'])) { $total += (int) $cap['credit_cost']; continue; }
            try { $total += (int) app(\App\Core\Intelligence\ToolCostCalculatorService::class)->costForTool((string) $t['engine'], (string) $t['action']); } catch (\Throwable $e) {}
        }
        return $total;
    }

    /**
     * The owner's Launch: one plan-level approval for the whole campaign (DEC-0018). The mandate lists every item and a
     * spend ceiling; approving its gate as this owner is the approval. The mandate going live activates the campaign.
     */
    public function launch(int $wsId, int $campaignId, int $userId): array
    {
        $c = DB::table('marketing_campaigns')->where('id', $campaignId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $c) return ['success' => false, 'error' => 'Campaign not found.'];
        if (! in_array($c->status, ['idea', 'declined', 'paused'], true)) return ['success' => false, 'error' => 'This campaign is already ' . $c->status . '.'];
        if ($c->status === 'paused' && $c->mandate_id) {
            DB::table('marketing_campaigns')->where('id', $c->id)->update(['status' => 'active', 'updated_at' => now()]);
            $this->syncCalendar($c->id);
            return ['success' => true, 'status' => 'active', 'resumed' => true];
        }
        $items = DB::table('campaign_items')->where('campaign_id', $c->id)->orderBy('scheduled_at')->get();
        $tasks = [];
        foreach ($items as $it) {
            $t = $this->planTask($c, $it);
            $when = Carbon::parse($it->scheduled_at)->toFormattedDateString();
            $tasks[] = $t ? $t + ['description' => $when . ' — ' . $t['description']] : ['engine' => 'sarah', 'action' => 'owner_reminder', 'agent' => 'sarah', 'description' => $when . ' — You: ' . $it->title, 'params' => []];
        }
        // Rebase dates if the idea sat for a while: a campaign never starts in the past.
        $tz = $this->tz($wsId);
        $today = Carbon::now($tz)->startOfDay();
        if ($c->starts_on && Carbon::parse($c->starts_on, $tz)->lt($today)) {
            $shift = (int) round(abs(Carbon::parse($c->starts_on, $tz)->diffInDays($today->copy()->addDay())));
            DB::table('campaign_items')->where('campaign_id', $c->id)->update(['scheduled_at' => DB::raw('DATE_ADD(scheduled_at, INTERVAL ' . (int) $shift . ' DAY)')]);
            DB::table('marketing_campaigns')->where('id', $c->id)->update(['starts_on' => Carbon::parse($c->starts_on)->addDays($shift)->toDateString(), 'ends_on' => Carbon::parse($c->ends_on)->addDays($shift)->toDateString()]);
            $c = DB::table('marketing_campaigns')->where('id', $c->id)->first();
        }
        $validity = max(14, (int) round(abs(Carbon::parse($c->ends_on)->diffInDays(now()))) + 14);
        $mandates = app(\App\Core\Governance\MandateService::class);
        $m = $mandates->propose($wsId, [
            'business_id' => $c->business_id, 'source_type' => 'campaign', 'source_id' => (int) $c->id, 'title' => 'Campaign: ' . $c->title,
            'objective' => $c->objective, 'strategy' => $c->why_now, 'tasks' => $tasks, 'validity_days' => (int) $validity, 'proposed_by' => $userId,
            'spend_ceiling_credits' => max(1, (int) ceil($this->estimate((int) $c->id) * 1.25)),   // VIDEO-2: the ceiling from what is really charged
        ]);
        DB::table('marketing_campaigns')->where('id', $c->id)->update(['status' => 'launching', 'mandate_id' => $m['mandate_id'], 'decided_by' => $userId, 'decided_at' => now(), 'decline_reason' => null, 'updated_at' => now()]);
        if (! empty($m['approval_id'])) {
            try {
                app(\App\Core\Governance\ApprovalService::class)->approve((int) $m['approval_id'], $userId, 'Launched from the campaign');
            } catch (\Throwable $e) {
                Log::warning('[CAMPAIGNS-1] launch approval failed', ['campaign' => $c->id, 'e' => $e->getMessage()]);
                DB::table('marketing_campaigns')->where('id', $c->id)->update(['status' => 'idea', 'updated_at' => now()]);
                return ['success' => false, 'error' => 'The launch could not be approved: ' . $e->getMessage()];
            }
        }
        return ['success' => true, 'status' => 'launching', 'mandate_id' => $m['mandate_id']];
    }

    /** MandateService::execute calls this for a campaign mandate instead of creating every task at once. */
    public function onMandateLive(int $campaignId, int $mandateId): void
    {
        DB::table('marketing_campaigns')->where('id', $campaignId)->update(['status' => 'active', 'mandate_id' => $mandateId, 'launched_at' => now(), 'updated_at' => now()]);
        $this->syncCalendar($campaignId);
        if (! \Illuminate\Support\Facades\Cache::pull('campaign-launch-quiet:' . $campaignId) && \Illuminate\Support\Facades\Cache::add('campaign-live-told:' . $campaignId, 1, now()->addDays(2))) {   // CHAT-FIRST-1 (said once)
            $c = DB::table('marketing_campaigns')->where('id', $campaignId)->first();
            $next = DB::table('campaign_items')->where('campaign_id', $campaignId)->where('status', 'planned')->orderBy('scheduled_at')->first(['title', 'scheduled_at']);
            $this->tell((int) $c->workspace_id, 'campaign_live', "Write Sarah's short chat message (1-2 sentences): the campaign in FACTS is live, her team runs each step on its date, the owner okays each post before it goes out, and what comes first (FACTS.first_step). No emojis.",
                ['campaign' => $c->title, 'dates' => $c->starts_on . ' to ' . $c->ends_on, 'first_step' => $next ? $next->title . ' on ' . Carbon::parse($next->scheduled_at)->setTimezone($this->tz((int) $c->workspace_id))->format('l j M') : null],
                '"' . $c->title . '" is live. My team runs each step on its date, and you okay every post before it goes out.', ['campaign_id' => $campaignId]);
        }
        $this->tickCampaign($campaignId);
    }

    public function decline(int $wsId, int $campaignId, int $userId, ?string $reason): bool
    {
        return (bool) DB::table('marketing_campaigns')->where('id', $campaignId)->where('workspace_id', $wsId)->where('status', 'idea')
            ->update(['status' => 'declined', 'decline_reason' => $reason ? mb_substr(trim($reason), 0, 300) : null, 'decided_by' => $userId, 'decided_at' => now(), 'updated_at' => now()]);
    }

    public function pause(int $wsId, int $campaignId): bool
    {
        $ok = (bool) DB::table('marketing_campaigns')->where('id', $campaignId)->where('workspace_id', $wsId)->where('status', 'active')->update(['status' => 'paused', 'updated_at' => now()]);
        if ($ok) $this->syncCalendar($campaignId);
        return $ok;
    }

    /** Every few minutes: release items whose time has come, follow their tasks, finish campaigns whose dates passed. */
    public function tick(): array
    {
        $n = ['released' => 0, 'updated' => 0, 'completed' => 0];
        foreach (DB::table('marketing_campaigns')->whereIn('status', ['active', 'launching'])->whereNull('deleted_at')->pluck('id') as $id) {
            $r = $this->tickCampaign((int) $id);
            foreach ($n as $k => $v) $n[$k] += $r[$k] ?? 0;
        }
        return $n;
    }

    public function tickCampaign(int $id): array
    {
        $out = ['released' => 0, 'updated' => 0, 'completed' => 0];
        $c = DB::table('marketing_campaigns')->where('id', $id)->first();
        if (! $c || $c->status !== 'active') return $out;
        $m = $c->mandate_id ? DB::table('mandates')->where('id', $c->mandate_id)->first() : null;
        $live = $m && $m->status === 'live' && (! $m->ends_at || Carbon::parse($m->ends_at)->isFuture());
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        foreach (DB::table('campaign_items')->where('campaign_id', $id)->orderBy('scheduled_at')->get() as $it) {
            // release
            if ($it->status === 'planned' && $live && $it->scheduled_at && Carbon::parse($it->scheduled_at)->subHours(self::LEAD_HOURS[$it->kind] ?? 0)->lte(now())) {
                $t = $this->planTask($c, $it);
                if (! $t) {
                    DB::table('campaign_items')->where('id', $it->id)->update(['status' => 'needs_you', 'updated_at' => now()]); $out['released']++;
                    $this->tell((int) $c->workspace_id, 'campaign_step', "Write Sarah's short chat message (1-3 sentences) telling the owner that today's step in their campaign is theirs to do: what it is and the gist of FACTS.brief (if it is a message to send, include the message itself, ready to copy). Ask them to reply done when finished. Warm, no emojis.",
                        ['campaign' => $c->title, 'step' => $it->title, 'brief' => $it->brief, 'channel' => $it->channel],
                        'Today\'s step in "' . $c->title . '" is yours: ' . $it->title . ($it->brief ? ' — ' . $it->brief : '') . ' Reply **done** when it\'s finished.', ['item_id' => (int) $it->id, 'campaign_id' => (int) $c->id]);
                    continue;
                }
                try {
                    $res = $engine->materialisePlanTask((int) $c->workspace_id, $t, [
                        'requires_approval' => false, 'authorized_by_proposal' => true, 'auto_approve' => true, 'mandate_id' => (int) $c->mandate_id,
                        'business_id' => $c->business_id, 'decided_by' => $c->decided_by, 'payload_extra' => ['_mandate_id' => (int) $c->mandate_id, 'from_plan' => 'Campaign: ' . $c->title, 'campaign_item_id' => (int) $it->id],
                    ]);
                    $upd = ! empty($res['task_id']) ? ['status' => 'in_progress', 'task_id' => (int) $res['task_id']] : ['status' => 'held', 'note' => self::plainNote((string) ($res['held'] ?? $res['error'] ?? 'Could not start'))];
                } catch (\Throwable $e) {
                    $upd = ['status' => 'failed', 'note' => self::plainNote($e->getMessage())];
                }
                DB::table('campaign_items')->where('id', $it->id)->update($upd + ['updated_at' => now()]);
                $out['released']++;
                if (in_array($upd['status'], ['held', 'failed'], true)) $this->tell((int) $c->workspace_id, 'campaign_step_problem', "Write Sarah's one or two sentence chat message: a step in the campaign could not run, why in plain words (FACTS.why), and what the owner can do. No emojis, no codes.",
                    ['campaign' => $c->title, 'step' => $it->title, 'why' => $upd['note'] ?? null], 'A step in "' . $c->title . '" could not run: ' . $it->title . '. ' . ($upd['note'] ?? ''), ['campaign_id' => (int) $c->id]);
                continue;
            }
            // follow
            if (in_array($it->status, ['in_progress', 'needs_you'], true) && $it->task_id) {
                $task = DB::table('tasks')->where('id', $it->task_id)->first(['status', 'approval_status', 'result_json', 'error_text']);
                if (! $task) continue;
                $new = null; $extra = [];
                if ($it->kind === 'video' && $task->status === 'completed') {   // VIDEO-2: the task finishes at dispatch; the clip is done when its asset is
                    $va = DB::table('assets')->where('task_id', $it->task_id)->where('type', 'video')->orderByDesc('id')->first(['status', 'url']);
                    if (! $va || ! in_array($va->status, ['completed', 'failed'], true)) continue;
                    if ($va->status === 'completed') { DB::table('campaign_items')->where('id', $it->id)->update(['status' => 'done', 'result_type' => 'media', 'result_url' => mb_substr((string) $va->url, 0, 1024), 'updated_at' => now()]); $out['updated']++; }
                    else { DB::table('campaign_items')->where('id', $it->id)->update(['status' => 'failed', 'note' => 'The video could not be made; the credits were returned.', 'updated_at' => now()]); $out['updated']++; }
                    continue;
                }
                if ($task->status === 'completed') {
                    $new = 'done';
                    $extra = $this->resultOf($it, $task);
                    // a drafted post is only done once it is published: until then the owner has a "Post it" to tap
                    if ($it->kind === 'post' && ($extra['result_type'] ?? null) === 'social_post') {
                        $st = DB::table('social_posts')->where('id', $extra['result_id'])->value('status');
                        if ($st && $st !== 'published') $new = 'needs_you';
                    }
                } elseif (in_array($task->status, ['failed', 'cancelled'], true)) {
                    $new = 'failed'; $extra = ['note' => self::plainNote((string) ($task->error_text ?? 'The step failed'))];
                } elseif ($task->status === 'awaiting_approval' || $task->approval_status === 'pending') {
                    $new = 'needs_you';
                }
                if ($new && ($new !== $it->status || $extra)) {
                    DB::table('campaign_items')->where('id', $it->id)->update(['status' => $new, 'updated_at' => now()] + $extra); $out['updated']++;
                    if ($new === 'needs_you' && $it->status !== 'needs_you' && $it->kind === 'post') $this->tell((int) $c->workspace_id, 'campaign_post_ready', "Write Sarah's one or two sentence chat message: the post for the campaign in FACTS is drafted with its banner and ready; the owner can look at the preview right here and tap Post it, or ask for changes. No emojis.",
                        ['campaign' => $c->title, 'post' => $it->title, 'goes_out' => Carbon::parse($it->scheduled_at)->setTimezone($this->tz((int) $c->workspace_id))->format('l j M')], 'The post "' . $it->title . '" for "' . $c->title . '" is ready — have a look at the preview here and tap Post it, or tell me what to change.', ['campaign_id' => (int) $c->id]);
                    elseif ($new === 'failed') $this->tell((int) $c->workspace_id, 'campaign_step_problem', "Write Sarah's one or two sentence chat message: a step in the campaign could not finish, why in plain words (FACTS.why), and what happens next. No emojis, no codes.",
                        ['campaign' => $c->title, 'step' => $it->title, 'why' => $extra['note'] ?? null], 'A step in "' . $c->title . '" could not finish: ' . $it->title . '. ' . ($extra['note'] ?? ''), ['campaign_id' => (int) $c->id]);
                }
            }
            if ($it->status === 'needs_you' && $it->result_type === 'social_post' && $it->result_id) {
                if (DB::table('social_posts')->where('id', $it->result_id)->value('status') === 'published') { DB::table('campaign_items')->where('id', $it->id)->update(['status' => 'done', 'updated_at' => now()]); $out['updated']++; }
            }
        }
        if ($out['released'] || $out['updated']) $this->syncCalendar($id);
        // finish
        $open = DB::table('campaign_items')->where('campaign_id', $id)->whereIn('status', ['planned', 'in_progress'])->count();
        if ($c->ends_on && Carbon::parse($c->ends_on)->endOfDay()->lt(now()) && $open === 0) { $this->complete($id); $out['completed']++; }
        return $out;
    }

    /** A step's note, in the owner's words. Internal codes and raw errors never reach the page. */
    public static function plainNote(?string $raw): ?string
    {
        $r = trim((string) $raw);
        if ($r === '') return null;
        if (preg_match('/credit/i', $r)) return 'Not enough credits to run this step. Top up or wait for your monthly renewal, then Sarah will pick it up.';
        if (preg_match('/LAUNCH_SCOPE|removed capability|not part of|UNMAPPED|no capability/i', $r)) return 'This kind of step is not available yet, so it is yours to do.';
        if (preg_match('/NEEDS:|website URL|which lead/i', $r)) return 'Sarah needs a detail before this step can run: ' . trim(preg_replace('/^.*NEEDS:\s*/i', '', $r)) . '.';
        if (preg_match('/connect|not connected|account/i', $r)) return 'Connect the account this step posts to, then Sarah will run it.';
        return 'This step could not run. Sarah will look at it, or you can skip it.';
    }

    private function resultOf(object $it, object $task): array
    {
        $r = json_decode((string) ($task->result_json ?? ''), true) ?: [];
        $flat = json_encode($r);
        $find = function (array $keys) use ($r) { foreach ($keys as $k) { $v = data_get($r, $k); if (is_numeric($v) && (int) $v > 0) return (int) $v; } return null; };
        return match ($it->kind) {
            'post' => ($id = $find(['post_id', 'data.post_id', 'id', 'data.id', 'post.id'])) ? ['result_type' => 'social_post', 'result_id' => $id] : [],
            'article' => ($id = $find(['article_id', 'data.article_id', 'data.id', 'id'])) ? ['result_type' => 'article', 'result_id' => $id] : [],
            'email' => ($id = $find(['campaign_id', 'data.campaign_id', 'data.id', 'id'])) ? ['result_type' => 'email_campaign', 'result_id' => $id] : [],
            'image' => ($u = data_get($r, 'url') ?? data_get($r, 'data.url')) && is_string($u) ? ['result_type' => 'media', 'result_url' => mb_substr($u, 0, 1024)] : [],
            'event' => ($id = $find(['event_id', 'data.id', 'id'])) ? ['result_type' => 'calendar_event', 'result_id' => $id] : [],
            default => [],
        };
    }

    /** The owner marks their own task done, skips an item, or moves it to another date. */
    public function updateItem(int $wsId, int $itemId, array $in): array
    {
        $it = DB::table('campaign_items')->where('id', $itemId)->where('workspace_id', $wsId)->first();
        if (! $it) return ['success' => false, 'error' => 'Not found.'];
        $u = [];
        if (! empty($in['done']) && in_array($it->status, ['planned', 'needs_you', 'held', 'failed'], true)) $u['status'] = 'done';
        if (! empty($in['skip']) && in_array($it->status, ['planned', 'needs_you', 'held', 'failed'], true)) $u['status'] = 'skipped';
        if (! empty($in['date']) && $it->status === 'planned') {
            try { $tz = $this->tz($wsId); $u['scheduled_at'] = Carbon::parse($in['date'] . ' 10:00', $tz)->utc(); } catch (\Throwable $e) { return ['success' => false, 'error' => 'That date could not be read.']; }
        }
        if (! $u) return ['success' => false, 'error' => 'Nothing to change.'];
        DB::table('campaign_items')->where('id', $itemId)->update($u + ['updated_at' => now()]);
        $this->syncCalendar((int) $it->campaign_id);
        return ['success' => true];
    }

    /** Close the campaign: results measured over its dates (and a week after), then Sarah reports and learns. */
    public function complete(int $id): void
    {
        $c = DB::table('marketing_campaigns')->where('id', $id)->first();
        if (! $c || in_array($c->status, ['completed', 'declined'], true)) return;
        $res = $this->results($c);
        DB::table('marketing_campaigns')->where('id', $id)->update(['status' => 'completed', 'results_json' => json_encode($res), 'completed_at' => now(), 'updated_at' => now()]);
        if ($c->mandate_id) DB::table('mandates')->where('id', $c->mandate_id)->where('status', 'live')->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        $this->syncCalendar($id);
        try {
            $facts = ['campaign' => $c->title, 'objective' => $c->objective, 'target' => json_decode((string) $c->kpi_json, true)['label'] ?? null, 'results' => $res];
            $fallback = 'The "' . $c->title . '" campaign has finished. ' . $res['summary'] . ' I will use what worked in the next ideas.';
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords((int) $c->workspace_id, 'campaign_report',
                "Write Sarah's short campaign wrap-up (3-4 sentences) for the owner from FACTS only: what ran, the numbers during the campaign compared with the target (say 'during the campaign', never claim the campaign caused them), one lesson, and that she will use it in the next ideas. No emojis.",
                $facts, $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent((int) $c->workspace_id, 'sarah', $words, ['notification_type' => 'campaign_report', 'campaign_id' => $id]);
        } catch (\Throwable $e) {}
    }

    /** Honest measures: what happened during the campaign, next to the same length of time before it. */
    public function results(object $c): array
    {
        $from = Carbon::parse($c->starts_on)->startOfDay();
        $to = Carbon::parse($c->ends_on)->endOfDay()->addDays(7)->min(now());
        $days = max(1, (int) round(abs($from->diffInDays($to))) + 1);
        $prevFrom = $from->copy()->subDays($days);
        $count = function ($table, $col, $a, $b, $extra = null) use ($c) {
            try { $q = DB::table($table)->where('workspace_id', $c->workspace_id)->whereBetween($col, [$a, $b]); if ($extra) $extra($q); return $q->count(); } catch (\Throwable $e) { return null; }
        };
        $leads = $count('leads', 'created_at', $from, $to);
        $leadsBefore = $count('leads', 'created_at', $prevFrom, $from);
        $bookings = $count('calendar_events', 'created_at', $from, $to, fn ($q) => $q->where('category', 'like', 'booking%'));
        $buying = $count('social_comments', 'created_at', $from, $to, fn ($q) => $q->where('intent', 'hot'));
        $clicks = null;
        try { $clicks = DB::table('tracked_link_clicks as k')->join('tracked_links as l', 'l.id', '=', 'k.tracked_link_id')->where('l.workspace_id', $c->workspace_id)->whereBetween('k.created_at', [$from, $to])->count(); } catch (\Throwable $e) {}
        $items = DB::table('campaign_items')->where('campaign_id', $c->id)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status')->all();
        $done = (int) ($items['done'] ?? 0); $total = array_sum($items);
        $kpi = json_decode((string) $c->kpi_json, true) ?: [];
        $actual = match ($kpi['metric'] ?? 'leads') { 'bookings' => $bookings, 'leads', 'enquiries' => $leads, default => null };
        $summary = $done . ' of ' . $total . ' steps were completed.' . ($leads !== null ? ' ' . $leads . ' new lead' . ($leads === 1 ? '' : 's') . ' came in during the campaign' . ($leadsBefore !== null ? ' (' . $leadsBefore . ' in the same length of time before)' : '') . '.' : '')
            . ($actual !== null && ! empty($kpi['target']) ? ' Target was ' . $kpi['target'] . ' ' . ($kpi['metric'] ?? 'leads') . '.' : '');
        return array_filter(['steps_done' => $done, 'steps_total' => $total, 'leads' => $leads, 'leads_before' => $leadsBefore, 'bookings' => $bookings, 'buying_comments' => $buying,
            'link_clicks' => $clicks, 'kpi_actual' => $actual, 'kpi_target' => $kpi['target'] ?? null, 'summary' => $summary], fn ($v) => $v !== null);
    }

    /** Mirror the campaign's items (and its span) into the shared calendar. */
    public function syncCalendar(int $campaignId): void
    {
        $c = DB::table('marketing_campaigns')->where('id', $campaignId)->first();
        if (! $c) return;
        $visible = in_array($c->status, ['active', 'launching', 'paused', 'completed'], true);
        foreach (DB::table('campaign_items')->where('campaign_id', $campaignId)->get() as $it) {
            if (! $visible || in_array($it->status, ['skipped'], true)) {
                if ($it->calendar_event_id) { DB::table('calendar_events')->where('id', $it->calendar_event_id)->delete(); DB::table('campaign_items')->where('id', $it->id)->update(['calendar_event_id' => null]); }
                continue;
            }
            $row = ['workspace_id' => $c->workspace_id, 'business_id' => $c->business_id, 'title' => $it->title, 'description' => $c->title . ' · ' . $it->phase,
                'category' => 'campaign_' . $it->kind, 'engine' => 'campaigns', 'reference_type' => 'campaign_item', 'reference_id' => $it->id,
                'color' => null, 'starts_at' => $it->scheduled_at, 'ends_at' => Carbon::parse($it->scheduled_at)->addHour(), 'all_day' => 0, 'updated_at' => now()];
            if ($it->calendar_event_id && DB::table('calendar_events')->where('id', $it->calendar_event_id)->exists()) DB::table('calendar_events')->where('id', $it->calendar_event_id)->update($row);
            else DB::table('campaign_items')->where('id', $it->id)->update(['calendar_event_id' => DB::table('calendar_events')->insertGetId($row + ['created_at' => now()])]);
        }
    }

    /** Detail for the page and the API. */
    public function show(int $wsId, int $id): ?array
    {
        $c = DB::table('marketing_campaigns')->where('id', $id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $c) return null;
        $items = DB::table('campaign_items')->where('campaign_id', $id)->orderBy('phase_order')->orderBy('scheduled_at')->orderBy('sort')->get();
        $out = $this->row($c, $items);
        $out['phases'] = [];
        foreach ($items as $it) {
            $k = $it->phase_order . '|' . $it->phase;
            $out['phases'][$k] ??= ['name' => $it->phase, 'order' => (int) $it->phase_order, 'items' => []];
            $out['phases'][$k]['items'][] = ['id' => (int) $it->id, 'kind' => $it->kind, 'channel' => $it->channel, 'title' => $it->title, 'brief' => $it->brief, 'scheduled_at' => $it->scheduled_at ? Carbon::parse($it->scheduled_at)->toIso8601String() : null,
                'status' => $it->status, 'note' => self::plainNote($it->note), 'result_type' => $it->result_type, 'result_id' => $it->result_id ? (int) $it->result_id : null, 'result_url' => $it->result_url];
        }
        $out['phases'] = array_values($out['phases']);
        $out['results'] = in_array($c->status, ['active', 'paused', 'completed'], true) ? (json_decode((string) $c->results_json, true) ?: $this->results($c)) : null;
        return $out;
    }

    public function row(object $c, $items = null): array
    {
        $items = $items ?? DB::table('campaign_items')->where('campaign_id', $c->id)->get(['status', 'kind', 'scheduled_at', 'title']);
        $done = collect($items)->whereIn('status', ['done', 'skipped'])->count();
        $needs = collect($items)->where('status', 'needs_you')->count();
        $next = collect($items)->where('status', 'planned')->sortBy('scheduled_at')->first();
        return ['id' => (int) $c->id, 'business_id' => $c->business_id ? (int) $c->business_id : null, 'business_name' => $c->business_id ? DB::table('businesses')->where('id', $c->business_id)->value('name') : null,
            'title' => $c->title, 'objective' => $c->objective, 'why_now' => $c->why_now, 'audience' => $c->audience, 'offer' => $c->offer, 'channels' => json_decode((string) $c->channels_json, true) ?: [],
            'starts_on' => $c->starts_on, 'ends_on' => $c->ends_on, 'status' => $c->status, 'kpi' => json_decode((string) $c->kpi_json, true) ?: null, 'credit_estimate' => $c->credit_estimate !== null ? (int) $c->credit_estimate : null,
            'steps_total' => count($items), 'steps_done' => $done, 'needs_you' => $needs, 'next_step' => $next ? ['title' => $next->title ?? null, 'at' => $next->scheduled_at ? Carbon::parse($next->scheduled_at)->toIso8601String() : null] : null,
            'decline_reason' => $c->decline_reason, 'source' => $c->source, 'created_at' => (string) $c->created_at];
    }

    /** CHAT-FIRST-1: everything that happens in a campaign reaches the owner in Sarah's chat (and so the companion app), in her words. */
    private function tell(int $wsId, string $type, string $instruction, array $facts, string $fallback, array $meta = []): void
    {
        try {
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, $type, $instruction, array_filter($facts, fn ($v) => $v !== null && $v !== ''), $fallback);
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, $meta + ['notification_type' => $type]);
        } catch (\Throwable $e) { Log::info('[CHAT-FIRST-1] campaign message failed', ['ws' => $wsId, 'type' => $type, 'e' => $e->getMessage()]); }
    }

    private function tz(int $wsId): string
    {
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        try { new \DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return 'UTC'; }
    }
}
