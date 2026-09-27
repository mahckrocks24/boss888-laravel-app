<?php

namespace App\Core\Campaigns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * CAMPAIGNS-1 (RFC-0018): Sarah thinks of campaigns that grow the business.
 *
 * She reads the facts — who the business is and serves, where it is and what season is coming, its brand and saved
 * inspirations, which channels are really connected, what happened in the last 30 days (leads, buying signals, posts,
 * articles), past campaigns with their results and the owner's reasons for declining — and designs 2-4 campaigns, each
 * with a business outcome, a reason for now, phases of dated work and a measurable target. Nothing is invented: every
 * number she states must come from the facts. How she plans is private (SECRET-1): the owner sees ideas, not prompts.
 */
final class CampaignPlanner
{
    public const KINDS = ['post', 'article', 'email', 'image', 'event', 'owner_task'];
    public const CHANNELS = ['facebook', 'instagram', 'linkedin', 'website', 'email', 'in_person', 'phone'];
    private const KIND_ALIAS = ['social_post' => 'post', 'social' => 'post', 'facebook_post' => 'post', 'instagram_post' => 'post', 'linkedin_post' => 'post', 'reel' => 'post', 'story' => 'post', 'carousel' => 'post',
        'blog' => 'article', 'blog_post' => 'article', 'newsletter' => 'email', 'email_blast' => 'email', 'graphic' => 'image', 'banner' => 'image', 'flyer' => 'image', 'poster' => 'image', 'design' => 'image',
        'call' => 'owner_task', 'task' => 'owner_task', 'in_store' => 'owner_task', 'owner' => 'owner_task', 'promotion' => 'event', 'offer' => 'event', 'workshop' => 'event', 'tasting' => 'event'];

    /** @return array<int,array> normalised campaign ideas (not yet saved) */
    public function ideas(int $wsId, ?int $businessId, int $count = 3, string $ask = ''): array
    {
        $facts = $this->facts($wsId, $businessId);
        $runtime = app(\App\Connectors\RuntimeClient::class);
        if (! $runtime->isConfigured()) return [];
        $count = max(1, min(4, $count));
        $sys = 'You are Sarah, a senior digital marketing manager who plans campaigns that grow small and medium businesses: more enquiries, bookings, sales, repeat customers and reviews. '
            . "Design {$count} distinct campaign ideas for THIS business from the FACTS. Each must be realistic for a small team, specific to the business, the audience and the local season, and move one clear business outcome.\n"
            . "Rules:\n"
            . "- Ground every idea in the facts: the business's services and audience, the date and location (upcoming holidays, seasons, local moments in the next 8 weeks), recent signals (leads, buying comments, what content exists), and what worked or was declined before. Never repeat a declined idea or an active campaign.\n"
            . "- Use the channels the business really has (connected_channels). A channel that is not connected may appear only if the item says what the owner must connect first.\n"
            . "- Mix formats a designer and marketer would: social posts with banners, a website article that supports search, an offer or event, and simple owner tasks (call past clients, ask for reviews, put up a poster).\n"
            . "- Bulk email is not part of the product: never plan automated email sends. When emailing past customers would help, add an owner_task whose brief is the short, personal message the owner can send themselves.\n"
            . "- Structure: 2-4 phases (task groups) such as Build-up, Launch, Follow-up; 5-12 items in total, each with a day_offset from the campaign start (0 = first day) and a short plain-language brief of what it says or shows.\n"
            . "- Target: one measurable KPI (leads, bookings, enquiries, sales, reviews, followers, email_signups or visits) with a modest, believable number for this business's size. Never promise results.\n"
            . "- Starts within the next 3-30 days (starts_in_days); 7-35 days long (duration_days).\n"
            . "- Write for the owner: warm, concrete, no jargon, no internal codes, no mention of AI, prompts or how you plan.\n"
            . 'Return ONLY JSON: {"campaigns":[{"title":"","objective":"the business outcome in one sentence","why_now":"the moment or signal that makes this timely","audience":"","offer":"optional",'
            . '"channels":["facebook|instagram|linkedin|website|email|in_person|phone"],"starts_in_days":0,"duration_days":14,"kpi":{"metric":"leads","target":10,"label":"10 new enquiries"},'
            . '"phases":[{"name":"Build-up","items":[{"kind":"post|article|email|image|event|owner_task","channel":"facebook","day_offset":0,"title":"","brief":""}]}]}]}';
        $user = 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ($ask !== '' ? "\nOWNER ASKED: " . mb_substr($ask, 0, 600) : '');
        $r = $runtime->chatJson($sys, $user, ['task' => 'campaign_ideas', 'workspace_id' => (string) $wsId], 4000);
        $raw = (($r['success'] ?? false) && is_array($r['parsed']['campaigns'] ?? null)) ? $r['parsed']['campaigns'] : [];
        if (! $raw) Log::warning('[CAMPAIGNS-1] planner returned nothing', ['ws' => $wsId, 'err' => $r['error'] ?? null]);
        Log::info('[CAMPAIGNS-1] planner', ['ws' => $wsId, 'asked' => $count, 'returned' => count($raw)]);
        $out = [];
        foreach ($raw as $c) { if ($n = $this->normalise($c, $facts)) $out[] = $n; }
        return array_slice($out, 0, $count);
    }

