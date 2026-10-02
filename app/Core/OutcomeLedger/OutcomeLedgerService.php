<?php

namespace App\Core\OutcomeLedger;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0023 P2. The ONE writer of outcomes: plan and delivery from task completion / failure, verdict from the owner's
 * approval or rejection, campaign results from campaigns:tick, business results measured at 24 h / 7 d / 30 d from the
 * systems of record (leads, bookings, tracked clicks, Search Console, social stats when a channel reports them).
 * Principle 5: numbers come from ledgers and connected channels, never from an agent's completion report. Where no source
 * is connected the row says "not measurable" rather than inventing a number. Lessons are written from the numbers and the
 * verdict by a rule (lesson_source = rule), never by the agent that did the work.
 */
final class OutcomeLedgerService
{
    public const KIND_BY_ACTION = [
        'write_article' => 'article', 'improve_draft' => 'article', 'rewrite_article' => 'article', 'expand_article' => 'article', 'publish_article' => 'article',
        'generate_image' => 'image', 'generate_image_mini' => 'image', 'generate_image_high' => 'image', 'generate_video' => 'image',
        'social_create_post' => 'post', 'social_ai_post' => 'post', 'ai_generate_post' => 'post', 'create_post' => 'post', 'social_publish_post' => 'post', 'social_schedule_post' => 'post',
        'fix_orphans' => 'seo_change', 'generate_meta' => 'seo_change', 'insert_link' => 'seo_change', 'link_suggestions' => 'seo_change', 'aeo_enrich' => 'seo_change', 'add_keyword' => 'seo_change', 'deep_audit' => 'seo_change',
        'create_lead' => 'crm', 'update_lead' => 'crm', 'send_email' => 'crm', 'lead_reply' => 'crm',
    ];

    /** Plan + delivery (or failure) from a task row, called by TaskService::markCompleted / markFailed. */
    public function recordTask(object $task, string $status, ?array $result = null, ?string $error = null): ?int
    {
        try {
            $action = (string) $task->action; $kind = self::KIND_BY_ACTION[$action] ?? 'task';
            if (in_array($action, ['sarah_chat', 'chat', 'noop'], true)) return null;
            $payload = is_array($task->payload_json ?? null) ? $task->payload_json : (json_decode((string) ($task->payload_json ?? ''), true) ?: []);
            $title = (string) ($payload['title'] ?? $payload['topic'] ?? $payload['subject'] ?? $payload['prompt'] ?? $payload['content'] ?? $action);
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $delivered = array_filter([
                'what' => mb_substr((string) ($data['title'] ?? $data['message'] ?? $result['message'] ?? $title), 0, 160),
                'post_id' => $data['post_id'] ?? null, 'article_id' => $data['article_id'] ?? $payload['article_id'] ?? null, 'url' => $data['url'] ?? $data['featured_image_url'] ?? null,
                'credits' => $task->credit_cost ?? null, 'qa' => $task->qa_status ?? null, 'error' => $error ? mb_substr($error, 0, 200) : null,
            ], fn ($v) => $v !== null && $v !== '');
            $planned = array_filter(['what' => mb_substr($title, 0, 160), 'action' => $action, 'engine' => $task->engine ?? null, 'platform' => $payload['platform'] ?? null, 'source' => $task->source ?? null, 'requested_at' => (string) ($task->created_at ?? '')]);
            $now = now();
            $row = ['workspace_id' => (int) $task->workspace_id, 'business_id' => (int) ($task->business_id ?? 0) ?: null, 'kind' => $kind, 'ref' => 'task:' . $task->id,
                'goal_ref' => ! empty($task->mandate_id) ? 'mandate:' . $task->mandate_id : null, 'status' => $status,
                'planned_json' => json_encode($planned, JSON_UNESCAPED_UNICODE), 'delivered_json' => json_encode($delivered, JSON_UNESCAPED_UNICODE),
                'delivered_at' => ! empty($task->completed_at) ? Carbon::parse((string) $task->completed_at) : $now, 'updated_at' => $now];
            $existing = DB::table('outcome_ledger')->where('ref', 'task:' . $task->id)->first(['id', 'verdict']);
            if ($existing) { DB::table('outcome_ledger')->where('id', $existing->id)->update($row); $id = (int) $existing->id; }
            else { $row['created_at'] = $now; $row['verdict'] = ($task->qa_status ?? '') === 'rejected' ? 'rejected' : 'none'; $row['verdict_json'] = ($task->qa_status ?? '') === 'rejected' ? json_encode(['by' => 'sarah_qa', 'reason' => $this->qaReason($task)]) : null; $id = (int) DB::table('outcome_ledger')->insertGetId($row); }
            if ($status === 'failed' || ($task->qa_status ?? '') === 'rejected') $this->writeLesson($id);
            return $id;
        } catch (\Throwable $e) { Log::info('[OUTCOME-LEDGER] recordTask skipped: ' . $e->getMessage()); return null; }
    }

