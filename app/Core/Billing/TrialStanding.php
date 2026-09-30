<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;

/**
 * TRIAL-AWARE-1 (Owner 2026-09-30: "add some hardening on how Sarah is aware of the trial and her aim is to convince the
 * user to upgrade" … "it has to sell value not subscriptions" … "Your brand is important, it's best to add your own
 * personalized domain, would you like me to look for available domain names for you? … things like that").
 *
 * One computed standing about the workspace's plan, written for Sarah's own eyes, plus HOW she earns the decision: with
 * value for this business, never with a package. Everything here is read from the record: TrialService for the trial,
 * the plans table for the plan and price, the capability map for every credit figure, the workspace's websites, social
 * accounts, articles, posts and leads for what has been done and what is missing, the workspace's industry for the words.
 * The model never computes a number; it is handed them. Nothing here spends AI.
 *
 * Rendered into the never-truncated DERIVED STATE block of every turn (DerivedState) and into the daily brief's state
 * (WorkspaceStateGatherer tier_state.trial_line), so the conversation and the morning brief carry the same facts.
 */
final class TrialStanding
{
    public function __construct(
        private TrialService $trials,
        private FeatureGateService $gate,
    ) {}

    /** The facts, for code and for the brief's state. */
    public function facts(int $wsId): array
    {
        $out = ['state' => 'unknown', 'line' => '', 'aim' => '', 'plays' => [], 'month' => ''];
        try {
            $ws = DB::table('workspaces')->where('id', $wsId)->first(['timezone', 'industry', 'trial_started_at', 'trial_expires_at', 'trial_credits']);
            if (! $ws) return $out;
            $tz = $ws->timezone ?: 'UTC';
            $plan = $this->gate->getActivePlanFor($wsId);
            $offer = $this->offerPlan();
            $status = $this->trials->getTrialStatus($wsId);
            $words = $this->industryWords((string) ($ws->industry ?? ''));

            if (! empty($status['has_trial']) && ! empty($status['active'])) {
                $started = \Carbon\Carbon::parse($status['started_at']);
                $ends = \Carbon\Carbon::parse($status['expires_at'])->tz($tz);
                $day = min(3, max(1, (int) floor($started->diffInHours(now()) / 24) + 1));
                $left = (int) ($status['credits_remaining'] ?? 0);
                $total = (int) ($status['trial_credits'] ?? 50);
                $lastDay = $ends->isToday() || $ends->lte(now()->addHours(24));
                $trialPlan = $plan ? $plan->name : 'AI Lite';
                $out['state'] = $lastDay ? 'trial_last_day' : 'trial';
                $out['line'] = sprintf(
                    'PLAN & TRIAL (computed now, exact): free trial of %s, day %d of 3, ends %s%s (%s); %d of %d trial credits left.',
                    $trialPlan, $day, $lastDay ? 'TODAY at ' : '', $ends->format($lastDay ? 'H:i' : 'D j M \a\t H:i'), $tz, $left, $total
                );
                $done = $this->done($wsId, (string) $ws->trial_started_at);
                $out['plays'] = $this->plays($wsId, $words, $done);
                $out['month'] = $offer ? $this->monthLine($offer, $words) : '';
                $price = $offer ? sprintf('%s at $%d a month', $offer->name, (int) $offer->price) : '';
                $aim  = "  HOW YOU EARN THE DECISION: sell value for this business, never a subscription. Never say upgrade, package, subscription, tier, or 'most of our users'.\n";
                $aim .= "  Done so far in the trial (exact): " . $done['line'] . "\n";
                if ($out['plays']) {
                    $aim .= "  The next useful things for this business, each offered as something you will do now (one at a time, the top one first):\n";
                    foreach ($out['plays'] as $p) $aim .= "    - " . $p . "\n";
                }
                if ($out['month'] !== '') $aim .= "  What the team keeps doing every month, with the maths done for you: " . $out['month'] . "\n";
                $aim .= $lastDay
                    ? "  This is the LAST DAY. Before you ask, say in your own words what the team does for this business every month if it stays (use the month line, its numbers exactly) and what that leads to; then say what stops without it (you and the team) and what stays regardless (the website, contacts and calendar); then ask for the decision, once, plainly, and respect the answer."
                    : "  Make the case once per conversation, tied to a result you have just shown or a play you have just offered, not as a notice.";
                if ($price !== '') $aim .= "\n  The price, named once and last, as what keeps this going: {$price}. Never quote another price.";
                $out['aim'] = $aim;
                return $out;
            }

            if ($plan && (bool) $plan->includes_dmm) {
                $balance = $this->balance($wsId);
                $out['state'] = 'paid';
                $out['line'] = sprintf('PLAN (computed now, exact): %s, %s credits a month; %s credits left. No trial running.',
                    $plan->name, number_format((int) $plan->credit_limit), number_format($balance));
                $out['plays'] = $this->plays($wsId, $words, $this->done($wsId, null));
                if ($out['plays']) $out['aim'] = "  The next useful things for this business, offered as something you will do now:\n" . implode('', array_map(fn ($p) => "    - {$p}\n", $out['plays']));
                return $out;
            }

            $endedAt = ! empty($status['has_trial']) && ! empty($status['expired']) && ! empty($status['expires_at'])
                ? \Carbon\Carbon::parse($status['expires_at'])->tz($tz)->format('D j M') : null;
            $out['state'] = 'paused';
            $out['month'] = $offer ? $this->monthLine($offer, $words) : '';   // the pause sells the month too
            $out['line'] = 'PLAN (computed now, exact): ' . ($plan ? $plan->name : 'Free') . ($endedAt ? ", the free trial ended {$endedAt}" : '')
                . '. This plan does not include you or the team: you are paused and nothing runs until a plan that includes you is chosen'
                . ($offer ? sprintf(' (%s at $%d a month with %s credits)', $offer->name, (int) $offer->price, number_format((int) $offer->credit_limit)) : '') . '.';
            return $out;
        } catch (\Throwable $e) {
            return $out;
        }
    }

