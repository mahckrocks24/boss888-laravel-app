<?php

namespace App\Core\Partners;

use Illuminate\Support\Facades\DB;

/**
 * RFC-0028 - what a Team Leader sees and manages about their team (Owner: "a different interface with additional options to
 * manage and monitor its affiliates and their referrals"). A leader sees their recruits' results the way a recruit sees their own
 * (D12: masked email, plan, payments, status, earned) and never a business's name, content or website. A leader cannot change a
 * recruit's codes, payout details or money, and never pays recruits. Approval of every applicant stays with LevelUpGrowth (D11).
 */
class TeamPortal
{
    public const MAX_PRESETS = 5;
    public const MAX_MESSAGES_A_MONTH = 4;
    public const MAX_INVITES_A_DAY = 25;

    private static function members(object $leader)
    {
        return DB::table('affiliates')->where('leader_id', $leader->id)->orderByDesc('id')->limit(1000)->get(['id', 'display_name', 'status', 'created_at', 'approved_at', 'leader_recommendation', 'leader_rec_note', 'channel_url', 'audience']);
    }

    /**
     * D22 (Owner: "records stays for all but there must be a badge saying it has upgraded"): members who left this leader's
     * current team - upgraded, removed or moved - stay in the records, frozen at the day they left (left_at, left_reason).
     */
    private static function formers(object $leader)
    {
        $since = $leader->leader_since ? \Carbon\Carbon::parse($leader->leader_since)->subSeconds(5) : null;
        $current = DB::table('affiliates')->where('leader_id', $leader->id)->pluck('id')->all();
        $rows = DB::table('affiliate_team_history')->where('leader_id', $leader->id)->whereNull('returned_at')->when($since, fn ($q) => $q->where('left_at', '>=', $since))->orderByDesc('id')->get()->unique('recruit_id');
        return $rows->reject(fn ($h) => in_array($h->recruit_id, $current, true))->map(function ($h) {
            $m = DB::table('affiliates')->where('id', $h->recruit_id)->first(['id', 'display_name', 'status', 'created_at', 'approved_at', 'leader_recommendation', 'leader_rec_note', 'channel_url', 'audience']);
            if (! $m) return null;
            $m->left_reason = $h->reason; $m->left_at = $h->left_at;
            return $m;
        })->filter()->values();
    }

    /** A former member's referrals stop at the day they left: the leader never sees their later work. */
    private static function upTo(array $refs, ?object $m): array
    {
        if (! $m || empty($m->left_at)) return $refs;
        return array_values(array_filter($refs, fn ($r) => (string) $r['joined'] <= (string) $m->left_at));
    }

    /** The leader's 5% rows (and their reversals), optionally for one recruit. */
    private static function teamRows(int $leaderId, ?int $recruitId = null)
    {
        $q = DB::table('commissions as o')->where('o.affiliate_id', $leaderId)->whereNotNull('o.override_of');
        if ($recruitId) $q->join('commissions as c', 'c.id', '=', 'o.override_of')->where('c.affiliate_id', $recruitId);
        $ids = $q->pluck('o.id');
        return DB::table('commissions')->where(fn ($w) => $w->whereIn('id', $ids)->orWhereIn('reverses_id', $ids));
    }