    private function qaReason(object $task): string
    {
        $qa = is_array($task->qa_json ?? null) ? $task->qa_json : (json_decode((string) ($task->qa_json ?? ''), true) ?: []);
        return mb_substr((string) (($qa['reasons'] ?? [''])[0] ?? ''), 0, 200);
    }

    /** The owner's verdict on a task's deliverable (ApprovalController, social approvals). */
    public function verdictForTask(int $taskId, string $verdict, ?string $reason = null, ?int $by = null): void
    {
        try {
            $row = DB::table('outcome_ledger')->where('ref', 'task:' . $taskId)->first(['id']);
            $vj = json_encode(array_filter(['reason' => $reason ? mb_substr($reason, 0, 200) : null, 'by' => $by, 'at' => now()->toDateTimeString()]), JSON_UNESCAPED_UNICODE);
            if ($row) { DB::table('outcome_ledger')->where('id', $row->id)->update(['verdict' => $verdict, 'verdict_json' => $vj, 'updated_at' => now()]); $this->writeLesson((int) $row->id); return; }
            $task = DB::table('tasks')->where('id', $taskId)->first();
            if (! $task) return;
            $id = $this->recordTask($task, $task->status === 'failed' ? 'failed' : ($task->status === 'completed' ? 'delivered' : 'planned'), is_array($task->result_json ?? null) ? $task->result_json : (json_decode((string) ($task->result_json ?? ''), true) ?: null));
            if ($id) { DB::table('outcome_ledger')->where('id', $id)->update(['verdict' => $verdict, 'verdict_json' => $vj, 'updated_at' => now()]); $this->writeLesson($id); }
        } catch (\Throwable $e) { Log::info('[OUTCOME-LEDGER] verdict skipped: ' . $e->getMessage()); }
    }

    /** A campaign that completed (results from CampaignService::results) or was declined. */
    public function recordCampaign(int $campaignId, string $status): ?int
    {
        try {
            $c = DB::table('marketing_campaigns')->where('id', $campaignId)->first();
            if (! $c) return null;
            $res = json_decode((string) ($c->results_json ?? ''), true) ?: [];
            $kpi = json_decode((string) ($c->kpi_json ?? ''), true) ?: [];
            $now = now();
            $row = ['workspace_id' => (int) $c->workspace_id, 'business_id' => $c->business_id ? (int) $c->business_id : null, 'kind' => 'campaign', 'ref' => 'campaign:' . $c->id,
                'goal_ref' => $c->mandate_id ? 'mandate:' . $c->mandate_id : null, 'status' => $status === 'declined' ? 'planned' : 'delivered',
                'planned_json' => json_encode(array_filter(['what' => $c->title, 'objective' => $c->objective, 'target' => $kpi['label'] ?? null, 'channels' => json_decode((string) ($c->channels_json ?? ''), true), 'starts_on' => $c->starts_on, 'ends_on' => $c->ends_on, 'credits' => $c->credit_estimate]), JSON_UNESCAPED_UNICODE),
                'delivered_json' => json_encode(array_filter(['steps_done' => $res['steps_done'] ?? null, 'steps_total' => $res['steps_total'] ?? null, 'completed_at' => $c->completed_at]), JSON_UNESCAPED_UNICODE),
                'verdict' => $status === 'declined' ? 'declined' : 'approved', 'verdict_json' => $status === 'declined' ? json_encode(['reason' => $c->decline_reason, 'by' => $c->decided_by, 'at' => $c->decided_at]) : json_encode(['by' => $c->decided_by, 'at' => $c->launched_at ?? $c->decided_at]),
                'result_7d_json' => $res ? json_encode($res, JSON_UNESCAPED_UNICODE) : null, 'measured_7d_at' => $res ? $now : null, 'measurable' => true,
                'delivered_at' => $c->completed_at ?? $c->decided_at ?? $now, 'updated_at' => $now];
            $ex = DB::table('outcome_ledger')->where('ref', 'campaign:' . $c->id)->first(['id']);
            if ($ex) { DB::table('outcome_ledger')->where('id', $ex->id)->update($row); $id = (int) $ex->id; } else { $row['created_at'] = $now; $id = (int) DB::table('outcome_ledger')->insertGetId($row); }
            $this->writeLesson($id);
            return $id;
        } catch (\Throwable $e) { Log::info('[OUTCOME-LEDGER] recordCampaign skipped: ' . $e->getMessage()); return null; }
    }

