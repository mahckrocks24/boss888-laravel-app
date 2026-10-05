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
            return self::send($a->email, "You're in: the LevelUpGrowth Affiliate Program", ['preheader' => 'Create your first code and start sharing.', 'eyebrow' => 'Affiliate Program', 'tone' => 'success',
                'heading' => 'Welcome to the Affiliate Program, ' . self::first($a), 'lead' => 'Your next step: create your own code in the affiliate portal.',
                'list' => ['Every business that signs up with your code is yours.', 'Each code shares up to ' . ($b['monthly'] / 100) . '% of their monthly plan for 6 payments, ' . ($b['yearly'] / 100) . '% of a yearly plan, ' . ($b['domain'] / 100) . '% of each new domain registration, once.',
                    'Drag the split when you create the code: more for you, or a bigger discount for them.', 'Make a different code for each video or post to see which one works.'],
                'button' => ['Create your first code', self::PORTAL . '#codes'], 'after' => ['Please say clearly that it is an affiliate code wherever you share it. The affiliate terms are at levelupgrowth.io/legal/affiliates/.'],
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

    /** M1 / M2: the platform admin is told in the app. */
    public static function tellAdmin(string $title, string $body): void
    {
        try { app(\App\Core\Notifications\NotificationService::class)->dispatch(type: \App\Core\Notifications\NotificationTypes::SYSTEM_USER_SIGNUP, userId: 1, title: $title, body: $body, severity: 'info'); } catch (\Throwable $e) {}
    }
}
