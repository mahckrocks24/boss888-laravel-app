<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * REPORT-0071 P1-4 (2026-10-02): live operational state for operational questions - approvals, credit runway, team and
 * queue, businesses and websites - computed from the governed tables of THIS workspace only (never platform-wide figures:
 * a customer sees their own queue, not other tenants'). Rendered as facts the reply must use; when a figure cannot be
 * computed it is listed under "cannot see" so Sarah says so instead of guessing.
 */
final class OperationalFacts
{
    public const TOPICS = [
        'approvals' => '/\b(approv\w*|review queue|sign[- ]?off|waiting (for|on) (me|my)|needs? my (ok|okay|go|yes|decision))\b/i',
        'credits'   => '/\b(credits?|balance|runway|burn\w*|spend(ing)?|budget|run out|last (me )?(the|this) month)\b/i',
        'team'      => '/\b(team|agents?|specialists?|who(\'s| is)? (working|doing)|working on|in progress|queue[ds]?|blocked|stuck|backlog|what(\'s| is) (running|happening))\b/i',
        'status'    => '/\b(status|overview|where (do )?(we|things) stand|what(\'s| is) live|which (website|site)s?|my businesses|businesses (do )?i have|anything waiting)\b/i',
    ];

    private const EFFECT = [
        'write_article' => 'writes the article as a draft (nothing publishes without its own approval)', 'improve_draft' => 'rewrites an existing draft',
        'generate_meta' => 'writes meta titles and descriptions for the listed pages', 'social_create_post' => 'drafts the social post with its banner',
        'social_publish_post' => 'publishes the post to the connected account', 'ask_arthur' => 'applies the website change, with a before/after snapshot for undo',
        'publish_article' => 'publishes the article live', 'generate_image' => 'generates the image', 'create_event' => 'puts the event on your calendar',
        'keyword_research' => 'runs keyword research', 'deep_audit' => 'runs a full SEO audit', 'fix_orphans' => 'adds internal links to orphan pages',
    ];

    /** Topics the message asks about. */
    public static function topics(string $message): array
    {
        $out = [];
        foreach (self::TOPICS as $k => $re) if (preg_match($re, $message)) $out[] = $k;
        return $out;
    }

    public static function render(int $wsId, string $message): string
    {
        $topics = self::topics($message);
        if (! $topics) return '';
        $lines = []; $cannot = [];
        try {
            if (in_array('status', $topics, true)) { $topics = array_values(array_unique(array_merge($topics, ['approvals', 'credits']))); $lines[] = self::businesses($wsId); }
            if (in_array('approvals', $topics, true)) $lines[] = self::approvals($wsId);
            if (in_array('credits', $topics, true)) { [$c, $cn] = self::credits($wsId); $lines[] = $c; $cannot = array_merge($cannot, $cn); }
            if (in_array('team', $topics, true)) $lines[] = self::team($wsId);
        } catch (\Throwable $e) { $cannot[] = 'part of the live state could not be read this turn (' . mb_substr($e->getMessage(), 0, 80) . ')'; }
        $body = implode("\n", array_filter($lines));
        if ($body === '') return '';
        return "\nLIVE OPERATIONAL STATE (this workspace only, computed from the database this turn - AUTHORITATIVE; topics: " . implode(', ', $topics) . "):\n" . $body
            . ($cannot ? "\nCANNOT SEE (say so plainly if it matters): " . implode('; ', $cannot) : '')
            . "\nThe owner asked an operational question. Answer it with these exact numbers and items - a short list is right here. Name counts, costs, ages and owners;"
            . " say what approving or unblocking each group does. Never use figures that are not above, never platform-wide or other workspaces' figures.\n";
    }

    private static function businesses(int $wsId): string
    {
        $out = ["BUSINESSES AND WEBSITES:"];
        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name', 'business_id', 'status', 'published_at']);
        foreach (DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('sort_order')->get(['id', 'name', 'industry', 'is_default']) as $b) {
            $own = $sites->where('business_id', $b->id);
            $live = $own->where('status', 'published')->pluck('name')->all();
            $out[] = '  - ' . $b->name . ($b->industry ? ' (' . str_replace('_', ' ', $b->industry) . ')' : '') . ($b->is_default ? ', default business' : '') . ': '
                . ($own->isEmpty() ? 'no website' : ($live ? 'live website ' . implode(', ', $live) : 'website in draft (' . $own->pluck('name')->implode(', ') . ')'));
        }
        $un = $sites->whereNull('business_id');
        if ($un->count()) $out[] = '  - websites not linked to a business: ' . $un->count() . ' (' . $un->where('status', 'published')->count() . ' live, ' . $un->where('status', '<>', 'published')->count() . ' draft)';
        $social = DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected')->pluck('platform')->all();
        $out[] = '  - connected social accounts: ' . ($social ? implode(', ', $social) : 'none (posts can only be drafts)');
        return implode("\n", $out);
    }

    private static function approvals(int $wsId): string
    {
        $rows = DB::table('approvals as a')->leftJoin('tasks as t', 't.id', '=', 'a.task_id')->where('a.workspace_id', $wsId)->where('a.status', 'pending')
            ->get(['a.id', 'a.action', 'a.created_at', 't.credit_cost', 't.business_id', 't.payload_json']);
        if ($rows->isEmpty()) return 'APPROVALS: none waiting.';
        $out = ['APPROVALS WAITING: ' . $rows->count() . ' in total, oldest from ' . substr((string) $rows->min('created_at'), 0, 10) . '.'];
        foreach ($rows->groupBy('action') as $action => $g) {
            $cost = 0; foreach ($g as $r) { $p = json_decode((string) $r->payload_json, true) ?: []; $cost += (int) ($r->credit_cost ?: ($p['credit_estimate'] ?? 0)); }
            $out[] = '  - ' . $g->count() . ' x ' . str_replace('_', ' ', (string) $action) . ': approving ' . (self::EFFECT[$action] ?? 'runs that work')
                . '; ' . ($cost ? $cost . ' credits in total' : 'no credits') . '; oldest ' . substr((string) $g->min('created_at'), 0, 10) . '.';
        }
        return implode("\n", $out);
    }

    /** @return array{0:string,1:array} */
    private static function credits(int $wsId): array
    {
        $cannot = [];
        $bal = app(\App\Core\Billing\CreditService::class)->getBalance($wsId);
        $avail = (float) ($bal['available'] ?? ((float) ($bal['balance'] ?? 0) - (float) ($bal['reserved'] ?? 0)));
        $spent = fn ($days) => (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->whereIn('type', ['commit', 'debit'])->where('created_at', '>=', now()->subDays($days))->sum('amount');
        $refunded = fn ($days) => (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->where('type', 'credit')->where('created_at', '>=', now()->subDays($days))
            ->where(fn ($q) => $q->whereRaw("JSON_EXTRACT(metadata_json, '$.refund_for_task') = true")->orWhereRaw("JSON_EXTRACT(metadata_json, '$.repair_refund') = true"))->sum('amount');
        $b7 = max(0, $spent(7) - $refunded(7)); $b30 = max(0, $spent(30) - $refunded(30));
        $top = DB::table('credit_transactions')->where('workspace_id', $wsId)->whereIn('type', ['commit', 'debit'])->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('reference_type r, sum(amount) s')->groupBy('r')->orderByDesc('s')->limit(4)->get()->map(fn ($x) => self::label((string) $x->r) . ' ' . (int) $x->s)->implode(', ');
        $pendingCost = 0;
        foreach (DB::table('approvals as a')->leftJoin('tasks as t', 't.id', '=', 'a.task_id')->where('a.workspace_id', $wsId)->where('a.status', 'pending')->get(['t.credit_cost', 't.payload_json']) as $r) { $p = json_decode((string) $r->payload_json, true) ?: []; $pendingCost += (int) ($r->credit_cost ?: ($p['credit_estimate'] ?? 0)); }
        $allow = (int) DB::table('workspaces')->where('id', $wsId)->value('monthly_credit_allowance');
        $daily = $b7 / 7;
        $runway = $daily > 0 ? (int) floor($avail / $daily) : null;
        $out = ['CREDITS: ' . (int) round($avail) . ' available (' . (int) round((float) ($bal['balance'] ?? 0)) . ' balance, ' . (int) round((float) ($bal['reserved'] ?? 0)) . ' reserved).',
            '  - spent: ' . $b7 . ' in the last 7 days, ' . $b30 . ' in the last 30 days (net of refunds)' . ($top ? '; biggest uses over 30 days: ' . $top : '') . '.',
            '  - runway at the last 7 days\' pace: ' . ($runway !== null ? $runway . ' days' : 'not computable (no spend in the last 7 days)') . '.',
            '  - approving everything now waiting would cost ' . $pendingCost . ' credits' . ($avail > 0 ? ' (' . (int) round(100 * $pendingCost / max(1, $avail)) . '% of what is available)' : '') . '.',
            '  - monthly plan allowance: ' . ($allow > 0 ? $allow . ' credits' : 'not recorded for this workspace') . '.'];
        if ($allow <= 0) $cannot[] = 'the plan\'s monthly allowance is not recorded, so a month-end forecast is pace-based only';
        return [implode("\n", $out), $cannot];
    }

    private static function team(int $wsId): string
    {
        $rows = DB::table('tasks')->where('workspace_id', $wsId)->whereIn('status', ['running', 'queued', 'pending', 'blocked', 'awaiting_approval'])
            ->get(['id', 'action', 'status', 'assigned_agents_json', 'business_id', 'progress_message', 'error_text', 'created_at']);
        $done7 = DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'completed')->where('completed_at', '>=', now()->subDays(7))->count();
        $failed7 = DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'failed')->where('completed_at', '>=', now()->subDays(7))->count();
        if ($rows->isEmpty()) return "TEAM: nothing running, queued or blocked. Last 7 days: {$done7} completed, {$failed7} failed.";
        $names = DB::table('agents')->pluck('name', 'slug');
        $biz = DB::table('businesses')->where('workspace_id', $wsId)->pluck('name', 'id');
        $out = ['TEAM AND QUEUE (' . $rows->count() . ' open items; last 7 days: ' . $done7 . ' completed, ' . $failed7 . ' failed):'];
        $byAgent = [];
        foreach ($rows as $r) { $a = json_decode((string) $r->assigned_agents_json, true); $slug = is_array($a) ? (string) (reset($a) ?: 'unassigned') : 'unassigned'; $byAgent[$slug][] = $r; }
        foreach ($byAgent as $slug => $list) {
            $st = collect($list)->groupBy('status')->map->count()->map(fn ($n, $s) => $n . ' ' . ($s === 'pending' ? 'waiting for approval' : $s))->implode(', ');
            $acts = collect($list)->groupBy('action')->map->count()->map(fn ($n, $a) => $n . ' ' . str_replace('_', ' ', $a))->take(4)->implode(', ');
            $bz = collect($list)->pluck('business_id')->filter()->unique()->map(fn ($id) => $biz[$id] ?? null)->filter()->take(3)->implode(', ');
            $out[] = '  - ' . ($names[$slug] ?? ucfirst($slug)) . ': ' . $st . ' (' . $acts . ')' . ($bz ? ' for ' . $bz : '') . '.';
        }
        $blocked = $rows->where('status', 'blocked');
        if ($blocked->count()) {
            $why = $blocked->map(fn ($r) => trim((string) ($r->error_text ?: $r->progress_message)) ?: 'no reason recorded')->map(fn ($s) => mb_substr(preg_replace('/\s+/', ' ', $s), 0, 70))->countBy()->sortDesc()->take(3);
            $out[] = '  - blocked reasons: ' . $why->map(fn ($n, $s) => $n . ' x "' . $s . '"')->implode('; ') . '.';
        }
        return implode("\n", $out);
    }

    private static function label(string $ref): string
    {
        $r = strtolower($ref);
        return match (true) { $r === 'task' => 'tasks', $r === 'agent_message' => 'chat', str_contains($r, 'image') => 'images', str_contains($r, 'arthur') || str_contains($r, 'builder') => 'website edits', str_contains($r, 'seo') => 'SEO', default => str_replace(['_', '/'], ' ', $r) };
    }
}