    public static function overview(object $leader, int $days): array
    {
        $from = $days > 0 ? now()->subDays($days) : null;
        $ms = self::members($leader); $ids = $ms->pluck('id')->all();
        $since = fn ($q, $col) => $from ? $q->where($col, '>=', $from) : $q;
        $sales = (int) $since(DB::table('commissions')->whereIn('affiliate_id', $ids ?: [0])->whereNull('override_of')->whereIn('source_type', ['invoice', 'domain_order'])->where('amount_minor', '>', 0), 'created_at')->sum('base_minor');
        $team = self::teamRows((int) $leader->id);
        $money = [];
        foreach (['pending', 'payable', 'paid'] as $s) $money[$s] = (int) (clone $team)->where('status', $s)->sum('amount_minor');
        // 12 months: what the leader earned from their own customers and from the team, month by month
        $chart = [];
        for ($i = 11; $i >= 0; $i--) {
            $m0 = now()->startOfMonth()->subMonths($i); $m1 = (clone $m0)->addMonth();
            $teamM = (int) (clone $team)->where('created_at', '>=', $m0)->where('created_at', '<', $m1)->sum('amount_minor');
            $ownM = (int) DB::table('commissions')->where('affiliate_id', $leader->id)->whereNull('override_of')->whereIn('source_type', ['invoice', 'domain_order', 'adjustment'])
                ->whereNotIn('id', (clone $team)->pluck('id'))->where('created_at', '>=', $m0)->where('created_at', '<', $m1)->sum('amount_minor');
            $chart[] = ['month' => $m0->format('M'), 'own' => $ownM, 'team' => $teamM];
        }
        $board = $ms->filter(fn ($m) => $m->status === 'approved')->concat(self::formers($leader))->map(fn ($m) => self::memberLine($leader, $m, $from))
            ->sortBy(fn ($x) => [$x['left'] ? 1 : 0, -$x['sales_minor'], -$x['signups']])->values()->all();
        return [
            'days' => $days,
            'members' => ['active' => $ms->where('status', 'approved')->count(), 'waiting' => $ms->where('status', 'pending')->count()],
            'signups' => $since(DB::table('referrals')->whereIn('affiliate_id', $ids ?: [0])->where('status', '!=', 'void'), 'signed_up_at')->count(),
            'paying' => self::payingNow($ids ?: [0]),
            'sales_minor' => $sales,
            'earned_minor' => (int) $since(clone $team, 'created_at')->sum('amount_minor'),
            'earned_total_minor' => (int) (clone $team)->sum('amount_minor'),
            'money' => $money, 'chart' => $chart, 'leaderboard' => $board,
            'applicants' => $ms->where('status', 'pending')->map(fn ($m) => ['id' => $m->id, 'name' => $m->display_name, 'applied' => $m->created_at, 'channel_url' => $m->channel_url,
                'recommendation' => $m->leader_recommendation, 'rec_note' => $m->leader_rec_note])->values()->all(),
            'activity' => self::activity($leader, 40),
        ];
    }

    private static function memberLine(object $leader, object $m, $from = null): array
    {
        $left = $m->left_at ?? null;   // a former member: their figures stop at the day they left
        $since = fn ($q, $col) => ($from ? $q->where($col, '>=', $from) : $q)->when($left, fn ($w) => $w->where($col, '<=', $left));
        $last = DB::table('referrals')->where('affiliate_id', $m->id)->max('signed_up_at');
        $quiet = ! $left && $m->status === 'approved' && $m->approved_at && now()->diffInDays($m->approved_at, true) >= 30 && (! $last || now()->diffInDays($last, true) >= 30);
        $note = DB::table('affiliate_team_notes')->where('leader_id', $leader->id)->where('recruit_id', $m->id)->first();
        $month = $left ? 0 : DB::table('referrals')->where('affiliate_id', $m->id)->where('status', '!=', 'void')->where('signed_up_at', '>=', now()->startOfMonth())->count();
        return ['id' => $m->id, 'name' => $m->display_name, 'status' => $m->status, 'quiet' => $quiet, 'joined' => $m->approved_at ?: $m->created_at,
            'left' => $m->left_reason ?? null, 'left_at' => $left,
            'signups' => $since(DB::table('referrals')->where('affiliate_id', $m->id)->where('status', '!=', 'void'), 'signed_up_at')->count(),
            'paying' => $left ? 0 : self::payingNow([$m->id]),
            'sales_minor' => (int) $since(DB::table('commissions')->where('affiliate_id', $m->id)->whereNull('override_of')->whereIn('source_type', ['invoice', 'domain_order'])->where('amount_minor', '>', 0), 'created_at')->sum('base_minor'),
            'earned_minor' => (int) self::teamRows((int) $leader->id, (int) $m->id)->sum('amount_minor'),
            'goal' => $note->goal_signups ?? null, 'this_month' => $month, 'has_note' => (bool) ($note && $note->note)];
    }

