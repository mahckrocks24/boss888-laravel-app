<?php

namespace App\Core\Partners;

use App\Core\Lifecycle\LifecycleEmails;
use App\Core\Lifecycle\LifecycleMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * RFC-0026 section 11a - the email journeys. Affiliate mail (A*) and the referred customer's mail (C*) in platform
 * branding; admin alerts (M*) as in-app notifications to the platform admin, because the platform's own mailboxes do
 * not receive yet (RISK-0214). Each email is sent once per event (affiliate_email_log), money mail always, tips only
 * to people who have not opted out of lifecycle mail. Never throws: mail must never break a payment or a sign-up.
 */
class PartnerEmails
{
    private const PORTAL = 'https://levelupgrowth.io/affiliates/portal';

    private static function once(string $kind, string $ref, ?int $affId = null): bool
    {
        try { return DB::table('affiliate_email_log')->insertOrIgnore(['affiliate_id' => (int) ($affId ?? 0), 'kind' => $kind, 'ref' => substr($ref, 0, 80), 'created_at' => now()]) === 1; }
        catch (\Throwable $e) { Log::warning('[AFF] email log failed', ['e' => $e->getMessage()]); return false; }
    }

    private static function send(string $email, string $subject, array $layout, string $kind): bool
    {
        if (! LifecycleEmails::deliverable($email)) { Log::info('[AFF] email skipped (address)', ['kind' => $kind]); return false; }
        try { Mail::to($email)->queue(new LifecycleMail('partners', $subject, $layout)); Log::info('[AFF] email', ['kind' => $kind]); return true; }
        catch (\Throwable $e) { Log::warning('[AFF] email failed', ['kind' => $kind, 'e' => $e->getMessage()]); return false; }
    }

    private static function partner(int $affId): ?object
    {
        return DB::table('affiliates as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.id', $affId)->first(['a.*', 'u.email', 'u.name as user_name', 'u.id as uid']);
    }

    private static function first(object $a): string { return trim(explode(' ', (string) ($a->user_name ?: $a->display_name))[0]) ?: 'there'; }
    private static function usd(int $m): string { return '$' . number_format($m / 100, 2); }
    private static function why(): string { return 'You received this email because you are a LevelUpGrowth affiliate.'; }

    public static function safe(callable $fn): void { try { $fn(); } catch (\Throwable $e) { Log::warning('[AFF] affiliate email step failed', ['e' => $e->getMessage()]); } }

    // A1
    public static function applicationReceived(int $affId): bool
    {
        $a = self::partner($affId); if (! $a || ! self::once('A1', (string) $affId, $affId)) return false;
        return self::send($a->email, 'We got your affiliate application', ['preheader' => 'A person reads every application, usually within 2 working days.', 'eyebrow' => 'Affiliate Program',
            'heading' => 'Thanks for applying, ' . self::first($a), 'lead' => 'We read every application by hand, usually within 2 working days, and email you the moment it is decided.',
            'paragraphs' => ['Where you publish: ' . $a->channel_url . '.', 'Once you are approved, you create your own codes in the affiliate portal.'],
            'button' => ['Open the affiliate portal', self::PORTAL], 'signoff' => 'team', 'reason' => 'You received this email because you applied to the LevelUpGrowth Affiliate Program.'], 'A1');
    }

