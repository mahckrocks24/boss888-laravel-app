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
    public const KINDS = ['post', 'article', 'email', 'image', 'video', 'event', 'owner_task'];   // VIDEO-2: video
    public const CHANNELS = ['facebook', 'instagram', 'linkedin', 'website', 'email', 'in_person', 'phone'];
    /** CAMPAIGN-CADENCE-1: the Owner's posting goal — at least this many social posts a day for each business. */
    public const POSTS_PER_DAY = 1;
    private const POST_KINDS = ['post', 'video'];   // what lands on a social feed
    private const CALENDAR_DAYS = 45;
    private const KIND_ALIAS = ['social_post' => 'post', 'social' => 'post', 'facebook_post' => 'post', 'instagram_post' => 'post', 'linkedin_post' => 'post', 'reel' => 'video', 'reels' => 'video', 'clip' => 'video', 'short_video' => 'video', 'tiktok' => 'video', 'story' => 'post', 'carousel' => 'post',
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
            . "- Ground every idea in the facts: the business's services and audience, the date and location (upcoming holidays, seasons, local moments in the next 8 weeks), recent signals (leads, buying comments, what content exists), and what worked or was declined before. Never repeat a declined idea, an active campaign or an idea still waiting for the owner (past_campaigns with status idea).\n"
            . "- Use the channels the business really has (connected_channels). A channel that is not connected may appear only if the item says what the owner must connect first.\n"
            . "- Mix formats a designer and marketer would: social posts with banners, a website article that supports search, an offer or event, and simple owner tasks (call past clients, ask for reviews, put up a poster). A short brand video (kind video: a 6-second vertical clip for Reels and Stories, 28 credits) fits once or twice in a campaign when motion sells the offer (food, places, products in use).\n"
            . "- Search (FACTS.search): when the business has a Search Roadmap, a campaign's website article is the roadmap article of that week (use its exact title from roadmap_articles_coming) — do not plan a second article that week; add a post that shares it instead. Pages close to page one and searches worth winning are good campaign themes (a keyword push: the article plus posts that point to it). A campaign aimed at search may use the KPI visits.\n"
            . "- Bulk email is not part of the product: never plan automated email sends. When emailing past customers would help, add an owner_task whose brief is the short, personal message the owner can send themselves.\n"
            . "- POSTING GOAL: the business should have at least one social post every day (FACTS.posting_goal). FACTS.calendar lists what is already planned (live campaigns, ideas still waiting for the owner, scheduled posts) and days_without_a_post. Put each campaign's posts on days that have no post yet, so together with what is planned every day of the campaign has one post; never stack a second post on a day that already has one.\n"
            . "- The ideas in this set may all be launched: give them different start dates and never put two of their posts on the same day.\n"
            . "- Structure: 2-4 phases (task groups) such as Build-up, Launch, Follow-up. Plan one post (or a short video) for every free day of the campaign, varied so a daily feed stays interesting (tips, behind the scenes, the offer, a customer story, a question, a countdown), plus the article, event and owner tasks that move the outcome. Each item has a day_offset from the campaign start (0 = first day) and a short plain-language brief of what it says or shows.\n"
            . "- Target: one measurable KPI (leads, bookings, enquiries, sales, reviews, followers, email_signups or visits) with a modest, believable number for this business's size. Never promise results.\n"
            . "- Starts within the next 1-30 days (starts_in_days); 7-21 days long (duration_days).\n"
            . "- Write for the owner: warm, concrete, no jargon, no internal codes, no mention of AI, prompts or how you plan.\n"
            . 'Return ONLY JSON: {"campaigns":[{"title":"","objective":"the business outcome in one sentence","why_now":"the moment or signal that makes this timely","audience":"","offer":"optional",'
            . '"channels":["facebook|instagram|linkedin|website|email|in_person|phone"],"starts_in_days":0,"duration_days":14,"kpi":{"metric":"leads","target":10,"label":"10 new enquiries"},'
            . '"phases":[{"name":"Build-up","items":[{"kind":"post|article|email|image|video|event|owner_task","channel":"facebook","day_offset":0,"title":"","brief":""}]}]}]}';
        $user = 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ($ask !== '' ? "\nOWNER ASKED: " . mb_substr($ask, 0, 600) : '');
        $r = $runtime->chatJson($sys, $user, ['task' => 'campaign_ideas', 'workspace_id' => (string) $wsId], 6000);   // CAMPAIGN-CADENCE-1: a post a day is a longer plan (runtime cap 8192 = output + 2000 reasoning)
        $raw = (($r['success'] ?? false) && is_array($r['parsed']['campaigns'] ?? null)) ? $r['parsed']['campaigns'] : [];
        if (! $raw) Log::warning('[CAMPAIGNS-1] planner returned nothing', ['ws' => $wsId, 'err' => $r['error'] ?? null]);
        Log::info('[CAMPAIGNS-1] planner', ['ws' => $wsId, 'asked' => $count, 'returned' => count($raw)]);
        $out = [];
        foreach ($raw as $c) { if ($n = $this->normalise($c, $facts)) $out[] = $n; }
        $bz = app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $businessId)->id ?? $businessId;
        $tz = app(\App\Core\Campaigns\CampaignService::class)->tz($wsId, $bz ? (int) $bz : null);
        return $this->fillDaily($this->layOut(array_slice($out, 0, $count), $this->postDays($wsId, $businessId), $tz), $this->postDays($wsId, $businessId), $tz, $facts, $wsId);
    }

    private function normalise($c, array $facts): ?array
    {
        if (! is_array($c) || trim((string) ($c['title'] ?? '')) === '') return null;
        $s = fn ($v, $n) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $n);
        $channels = array_values(array_unique(array_filter(array_map(fn ($x) => strtolower(trim((string) $x)), (array) ($c['channels'] ?? [])), fn ($x) => in_array($x, self::CHANNELS, true))));
        $start = max(1, min(45, (int) ($c['starts_in_days'] ?? 7)));
        $dur = max(5, min(30, (int) ($c['duration_days'] ?? 14)));
        $phases = [];
        $order = 0;
        foreach (array_slice((array) ($c['phases'] ?? []), 0, 4) as $p) {
            if (! is_array($p)) continue;
            $order++;
            $items = [];
            foreach (array_slice((array) ($p['items'] ?? []), 0, 24) as $it) {
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
                if ($kind === 'video' && ! in_array($ch, ['facebook', 'instagram', 'linkedin', 'website'], true)) $ch = $social[0] ?? 'instagram';
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

    /**
     * CAMPAIGN-CADENCE-1: every social post already planned for this business in the coming weeks, by local date:
     * items of live, paused and waiting campaigns, and posts scheduled outside campaigns. @return array<string,array<int,string>>
     */
    public function plannedPosts(int $wsId, ?int $businessId): array
    {
        $biz = app(\App\Core\Brand\BrandProfileService::class)->business($wsId, $businessId);
        $bizId = $biz->id ?? $businessId;
        $tz = app(\App\Core\Campaigns\CampaignService::class)->tz($wsId, $bizId ? (int) $bizId : null);   // the clock saveIdeas dates by
        try { $today = Carbon::now($tz)->startOfDay(); } catch (\Throwable $e) { $tz = 'UTC'; $today = Carbon::now('UTC')->startOfDay(); }
        $until = $today->copy()->addDays(self::CALENDAR_DAYS);
        $day = fn ($at) => Carbon::parse($at, 'UTC')->setTimezone($tz)->toDateString();
        $out = [];
        try {
            $q = DB::table('campaign_items as i')->join('marketing_campaigns as c', 'c.id', '=', 'i.campaign_id')
                ->where('c.workspace_id', $wsId)->whereNull('c.deleted_at')->whereIn('i.kind', self::POST_KINDS)
                ->where('i.scheduled_at', '>=', $today->copy()->utc())->where('i.scheduled_at', '<', $until->copy()->utc())
                ->whereNotIn('i.status', ['skipped', 'cancelled', 'failed'])
                ->where(fn ($w) => $w->whereIn('c.status', ['active', 'paused'])->orWhere(fn ($x) => $x->where('c.status', 'idea')->where('c.created_at', '>=', now()->subDays(21))));
            if ($bizId) $q->where(fn ($w) => $w->where('c.business_id', $bizId)->orWhereNull('c.business_id'));
            foreach ($q->get(['i.scheduled_at', 'i.title', 'c.title as campaign', 'c.status as cstatus']) as $r)
                $out[$day($r->scheduled_at)][] = 'post: ' . mb_substr((string) $r->title, 0, 70) . ' (' . ($r->cstatus === 'idea' ? 'idea waiting: ' : 'campaign: ') . mb_substr((string) $r->campaign, 0, 50) . ')';
        } catch (\Throwable $e) {}
        try {
            $q = DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'scheduled')
                ->where('scheduled_at', '>=', $today->copy()->utc())->where('scheduled_at', '<', $until->copy()->utc());
            if ($bizId) $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id'));
            foreach ($q->get(['scheduled_at', 'content']) as $r) $out[$day($r->scheduled_at)][] = 'post scheduled: ' . mb_substr(trim((string) $r->content), 0, 60);
        } catch (\Throwable $e) {}
        ksort($out);
        return $out;
    }

    /** @return array<string,true> the local dates that already have a post */
    private function postDays(int $wsId, ?int $businessId): array
    {
        return array_fill_keys(array_keys($this->plannedPosts($wsId, $businessId)), true);
    }

    private function calendarFacts(int $wsId, ?int $bizId, Carbon $now): array
    {
        $planned = $this->plannedPosts($wsId, $bizId);
        $free = [];
        for ($d = $now->copy()->startOfDay()->addDay(), $i = 0; $i < 35; $i++, $d->addDay()) if (! isset($planned[$d->toDateString()])) $free[] = $d->toDateString();
        return ['already_planned' => array_map(fn ($l) => array_slice($l, 0, 3), $planned), 'days_without_a_post' => $free];
    }

    /**
     * CAMPAIGN-CADENCE-1: lay the ideas' dates out in code. Each idea starts on its first free day (not before the day
     * Sarah chose), and its posts go on successive days that have no post yet, in her order and never earlier than
     * she placed them; other steps keep their place relative to the start. Ideas are laid out in turn, so one batch
     * never shares a post day. The campaign stretches to hold its posts (at most 30 days).
     */
    private function layOut(array $ideas, array $taken, string $tz): array
    {
        foreach ($ideas as &$c) {
            $base = Carbon::now($tz)->startOfDay();
            $start = (int) $c['starts_in_days'];
            for ($g = 0; $g < 30 && isset($taken[$base->copy()->addDays($start)->toDateString()]); $g++) $start++;
            $c['starts_in_days'] = $start;
            $posts = [];
            foreach ($c['phases'] as $pi => $p) foreach ($p['items'] as $ii => $it) if (in_array($it['kind'], self::POST_KINDS, true)) $posts[] = [$pi, $ii, (int) $it['day_offset']];
            usort($posts, fn ($a, $b) => $a[2] <=> $b[2] ?: ($a[0] <=> $b[0] ?: $a[1] <=> $b[1]));
            $cursor = 0; $last = 0;
            foreach ($posts as [$pi, $ii, $want]) {
                $off = max($want, $cursor);
                while ($off < 30 && isset($taken[$base->copy()->addDays($start + $off)->toDateString()])) $off++;
                if ($off >= 30) $off = max($want, $cursor);   // no free day left in reach: keep her date
                $c['phases'][$pi]['items'][$ii]['day_offset'] = $off;
                $taken[$base->copy()->addDays($start + $off)->toDateString()] = true;
                $cursor = $off + 1; $last = max($last, $off);
            }
            foreach ($c['phases'] as $p) foreach ($p['items'] as $it) $last = max($last, (int) $it['day_offset']);
            $c['duration_days'] = max((int) $c['duration_days'], min(30, $last + 1));
        }
        unset($c);
        return $ideas;
    }

    /**
     * CAMPAIGN-CADENCE-1b: the posting goal, kept. Days inside a campaign that still have no post (nothing planned, no
     * post of this idea or an earlier idea in the batch) are reserved in turn, then Sarah writes one post for each in a
     * short step per idea, all at once. If she cannot, the idea keeps the posts it has.
     */
    private function fillDaily(array $ideas, array $taken, string $tz, array $facts, int $wsId): array
    {
        $base = Carbon::now($tz)->startOfDay();
        $gaps = [];
        foreach ($ideas as $c)   // every idea's own posts first, so no gap is filled on another idea's post day
            foreach ($c['phases'] as $p) foreach ($p['items'] as $it) if (in_array($it['kind'], self::POST_KINDS, true)) $taken[$base->copy()->addDays($c['starts_in_days'] + (int) $it['day_offset'])->toDateString()] = true;
        foreach ($ideas as $k => $c) {
            for ($o = 0; $o < (int) $c['duration_days']; $o++) {
                $d = $base->copy()->addDays($c['starts_in_days'] + $o)->toDateString();
                if (! isset($taken[$d])) { $gaps[$k][] = $o; $taken[$d] = true; }
            }
        }
        if (! $gaps) return $ideas;
        $sys = 'You are Sarah, a senior digital marketing manager. A campaign needs one social post on each of the listed days so the business posts every day. '
            . 'Write exactly one post per day_offset: varied (a tip, behind the scenes, the offer, a customer story, a question, a countdown, a reminder), specific to the business and the campaign, never repeating a post it already has. '
            . 'Use only facts given; no invented numbers, prices or reviews. Warm, plain words, no mention of AI. '
            . 'Return ONLY JSON: {"posts":[{"day_offset":0,"channel":"facebook|instagram|linkedin","title":"short","brief":"what it says or shows, at most 20 words"}]}';
        $calls = [];
        foreach ($gaps as $k => $offs) {
            $c = $ideas[$k];
            $have = [];
            foreach ($c['phases'] as $p) foreach ($p['items'] as $it) $have[] = $it['day_offset'] . ': ' . $it['kind'] . ' — ' . $it['title'];
            $calls[$k] = [$sys, 'BUSINESS: ' . json_encode($facts['business'] ?? [], JSON_UNESCAPED_UNICODE) . "\nCONNECTED: " . implode(', ', (array) ($facts['connected_channels'] ?? []))
                . "\nCAMPAIGN: " . $c['title'] . ' — ' . $c['objective'] . ($c['offer'] ? ' Offer: ' . $c['offer'] : '') . "\nALREADY IN IT:\n" . implode("\n", $have)
                . "\nWRITE ONE POST FOR EACH day_offset: " . implode(', ', $offs), ['task' => 'campaign_daily_posts', 'workspace_id' => (string) $wsId], min(6000, 200 + 90 * count($offs))];
        }
        try { $res = app(\App\Connectors\RuntimeClient::class)->chatJsonPool($calls); } catch (\Throwable $e) { Log::warning('[CAMPAIGN-CADENCE-1] fill failed', ['e' => $e->getMessage()]); return $ideas; }
        $social = array_values(array_intersect((array) ($facts['connected_channels'] ?? []), ['facebook', 'instagram', 'linkedin']));
        foreach ($gaps as $k => $offs) {
            $posts = (($res[$k]['success'] ?? false) && is_array($res[$k]['parsed']['posts'] ?? null)) ? $res[$k]['parsed']['posts'] : [];
            $byOff = [];
            foreach ($posts as $p) if (is_array($p) && trim((string) ($p['title'] ?? '')) !== '') $byOff[(int) ($p['day_offset'] ?? -1)] = $p;
            $pending = array_values(array_filter($posts, fn ($p) => is_array($p) && ! in_array((int) ($p['day_offset'] ?? -1), $offs, true) && trim((string) ($p['title'] ?? '')) !== ''));
            $last = count($ideas[$k]['phases']) - 1;
            $added = 0;
            foreach ($offs as $o) {
                $p = $byOff[$o] ?? array_shift($pending);   // a post with a stray day still fills a free day
                if (! $p) continue;
                $ch = strtolower(trim((string) ($p['channel'] ?? '')));
                if (! in_array($ch, ['facebook', 'instagram', 'linkedin'], true)) $ch = $social[0] ?? 'facebook';
                // the post joins the phase whose steps are closest in time
                $phase = $last;
                foreach ($ideas[$k]['phases'] as $pi => $ph) { $mx = max(array_map(fn ($i) => (int) $i['day_offset'], $ph['items'])); if ($o <= $mx) { $phase = $pi; break; } }
                $ideas[$k]['phases'][$phase]['items'][] = ['kind' => 'post', 'channel' => $ch, 'day_offset' => $o, 'title' => mb_substr(trim((string) $p['title']), 0, 200), 'brief' => mb_substr(trim((string) ($p['brief'] ?? '')), 0, 1500)];
                $added++;
            }
            foreach ($ideas[$k]['phases'] as &$ph) usort($ph['items'], fn ($a, $b) => $a['day_offset'] <=> $b['day_offset']);
            unset($ph);
            Log::info('[CAMPAIGN-CADENCE-1] daily posts', ['ws' => $wsId, 'idea' => $ideas[$k]['title'], 'free_days' => count($offs), 'added' => $added]);
        }
        return $ideas;
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
            $pq = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereIn('status', ['active', 'completed', 'declined', 'paused', 'idea']);
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
            'active_goals' => $goals, 'past_campaigns' => $past, 'what_worked_last_90_days' => app(\App\Core\OutcomeLedger\OutcomeLedgerService::class)->whatWorked($wsId, $bizId),   // RFC-0023 P2
            'posting_goal' => 'At least ' . self::POSTS_PER_DAY . ' social post every day',
            'calendar' => $this->calendarFacts($wsId, $bizId, $now),
            'search' => (function () use ($wsId, $bizId) { try { return \App\Core\Search\Performance::plannerFacts($wsId, $bizId) ?: null; } catch (\Throwable $e) { return null; } })(),   // PAGE-ONE-1 S4
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }
}
