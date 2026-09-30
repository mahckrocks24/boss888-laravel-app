<?php

namespace App\Core\Lifecycle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * LIFECYCLE-1 (Owner 2026-09-28 "build now", phase 1 of the enterprise sign-up plan): the emails that guide a new
 * customer from sign-up to the end of the trial. Every one is also said by Sarah in her chat (she is the point of
 * contact), sent once per workspace, only to the owner, never to test addresses, and honours the lifecycle unsubscribe.
 * Only events after SINCE are mailed, so accounts that expired before this shipped are not suddenly written to.
 */
final class LifecycleEmails
{
    public const SINCE = '2026-09-28 18:00:00';
    public const PURPOSE = 'lifecycle';

    public function owner(int $wsId): ?object
    {
        $uid = DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->orderBy('id')->value('user_id')
            ?: DB::table('workspaces')->where('id', $wsId)->value('created_by');
        return $uid ? DB::table('users')->where('id', $uid)->first(['id', 'name', 'email']) : null;
    }

    public static function deliverable(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
            && ! preg_match('/@(example\.(com|org|net)|[^@]*\.(test|invalid|localhost|example))$/', $email);
    }

    public function optedOut(int $userId): bool
    {
        return DB::table('notification_preferences')->where('user_id', $userId)->where('notification_type', 'lifecycle')->where('email', 0)->exists();
    }

    public function unsubscribeUrl(int $userId): string
    {
        return URL::signedRoute('lifecycle.unsubscribe', ['user' => $userId]);
    }

    private function flag(int $wsId, string $key): ?string
    {
        $s = json_decode((string) (DB::table('workspaces')->where('id', $wsId)->value('settings_json') ?: '{}'), true) ?: [];
        return $s['lifecycle'][$key] ?? null;
    }

    private function setFlag(int $wsId, string $key): void
    {
        $raw = (string) (DB::table('workspaces')->where('id', $wsId)->value('settings_json') ?: '{}');
        $s = json_decode($raw, true) ?: [];
        $s['lifecycle'][$key] = now()->toDateTimeString();
        DB::table('workspaces')->where('id', $wsId)->update(['settings_json' => json_encode($s)]);
    }

    private function first(?object $u): string
    {
        $n = trim(explode(' ', trim((string) ($u->name ?? '')))[0] ?? '');
        return $n !== '' ? $n : 'there';
    }

    private function link(string $path): string
    {
        return EmailLayout::appUrl() . $path;
    }

    /** MAIL-BRAND-2: the AI plans as cards (price, credits); the cheapest marked, or the next one up from the current plan. */
    private function planCards(string $currentSlug = ''): array
    {
        $rows = DB::table('plans')->where('is_public', 1)->where('includes_dmm', 1)->where('price', '>', 0)->orderBy('price')->get(['slug', 'name', 'price', 'credit_limit']);
        if ($currentSlug !== '') { $cur = (float) ($rows->firstWhere('slug', $currentSlug)->price ?? 0); $rows = $rows->filter(fn ($r) => (float) $r->price >= $cur)->values(); }   // never offer a step down
        $mark = $currentSlug === '' ? ($rows->first()->slug ?? '') : ($rows->first(fn ($r) => (float) $r->price > (float) ($rows->firstWhere('slug', $currentSlug)->price ?? 0))->slug ?? '');
        return $rows->map(fn ($r) => [$r->name, '$' . (int) $r->price, number_format((int) $r->credit_limit) . ' credits a month', $r->slug === $mark ? ($currentSlug === '' ? 'Best to start' : 'Next step') : null])->all();
    }

    private function plans(): array
    {
        $rows = DB::table('plans')->where('is_public', 1)->where('includes_dmm', 1)->where('price', '>', 0)->orderBy('price')->get(['name', 'price', 'credit_limit']);
        return $rows->map(fn ($p) => $p->name . ' $' . (int) $p->price . ' a month, ' . number_format((int) $p->credit_limit) . ' credits')->all();
    }

    /** What the team did since the trial started — the customer's own numbers, never invented. */
    /** Preview only (samples for the Owner): demo numbers instead of a real account. Never set in production sends. */
    public static ?array $demo = null;