    // A2 / A2r
    public static function decided(int $affId, string $status, ?string $note): bool
    {
        $a = self::partner($affId); if (! $a) return false;
        if ($status === 'approved') {
            if (! self::once('A2', (string) $affId, $affId)) return false;
            $b = PartnerProgram::budgets($a);
            $team = $a->leader_id ? DB::table('affiliates')->where('id', $a->leader_id)->value('display_name') : null;   // RFC-0028
            return self::send($a->email, "You're in: the LevelUpGrowth Affiliate Program", ['preheader' => 'Create your first code and start sharing.', 'eyebrow' => 'Affiliate Program', 'tone' => 'success',
                'heading' => 'Welcome to the Affiliate Program, ' . self::first($a), 'lead' => 'Your next step: create your own code in the affiliate portal.',
                'list' => ['Every business that signs up with your code is yours.', 'Each code shares up to ' . ($b['monthly'] / 100) . '% of their monthly plan for 6 payments, ' . ($b['yearly'] / 100) . '% of a yearly plan, ' . ($b['domain'] / 100) . '% of each new domain registration, once.',
                    'Drag the split when you create the code: more for you, or a bigger discount for them.', 'Make a different code for each video or post to see which one works.'],
                'button' => ['Create your first code', self::PORTAL . '#codes'], 'after' => array_values(array_filter([$team ? 'You are on ' . $team . "'s team. Your Team Leader sees your results and can send you tips and recommended splits." : null, 'Please say clearly that it is an affiliate code wherever you share it. The affiliate terms are at levelupgrowth.io/legal/affiliates/.'])),
                'signoff' => 'team', 'reason' => self::why()], 'A2');
        }
        if ($status === 'rejected') {
            if (! self::once('A2r', (string) $affId, $affId)) return false;
            return self::send($a->email, 'About your affiliate application', ['eyebrow' => 'Affiliate Program', 'heading' => 'Thank you for applying, ' . self::first($a),
                'lead' => 'We could not approve your application this time.', 'paragraphs' => array_values(array_filter([$note ? 'Our note: ' . $note : null, 'If your channel or audience changes, you are welcome to write to us from the contact page.'])),
                'signoff' => 'team', 'reason' => 'You received this email because you applied to the LevelUpGrowth Affiliate Program.'], 'A2r');
        }
        return false;
    }