    /** The block for Sarah's frame: the line, then how to earn the decision. Empty when nothing is known. */
    public function render(int $wsId): string
    {
        $f = $this->facts($wsId);
        if ($f['line'] === '') return '';
        return '  ' . $f['line'] . "\n" . ($f['aim'] !== '' ? $f['aim'] . "\n" : '');
    }

    /** One paragraph for the daily brief's state. */
    public function line(int $wsId): string
    {
        $f = $this->facts($wsId);
        return trim($f['line'] . ($f['aim'] !== '' ? ' ' . preg_replace('/\s+/', ' ', $f['aim']) : ''));
    }

    // ── what has been done, what is missing ─────────────────────────────────────────────────────────────

    private function done(int $wsId, ?string $since): array
    {
        $since = $since ?: now()->subDays(3)->toDateTimeString();
        $n = function (string $t) use ($wsId, $since): int {
            try { return (int) DB::table($t)->where('workspace_id', $wsId)->where('created_at', '>=', $since)->whereNull('deleted_at')->count(); } catch (\Throwable $e) { return 0; }
        };
        $site = null;
        try { $site = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByRaw("status = 'published' DESC")->orderBy('id')->first(['name', 'subdomain', 'custom_domain', 'domain_verified', 'status']); } catch (\Throwable $e) {}
        $social = 0;
        try { $social = (int) DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected')->count(); } catch (\Throwable $e) {}
        $posts = $n('social_posts'); $articles = $n('articles'); $leads = $n('leads');
        $parts = [];
        $parts[] = $site ? ($site->status === 'published' ? 'website "' . $site->name . '" is live' : 'website "' . $site->name . '" built, not published yet') : 'no website yet';
        $parts[] = $posts ? "{$posts} social post" . ($posts === 1 ? '' : 's') . ' drafted' : 'no posts yet';
        $parts[] = $articles ? "{$articles} article" . ($articles === 1 ? '' : 's') . ' written' : 'no articles yet';
        $parts[] = $leads ? "{$leads} new lead" . ($leads === 1 ? '' : 's') : 'no leads yet';
        return ['site' => $site, 'social' => $social, 'posts' => $posts, 'articles' => $articles, 'leads' => $leads, 'line' => implode(', ', $parts) . '.'];
    }

    /** Value plays for THIS business, from what is missing, top one first. The words are the industry's. */
    private function plays(int $wsId, array $w, array $done): array
    {
        $plays = [];
        $site = $done['site'];
        // Owner 2026-09-30 ("why would she say she would build a website?"): the website is Arthur's work, never Sarah's pitch.
        // Signup lands the customer in Arthur's wizard, so a workspace without a published site is the exception; when it
        // happens Sarah hands off to Arthur and sells nothing about it. Her plays are growth: domain, channels, articles, leads.
        if (! $site) {
            $plays[] = "No website yet: that is Arthur's job, not yours and not a pitch. If it comes up, say Arthur builds it (builder.ask_arthur) and move on to what you do.";
        } elseif ($site->status !== 'published') {
            $plays[] = "The website is built but not published: publishing is Arthur's; if it comes up, hand off to Arthur (builder.ask_arthur) and move on.";
        } elseif (empty($site->custom_domain)) {
            $plays[] = "Their brand matters and the site sits on a levelupgrowth.io address: say a domain of their own makes it theirs, and offer to look for available names right now (the domain search is in the app).";
        }
        if ($done['social'] === 0) {
            $plays[] = "No Facebook or Instagram connected: say that once one is connected the team posts every day for them, and offer to plan the first week of posts now so it is ready the moment they connect.";
        } elseif ($done['posts'] === 0) {
            $plays[] = "Channels are connected and nothing is scheduled: offer to plan and draft this week's posts now.";
        }
        if ($done['articles'] === 0) {
            $plays[] = "No articles yet: say that an article a week is how a {$w['noun']} gets found for '{$w['search']}', and offer to write the first one now.";
        }
        if ($site && $done['leads'] === 0) {
            $plays[] = "No leads captured yet: say the website chatbot answers visitors and puts {$w['leads']} straight into their calendar and contacts, and offer to switch it on.";
        }
        return array_slice($plays, 0, 4);
    }

    /** The month, priced from the capability map, on the offered plan's credits. */
    private function monthLine(object $plan, array $w): string
    {
        $cost = function (string $action, int $fallback): int {
            try { $c = (int) app(\App\Core\EngineKernel\CapabilityMapService::class)->getCreditCost($action); return $c > 0 ? $c : $fallback; } catch (\Throwable $e) { return $fallback; }
        };
        $post = $cost('social_create_post', 4); $article = $cost('write_article', 2); $image = $cost('generate_image', 4); $video = $cost('generate_video', 28); $audit = $cost('deep_audit', 3);
        $limit = (int) $plan->credit_limit;
        if ($limit <= 0) return '';
        $posts = 30; $articles = 4; $videos = 2; $images = 12;
        $spend = fn () => $posts * $post + $articles * $article + $images * $image + $videos * $video + $audit;
        while ($spend() > $limit && $videos > 0) $videos--;
        while ($spend() > $limit && $images > 0) $images--;
        while ($spend() > $limit && $posts > 8) $posts -= 2;
        $rest = max(0, $limit - $spend());
        $pieces = ["a post every day for a month ({$posts} posts, " . ($posts * $post) . " credits)", "an article a week ({$articles} articles, " . ($articles * $article) . " credits)"];
        if ($images > 0) $pieces[] = "{$images} images for the posts (" . ($images * $image) . " credits)";
        if ($videos > 0) $pieces[] = "{$videos} short video" . ($videos === 1 ? '' : 's') . " (" . ($videos * $video) . " credits)";
        $pieces[] = "a monthly site audit ({$audit} credits)";
        return sprintf("on %s's %s credits a month the team can do %s, with %d credits left for conversation and checks. For a %s that is %s.",
            $plan->name, number_format($limit), implode(', ', $pieces), $rest, $w['noun'], $w['result']);
    }

    private function industryWords(string $industry): array
    {
        $i = strtolower($industry);
        $map = [
            ['restaurant|cafe|food|bar', ['noun' => 'restaurant', 'search' => 'restaurant near me', 'outcome' => 'bookings', 'leads' => 'bookings', 'result' => 'fuller tables on the quiet nights and a menu people find before they arrive']],
            ['gym|fitness|yoga|studio', ['noun' => 'gym', 'search' => 'gym near me', 'outcome' => 'new members', 'leads' => 'trial sign-ups', 'result' => 'a steady flow of new members instead of a January spike']],
            ['dental|medical|clinic|doctor|health', ['noun' => 'clinic', 'search' => 'dentist near me', 'outcome' => 'appointments', 'leads' => 'appointment requests', 'result' => 'a booked diary and patients who found you on their own']],
            ['real estate|property|estate', ['noun' => 'brokerage', 'search' => 'estate agent near me', 'outcome' => 'enquiries', 'leads' => 'valuation and viewing requests', 'result' => 'more enquiries from people already looking in your area']],
            ['beauty|salon|barber|spa|nail', ['noun' => 'salon', 'search' => 'salon near me', 'outcome' => 'bookings', 'leads' => 'bookings', 'result' => 'a full appointment book and regulars who rebook']],
            ['retail|shop|store|brand|ecommerce', ['noun' => 'shop', 'search' => 'shop near me', 'outcome' => 'orders', 'leads' => 'orders and enquiries', 'result' => 'products people discover and buy without an ad budget']],
            ['consult|agency|professional|legal|account', ['noun' => 'consultancy', 'search' => 'consultant near me', 'outcome' => 'consultations', 'leads' => 'consultation requests', 'result' => 'authority content that brings the right clients to you']],
            ['hotel|rental|hospitality|holiday|guest', ['noun' => 'hotel', 'search' => 'hotel near me', 'outcome' => 'direct bookings', 'leads' => 'direct booking enquiries', 'result' => 'direct bookings you pay no commission on']],
        ];
        foreach ($map as [$re, $w]) if (preg_match('/' . $re . '/', $i)) return $w;
        $noun = $industry !== '' ? strtolower(preg_replace('/\s+or\s+.*$/i', '', $industry)) : 'business';
        return ['noun' => $noun, 'search' => $noun . ' near me', 'outcome' => 'enquiries', 'leads' => 'enquiries', 'result' => 'customers who find you and get in touch on their own'];
    }

    /** The cheapest public plan that includes Sarah, from the plans table (the rule SarahPaused uses). */
    private function offerPlan(): ?object
    {
        try { return DB::table('plans')->where('is_public', 1)->where('includes_dmm', 1)->where('price', '>', 0)->orderBy('price')->first(['name', 'price', 'credit_limit']); } catch (\Throwable $e) { return null; }
    }

    private function balance(int $wsId): int
    {
        try { return (int) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0); } catch (\Throwable $e) { return 0; }
    }
}