    /** Hourly: declined campaigns not yet in the ledger, then the 24 h / 7 d / 30 d measurements that are due. */
    public function measureDue(int $limit = 400): array
    {
        $out = ['declined' => 0, '24h' => 0, '7d' => 0, '30d' => 0];
        try {
            foreach (DB::table('marketing_campaigns')->where('status', 'declined')->where('updated_at', '>=', now()->subDays(14))->pluck('id') as $cid) {
                if (! DB::table('outcome_ledger')->where('ref', 'campaign:' . $cid)->exists()) { $this->recordCampaign((int) $cid, 'declined'); $out['declined']++; }
            }
        } catch (\Throwable $e) {}
        foreach ([['24h', 1, 'measured_24h_at', 'result_24h_json'], ['7d', 7, 'measured_7d_at', 'result_7d_json'], ['30d', 30, 'measured_30d_at', 'result_30d_json']] as [$label, $days, $atCol, $resCol]) {
            $rows = DB::table('outcome_ledger')->whereNull($atCol)->where('status', 'delivered')->whereNotNull('delivered_at')->where('delivered_at', '<=', now()->subDays($days))->where('delivered_at', '>=', now()->subDays($days + 45))->orderBy('id')->limit($limit)->get();
            foreach ($rows as $r) {
                try {
                    $res = $this->measure($r, $days);
                    DB::table('outcome_ledger')->where('id', $r->id)->update([$resCol => json_encode($res, JSON_UNESCAPED_UNICODE), $atCol => now(), 'measurable' => (bool) ($res['measurable'] ?? false), 'updated_at' => now()]);
                    if ($days === 7) $this->writeLesson((int) $r->id);
                    $out[$label]++;
                } catch (\Throwable $e) { Log::info('[OUTCOME-LEDGER] measure failed', ['id' => $r->id, 'e' => $e->getMessage()]); }
            }
        }
        return $out;
    }