    /** Businesses still on a paid plan now, the way PartnerPortal::overview counts them. */
    private static function payingNow(array $affIds): int
    {
        return DB::table('referrals as r')->whereIn('r.affiliate_id', $affIds)->where('r.status', 'paying')->whereExists(fn ($q) => $q->from('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->whereColumn('s.workspace_id', 'r.workspace_id')->whereIn('s.status', ['active', 'trialing', 'past_due'])->where('p.price', '>', 0))->count();
    }

    public static function activity(object $leader, int $limit = 40): array
    {
        $names = DB::table('affiliates')->where('leader_id', $leader->id)->orWhereIn('id', DB::table('affiliate_team_history')->where('leader_id', $leader->id)->select('recruit_id'))->pluck('display_name', 'id');
        return DB::table('affiliate_team_events')->where('leader_id', $leader->id)->when($leader->leader_since, fn ($q) => $q->where('created_at', '>=', \Carbon\Carbon::parse($leader->leader_since)->subSeconds(5)))->orderByDesc('id')->limit($limit)->get()->map(function ($e) use ($names) {
            $d = json_decode((string) $e->data_json, true) ?: []; $who = $e->recruit_id ? ($names[$e->recruit_id] ?? ($d['name'] ?? 'A former recruit')) : null;
            $t = [
                'recruit_applied' => $who . ' applied with your team code',
                'recruit_approved' => $who . ' was approved and joined your team',
                'recruit_rejected' => $who . "'s application was not approved",
                'recruit_removed' => $who . ' was removed from your team',
                'recruit_first_sale' => $who . ' made their first sale',
                'team_payment' => 'A business ' . $who . ' referred paid: you earned ' . PartnerProgram::money((int) ($d['amount'] ?? 0)),
                'team_reversal' => 'A refund or dispute on ' . $who . "'s referral took back " . PartnerProgram::money((int) ($d['amount'] ?? 0)),
                'recruit_upgraded' => $who . ' became a Team Leader and left your team',
                'recruit_returned' => $who . ' is a regular affiliate again and is back on your team',
                'invite_sent' => 'Invitation sent to ' . ($d['email'] ?? 'someone'),
                'leader_on' => 'You became a Team Leader',
                'fee_from_commissions' => 'Your Team Leader fee: ' . PartnerProgram::money((int) ($d['amount'] ?? 0)) . ' taken from your commissions',
                'grace_started' => 'Your Team Leader payment did not go through. Pay or earn it by ' . ($d['until'] ?? 'the date shown'),
                'grace_cleared' => 'Your Team Leader fee is paid',
                'leader_cancelling' => 'You cancelled Team Leader. It ends at the end of your paid month',
                'team_dissolved' => 'Your team was dissolved',
                'message_sent' => 'Announcement sent to ' . (int) ($d['recipients'] ?? 0) . ' team members',
            ][$e->kind] ?? str_replace('_', ' ', $e->kind);
            return ['kind' => $e->kind, 'text' => $t, 'at' => $e->created_at];
        })->all();
    }

    /** One recruit in full (D12: what the recruit sees of their own work, plus the leader's 5% from each referral). */
    public static function member(object $leader, int $recruitId): ?array
    {
        $m = DB::table('affiliates')->where('id', $recruitId)->where('leader_id', $leader->id)->first();   // the full row: their codes' terms need their budgets (only listed fields are returned)
        if (! $m) {   // a former member: read-only, up to the day they left
            $f = self::formers($leader)->firstWhere('id', $recruitId); if (! $f) return null;
            $m = DB::table('affiliates')->where('id', $recruitId)->first(); $m->left_reason = $f->left_reason; $m->left_at = $f->left_at;
        }
        $refs = self::upTo(PartnerPortal::referrals($m), $m);
        foreach ($refs as &$r) {
            $oids = DB::table('commissions')->where('affiliate_id', $leader->id)->where('referral_id', $r['id'])->whereNotNull('override_of')->pluck('id');
            $r['leader_minor'] = (int) DB::table('commissions')->where(fn ($w) => $w->whereIn('id', $oids)->orWhereIn('reverses_id', $oids))->sum('amount_minor');
            unset($r['id']);
        }
        unset($r);
        $note = DB::table('affiliate_team_notes')->where('leader_id', $leader->id)->where('recruit_id', $m->id)->first();
        return ['member' => self::memberLine($leader, $m) + ['channel_url' => $m->channel_url, 'recommendation' => $m->leader_recommendation, 'rec_note' => $m->leader_rec_note],
            'codes' => array_map(fn ($c) => ['code' => $c['code'], 'status' => $c['status'], 'used' => $c['redemptions'], 'describe' => $c['describe'],
                'earns' => ['monthly' => $c['commission']['commission_monthly_bps'], 'yearly' => $c['commission']['commission_yearly_bps'], 'domain' => $c['commission']['commission_domain_bps']]], PartnerPortal::codes($m)),
            'referrals' => $refs, 'note' => $note->note ?? '', 'goal' => $note->goal_signups ?? null];
    }

    /** Every business across the team in one list. */
    public static function referrals(object $leader): array
    {
        $out = [];
        foreach (self::members($leader)->concat(self::formers($leader)) as $m) {
            if ($m->status !== 'approved' && $m->status !== 'suspended' && empty($m->left_at)) continue;
            foreach (self::upTo(PartnerPortal::referrals($m), $m) as $r) {
                $oids = DB::table('commissions')->where('affiliate_id', $leader->id)->where('referral_id', $r['id'])->whereNotNull('override_of')->pluck('id');
                $r['leader_minor'] = (int) DB::table('commissions')->where(fn ($w) => $w->whereIn('id', $oids)->orWhereIn('reverses_id', $oids))->sum('amount_minor');
                $r['recruit'] = $m->display_name; $r['recruit_id'] = $m->id; unset($r['id']);
                $out[] = $r;
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['joined'], (string) $a['joined']));
        return array_slice($out, 0, 2000);
    }

    public static function earnings(object $leader): array
    {
        $team = self::teamRows((int) $leader->id);
        $names = DB::table('affiliates')->pluck('display_name', 'id');
        $rows = (clone $team)->orderByDesc('id')->limit(300)->get(['id', 'kind', 'base_minor', 'rate_bps', 'amount_minor', 'status', 'payable_at', 'created_at', 'note', 'override_of', 'reverses_id']);
        $bal = []; foreach (['pending', 'payable', 'paid'] as $s) $bal[$s] = (int) (clone $team)->where('status', $s)->sum('amount_minor');
        $fees = DB::table('affiliate_leader_fees')->where('affiliate_id', $leader->id)->whereIn('status', ['applied', 'out_of_band', 'credited'])->orderByDesc('id')->limit(36)->get(['period_key', 'from_commission_minor', 'card_minor', 'status', 'created_at']);
        return ['balances' => $bal, 'rows' => $rows, 'fees' => $fees];
    }

    // ------------------------------------------------------------------ manage

    public static function recommend(object $leader, int $recruitId, string $verdict, ?string $note): array
    {
        if (! in_array($verdict, ['recommend', 'decline'], true)) return ['ok' => false, 'error' => 'Choose recommend or decline.'];
        $m = DB::table('affiliates')->where('id', $recruitId)->where('leader_id', $leader->id)->first();
        if (! $m) return ['ok' => false, 'error' => 'Not on your team.'];
        if ($m->status !== 'pending') return ['ok' => false, 'error' => 'This application has already been decided.'];
        DB::table('affiliates')->where('id', $m->id)->update(['leader_recommendation' => $verdict, 'leader_rec_note' => $note ? mb_substr($note, 0, 300) : null, 'updated_at' => now()]);
        return ['ok' => true];
    }

    public static function remove(object $leader, int $recruitId): array
    {
        $m = DB::table('affiliates')->where('id', $recruitId)->where('leader_id', $leader->id)->first();
        if (! $m) return ['ok' => false, 'error' => 'Not on your team.'];
        DB::table('affiliates')->where('id', $m->id)->update(['leader_id' => null, 'leader_recommendation' => null, 'leader_rec_note' => null, 'updated_at' => now()]);
        TeamLeader::leftTeam((int) $leader->id, $m, 'removed');   // D22: the records stay, badged
        TeamLeader::event((int) $leader->id, (int) $m->id, 'recruit_removed', ['name' => $m->display_name]);
        PartnerEmails::safe(fn () => PartnerEmails::removedFromTeam((int) $m->id, (string) $leader->display_name));   // R1
        return ['ok' => true];
    }

    public static function saveNote(object $leader, int $recruitId, ?string $note, $goal): array
    {
        if (! DB::table('affiliates')->where('id', $recruitId)->where('leader_id', $leader->id)->exists()) return ['ok' => false, 'error' => 'Not on your team.'];
        $g = ($goal === null || $goal === '') ? null : max(0, min(9999, (int) $goal));
        DB::table('affiliate_team_notes')->updateOrInsert(['leader_id' => $leader->id, 'recruit_id' => $recruitId], ['note' => $note !== null ? mb_substr($note, 0, 3000) : null, 'goal_signups' => $g, 'updated_at' => now(), 'created_at' => now()]);
        return ['ok' => true];
    }

    public static function invites(object $leader): array
    {
        return DB::table('affiliate_team_invites')->where('leader_id', $leader->id)->orderByDesc('id')->limit(300)->get(['id', 'email', 'status', 'sent_count', 'last_sent_at'])->all();
    }

    public static function invite(object $leader, string $email, bool $resend = false): array
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email address.'];
        $own = DB::table('users')->where('id', $leader->user_id)->value('email');
        if ($email === strtolower((string) $own)) return ['ok' => false, 'error' => 'That is your own email.'];
        $uid = DB::table('users')->where('email', $email)->value('id');
        if ($uid && DB::table('affiliates')->where('user_id', $uid)->exists()) return ['ok' => false, 'error' => 'That person is already an affiliate.'];
        $today = DB::table('affiliate_team_invites')->where('leader_id', $leader->id)->where('last_sent_at', '>=', now()->subDay())->count();
        if ($today >= self::MAX_INVITES_A_DAY) return ['ok' => false, 'error' => 'You can send ' . self::MAX_INVITES_A_DAY . ' invitations a day. Try again tomorrow.'];
        $ex = DB::table('affiliate_team_invites')->where('leader_id', $leader->id)->where('email', $email)->first();
        if ($ex && ! $resend) return ['ok' => false, 'error' => 'You already invited ' . $email . '. Use Resend.'];
        if ($ex && $ex->sent_count >= 3) return ['ok' => false, 'error' => 'That invitation was sent 3 times already.'];
        if ($ex) DB::table('affiliate_team_invites')->where('id', $ex->id)->update(['status' => 'sent', 'sent_count' => $ex->sent_count + 1, 'last_sent_at' => now(), 'updated_at' => now()]);
        else DB::table('affiliate_team_invites')->insert(['leader_id' => $leader->id, 'email' => $email, 'status' => 'sent', 'sent_count' => 1, 'last_sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $id = (int) DB::table('affiliate_team_invites')->where('leader_id', $leader->id)->where('email', $email)->value('id');
        PartnerEmails::safe(fn () => PartnerEmails::teamInvite($id));   // I1
        TeamLeader::event((int) $leader->id, null, 'invite_sent', ['email' => $email]);
        return ['ok' => true, 'id' => $id];
    }

    public static function withdrawInvite(object $leader, int $id): array
    {
        $n = DB::table('affiliate_team_invites')->where('id', $id)->where('leader_id', $leader->id)->where('status', 'sent')->update(['status' => 'withdrawn', 'updated_at' => now()]);
        return $n ? ['ok' => true] : ['ok' => false, 'error' => 'Not found.'];
    }

    public static function presets(object $leader): array
    {
        return DB::table('affiliate_team_presets')->where('leader_id', $leader->id)->orderBy('id')->get(['id', 'label', 'monthly_bps', 'yearly_bps', 'domain_bps'])->all();
    }

    /** A recommended split, in what the recruit gives their customer (the recruit's own budget is 20 / 15 / 10). */
    public static function savePreset(object $leader, array $in): array
    {
        if (DB::table('affiliate_team_presets')->where('leader_id', $leader->id)->count() >= self::MAX_PRESETS) return ['ok' => false, 'error' => 'You can keep ' . self::MAX_PRESETS . ' recommended splits.'];
        $label = trim((string) ($in['label'] ?? '')); if ($label === '' || mb_strlen($label) > 60) return ['ok' => false, 'error' => 'Give it a short name.'];
        $row = [];
        foreach (PartnerProgram::BUDGET as $k => $max) {
            $v = (int) ($in[$k] ?? 0);
            if ($v < 0 || $v > $max || $v % 100) return ['ok' => false, 'error' => 'The ' . $k . ' discount must be a whole percent from 0 to ' . ($max / 100) . '%.'];
            $row[$k . '_bps'] = $v;
        }
        $id = DB::table('affiliate_team_presets')->insertGetId($row + ['leader_id' => $leader->id, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
        return ['ok' => true, 'id' => $id];
    }

    public static function deletePreset(object $leader, int $id): array
    {
        return DB::table('affiliate_team_presets')->where('id', $id)->where('leader_id', $leader->id)->delete() ? ['ok' => true] : ['ok' => false, 'error' => 'Not found.'];
    }

    public static function messages(object $leader): array
    {
        return ['sent_this_month' => DB::table('affiliate_team_messages')->where('leader_id', $leader->id)->where('created_at', '>=', now()->startOfMonth())->count(),
            'max' => self::MAX_MESSAGES_A_MONTH, 'messages' => DB::table('affiliate_team_messages')->where('leader_id', $leader->id)->orderByDesc('id')->limit(50)->get(['id', 'subject', 'body', 'recipients', 'created_at'])->all()];
    }

    /** D13: an announcement to the whole team, by email and in their portal, sent by LevelUpGrowth on the leader's behalf. */
    public static function sendMessage(object $leader, string $subject, string $body): array
    {
        $subject = trim($subject); $body = trim($body);
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 120) return ['ok' => false, 'error' => 'Write a subject of 3 to 120 characters.'];
        if (mb_strlen($body) < 10 || mb_strlen($body) > 2000) return ['ok' => false, 'error' => 'Write a message of 10 to 2,000 characters.'];
        if (DB::table('affiliate_team_messages')->where('leader_id', $leader->id)->where('created_at', '>=', now()->startOfMonth())->count() >= self::MAX_MESSAGES_A_MONTH) return ['ok' => false, 'error' => 'You can send ' . self::MAX_MESSAGES_A_MONTH . ' announcements a month.'];
        $to = DB::table('affiliates')->where('leader_id', $leader->id)->where('status', 'approved')->pluck('id');
        if ($to->isEmpty()) return ['ok' => false, 'error' => 'Your team has no active members yet.'];
        $id = DB::table('affiliate_team_messages')->insertGetId(['leader_id' => $leader->id, 'subject' => $subject, 'body' => $body, 'recipients' => $to->count(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($to as $rid) PartnerEmails::safe(fn () => PartnerEmails::teamMessage($id, (int) $rid));   // T1
        TeamLeader::event((int) $leader->id, null, 'message_sent', ['recipients' => $to->count()]);
        return ['ok' => true, 'id' => $id, 'recipients' => $to->count()];
    }

    /** What a recruit sees about their team. */
    public static function forRecruit(object $a): ?array
    {
        if (! $a->leader_id) return null;
        $l = DB::table('affiliates')->where('id', $a->leader_id)->first();
        if (! TeamLeader::isActive($l)) return null;
        return ['leader' => $l->display_name, 'presets' => self::presets($l),
            'messages' => DB::table('affiliate_team_messages')->where('leader_id', $l->id)->where('created_at', '>=', $a->approved_at ?: $a->created_at)->orderByDesc('id')->limit(5)->get(['subject', 'body', 'created_at'])->all()];
    }
}