    // A5: the first business that joins through this affiliate
    public static function firstSignup(int $affId, int $referralId): bool
    {
        $a = self::partner($affId); if (! $a || ! self::once('A5', (string) $affId, $affId)) return false;
        $r = DB::table('referrals as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->leftJoin('vouchers as v', 'v.id', '=', 'r.voucher_id')->where('r.id', $referralId)->first(['u.email', 'r.source', 'r.sub_id', 'v.code']);
        $via = 'with your code ' . ($r->code ?? '');
        return self::send($a->email, 'Someone joined through you', ['preheader' => 'Your first referral is in.', 'eyebrow' => 'Affiliate Program', 'tone' => 'success',
            'heading' => 'Your first referral, ' . self::first($a), 'lead' => PartnerPortal::maskEmail($r->email ?? '') . ' joined LevelUpGrowth ' . $via . '.',
            'paragraphs' => ['You earn from their first payment. Trials earn nothing until they pay.'], 'button' => ['See your referrals', self::PORTAL . '#referrals'], 'signoff' => 'team', 'reason' => self::why()], 'A5');
    }

    // A6 (first commission) / A8 (a reversal)
    public static function commission(int $commissionId): bool
    {
        $c = DB::table('commissions')->where('id', $commissionId)->first(); if (! $c) return false;
        $a = self::partner((int) $c->affiliate_id); if (! $a) return false;
        if ((int) $c->amount_minor < 0) {
            if (! self::once('A8', (string) $commissionId, (int) $a->id)) return false;
            return self::send($a->email, 'A commission was reversed', ['eyebrow' => 'Affiliate earnings', 'heading' => self::usd(-(int) $c->amount_minor) . ' was taken back',
                'lead' => 'A payment that earned you a commission was ' . ($c->note === 'dispute' ? 'disputed' : 'refunded') . ', so the matching part of your commission was reversed.',
                'paragraphs' => ['If it had already been paid, it comes off your next payout. You can see every line in the portal.'], 'button' => ['See your earnings', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::why()], 'A8');
        }
        if (DB::table('commissions')->where('affiliate_id', $a->id)->where('amount_minor', '>', 0)->count() !== 1 || ! self::once('A6', (string) $a->id, (int) $a->id)) return false;
        return self::send($a->email, 'You earned your first commission', ['preheader' => self::usd((int) $c->amount_minor) . ' from your first paying referral.', 'eyebrow' => 'Affiliate earnings', 'tone' => 'success',
            'heading' => self::usd((int) $c->amount_minor) . ', your first commission', 'lead' => 'A business you referred just paid. Your commission is held for ' . PartnerProgram::HOLD_DAYS . ' days in case of a refund.',
            'stats' => [[self::usd((int) $c->amount_minor), 'earned'], [date('j M', strtotime((string) $c->payable_at)), 'ready to pay']],
            'paragraphs' => ['Ready balances are paid on the 15th of each month once they reach ' . self::usd(PartnerProgram::MIN_PAYOUT_MINOR) . '.'],
            'button' => ['See your earnings', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::why()], 'A6');
    }

    // C1: a business that joined with a discount code is told what it gets
    public static function customerCode(int $referralId): bool
    {
        $r = DB::table('referrals as r')->join('users as u', 'u.id', '=', 'r.user_id')->leftJoin('vouchers as v', 'v.id', '=', 'r.voucher_id')->leftJoin('affiliates as a', 'a.id', '=', 'r.affiliate_id')
            ->where('r.id', $referralId)->first(['u.email', 'u.name', 'v.code', 'v.discount_monthly_bps', 'v.discount_yearly_bps', 'v.discount_domain_bps', 'v.applies_plans', 'v.applies_domains', 'v.months', 'a.display_name']);
        if (! $r || ! $r->code || ((int) $r->discount_monthly_bps + (int) $r->discount_yearly_bps + (int) $r->discount_domain_bps) === 0 || ! self::once('C1', (string) $referralId)) return false;
        $line = PartnerProgram::describe((object) ['discount_monthly_bps' => $r->discount_monthly_bps, 'discount_yearly_bps' => $r->discount_yearly_bps, 'discount_domain_bps' => $r->discount_domain_bps, 'applies_plans' => $r->applies_plans, 'applies_domains' => $r->applies_domains, 'months' => $r->months]);
        return self::send($r->email, 'Your code ' . $r->code . ' is on your account', ['eyebrow' => 'Your discount', 'tone' => 'success', 'heading' => 'Your code ' . $r->code . ' is saved',
            'lead' => $line, 'paragraphs' => ['It applies on its own when you choose a plan or buy a domain. Nothing else to do.' . ($r->display_name ? ' Thanks to ' . $r->display_name . ' for the introduction.' : '')],
            'button' => ['See plans', 'https://levelupgrowth.io/app/billing'], 'signoff' => 'team', 'reason' => 'You received this email because you signed up for LevelUpGrowth with a code.'], 'C1');
    }

    /** Daily (affiliates:tick): codes ending (A14), the last discounted payment (C3), the Monday summary (A7), a quiet month (A13). */
    public static function tick(): array
    {
        $n = ['A14' => 0, 'C3' => 0, 'A7' => 0, 'A13' => 0];
        foreach (DB::table('vouchers')->whereNotNull('affiliate_id')->where('status', 'active')->where(fn ($q) => $q->whereBetween('ends_at', [now(), now()->addDays(7)])->orWhereRaw('max_redemptions IS NOT NULL AND redemptions >= max_redemptions'))->get() as $v) {
            $a = self::partner((int) $v->affiliate_id); if (! $a || ! self::once('A14', $v->id . ':' . ($v->ends_at ?: 'max'), (int) $a->id)) continue;
            $full = $v->max_redemptions !== null && (int) $v->redemptions >= (int) $v->max_redemptions;
            $n['A14'] += (int) self::send($a->email, 'Your code ' . $v->code . ($full ? ' has been fully used' : ' ends soon'), ['eyebrow' => 'Your codes', 'heading' => $v->code . ($full ? ' reached its limit' : ' ends on ' . date('j M', strtotime((string) $v->ends_at))),
                'lead' => $full ? 'It has been used ' . $v->redemptions . ' times, its limit.' : 'After that it stops working for new customers. Everyone who already joined keeps their terms.',
                'paragraphs' => ['Extend it, or make a new one, in the portal.'], 'button' => ['Open your codes', self::PORTAL . '#codes'], 'signoff' => 'team', 'reason' => self::why()], 'A14');
        }
        foreach (DB::table('referrals as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.status', 'paying')->where('r.discount_monthly_bps', '>', 0)->whereRaw('r.cycles_paid = r.months - 1')->get(['r.id', 'r.discount_monthly_bps', 'r.months', 'u.email', 'u.id as uid']) as $r) {
            if (app(LifecycleEmails::class)->optedOut((int) $r->uid) || ! self::once('C3', (string) $r->id)) continue;
            $n['C3'] += (int) self::send($r->email, 'Your affiliate discount ends after your next payment', ['eyebrow' => 'Your plan', 'heading' => 'One discounted payment left',
                'lead' => 'Your ' . ((int) $r->discount_monthly_bps / 100) . '% affiliate discount covers your next monthly payment, the last of ' . (int) $r->months . '. After that your plan renews at its normal price.',
                'paragraphs' => ['Nothing changes in your account.'], 'button' => ['Open Billing', 'https://levelupgrowth.io/app/billing'], 'signoff' => 'team',
                'unsubscribe' => app(LifecycleEmails::class)->unsubscribeUrl((int) $r->uid), 'reason' => 'You received this email because you joined LevelUpGrowth with an affiliate code.'], 'C3');
        }
        foreach (DB::table('affiliates as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.status', 'approved')->get(['a.id', 'a.approved_at', 'u.id as uid']) as $a) {
            if (app(LifecycleEmails::class)->optedOut((int) $a->uid)) continue;
            $signups7 = DB::table('referrals')->where('affiliate_id', $a->id)->where('signed_up_at', '>=', now()->subDays(7))->count();
            $paying7 = DB::table('referrals')->where('affiliate_id', $a->id)->where('locked_at', '>=', now()->subDays(7))->count();
            $earned7 = (int) DB::table('commissions')->where('affiliate_id', $a->id)->where('created_at', '>=', now()->subDays(7))->sum('amount_minor');
            if (now()->isMonday() && ($signups7 || $earned7) && self::once('A7', now()->format('o-W'), (int) $a->id)) {
                $p = self::partner((int) $a->id);
                $n['A7'] += (int) self::send($p->email, 'Your affiliate week', ['eyebrow' => 'Affiliate Program', 'heading' => 'Your week, ' . self::first($p),
                    'stats' => [[(string) $signups7, 'sign-ups'], [(string) $paying7, 'first payments'], [self::usd($earned7), 'earned']], 'button' => ['Open your portal', self::PORTAL],
                    'signoff' => 'team', 'unsubscribe' => app(LifecycleEmails::class)->unsubscribeUrl((int) $a->uid), 'reason' => self::why()], 'A7');
            }
            // a quiet month: no sign-up with any of their codes for 30 days
            $quiet = ! DB::table('referrals')->where('affiliate_id', $a->id)->where('signed_up_at', '>=', now()->subDays(30))->exists();
            if ($quiet && $a->approved_at && now()->diffInDays($a->approved_at, true) >= 30 && self::once('A13', now()->format('Y-m'), (int) $a->id)) {
                $p = self::partner((int) $a->id);
                $n['A13'] += (int) self::send($p->email, 'Ideas for your next post', ['eyebrow' => 'Affiliate Program', 'heading' => 'A month without sign-ups',
                    'lead' => 'Affiliates who do best put their code in the first line of the description, say what it saves, and make a different code for each video.',
                    'list' => ['A code with 5% off: your audience saves and you still keep most of the share.', 'A code per video or post, so you can see which one works.', 'Say clearly that it is an affiliate code.'],
                    'button' => ['Open your portal', self::PORTAL], 'signoff' => 'team', 'unsubscribe' => app(LifecycleEmails::class)->unsubscribeUrl((int) $a->uid), 'reason' => self::why()], 'A13');
            }
        }
        return $n + self::payoutTick();
    }

    // A9: the money is on its way
    public static function payoutSent(int $payoutId): bool
    {
        $p = DB::table('payouts')->where('id', $payoutId)->first(); if (! $p) return false;
        $a = self::partner((int) $p->affiliate_id); if (! $a || ! self::once('A9', (string) $payoutId, (int) $a->id)) return false;
        $to = $p->method === 'stripe' ? 'your bank account' : 'your ' . (PartnerPayouts::METHODS[$p->method] ?? $p->method) . ' account (' . $a->payout_email . ')';
        return self::send($a->email, 'Your affiliate payout of ' . self::usd((int) $p->amount_minor) . ' is on its way', ['eyebrow' => 'Affiliate payout', 'tone' => 'success',
            'heading' => self::usd((int) $p->amount_minor) . ' sent', 'lead' => 'Your commissions for ' . $p->period . ' were paid to ' . $to . '.',
            'stats' => [[self::usd((int) $p->amount_minor), 'paid'], [(string) count(json_decode((string) $p->commission_ids, true) ?: []), 'commissions']],
            'paragraphs' => array_values(array_filter([$p->method === 'stripe' ? 'Your bank usually shows it within a few working days.' : null, 'Every line is in your portal under Earnings.'])),
            'button' => ['See your earnings', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::why()], 'A9');
    }

    // A11: a bank payout failed
    public static function payoutProblem(int $payoutId): bool
    {
        $p = DB::table('payouts')->where('id', $payoutId)->first(); if (! $p) return false;
        $a = self::partner((int) $p->affiliate_id); if (! $a || ! self::once('A11', (string) $payoutId, (int) $a->id)) return false;
        self::tellAdmin('Affiliate payout failed', $a->display_name . ': ' . self::usd((int) $p->amount_minor) . ' for ' . $p->period . '. ' . ($p->note ?? ''));   // M4
        return self::send($a->email, 'We could not send your affiliate payout', ['eyebrow' => 'Affiliate payout', 'heading' => 'Your payout did not go through',
            'lead' => 'We tried to send ' . self::usd((int) $p->amount_minor) . ' and it was refused. The money stays in your balance and goes out in the next run.',
            'paragraphs' => ['Open the portal and check your payout details.'], 'button' => ['Check payout details', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::why()], 'A11');
    }

    /** Payout-side daily mail: A3 set-up reminder; on the 14th M3 to admin; on the 15th A10 roll-over. */
    public static function payoutTick(): array
    {
        $n = ['A3' => 0, 'A10' => 0, 'M3' => 0];
        foreach (DB::table('affiliates')->where('status', 'approved')->whereNull('payout_method')->where('approved_at', '<=', now()->subDays(3))->pluck('id') as $id) {
            $a = self::partner((int) $id); if (! $a || ! self::once('A3', (string) $id, (int) $id)) continue;
            $n['A3'] += (int) self::send($a->email, 'Set up how you get paid', ['eyebrow' => 'Affiliate Program', 'heading' => 'Where should we send your money?',
                'lead' => 'Choose how you get paid so your commissions can go out on the 15th of each month.', 'button' => ['Set up payouts', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::why()], 'A3');
        }
        if ((int) now()->format('j') === 14 && self::once('M3', now()->format('Y-m'))) {
            $ready = array_filter(PartnerPayouts::preview(), fn ($p) => $p['eligible']);
            if ($ready) { self::tellAdmin('Affiliate payouts ready', count($ready) . ' affiliates, ' . self::usd(array_sum(array_column($ready, 'amount_minor'))) . '. Review and pay in Admin, Affiliates, Payouts.'); $n['M3'] = 1; }
        }
        if ((int) now()->format('j') === 15) {
            foreach (PartnerPayouts::preview() as $p) {
                if ($p['eligible'] || $p['amount_minor'] <= 0 || $p['amount_minor'] >= PartnerProgram::MIN_PAYOUT_MINOR || ! self::once('A10', now()->format('Y-m'), $p['affiliate_id'])) continue;
                $a = self::partner($p['affiliate_id']); if (! $a) continue;
                $n['A10'] += (int) self::send($a->email, 'Your affiliate balance rolls over', ['eyebrow' => 'Affiliate payout', 'heading' => self::usd($p['amount_minor']) . ' ready, carried to next month',
                    'lead' => 'Payouts go out once your ready balance reaches ' . self::usd(PartnerProgram::MIN_PAYOUT_MINOR) . '. Nothing is lost: it is added to next month.', 'button' => ['See your earnings', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::why()], 'A10');
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ RFC-0028 Team Leader mail

    private static function whyLeader(): string { return 'You received this email because you are a LevelUpGrowth Team Leader.'; }
    private static function day(?string $ts): string { return $ts ? date('j M Y', strtotime($ts)) : ''; }

    // L1: welcome
    public static function leaderWelcome(int $affId, string $subId): bool
    {
        $a = self::partner($affId); if (! $a || ! self::once('L1', $subId, $affId)) return false;
        return self::send($a->email, "You're a Team Leader", ['preheader' => 'Your team code is ' . $a->team_code . '.', 'eyebrow' => 'Team Leader', 'tone' => 'success',
            'heading' => 'Welcome to Team Leader, ' . self::first($a), 'lead' => 'Your own customers now carry 25% of their monthly plan for 6 payments, 20% of a yearly plan and 15% of new domains, to split with the bar.',
            'list' => ['Recruit affiliates with your team code: ' . $a->team_code . '.', 'You get 5% of every payment their customers make, on top of what they earn.', 'You see your whole team, their codes and their referrals in the Team area of your portal.',
                'Your $99 a month is taken from your commissions when you have any, otherwise from your card.'],
            'button' => ['Open your Team area', self::PORTAL . '#team'], 'after' => ['Every recruit is approved by LevelUpGrowth before they join your team. You earn only from customers paying, never for recruiting someone.'],
            'signoff' => 'team', 'reason' => self::whyLeader()], 'L1');
    }

    // L2: a voluntary cancel (D21)
    public static function leaderCancelling(int $affId): bool
    {
        $a = self::partner($affId); if (! $a || ! self::once('L2', (string) ($a->leader_until ?: now()->toDateString()), $affId)) return false;
        return self::send($a->email, 'Your Team Leader plan ends on ' . self::day($a->leader_until), ['eyebrow' => 'Team Leader', 'heading' => 'Team Leader ends on ' . self::day($a->leader_until),
            'lead' => 'You cancelled. Until then everything stays as it is.', 'list' => ['After that you are a regular affiliate again: 20% / 15% / 10% to split.', 'Your team dissolves: your recruits become regular affiliates and the 5% stops.', 'Money you already earned stays yours.'],
            'paragraphs' => ['Changed your mind? Keep Team Leader in the Team area before that date.'], 'button' => ['Keep Team Leader', self::PORTAL . '#team'], 'signoff' => 'team', 'reason' => self::whyLeader()], 'L2');
    }

    // L3: a recruit was approved
    public static function recruitJoined(int $leaderId, int $recruitId): bool
    {
        $l = self::partner($leaderId); $r = DB::table('affiliates')->where('id', $recruitId)->first(['display_name']);
        if (! $l || ! $r || ! self::once('L3', (string) $recruitId, $leaderId)) return false;
        return self::send($l->email, $r->display_name . ' joined your team', ['eyebrow' => 'Team Leader', 'tone' => 'success', 'heading' => $r->display_name . ' is on your team',
            'lead' => 'We approved their application. From now on you get 5% of every payment their customers make.', 'paragraphs' => ['Send them your recommended splits and a welcome announcement from the Team area.'],
            'button' => ['Open your Team area', self::PORTAL . '#team'], 'signoff' => 'team', 'reason' => self::whyLeader()], 'L3');
    }

    // L4: the first 5% from the team
    public static function firstTeamEarning(int $leaderId, int $commissionId): bool
    {
        $c = DB::table('commissions')->where('id', $commissionId)->first(); $l = self::partner($leaderId);
        if (! $c || ! $l || DB::table('commissions')->where('affiliate_id', $leaderId)->whereNotNull('override_of')->count() !== 1 || ! self::once('L4', (string) $leaderId, $leaderId)) return false;
        return self::send($l->email, 'Your team just earned you ' . self::usd((int) $c->amount_minor), ['eyebrow' => 'Team Leader', 'tone' => 'success', 'heading' => 'Your first team earnings',
            'lead' => 'A business one of your recruits referred just paid. Your 5% is ' . self::usd((int) $c->amount_minor) . ', held ' . PartnerProgram::HOLD_DAYS . ' days in case of a refund.',
            'button' => ['See your team earnings', self::PORTAL . '#team'], 'signoff' => 'team', 'reason' => self::whyLeader()], 'L4');
    }

    // F1: the fee was taken from commissions
    public static function feeFromCommissions(int $affId, int $amount, string $period): bool
    {
        $a = self::partner($affId); if (! $a || ! self::once('F1', $period, $affId)) return false;
        $card = TeamLeader::FEE_MINOR - $amount;
        return self::send($a->email, 'Your Team Leader fee comes from your commissions', ['eyebrow' => 'Team Leader', 'heading' => self::usd($amount) . ' from your commissions',
            'lead' => 'Your next month of Team Leader (' . self::usd(TeamLeader::FEE_MINOR) . ') is paid ' . ($card > 0 ? self::usd($amount) . ' from your commissions and ' . self::usd($card) . ' from your card.' : 'in full from your commissions. Your card is not charged.'),
            'paragraphs' => ['It shows as a line in your earnings and is netted from your next payout.'], 'button' => ['See your earnings', self::PORTAL . '#money'], 'signoff' => 'team', 'reason' => self::whyLeader()], 'F1');
    }

    // G1: the payment failed - 30 days
    public static function graceStarted(int $affId): bool
    {
        $a = self::partner($affId); if (! $a || ! $a->leader_grace_until || ! self::once('G1', (string) $a->leader_grace_until, $affId)) return false;
        return self::send($a->email, 'Your Team Leader payment did not go through', ['eyebrow' => 'Team Leader', 'heading' => 'Please pay by ' . self::day($a->leader_grace_until),
            'lead' => 'We could not take your ' . self::usd(TeamLeader::FEE_MINOR) . ' Team Leader fee. You have until ' . self::day($a->leader_grace_until) . ' to pay it, or to earn it: commissions you earn before then pay it automatically.',
            'paragraphs' => ['If it is still unpaid on that date, you become a regular affiliate and your team dissolves. Money you already earned stays yours.'],
            'button' => ['Pay now', self::PORTAL . '#team'], 'signoff' => 'team', 'reason' => self::whyLeader()], 'G1');
    }

    // G2 / G3: 7 days and 1 day left
    public static function graceReminder(int $affId, int $daysLeft): bool
    {
        $a = self::partner($affId); $k = $daysLeft <= 1 ? 'G3' : 'G2';
        if (! $a || ! $a->leader_grace_until || ! self::once($k, (string) $a->leader_grace_until, $affId)) return false;
        return self::send($a->email, $daysLeft <= 1 ? 'Last day to keep your team' : '7 days left to keep your team', ['eyebrow' => 'Team Leader', 'heading' => ($daysLeft <= 1 ? '1 day' : '7 days') . ' left',
            'lead' => 'Your Team Leader fee is still unpaid. On ' . self::day($a->leader_grace_until) . ' you become a regular affiliate and your team dissolves.',
            'button' => ['Pay now', self::PORTAL . '#team'], 'signoff' => 'team', 'reason' => self::whyLeader()], $k);
    }

    // G4: back to a regular affiliate, team dissolved
    public static function teamDissolved(int $affId, string $why): bool
    {
        $a = self::partner($affId); if (! $a || ! self::once('G4', now()->toDateString(), $affId)) return false;
        $lead = ['unpaid' => 'Your Team Leader fee was not paid within 30 days.', 'cancelled' => 'Your Team Leader plan has ended, as you chose.'][$why] ?? 'Your Team Leader plan has ended.';
        return self::send($a->email, 'You are a regular affiliate again', ['eyebrow' => 'Affiliate Program', 'heading' => 'Your team has dissolved', 'lead' => $lead,
            'list' => ['Your codes keep working, with 20% / 15% / 10% to split.', 'Your recruits are regular affiliates now, and the 5% has stopped.', 'Money you already earned stays yours.'],
            'paragraphs' => ['You can become a Team Leader again at any time. A new team starts empty.'], 'button' => ['Open your portal', self::PORTAL], 'signoff' => 'team', 'reason' => self::why()], 'G4');
    }

    // G5: to each recruit of a dissolved team
    public static function teamEndedForRecruit(int $recruitId, string $leaderName): bool
    {
        $a = self::partner($recruitId); if (! $a || $a->status !== 'approved' || ! self::once('G5', now()->toDateString(), $recruitId)) return false;
        return self::send($a->email, 'Your team has ended', ['eyebrow' => 'Affiliate Program', 'heading' => 'Nothing changes for your earnings', 'lead' => $leaderName . "'s team in the Affiliate Program has ended.",
            'paragraphs' => ['You stay a LevelUpGrowth affiliate with the same codes, the same 20% / 15% / 10% and everything you earned.'], 'button' => ['Open your portal', self::PORTAL], 'signoff' => 'team', 'reason' => self::why()], 'G5');
    }

    // R1: a leader removed a recruit
    public static function removedFromTeam(int $recruitId, string $leaderName): bool
    {
        $a = self::partner($recruitId); if (! $a || ! self::once('R1', now()->format('Y-m-d H'), $recruitId)) return false;
        return self::send($a->email, 'You are no longer on ' . $leaderName . "'s team", ['eyebrow' => 'Affiliate Program', 'heading' => 'Your team has changed', 'lead' => $leaderName . ' removed you from their team.',
            'paragraphs' => ['You stay a LevelUpGrowth affiliate with the same codes, the same 20% / 15% / 10% and everything you earned.'], 'button' => ['Open your portal', self::PORTAL], 'signoff' => 'team', 'reason' => self::why()], 'R1');
    }

    // I1: an invitation to join a team (the code, never a tracking link)
    public static function teamInvite(int $inviteId): bool
    {
        $i = DB::table('affiliate_team_invites')->where('id', $inviteId)->first(); if (! $i) return false;
        $l = DB::table('affiliates')->where('id', $i->leader_id)->first(['display_name', 'team_code']); if (! $l || ! $l->team_code || ! self::once('I1', $inviteId . ':' . $i->sent_count, (int) $i->leader_id)) return false;
        return self::send($i->email, $l->display_name . ' invites you to their affiliate team', ['preheader' => 'Team code ' . $l->team_code, 'eyebrow' => 'LevelUpGrowth Affiliate Program',
            'heading' => 'Join ' . $l->display_name . "'s team", 'lead' => 'LevelUpGrowth gives small businesses an AI team for their website and marketing. Affiliates share their own code and earn on every business that joins with it.',
            'list' => ['Up to 20% of their monthly plan for their first 6 payments, 15% of a yearly plan, 10% of new domains: you split it between their discount and your commission.', 'Apply with the team code ' . $l->team_code . ' to join ' . $l->display_name . "'s team.", 'We review every application, usually within 2 working days.'],
            'button' => ['Apply with code ' . $l->team_code, self::PORTAL . '?apply=1&team=' . rawurlencode($l->team_code)], 'signoff' => 'team',
            'reason' => 'You received this email because ' . $l->display_name . ' invited you. We use your address only for this invitation.'], 'I1');
    }

    // T1: a Team Leader's announcement (D13)
    public static function teamMessage(int $messageId, int $recruitId): bool
    {
        $m = DB::table('affiliate_team_messages')->where('id', $messageId)->first(); $a = self::partner($recruitId);
        if (! $m || ! $a || ! self::once('T1', $messageId . ':' . $recruitId, $recruitId)) return false;
        if (app(LifecycleEmails::class)->optedOut((int) $a->uid)) return false;
        $l = DB::table('affiliates')->where('id', $m->leader_id)->value('display_name');
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', (string) $m->body))));
        return self::send($a->email, $l . ': ' . $m->subject, ['eyebrow' => 'From your Team Leader', 'heading' => $m->subject, 'paragraphs' => array_slice($paras, 0, 12),
            'after' => ['Sent by LevelUpGrowth on behalf of ' . $l . ', your Team Leader. It is also in your portal.'], 'button' => ['Open your portal', self::PORTAL], 'signoff' => 'team',
            'unsubscribe' => app(LifecycleEmails::class)->unsubscribeUrl((int) $a->uid), 'reason' => 'You received this email because you are on ' . $l . "'s team in the LevelUpGrowth Affiliate Program."], 'T1');
    }

    /** M1 / M2: the platform admin is told in the app. */
    public static function tellAdmin(string $title, string $body): void
    {
        try { app(\App\Core\Notifications\NotificationService::class)->dispatch(type: \App\Core\Notifications\NotificationTypes::SYSTEM_USER_SIGNUP, userId: 1, title: $title, body: $body, severity: 'info'); } catch (\Throwable $e) {}
    }
}