    private function results(int $wsId, ?string $since): array
    {
        if (self::$demo !== null) return self::$demo;
        $since = $since ?: now()->subDays(3)->toDateTimeString();
        $n = fn ($t, $extra = null) => (int) (function () use ($t, $wsId, $since, $extra) { try { $q = DB::table($t)->where('workspace_id', $wsId)->where('created_at', '>=', $since); if ($extra) $extra($q); return $q->count(); } catch (\Throwable $e) { return 0; } })();
        return [
            'posts' => $n('social_posts', fn ($q) => $q->whereNull('deleted_at')),
            'articles' => $n('articles', fn ($q) => $q->whereNull('deleted_at')),
            'leads' => $n('leads', fn ($q) => $q->whereNull('deleted_at')),
            'site' => (function () use ($wsId) { $w = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'published')->orderBy('id')->first(['name', 'subdomain', 'custom_domain', 'domain_verified']); return $w ? ['name' => (string) $w->name, 'url' => 'https://' . (($w->custom_domain && $w->domain_verified) ? $w->custom_domain : $w->subdomain)] : null; })(),
        ];
    }

    private function send(int $wsId, object $u, string $subject, array $layout, bool $lifecycle = true, string $purpose = self::PURPOSE): bool
    {
        if (! self::deliverable($u->email ?? null)) return false;
        if ($lifecycle && $this->optedOut((int) $u->id)) return false;
        if ($lifecycle) $layout['unsubscribe'] = $this->unsubscribeUrl((int) $u->id);
        try {
            Mail::to($u->email)->queue(new LifecycleMail($purpose, $subject, $layout));
            return true;
        } catch (\Throwable $e) {
            Log::warning('[LIFECYCLE-1] send failed', ['ws' => $wsId, 'subject' => $subject, 'e' => $e->getMessage()]);
            return false;
        }
    }

    private function chat(int $wsId, string $text, string $type): void
    {
        try { app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $text, ['notification_type' => $type]); } catch (\Throwable $e) {}
    }

    // ── 1. Welcome (replaces the plain confirm email) ──────────────────────────────────────────────────
    public static function welcomeLayout(string $name, string $verifyUrl): array
    {
        return [
            'preheader' => 'Confirm your email and meet Sarah, your AI marketing manager.',
            'hero' => 'welcome',
            'eyebrow' => 'Welcome aboard',
            'heading' => 'Welcome to LevelUpGrowth, ' . $name,
            'lead' => "I'm Sarah, your marketing manager. For the next 3 days you have my whole team and 50 credits to try everything.",
            'paragraphs' => ['Here is how we will start:'],
            'steps' => [
                ['Build your website', 'Arthur designs it in your brand, in minutes.'],
                ['Connect Facebook, Instagram and LinkedIn', 'So I can post for you, every day.'],
                ['Ask me for campaign ideas', 'A post every day, planned around your business.'],
            ],
            'callout' => 'Your trial &nbsp;&middot;&nbsp; <strong>3 days</strong> &nbsp;&middot;&nbsp; <strong>50 credits</strong> &nbsp;&middot;&nbsp; the whole AI team &nbsp;&middot;&nbsp; no card needed',
            'button' => ['Confirm my email', $verifyUrl],
            'after' => [
                'Confirming lets your website and posts go live. The button works for 72 hours.',
                'Did not sign up for LevelUpGrowth? You can ignore this email.',
            ],
            'signoff' => 'sarah',
            'reason' => 'You received this email because this address was used to create a LevelUpGrowth account.',
        ];
    }

    // ── 2. Connect your social accounts (2 h after sign-up, again on day 1) ────────────────────────────
    public function socialNudge(int $wsId, int $n): bool
    {
        $key = 'social_' . $n;
        if ($this->flag($wsId, $key)) return false;
        $ws = DB::table('workspaces')->where('id', $wsId)->first(['id', 'created_at']);
        if (! $ws || (string) $ws->created_at < self::SINCE) return false;
        try { if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah($wsId)) return false; } catch (\Throwable $e) { return false; }
        $connected = DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected')->pluck('platform')->map(fn ($p) => strtolower((string) $p))->unique()->values()->all();
        if (count(array_intersect($connected, ['facebook', 'instagram', 'linkedin'])) >= 3) { $this->setFlag($wsId, $key); return false; }
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, $key);
        $missing = array_values(array_diff(['Facebook', 'Instagram', 'LinkedIn'], array_map('ucfirst', $connected)));
        $names = count($missing) > 1 ? implode(', ', array_slice($missing, 0, -1)) . ' and ' . end($missing) : ($missing[0] ?? 'your accounts');
        if ($n === 1) $this->chat($wsId, "Connect {$names} and I'll start posting for you. It takes a minute: open Social, sign in, pick your page.", 'lifecycle_social');
        return $this->send($wsId, $u, "Connect {$names} so I can post for you", [
            'preheader' => 'One minute, and your posts start going out on schedule.',
            'hero' => 'social',
            'eyebrow' => 'Takes one minute',
            'heading' => 'Let me post for you',
            'lead' => "Your posts are ready to go. I can publish them as soon as <strong>{$names}</strong> " . (count($missing) > 1 ? 'are' : 'is') . ' connected.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'steps' => [
                ['Open Social in LevelUpGrowth', ''],
                ['Sign in to ' . $names, 'The usual sign-in window, nothing to install.'],
                ['Pick your page', 'Done. I take it from there.'],
            ],
            'callout' => 'Once connected I also answer comments and messages for your approval, and turn buying comments into leads.',
            'button' => ['Connect my accounts', $this->link('/app/social')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you are trying LevelUpGrowth.',
        ]);
    }

    // ── 3. Your trial ends tomorrow (about 24 h before the end) ────────────────────────────────────────
    public function trialEndingSoon(int $wsId): bool
    {
        if ($this->flag($wsId, 'trial_ending')) return false;
        $w = DB::table('workspaces')->where('id', $wsId)->first(['trial_started_at', 'trial_expires_at']);
        if (! $w || ! $w->trial_expires_at) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, 'trial_ending');
        $r = $this->results($wsId, $w->trial_started_at);
        $did = [];
        if ($r['site']) $did[] = 'Your website is live at <a href="' . htmlspecialchars($r['site']['url'], ENT_QUOTES) . '" style="color:' . EmailLayout::PURPLE . '">' . htmlspecialchars(preg_replace('#^https://#', '', $r['site']['url']), ENT_QUOTES) . '</a>';
        if ($r['posts']) $did[] = $r['posts'] . ' social post' . ($r['posts'] === 1 ? '' : 's') . ' drafted';
        if ($r['articles']) $did[] = $r['articles'] . ' article' . ($r['articles'] === 1 ? '' : 's') . ' written';
        if ($r['leads']) $did[] = $r['leads'] . ' new lead' . ($r['leads'] === 1 ? '' : 's');
        $plans = $this->plans();
        // TRIAL-AWARE-1 (Owner 2026-09-30: "it has to sell value not subscriptions"): what was done, what the team keeps doing every month for this business, then the price once.
        $standing = (function () use ($wsId) { try { return app(\App\Core\Billing\TrialStanding::class)->facts($wsId); } catch (\Throwable $e) { return []; } })();
        $doneLine = preg_replace('/^Done so far in the trial \(exact\): /', '', (function ($aim) { foreach (explode("\n", (string) $aim) as $l) { $l = trim($l); if (str_starts_with($l, 'Done so far')) return $l; } return ''; })($standing['aim'] ?? ''));
        $monthLine = (string) ($standing['month'] ?? '');
        $this->chat($wsId, 'Your trial ends tomorrow.' . ($doneLine ? ' So far: ' . rtrim($doneLine, '.') . '.' : '') . ($monthLine ? ' If the team stays, every month it is ' . ltrim(preg_replace('/^on [^ ]+ [^ ]+\'s [0-9,]+ credits a month the team can do /i', '', $monthLine)) : '') . ($plans ? ' That is ' . $plans[0] . '; choose it under Settings › Plan & billing and nothing stops.' : '') . ' Your website, contacts and calendar stay yours either way.', 'lifecycle_trial_ending');
        return $this->send($wsId, $u, 'Your trial ends tomorrow', [
            'preheader' => 'Here is what we did together, and how to keep going.',
            'hero' => 'ending',
            'eyebrow' => 'Your trial · 1 day left',
            'heading' => 'Your trial ends tomorrow',
            'lead' => 'Here is what we built together so far, and how to keep the team working for you.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'stats' => [[(string) $r['posts'], 'Posts drafted'], [(string) $r['articles'], 'Articles written'], [(string) $r['leads'], 'New leads']],
            'paragraphs' => $r['site'] ? ['Your website is live at <a href="' . htmlspecialchars($r['site']['url'], ENT_QUOTES) . '" style="color:' . EmailLayout::PURPLE . ';font-weight:600">' . htmlspecialchars(preg_replace('#^https://#', '', $r['site']['url']), ENT_QUOTES) . '</a>.'] : [],
            'callout' => 'After tomorrow your website, contacts and calendar keep working on the Free plan. Choose a plan to keep me and the team working.',
            'plans' => $this->planCards(),
            'button' => ['Choose a plan', $this->link('/app/billing')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you are trying LevelUpGrowth.',
        ]);
    }

    // ── 4. Your trial has ended (replaces "How to remove the ads") ─────────────────────────────────────
    public function trialEnded(int $wsId): bool
    {
        if ($this->flag($wsId, 'trial_ended')) return false;
        $w = DB::table('workspaces')->where('id', $wsId)->first(['trial_started_at', 'trial_expires_at']);
        if (! $w) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, 'trial_ended');
        // the Free-site ad notice is folded into this email: mark it sent so the site does not get a second one
        foreach (DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'settings_json']) as $site) {
            $s = json_decode((string) ($site->settings_json ?? '{}'), true) ?: [];
            if (empty($s['ads_notice_sent_at'])) { $s['ads_notice_sent_at'] = now()->toDateTimeString(); DB::table('websites')->where('id', $site->id)->update(['settings_json' => json_encode($s)]); }
        }
        $r = $this->results($wsId, $w->trial_started_at);
        $plans = $this->plans();
        $site = $r['site'] ? '<a href="' . htmlspecialchars($r['site']['url'], ENT_QUOTES) . '" style="color:' . EmailLayout::PURPLE . '">' . htmlspecialchars($r['site']['name'], ENT_QUOTES) . '</a>' : 'Your website';
        $this->chat($wsId, \App\Core\Billing\SarahPaused::text($wsId), 'lifecycle_trial_ended');
        return $this->send($wsId, $u, 'Your trial has ended; your website is still live', [
            'preheader' => 'What stays, what changes, and how to bring the team back.',
            'hero' => 'ended',
            'eyebrow' => 'Trial ended',
            'heading' => 'Your website is still live',
            'lead' => 'Thank you for trying LevelUpGrowth. Your trial has ended; here is what happens now.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'list' => [
                $site . ' stays live, with your contacts and calendar, on the Free plan.',
                'A small LevelUpGrowth ad now shows on your website. That is how the Free plan stays free.',
                'I am paused, with the AI team: no new posts, articles or campaigns until you choose a plan.',
            ],
            'plans' => $this->planCards(),
            'button' => ['Choose a plan', $this->link('/app/billing')],
            'after' => ['Only want your own domain without ads? Starter is  a month.'],
            'signoff' => 'sarah',
            'reason' => 'You received this email because your LevelUpGrowth trial ended.',
        ], false);   // an account status notice: sent even to those who unsubscribed from tips
    }

    // ── LIFECYCLE-2 (Owner 2026-09-28 "continue") ──────────────────────────────────────────────────────

    private function wsTz(int $wsId): string
    {
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        try { new \DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return 'UTC'; }
    }

    private function paidPlan(int $wsId): ?object
    {
        try {
            $p = app(\App\Core\Billing\FeatureGateService::class)->getActivePlanFor($wsId);
            if (! $p || (float) $p->price <= 0) return null;
            if (app(\App\Core\Billing\TrialService::class)->isInTrial($wsId)) return null;
            return $p;
        } catch (\Throwable $e) { return null; }
    }

    private function plural(int $n, string $one): string { return $n . ' ' . $one . ($n === 1 ? '' : 's'); }

    /** Trial day 1, from 9:00 local: what Sarah did since sign-up and what comes today. */
    public function firstDay(int $wsId): bool
    {
        if ($this->flag($wsId, 'first_day')) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, 'first_day');
        $w = DB::table('workspaces')->where('id', $wsId)->first(['trial_started_at']);
        $r = $this->results($wsId, $w->trial_started_at ?? null);
        $done = [];
        if ($r['site']) $done[] = 'Your website is live at <a href="' . htmlspecialchars($r['site']['url'], ENT_QUOTES) . '" style="color:' . EmailLayout::PURPLE . '">' . htmlspecialchars(preg_replace('#^https://#', '', $r['site']['url']), ENT_QUOTES) . '</a>.';
        if ($r['posts']) $done[] = $this->plural($r['posts'], 'social post') . ' drafted for you.';
        if ($r['articles']) $done[] = $this->plural($r['articles'], 'article') . ' written.';
        $waiting = (int) (self::$demo["waiting"] ?? 0); if (self::$demo === null) try { $waiting = (int) DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'draft')->count(); } catch (\Throwable $e) {}
        $this->chat($wsId, 'Good morning. Today I will keep your posts coming' . ($waiting ? ': ' . $this->plural($waiting, 'post') . ' are waiting for your approval in Needs you' : '') . '. Ask me for campaign ideas any time.', 'lifecycle_first_day');
        return $this->send($wsId, $u, 'Your first day: here is what I did', [
            'preheader' => 'Your website, your first posts, and what comes today.',
            'hero' => 'firstday',
            'eyebrow' => 'Day 1',
            'heading' => 'Good morning, ' . $this->first($u),
            'lead' => $done ? 'Here is what the team did since you signed up, and what comes today.' : 'Here is how we get going today.',
            'stats' => [[(string) $r['posts'], 'Posts drafted'], [(string) $r['articles'], 'Articles'], [(string) $waiting, 'Waiting for you']],
            'paragraphs' => $r['site'] ? ['Your website is live at <a href="' . htmlspecialchars($r['site']['url'], ENT_QUOTES) . '" style="color:' . EmailLayout::PURPLE . ';font-weight:600">' . htmlspecialchars(preg_replace('#^https://#', '', $r['site']['url']), ENT_QUOTES) . '</a>.'] : [],
            'steps' => [
                [$waiting ? 'Approve your first posts' : 'Tell me about your business', $waiting ? 'They go out on schedule once you approve.' : 'I plan your first week from it.'],
                ['Connect your social accounts', 'Facebook, Instagram and LinkedIn.'],
                ['Ask me for campaign ideas', 'A post every day, planned for you.'],
            ],
            'button' => [$waiting ? 'Review my posts' : 'Open LevelUpGrowth', $this->link($waiting ? '/app/attention' : '/app/')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you are trying LevelUpGrowth.',
        ]);
    }

    /** 24 h after sign-up with the email still unconfirmed. */
    public function confirmReminder(int $wsId): bool
    {
        if ($this->flag($wsId, 'confirm_reminder')) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $user = \App\Models\User::find($u->id);
        if (! $user || $user->email_verified_at !== null) { $this->setFlag($wsId, 'confirm_reminder'); return false; }
        $this->setFlag($wsId, 'confirm_reminder');
        $url = URL::temporarySignedRoute('verification.verify', now()->addHours(72), ['id' => $user->id, 'hash' => sha1($user->email)]);
        return $this->send($wsId, $u, 'Confirm your email to publish your website', [
            'preheader' => 'One tap, and your website and posts can go live.',
            'hero' => 'welcome',
            'eyebrow' => 'One tap',
            'heading' => 'Please confirm your email',
            'lead' => 'Confirming keeps your account safe and lets your website and posts go live.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'button' => ['Confirm my email', $url],
            'after' => ['The button works for 72 hours. Did not sign up for LevelUpGrowth? You can ignore this email.'],
            'signoff' => 'sarah',
            'reason' => 'You received this email because this address was used to create a LevelUpGrowth account.',
        ], false);
    }

    /** First payment: welcome to the plan (replaces the bare "Subscription activated" email). */
    public function welcomeToPlan(int $wsId): bool
    {
        $p = $this->paidPlan($wsId); if (! $p) return false;
        $key = 'welcome_plan_' . $p->slug;
        if ($this->flag($wsId, $key)) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, $key);
        $has = fn ($f) => ! empty($p->$f);
        $list = [];
        if ((int) $p->credit_limit > 0) $list[] = number_format((int) $p->credit_limit) . ' credits every month for posts, articles, images and video.';
        if ($p->includes_dmm) $list[] = 'Me and the AI team, back at work on your business today.';
        $list[] = 'No ads on your website from now on.';
        $list[] = 'Your own domain: connect one you own, or buy one in the app.';
        if ($has('companion_app')) $list[] = 'The companion app on your phone: approve posts and chat with me on the go.';
        if ((int) $p->max_team_members > 1) $list[] = 'Invite up to ' . ((int) $p->max_team_members >= 999 ? 'unlimited' : (int) $p->max_team_members) . ' team members.';
        if ($p->includes_dmm) $this->chat($wsId, 'Welcome to ' . $p->name . '. I am back at work: your posts and campaigns pick up from where we left off.', 'lifecycle_welcome_plan');
        return $this->send($wsId, $u, 'Welcome to ' . $p->name . ': here is what is new', [
            'preheader' => 'Your plan is active. Here is everything it includes.',
            'hero' => 'plan',
            'eyebrow' => 'Plan active',
            'heading' => 'Welcome to ' . $p->name,
            'lead' => 'Thank you. Your plan is active and I am back at work on your business. Here is what it gives you:',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'list' => $list,
            'button' => ['Open LevelUpGrowth', $this->link('/app/')],
            'after' => ['Your receipt and invoices are always in Settings, Plan and billing.'],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you started a LevelUpGrowth plan.',
        ], false, 'billing');
    }

    /** Paid plan with 80% of this month's credits used (once per month). */
    public function creditsLow(int $wsId): bool
    {
        $p = $this->paidPlan($wsId); if (! $p || (int) $p->credit_limit <= 0) return false;
        $key = 'credits_low_' . now()->format('Ym');
        if ($this->flag($wsId, $key)) return false;
        $pool = (int) (DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
        $bal = (float) (DB::table('credits')->where('workspace_id', $pool)->value('balance') ?? 0);
        if ($bal > 0.2 * (int) $p->credit_limit) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, $key);
        $left = (int) floor($bal);
        $this->chat($wsId, 'Heads up: ' . $left . ' credits left this month. That covers about ' . intdiv($left, 6) . ' more published posts. Your credits refill at renewal, or you can move up a plan.', 'lifecycle_credits_low');
        return $this->send($wsId, $u, 'You have used 80% of this month\'s credits', [
            'preheader' => $left . ' credits left this month.',
            'hero' => 'credits',
            'eyebrow' => 'This month',
            'heading' => $left . ' credits left this month',
            'lead' => 'You have used most of this month\'s credits. Your balance refills at your next renewal.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'stats' => [[(string) $left, 'Credits left'], ['~' . intdiv($left, 6), 'Posts it covers'], [number_format((int) $p->credit_limit), 'Monthly credits']],
            'callout' => 'To keep the same pace until renewal, move up a plan. The change applies at once.',
            'plans' => $this->planCards((string) $p->slug),
            'button' => ['See the plans', $this->link('/app/billing')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you have a LevelUpGrowth plan.',
        ]);
    }

    /** Paid plans: every 30 days, the month in the customer's own numbers. */
    public function monthlyResults(int $wsId): bool
    {
        $p = $this->paidPlan($wsId); if (! $p) return false;
        $start = DB::table('subscriptions')->where('workspace_id', $wsId)->where('status', 'active')->orderByDesc('id')->value('starts_at');
        // existing customers start the 30-day clock at go-live, never an immediate backlog report
        $base = $start ? max(\Carbon\Carbon::parse($start), \Carbon\Carbon::parse(self::SINCE)) : null;
        if (! $base || $base->gt(now()->subDays(30))) return false;
        $last = $this->flag($wsId, 'monthly_last');
        if ($last && \Carbon\Carbon::parse($last)->gt(now()->subDays(30))) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, 'monthly_last');
        $since = now()->subDays(30)->toDateTimeString();
        $q = fn ($t, $extra = null) => (int) (function () use ($t, $wsId, $since, $extra) { try { $x = DB::table($t)->where('workspace_id', $wsId)->where('created_at', '>=', $since); if ($extra) $extra($x); return $x->count(); } catch (\Throwable $e) { return 0; } })();
        $posts = $q('social_posts', fn ($x) => $x->where('status', 'published')); $articles = $q('articles', fn ($x) => $x->where('status', 'published')); $leads = $q('leads', fn ($x) => $x->whereNull('deleted_at'));
        $list = [$this->plural($posts, 'social post') . ' published', $this->plural($articles, 'article') . ' published', $this->plural($leads, 'new lead')];
        $this->chat($wsId, 'Your month: ' . implode(', ', $list) . '. Ask me what we should do more of next month.', 'lifecycle_monthly');
        return $this->send($wsId, $u, 'Your month: ' . $posts . ' posts, ' . $articles . ' articles, ' . $leads . ' leads', [
            'preheader' => 'What the team did for your business in the last 30 days.',
            'hero' => 'monthly',
            'eyebrow' => 'Your month',
            'heading' => 'Your month with LevelUpGrowth',
            'lead' => 'Here is what the team did for your business in the last 30 days.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'stats' => [[(string) $posts, 'Posts published'], [(string) $articles, 'Articles'], [(string) $leads, 'New leads']],
            'callout' => 'Reply in chat with what you want more of next month, and I will plan it.',
            'button' => ['See the details', $this->link('/app/')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you have a LevelUpGrowth plan.',
        ]);
    }

    /** Free after a trial: day 7 (your week) and day 14 (something new). Day 30's offer waits for the Owner. */
    public function winBack(int $wsId, int $day): bool
    {
        $key = 'winback_' . $day;
        if ($this->flag($wsId, $key)) return false;
        if ($this->paidPlan($wsId)) { $this->setFlag($wsId, $key); return false; }
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, $key);
        $r = $this->results($wsId, now()->subDays(7)->toDateTimeString());
        $plans = $this->plans();
        if ($day === 7) {
            return $this->send($wsId, $u, 'What your website did this week', [
                'preheader' => 'Your website is still working for you on the Free plan.',
                'hero' => 'winback',
                'eyebrow' => 'Your week',
                'heading' => 'Your website kept working this week',
                'lead' => ($r['site'] ? htmlspecialchars($r['site']['name'], ENT_QUOTES) . ' is live on the Free plan' : 'Your website is live on the Free plan') . ($r['leads'] ? ', and it brought you ' . $this->plural($r['leads'], 'new lead') . '.' : '.'),
                'greeting' => 'Hi ' . $this->first($u) . ',',
                'paragraphs' => ['When you want more visitors, I can post every day, write articles that get you found on Google, and plan campaigns around your season.'],
                'plans' => $this->planCards(),
                'button' => ['Bring Sarah back', $this->link('/app/billing')],
                'signoff' => 'sarah',
                'reason' => 'You received this email because you tried LevelUpGrowth.',
            ]);
        }
        return $this->send($wsId, $u, 'One thing you have not tried yet', [
            'preheader' => 'Campaigns: a post every day, planned for your business.',
            'hero' => 'winback',
            'eyebrow' => 'Something you have not tried',
            'heading' => 'Campaigns, planned for you',
            'lead' => 'On a plan, I plan whole campaigns for your business. You approve the plan once, and each post before it goes out.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'steps' => [
                ['A theme that fits the season', 'Grounded in your business and your area.'],
                ['A post every day', 'With its banner, in your brand.'],
                ['An article that helps you get found', 'On the searches your customers use.'],
            ],
            'button' => ['See the plans', $this->link('/app/billing')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because you tried LevelUpGrowth.',
        ]);
    }

    /** A social connection broken for 24 hours. */
    public function socialBroken(int $wsId, int $accountId, string $platform): bool
    {
        $key = 'social_broken_' . $accountId;
        if ($this->flag($wsId, $key)) return false;
        $u = $this->owner($wsId); if (! $u) return false;
        $this->setFlag($wsId, $key);
        $name = ucfirst(strtolower($platform));
        return $this->send($wsId, $u, 'Your ' . $name . ' connection needs you', [
            'preheader' => 'Posts are waiting until it is reconnected.',
            'hero' => 'social',
            'eyebrow' => 'Action needed',
            'heading' => 'Reconnect ' . $name,
            'lead' => 'Your ' . $name . ' connection stopped working, usually after a password change or when a permission was removed.',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'callout' => 'Your posts are waiting, not lost. Reconnect and I carry on where we left off.',
            'button' => ['Reconnect ' . $name, $this->link('/app/social')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because ' . $name . ' is connected to your LevelUpGrowth account.',
        ], false, 'notification');
    }

    /** Security notices: always sent, from the security sender, no unsubscribe. */
    public function passwordChanged(int $userId): bool
    {
        $u = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email']); if (! $u) return false;
        return $this->send(0, $u, 'Your LevelUpGrowth password was changed', [
            'preheader' => 'If this was you, you do not need to do anything.',
            'hero' => 'security', 'tone' => 'security', 'eyebrow' => 'Security notice',
            'heading' => 'Your password was changed',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'paragraphs' => ['The password for your LevelUpGrowth account was changed on ' . now()->format('j M Y, H:i') . ' (UTC).', 'If this was you, you do not need to do anything. If it was not, reset your password now and reply to this email.'],
            'button' => ['Reset my password', EmailLayout::appUrl() . '/login/'],
            'reason' => 'You received this email because it is about the security of your LevelUpGrowth account.',
        ], false, 'account_security');
    }

    public function emailChanged(int $userId, string $old, string $new): void
    {
        $u = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email']); if (! $u) return;
        foreach (array_unique([$old, $new]) as $addr) {
            $this->send(0, (object) ['id' => $u->id, 'name' => $u->name, 'email' => $addr], 'Your LevelUpGrowth email address was changed', [
                'preheader' => 'If this was you, you do not need to do anything.',
                'hero' => 'security', 'tone' => 'security', 'eyebrow' => 'Security notice',
                'heading' => 'Your email address was changed',
                'greeting' => 'Hi ' . $this->first($u) . ',',
                'paragraphs' => ['The email address on your LevelUpGrowth account was changed from <strong>' . htmlspecialchars($old, ENT_QUOTES) . '</strong> to <strong>' . htmlspecialchars($new, ENT_QUOTES) . '</strong>.', 'If this was not you, reply to this email straight away and we will lock the account.'],
                'reason' => 'You received this email because it is about the security of your LevelUpGrowth account.',
            ], false, 'account_security');
        }
    }

    /** Every 15 minutes: every time-based lifecycle email that is due. */
    public function tick(): array
    {
        $n = ['social_1' => 0, 'social_2' => 0, 'trial_ending' => 0, 'first_day' => 0, 'confirm' => 0, 'credits_low' => 0, 'monthly' => 0, 'winback_7' => 0, 'winback_14' => 0, 'social_broken' => 0];
        $trialSvc = app(\App\Core\Billing\TrialService::class);
        foreach (DB::table('workspaces')->where('created_at', '>=', self::SINCE)->where('created_at', '<=', now()->subHours(20))->where('created_at', '>=', now()->subDays(3))->get(['id']) as $w) {
            $ws = (int) $w->id;
            if ($trialSvc->isInTrial($ws) && \Carbon\Carbon::now($this->wsTz($ws))->hour >= 9 && $this->firstDay($ws)) $n['first_day']++;
        }
        foreach (DB::table('workspaces')->where('created_at', '>=', self::SINCE)->where('created_at', '<=', now()->subDay())->where('created_at', '>=', now()->subDays(3))->pluck('id') as $ws) {
            if ($this->confirmReminder((int) $ws)) $n['confirm']++;
        }
        foreach (DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.status', 'active')->where('p.price', '>', 0)->pluck('s.workspace_id')->unique() as $ws) {
            if ($this->creditsLow((int) $ws)) $n['credits_low']++;
            if ($this->monthlyResults((int) $ws)) $n['monthly']++;
        }
        foreach (DB::table('workspaces')->whereNotNull('settings_json')->where('settings_json', 'like', '%trial_ended%')->get(['id', 'settings_json']) as $w) {
            $ended = (json_decode((string) $w->settings_json, true) ?: [])['lifecycle']['trial_ended'] ?? null;
            if (! $ended) continue;
            $age = \Carbon\Carbon::parse($ended)->diffInDays(now());
            if ($age >= 7 && $age < 10 && $this->winBack((int) $w->id, 7)) $n['winback_7']++;
            if ($age >= 14 && $age < 17 && $this->winBack((int) $w->id, 14)) $n['winback_14']++;
        }
        try {
            foreach (DB::table('social_accounts')->where('status', 'disconnected')->where('updated_at', '>=', self::SINCE)->where('updated_at', '<=', now()->subDay())->get(['id', 'workspace_id', 'platform']) as $a) {
                if ($this->socialBroken((int) $a->workspace_id, (int) $a->id, (string) $a->platform)) $n['social_broken']++;
            }
        } catch (\Throwable $e) {}
        $n += ['social_1' => 0];
        foreach (DB::table('workspaces')->where('created_at', '>=', self::SINCE)->where('created_at', '<=', now()->subHours(2))->where('created_at', '>=', now()->subDays(3))->pluck('id') as $ws) {
            if ($this->socialNudge((int) $ws, 1)) $n['social_1']++;
        }
        foreach (DB::table('workspaces')->where('created_at', '>=', self::SINCE)->where('created_at', '<=', now()->subDay())->where('created_at', '>=', now()->subDays(3))->pluck('id') as $ws) {
            if ($this->socialNudge((int) $ws, 2)) $n['social_2']++;
        }
        $trial = app(\App\Core\Billing\TrialService::class);
        foreach (DB::table('workspaces')->whereNotNull('trial_expires_at')->where('trial_expires_at', '>', now())->where('trial_expires_at', '<=', now()->addHours(24))->pluck('id') as $ws) {
            if ($trial->isInTrial((int) $ws) && $this->trialEndingSoon((int) $ws)) $n['trial_ending']++;
        }
        return $n;
    }
}