    private function normalise($c, array $facts): ?array
    {
        if (! is_array($c) || trim((string) ($c['title'] ?? '')) === '') return null;
        $s = fn ($v, $n) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $n);
        $channels = array_values(array_unique(array_filter(array_map(fn ($x) => strtolower(trim((string) $x)), (array) ($c['channels'] ?? [])), fn ($x) => in_array($x, self::CHANNELS, true))));
        $start = max(1, min(45, (int) ($c['starts_in_days'] ?? 7)));
        $dur = max(5, min(45, (int) ($c['duration_days'] ?? 14)));
        $phases = [];
        $order = 0;
        foreach (array_slice((array) ($c['phases'] ?? []), 0, 4) as $p) {
            if (! is_array($p)) continue;
            $order++;
            $items = [];
            foreach (array_slice((array) ($p['items'] ?? []), 0, 8) as $it) {
                if (! is_array($it)) continue;
                $kind = strtolower(trim((string) ($it['kind'] ?? '')));
                $kind = self::KIND_ALIAS[$kind] ?? $kind;
                if (! in_array($kind, self::KINDS, true) || trim((string) ($it['title'] ?? '')) === '') continue;
                $ch = strtolower(trim((string) ($it['channel'] ?? '')));
                if (! in_array($ch, self::CHANNELS, true)) $ch = match ($kind) { 'article' => 'website', 'email' => 'email', 'owner_task', 'event' => 'in_person', default => ($channels[0] ?? 'facebook') };
                // a social post lives on a social network; an article on the website; an email in email
                $social = array_values(array_intersect((array) ($facts['connected_channels'] ?? []), ['facebook', 'instagram', 'linkedin']));
                if ($kind === 'post' && ! in_array($ch, ['facebook', 'instagram', 'linkedin'], true)) $ch = $social[0] ?? (in_array($channels[0] ?? '', ['facebook', 'instagram', 'linkedin'], true) ? $channels[0] : 'facebook');
                if ($kind === 'article') $ch = 'website';
                if ($kind === 'email') { $kind = 'owner_task'; $ch = 'email'; }   // email marketing is out of launch scope: the owner sends it personally
                $items[] = ['kind' => $kind, 'channel' => $ch, 'day_offset' => max(0, min($dur - 1, (int) ($it['day_offset'] ?? 0))), 'title' => $s($it['title'], 200), 'brief' => $s($it['brief'] ?? '', 1500)];
            }
            if ($items) $phases[] = ['name' => $s($p['name'] ?? ('Phase ' . $order), 60) ?: 'Phase ' . $order, 'order' => $order, 'items' => $items];
        }
        $n = array_sum(array_map(fn ($p) => count($p['items']), $phases));
        if ($n < 3) return null;
        $kpi = is_array($c['kpi'] ?? null) ? $c['kpi'] : [];
        $metric = strtolower((string) ($kpi['metric'] ?? 'leads'));
        if (! in_array($metric, ['leads', 'bookings', 'enquiries', 'sales', 'reviews', 'followers', 'email_signups', 'visits'], true)) $metric = 'leads';
        return [
            'title' => $s($c['title'], 160), 'objective' => $s($c['objective'] ?? '', 600), 'why_now' => $s($c['why_now'] ?? '', 600),
            'audience' => $s($c['audience'] ?? '', 300), 'offer' => $s($c['offer'] ?? '', 300), 'channels' => array_values(array_diff(array_unique(array_map(fn ($i) => $i['channel'], array_merge(...array_map(fn ($p) => $p['items'], $phases)))), ['in_person', 'phone'])),
            'starts_in_days' => $start, 'duration_days' => $dur,
            'kpi' => ['metric' => $metric, 'target' => max(1, (int) ($kpi['target'] ?? 5)), 'label' => $s($kpi['label'] ?? '', 120)],
            'phases' => $phases,
        ];
    }

    /** Everything Sarah knows that should shape the ideas. Facts only. */
    public function facts(int $wsId, ?int $businessId): array
    {
        $biz = app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $businessId);
        $ws = DB::table('workspaces')->where('id', $wsId)->first();
        $tz = (string) ($ws->timezone ?? 'UTC') ?: 'UTC';
        try { $now = Carbon::now($tz); } catch (\Throwable $e) { $now = Carbon::now('UTC'); }
        $dec = fn ($v) => is_string($v) ? (json_decode($v, true) ?: $v) : $v;
        $since = now()->subDays(30);
        $bizId = $biz->id ?? null;

        $connected = [];
        try {
            $q = DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected');
            foreach ($q->get(['platform', 'business_id']) as $a) { if (! $bizId || ! $a->business_id || (int) $a->business_id === (int) $bizId) $connected[] = strtolower((string) $a->platform); }
        } catch (\Throwable $e) {}
        $site = null;
        try {
            $sq = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'published');
            $site = ($bizId ? (clone $sq)->where('business_id', $bizId)->first(['id', 'name', 'subdomain', 'custom_domain']) : null) ?: (clone $sq)->first(['id', 'name', 'subdomain', 'custom_domain']);
            if ($site) $connected[] = 'website';
        } catch (\Throwable $e) {}
        $emailList = 0;
        try { $emailList = DB::table('leads')->where('workspace_id', $wsId)->whereNotNull('email')->where('email', '!=', '')->where(fn ($w) => $w->whereNull('email_unsubscribed')->orWhere('email_unsubscribed', 0))->count(); } catch (\Throwable $e) {}
        if ($emailList > 0) $connected[] = 'email';

        $leads = []; $hot = 0; $posts = 0; $articles = 0;
        try { $leads = DB::table('leads')->where('workspace_id', $wsId)->where('created_at', '>=', $since)->select('source', DB::raw('count(*) c'))->groupBy('source')->pluck('c', 'source')->all(); } catch (\Throwable $e) {}
        try { if (Schema::hasTable('social_comments')) $hot = DB::table('social_comments')->where('workspace_id', $wsId)->where('created_at', '>=', $since)->where('intent', 'hot')->count(); } catch (\Throwable $e) {}
        try { $posts = DB::table('social_posts')->where('workspace_id', $wsId)->where('status', 'published')->where('updated_at', '>=', $since)->count(); } catch (\Throwable $e) {}
        try { $articles = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->where('updated_at', '>=', $since)->count(); } catch (\Throwable $e) {}

        $past = [];
        try {
            $pq = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereIn('status', ['active', 'completed', 'declined', 'paused']);
            if ($bizId) $pq->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id'));
            foreach ($pq->orderByDesc('id')->limit(8)->get() as $c) {
                $past[] = array_filter(['title' => $c->title, 'status' => $c->status, 'when' => $c->starts_on, 'target' => json_decode((string) $c->kpi_json, true)['label'] ?? null,
                    'result' => json_decode((string) $c->results_json, true)['summary'] ?? null, 'owner_declined_because' => $c->decline_reason]);
            }
        } catch (\Throwable $e) {}
        $goals = [];
        try { $goals = DB::table('workspace_goals')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereNotIn('status', ['achieved', 'abandoned'])->limit(3)->pluck('title')->all(); } catch (\Throwable $e) {}
        $brand = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId, $bizId);
        $insp = [];
        try { $insp = DB::table('design_inspirations')->where('workspace_id', $wsId)->where('status', 'active')->orderByDesc('pinned')->limit(4)->pluck('title')->all(); } catch (\Throwable $e) {}

        return array_filter([
            'today' => $now->toDateString() . ' (' . $now->format('l') . ')', 'timezone' => $tz,
            'business' => array_filter([
                'name' => $biz->name ?? ($ws->business_name ?? $ws->name ?? null), 'industry' => $biz->industry ?? ($ws->industry ?? null), 'location' => $biz->location ?? ($ws->location ?? null),
                'services' => $dec($biz->services_json ?? null), 'goal' => $biz->goal ?? null, 'audience' => $biz->target_audience ?? null, 'tone' => $brand['tone'] ?? null,
                'differentiators' => $biz->differentiators ?? null, 'pricing' => $biz->pricing_anchor ?? null, 'website' => $site ? ($site->custom_domain ?: $site->subdomain) : null,
            ]),
            'connected_channels' => array_values(array_unique($connected)), 'email_list_size' => $emailList,
            'design_styles' => array_map(fn ($d) => \App\Core\Brand\DesignDirections::ALL[$d]['name'] ?? $d, (array) ($brand['design_picks'] ?? [])),
            'saved_inspirations' => $insp,
            'last_30_days' => ['leads_by_source' => $leads, 'buying_comments' => $hot, 'posts_published' => $posts, 'articles_published' => $articles],
            'active_goals' => $goals, 'past_campaigns' => $past,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }
}