    /** What the business saw in the window after delivery, from connected sources only. */
    public function measure(object $r, int $days): array
    {
        $from = Carbon::parse($r->delivered_at); $to = $from->copy()->addDays($days)->min(now()); $prevFrom = $from->copy()->subDays($days);
        $ws = (int) $r->workspace_id; $biz = $r->business_id ? (int) $r->business_id : null;
        $res = ['window_days' => $days, 'sources' => []];
        $leadsQ = fn ($a, $b) => DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->when($biz, fn ($q) => $q->where('business_id', $biz))->whereBetween('created_at', [$a, $b])->count();
        try { $res['leads'] = $leadsQ($from, $to); $res['leads_before'] = $leadsQ($prevFrom, $from); $res['sources'][] = 'leads'; } catch (\Throwable) {}
        try { if (Schema::hasTable('calendar_events')) { $res['bookings'] = DB::table('calendar_events')->where('workspace_id', $ws)->where('category', 'like', 'booking%')->whereBetween('created_at', [$from, $to])->count(); $res['sources'][] = 'bookings'; } } catch (\Throwable) {}
        try { if (Schema::hasTable('tracked_link_clicks')) { $c = DB::table('tracked_link_clicks as k')->join('tracked_links as l', 'l.id', '=', 'k.tracked_link_id')->where('l.workspace_id', $ws)->whereBetween('k.created_at', [$from, $to])->count(); if ($c > 0 || DB::table('tracked_links')->where('workspace_id', $ws)->exists()) { $res['link_clicks'] = $c; $res['sources'][] = 'tracked_links'; } } } catch (\Throwable) {}
        $delivered = json_decode((string) ($r->delivered_json ?? ''), true) ?: [];
        if ($r->kind === 'post' && ! empty($delivered['post_id'])) {
            try { $p = DB::table('social_posts')->where('id', (int) $delivered['post_id'])->first(['stats_json', 'status', 'published_at', 'platform']); $st = json_decode((string) ($p->stats_json ?? ''), true) ?: [];
                $res['post_status'] = $p->status ?? null; if ($st) { foreach (['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'] as $k) if (isset($st[$k])) $res['post_' . $k] = (int) $st[$k]; $res['sources'][] = 'social_stats'; }
                elseif (! empty($p->published_at)) $res['note'] = 'the channel has not reported stats for this post';
                else $res['note'] = 'not published (draft or not approved), so it could not perform'; } catch (\Throwable) {}
        }
        if ($r->kind === 'article' && ! empty($delivered['article_id'])) {
            try { $a = DB::table('articles')->where('id', (int) $delivered['article_id'])->first(['slug', 'website_id', 'status', 'published_at']); $res['article_status'] = $a->status ?? null;
                if ($a && $a->slug && DB::table('gsc_connections')->where('workspace_id', $ws)->where('connected', 1)->exists()) {
                    $g = DB::table('gsc_metrics')->where('workspace_id', $ws)->where('page', 'like', '%' . $a->slug . '%')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->selectRaw('COALESCE(SUM(clicks),0) c, COALESCE(SUM(impressions),0) i')->first();
                    $res['gsc_clicks'] = (int) ($g->c ?? 0); $res['gsc_impressions'] = (int) ($g->i ?? 0); $res['sources'][] = 'search_console';
                } elseif (empty($a->published_at)) $res['note'] = 'not published yet, so it could not perform'; else $res['note'] = 'Search Console not connected, so page performance is not measurable'; } catch (\Throwable) {}
        }
        if ($r->kind === 'seo_change') {
            try { $imp = DB::table('keyword_rank_history as h')->join('keywords as k', 'k.id', '=', 'h.keyword_id')->where('h.workspace_id', $ws)->whereBetween('h.captured_at', [$from, $to])->count(); if ($imp) { $res['rank_captures'] = $imp; $res['sources'][] = 'rank_history'; } } catch (\Throwable) {}
        }
        $res['measurable'] = count(array_unique($res['sources'])) > 0;
        $res['sources'] = array_values(array_unique($res['sources']));
        return $res;
    }

    /** One honest line from the numbers and the verdict; never from the agent that did the work. */
    public function writeLesson(int $id): void
    {
        try {
            $r = DB::table('outcome_ledger')->where('id', $id)->first(); if (! $r) return;
            $p = json_decode((string) $r->planned_json, true) ?: []; $d = json_decode((string) $r->delivered_json, true) ?: []; $v = json_decode((string) $r->verdict_json, true) ?: [];
            $what = mb_substr((string) ($p['what'] ?? $r->kind), 0, 70);
            $bits = [];
            if ($r->status === 'failed') $bits[] = 'failed' . (! empty($d['error']) ? ' (' . mb_substr((string) $d['error'], 0, 80) . ')' : '');
            if ($r->verdict === 'rejected') $bits[] = 'rejected' . (! empty($v['reason']) ? ': ' . mb_substr((string) $v['reason'], 0, 100) : '') . (($v['by'] ?? '') === 'sarah_qa' ? ' (your own QA)' : ' by the owner');
            elseif ($r->verdict === 'declined') $bits[] = 'declined by the owner' . (! empty($v['reason']) ? ': ' . mb_substr((string) $v['reason'], 0, 100) : '');
            elseif ($r->verdict === 'approved') $bits[] = 'approved';
            elseif ($r->verdict === 'edited') $bits[] = 'approved after edits';
            $m = json_decode((string) ($r->result_7d_json ?? $r->result_24h_json ?? ''), true) ?: [];
            if ($m) {
                $w = $m['window_days'] ?? 7; $parts = [];
                if (isset($m['leads'])) $parts[] = $m['leads'] . ' lead' . ($m['leads'] == 1 ? '' : 's') . (isset($m['leads_before']) ? ' (' . $m['leads_before'] . ' in the ' . $w . ' days before)' : '');
                if (! empty($m['bookings'])) $parts[] = $m['bookings'] . ' booking' . ($m['bookings'] == 1 ? '' : 's');
                if (isset($m['link_clicks'])) $parts[] = $m['link_clicks'] . ' tracked clicks';
                if (isset($m['gsc_clicks'])) $parts[] = $m['gsc_clicks'] . ' search clicks, ' . ($m['gsc_impressions'] ?? 0) . ' impressions';
                if (isset($m['post_impressions']) || isset($m['post_reach'])) $parts[] = ($m['post_reach'] ?? $m['post_impressions']) . ' reach';
                if (! empty($m['steps_done']) || isset($m['steps_total'])) $parts[] = ($m['steps_done'] ?? 0) . ' of ' . ($m['steps_total'] ?? 0) . ' steps done';
                if (isset($m['kpi_actual']) && isset($m['kpi_target'])) $parts[] = 'target ' . $m['kpi_target'] . ', actual ' . $m['kpi_actual'];
                $bits[] = $parts ? ('in ' . $w . ' days: ' . implode(', ', $parts)) : 'no measurable result';
                if (! empty($m['note'])) $bits[] = $m['note'];
                if (empty($m['measurable']) && ! $parts) $bits[] = 'not measurable (no connected source)';
            }
            if (! $bits) return;
            DB::table('outcome_ledger')->where('id', $id)->update(['lesson' => ucfirst($r->kind) . ' "' . $what . '": ' . implode('; ', $bits) . '.', 'lesson_source' => 'rule', 'updated_at' => now()]);
        } catch (\Throwable $e) {}
    }

