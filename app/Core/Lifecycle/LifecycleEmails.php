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

    private function plans(): array
    {
        $rows = DB::table('plans')->where('is_public', 1)->where('includes_dmm', 1)->where('price', '>', 0)->orderBy('price')->get(['name', 'price', 'credit_limit']);
        return $rows->map(fn ($p) => $p->name . ' $' . (int) $p->price . ' a month, ' . number_format((int) $p->credit_limit) . ' credits')->all();
    }

    /** What the team did since the trial started — the customer's own numbers, never invented. */
    private function results(int $wsId, ?string $since): array
    {
        $since = $since ?: now()->subDays(3)->toDateTimeString();
        $n = fn ($t, $extra = null) => (int) (function () use ($t, $wsId, $since, $extra) { try { $q = DB::table($t)->where('workspace_id', $wsId)->where('created_at', '>=', $since); if ($extra) $extra($q); return $q->count(); } catch (\Throwable $e) { return 0; } })();
        return [
            'posts' => $n('social_posts', fn ($q) => $q->whereNull('deleted_at')),
            'articles' => $n('articles', fn ($q) => $q->whereNull('deleted_at')),
            'leads' => $n('leads', fn ($q) => $q->whereNull('deleted_at')),
            'site' => (function () use ($wsId) { $w = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'published')->orderBy('id')->first(['name', 'subdomain', 'custom_domain', 'domain_verified']); return $w ? ['name' => (string) $w->name, 'url' => 'https://' . (($w->custom_domain && $w->domain_verified) ? $w->custom_domain : $w->subdomain)] : null; })(),
        ];
    }

    private function send(int $wsId, object $u, string $subject, array $layout, bool $lifecycle = true): bool
    {
        if (! self::deliverable($u->email ?? null)) return false;
        if ($lifecycle && $this->optedOut((int) $u->id)) return false;
        if ($lifecycle) $layout['unsubscribe'] = $this->unsubscribeUrl((int) $u->id);
        try {
            Mail::to($u->email)->queue(new LifecycleMail(self::PURPOSE, $subject, $layout));
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
            'heading' => 'Welcome to LevelUpGrowth, ' . $name,
            'paragraphs' => [
                "I'm Sarah, your marketing manager. For the next 3 days you have the whole team and 50 credits to try everything, no card needed.",
                'First, confirm this is your email address:',
            ],
            'button' => ['Confirm my email', $verifyUrl],
            'list' => [
                '<strong>Build your website</strong> with Arthur, in your brand, in minutes.',
                '<strong>Connect Facebook, Instagram and LinkedIn</strong> so I can post for you.',
                '<strong>Ask me for campaign ideas</strong>: a post every day, planned for you.',
            ],
            'after' => [
                'The button works for 72 hours. If it does not open, copy this address into your browser: <span style="word-break:break-all">' . htmlspecialchars($verifyUrl, ENT_QUOTES) . '</span>',
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
            'heading' => 'Let me post for you',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'paragraphs' => [
                "Your posts are ready to go, but I can only publish them once your accounts are connected. Connect <strong>{$names}</strong>: sign in, pick your page, done.",
                'Once connected I will also answer comments and messages for your approval, and turn buying comments into leads.',
            ],
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
        $this->chat($wsId, 'Your trial ends tomorrow. Your website, contacts and calendar stay yours on the Free plan; to keep me and the team working, choose a plan' . ($plans ? ' (from ' . $plans[0] . ')' : '') . '.', 'lifecycle_trial_ending');
        return $this->send($wsId, $u, 'Your trial ends tomorrow', [
            'preheader' => 'Here is what we did together, and how to keep going.',
            'heading' => 'Your trial ends tomorrow',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'paragraphs' => array_merge(
                [$did ? 'Here is what we did so far:' : 'Your trial ends in about a day.'],
            ),
            'list' => $did ?: ['Your website, your brand and your first plan are ready for us to keep going.'],
            'after' => array_merge(
                ['After tomorrow your website, contacts and calendar keep working on the Free plan. To keep me and the team working, choose a plan:'],
                array_map(fn ($p) => '&bull; ' . htmlspecialchars($p, ENT_QUOTES), $plans)
            ),
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
            'heading' => 'Your trial has ended',
            'greeting' => 'Hi ' . $this->first($u) . ',',
            'paragraphs' => [
                'Thank you for trying LevelUpGrowth. Here is what happens now:',
            ],
            'list' => [
                $site . ' stays live, with your contacts and calendar, on the Free plan.',
                'A small LevelUpGrowth ad now shows on your website. That is how the Free plan stays free.',
                'I am paused, with the AI team: no new posts, articles or campaigns until you choose a plan.',
            ],
            'after' => array_merge(['Choose a plan to remove the ads and bring me back:'], array_map(fn ($p) => '&bull; ' . htmlspecialchars($p, ENT_QUOTES), $plans), ['Your own domain without ads starts from Starter at $19 a month.']),
            'button' => ['Choose a plan', $this->link('/app/billing')],
            'signoff' => 'sarah',
            'reason' => 'You received this email because your LevelUpGrowth trial ended.',
        ], false);   // an account status notice: sent even to those who unsubscribed from tips
    }

    /** Every 15 minutes: social nudges (2 h and day 1) and the day-before trial notice. */
    public function tick(): array
    {
        $n = ['social_1' => 0, 'social_2' => 0, 'trial_ending' => 0];
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