    /** For the Memory Pack: the last outcomes, those matching the message first. */
    public function recent(int $wsId, ?int $bizId, int $n = 3, string $message = ''): array
    {
        try {
            $q = DB::table('outcome_ledger')->where('workspace_id', $wsId)->whereIn('status', ['delivered', 'failed'])->whereNotNull('lesson');
            if ($bizId) $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id'));
            $rows = $q->orderByDesc('delivered_at')->limit(40)->get(['kind', 'lesson', 'delivered_at', 'planned_json', 'verdict']);
            $words = array_values(array_filter(array_unique(preg_split('/[^a-z0-9]+/', mb_strtolower($message)) ?: []), fn ($w) => mb_strlen($w) >= 4));
            $scored = $rows->map(function ($r) use ($words) { $hay = mb_strtolower((string) $r->lesson . ' ' . (string) $r->planned_json); $s = 0; foreach ($words as $w) if (str_contains($hay, $w)) $s++; return [$s, $r]; })->sortByDesc(fn ($x) => $x[0] * 1000 + strtotime((string) $x[1]->delivered_at) / 1e6)->take($n);
            return $scored->map(fn ($x) => '  - ' . substr((string) $x[1]->delivered_at, 0, 10) . ' ' . $x[1]->lesson)->values()->all();
        } catch (\Throwable) { return []; }
    }

    /** For the campaign planner and the weekly review: what worked and what the owner rejected, last 90 days. */
    public function whatWorked(int $wsId, ?int $bizId = null): array
    {
        try {
            $q = DB::table('outcome_ledger')->where('workspace_id', $wsId)->where('delivered_at', '>=', now()->subDays(90));
            if ($bizId) $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id'));
            $rows = $q->get(['kind', 'verdict', 'status', 'result_7d_json', 'verdict_json', 'lesson']);
            $byKind = [];
            foreach ($rows as $r) { $k = $r->kind; $byKind[$k] = $byKind[$k] ?? ['delivered' => 0, 'failed' => 0, 'approved' => 0, 'rejected' => 0, 'leads' => 0, 'measured' => 0];
                $byKind[$k][$r->status === 'failed' ? 'failed' : 'delivered']++; if (in_array($r->verdict, ['approved', 'edited'], true)) $byKind[$k]['approved']++; if (in_array($r->verdict, ['rejected', 'declined'], true)) $byKind[$k]['rejected']++;
                $m = json_decode((string) $r->result_7d_json, true) ?: []; if (isset($m['leads'])) { $byKind[$k]['leads'] += (int) $m['leads']; $byKind[$k]['measured']++; } }
            $reasons = $rows->filter(fn ($r) => in_array($r->verdict, ['rejected', 'declined'], true))->map(fn ($r) => (json_decode((string) $r->verdict_json, true) ?: [])['reason'] ?? null)->filter()->take(5)->values()->all();
            $lessons = $rows->filter(fn ($r) => $r->lesson && in_array($r->verdict, ['approved', 'edited'], true))->sortByDesc('leads')->take(4)->pluck('lesson')->values()->all();
            return ['by_kind' => $byKind, 'owner_rejected_because' => $reasons, 'best_recent' => $lessons];
        } catch (\Throwable) { return []; }
    }
}
