<?php
// RFC-0026 section 9 - /admin -> Affiliates: approve, monitor, adjust, reverse. Platform admins only; every change audited.
// Changes also carry mfa.stepup, which stands down until governance has two MFA admins and then guards them.

use App\Core\Partners\PartnerProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.jwt', \App\Http\Middleware\AdminMiddleware::class])->prefix('admin/partners')->group(function () {
    $audit = function (Request $r, string $action, string $type, $id, array $meta = []) {
        try { app(\App\Core\Audit\AuditLogService::class)->log(null, (int) $r->user()->id, 'affiliates.' . $action, $type, (int) $id, $meta); } catch (\Throwable $e) {
            DB::table('audit_logs')->insert(['workspace_id' => null, 'user_id' => (int) $r->user()->id, 'action' => 'affiliates.' . $action, 'entity_type' => $type, 'entity_id' => (int) $id, 'metadata_json' => json_encode($meta), 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $sumBy = fn (int $affId, string $status) => (int) DB::table('commissions')->where('affiliate_id', $affId)->where('status', $status)->sum('amount_minor');

    Route::get('/summary', function () {
        $byStatus = DB::table('affiliates')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $base = (int) DB::table('commissions')->where('amount_minor', '>', 0)->sum('base_minor');
        $commission = (int) DB::table('commissions')->sum('amount_minor');
        $discounts = (int) DB::table('voucher_redemptions')->sum('amount_saved_minor');
        $top = DB::table('commissions as c')->join('affiliates as a', 'a.id', '=', 'c.affiliate_id')->selectRaw('a.id, a.display_name, a.handle, SUM(c.amount_minor) earned')
            ->groupBy('a.id', 'a.display_name', 'a.handle')->orderByDesc('earned')->limit(5)->get();
        return response()->json([
            'enabled' => PartnerProgram::enabled(), 'affiliates' => $byStatus, 'pending_applications' => (int) ($byStatus['pending'] ?? 0),
            'referrals' => DB::table('referrals')->whereNotNull('affiliate_id')->where('status', '!=', 'void')->count(),
            'paying' => DB::table('referrals')->where('status', 'paying')->count(),
            'clicks_30d' => DB::table('affiliate_clicks')->where('created_at', '>=', now()->subDays(30))->count(),
            'referred_revenue_minor' => $base, 'commission_minor' => $commission, 'discounts_minor' => $discounts,
            'program_cost_pct' => $base ? round(($commission + $discounts) / $base * 100, 1) : 0,
            'by_status' => ['pending' => (int) DB::table('commissions')->where('status', 'pending')->sum('amount_minor'), 'payable' => (int) DB::table('commissions')->where('status', 'payable')->sum('amount_minor'), 'paid' => (int) DB::table('commissions')->where('status', 'paid')->sum('amount_minor')],
            'open_flags' => DB::table('affiliate_flags')->where('status', 'open')->count(),
            // RFC-0028
            'leaders' => DB::table('affiliates')->where('tier', 'leader')->count(), 'leaders_in_grace' => DB::table('affiliates')->where('tier', 'leader')->whereNotNull('leader_grace_until')->count(),
            'team_share_minor' => (int) DB::table('commissions')->whereNotNull('override_of')->sum('amount_minor') + (int) DB::table('commissions')->whereIn('reverses_id', DB::table('commissions')->whereNotNull('override_of')->select('id'))->sum('amount_minor'),
            'leader_fees_from_commissions_minor' => (int) -DB::table('commissions')->where('source_type', 'leader_fee')->sum('amount_minor'),
            'approval_mode' => PartnerProgram::approvalMode(), 'manual_queue' => DB::table('affiliates')->where('status', 'pending')->count(),
            'top' => $top,
        ]);
    });

    Route::get('/affiliates', function (Request $r) use ($sumBy) {
        $q = DB::table('affiliates as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->orderByRaw("FIELD(a.status, 'pending', 'approved', 'suspended', 'rejected', 'closed')")->orderByDesc('a.id');
        if ($s = $r->query('status')) $q->where('a.status', $s);
        if ($lf = (int) $r->query('leader')) $q->where('a.leader_id', $lf);
        return response()->json(['affiliates' => $q->limit(1000)->get(['a.*', 'u.email', 'u.name as user_name'])->map(function ($a) use ($sumBy) {
            return ['id' => $a->id, 'name' => $a->display_name, 'email' => $a->email, 'handle' => $a->handle, 'status' => $a->status, 'channel_url' => $a->channel_url, 'audience' => $a->audience,
                'applied' => $a->created_at, 'approved' => $a->approved_at, 'budgets' => PartnerProgram::budgets($a),
                'clicks' => DB::table('affiliate_clicks')->where('affiliate_id', $a->id)->count(),
                'signups' => DB::table('referrals')->where('affiliate_id', $a->id)->where('status', '!=', 'void')->count(),
                'paying' => DB::table('referrals')->where('affiliate_id', $a->id)->where('status', 'paying')->count(),
                'pending' => $sumBy($a->id, 'pending'), 'payable' => $sumBy($a->id, 'payable'), 'paid' => $sumBy($a->id, 'paid'),
                'flags' => DB::table('affiliate_flags')->where('affiliate_id', $a->id)->where('status', 'open')->count(),
                // RFC-0028
                'tier' => \App\Core\Partners\TeamLeader::isActive($a) ? 'leader' : 'affiliate', 'leader_id' => $a->leader_id, 'leader' => $a->leader_id ? DB::table('affiliates')->where('id', $a->leader_id)->value('display_name') : null,
                'team' => DB::table('affiliates')->where('leader_id', $a->id)->where('status', 'approved')->count(), 'grace_until' => $a->leader_grace_until,
                'review' => json_decode((string) $a->bella_review, true), 'decided_by' => $a->decided_by, 'recommendation' => $a->leader_recommendation, 'rec_note' => $a->leader_rec_note];
        })]);
    });

    Route::get('/affiliates/{id}', function (int $id) use ($sumBy) {
        $a = DB::table('affiliates as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.id', $id)->first(['a.*', 'u.email']);
        if (! $a) return response()->json(['error' => 'Not found'], 404);
        $refs = DB::table('referrals as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->leftJoin('workspaces as w', 'w.id', '=', 'r.workspace_id')->leftJoin('vouchers as v', 'v.id', '=', 'r.voucher_id')
            ->where('r.affiliate_id', $id)->orderByDesc('r.id')->limit(500)->get(['r.*', 'u.email', 'w.name as workspace_name', 'v.code']);
        return response()->json([
            'affiliate' => $a, 'budgets' => PartnerProgram::budgets($a),
            'money' => ['pending' => $sumBy($id, 'pending'), 'payable' => $sumBy($id, 'payable'), 'paid' => $sumBy($id, 'paid')],
            'links' => DB::table('affiliate_links')->where('affiliate_id', $id)->get(),
            'codes' => DB::table('vouchers')->where('affiliate_id', $id)->orderByDesc('id')->get(),
            'referrals' => $refs->map(function ($x) use ($id) { $x->earned_minor = (int) DB::table('commissions')->where('referral_id', $x->id)->where('affiliate_id', $id)->sum('amount_minor'); return $x; }),
            'commissions' => DB::table('commissions')->where('affiliate_id', $id)->orderByDesc('id')->limit(300)->get(),
            'payouts' => DB::table('payouts')->where('affiliate_id', $id)->orderByDesc('id')->get(),
            'flags' => DB::table('affiliate_flags')->where('affiliate_id', $id)->orderByDesc('id')->get(),
            'clicks_30d' => DB::table('affiliate_clicks')->where('affiliate_id', $id)->where('created_at', '>=', now()->subDays(30))->count(),
            // RFC-0028
            'team' => ['active' => \App\Core\Partners\TeamLeader::isActive($a), 'in_grace' => \App\Core\Partners\TeamLeader::inGrace($a),
                'leader' => $a->leader_id ? DB::table('affiliates')->where('id', $a->leader_id)->first(['id', 'display_name', 'tier']) : null,
                'members' => DB::table('affiliates')->where('leader_id', $id)->orderByDesc('id')->get(['id', 'display_name', 'status', 'created_at']),
                'fees' => DB::table('affiliate_leader_fees')->where('affiliate_id', $id)->orderByDesc('id')->limit(24)->get(),
                'leaders' => DB::table('affiliates')->where('tier', 'leader')->where('id', '!=', $id)->orderBy('display_name')->get(['id', 'display_name'])],
            'review' => json_decode((string) $a->bella_review, true),
        ]);
    });

    Route::post('/affiliates/{id}/decision', function (Request $r, int $id) {
        $in = $r->validate(['action' => 'required|in:approve,reject,suspend,reinstate,close', 'note' => 'nullable|string|max:500']);
        $res = PartnerProgram::decide($id, $in['action'], $in['note'] ?? null, (int) $r->user()->id, 'admin');   // RFC-0028: one door for admin and Bella
        return $res['ok'] ? response()->json(['success' => true, 'status' => $res['status']]) : response()->json(['error' => $res['error']], $res['code'] ?? 422);
    })->whereNumber('id')->middleware('mfa.stepup');

    // RFC-0028 D11 / D19: who approves applications
    Route::get('/approvals', fn () => response()->json(['mode' => PartnerProgram::approvalMode(),
        'recent' => DB::table('affiliates')->whereNotNull('bella_reviewed_at')->orderByDesc('bella_reviewed_at')->limit(50)->get(['id', 'display_name', 'status', 'decided_by', 'bella_review', 'bella_reviewed_at'])
            ->map(function ($x) { $x->bella_review = json_decode((string) $x->bella_review, true); return $x; })]));
    Route::post('/approvals', function (Request $r) use ($audit) {
        $in = $r->validate(['mode' => 'required|in:bella,manual']);
        \App\Core\Partners\BellaReviewer::setMode($in['mode']);
        $audit($r, 'approval_mode', 'Setting', 0, $in);
        return response()->json(['success' => true, 'mode' => PartnerProgram::approvalMode()]);
    })->middleware('mfa.stepup');
    Route::post('/affiliates/{id}/review', function (Request $r, int $id) use ($audit) {
        DB::table('affiliates')->where('id', $id)->update(['bella_reviewed_at' => null]);
        $res = \App\Core\Partners\BellaReviewer::review($id);
        $audit($r, 'bella_review', 'Affiliate', $id, ['decision' => $res['decision'] ?? null]);
        return response()->json(['success' => true, 'review' => $res]);
    })->whereNumber('id')->middleware('mfa.stepup');
    // RFC-0028: move an affiliate onto a Team Leader's team, or off any team
    Route::post('/affiliates/{id}/team', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['leader_id' => 'nullable|integer']);
        $a = DB::table('affiliates')->where('id', $id)->first(); if (! $a) return response()->json(['error' => 'Not found'], 404);
        $to = $in['leader_id'] ?? null;
        if ($to) {
            $l = DB::table('affiliates')->where('id', $to)->first();
            if ((int) $to === $id || ! \App\Core\Partners\TeamLeader::isActive($l)) return response()->json(['error' => 'Choose an active Team Leader (not the affiliate itself).'], 422);
        }
        DB::table('affiliates')->where('id', $id)->update(['leader_id' => $to, 'updated_at' => now()]);
        if ($a->leader_id && $a->leader_id != $to) \App\Core\Partners\TeamLeader::event((int) $a->leader_id, $id, 'recruit_removed', ['name' => $a->display_name, 'by' => 'admin']);
        if ($to) \App\Core\Partners\TeamLeader::event((int) $to, $id, 'recruit_approved', ['name' => $a->display_name, 'by' => 'admin']);
        $audit($r, 'team_move', 'Affiliate', $id, ['from' => $a->leader_id, 'to' => $to]);
        return response()->json(['success' => true]);
    })->whereNumber('id')->middleware('mfa.stepup');
    Route::post('/affiliates/{id}/end-leader', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['note' => 'required|string|min:3|max:255']);
        $a = DB::table('affiliates')->where('id', $id)->first(); if (! $a || $a->tier !== 'leader') return response()->json(['error' => 'Not a Team Leader.'], 422);
        \App\Core\Partners\TeamLeader::dissolve($a, 'ended by LevelUpGrowth');
        $audit($r, 'leader_ended', 'Affiliate', $id, $in);
        return response()->json(['success' => true]);
    })->whereNumber('id')->middleware('mfa.stepup');

    Route::post('/affiliates/{id}/budgets', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['monthly' => 'required|integer|min:0|max:5000', 'yearly' => 'required|integer|min:0|max:5000', 'domain' => 'required|integer|min:0|max:5000']);
        foreach ($in as $k => $v) if ($v % 100) return response()->json(['error' => 'Whole percent only.'], 422);
        $a = DB::table('affiliates')->where('id', $id)->first();
        if (! $a) return response()->json(['error' => 'Not found'], 404);
        DB::table('affiliates')->where('id', $id)->update(['budget_monthly_bps' => $in['monthly'], 'budget_yearly_bps' => $in['yearly'], 'budget_domain_bps' => $in['domain'], 'updated_at' => now()]);
        $audit($r, 'budgets', 'Affiliate', $id, ['from' => PartnerProgram::budgets($a), 'to' => $in]);
        return response()->json(['success' => true, 'note' => 'New budgets apply to new customers. Existing referrals keep the terms they joined on.']);
    })->whereNumber('id')->middleware('mfa.stepup');

    Route::post('/affiliates/{id}/notes', function (Request $r, int $id) use ($audit) {
        $r->validate(['notes' => 'nullable|string|max:5000']);
        DB::table('affiliates')->where('id', $id)->update(['notes' => $r->input('notes'), 'updated_at' => now()]);
        $audit($r, 'notes', 'Affiliate', $id);
        return response()->json(['success' => true]);
    })->whereNumber('id');

    Route::post('/affiliates/{id}/adjust', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['amount_minor' => 'required|integer|not_in:0|min:-1000000|max:1000000', 'note' => 'required|string|min:5|max:255']);
        $ref = DB::table('referrals')->where('affiliate_id', $id)->orderByDesc('id')->value('id') ?? 0;
        $cid = DB::table('commissions')->insertGetId(['affiliate_id' => $id, 'referral_id' => $ref, 'workspace_id' => 0, 'source_type' => 'adjustment', 'source_id' => 'admin-' . $r->user()->id,
            'stripe_event_id' => 'admin:' . uniqid('', true), 'kind' => 'monthly', 'base_minor' => 0, 'rate_bps' => 0, 'amount_minor' => $in['amount_minor'], 'currency' => 'USD',
            'status' => 'payable', 'payable_at' => now(), 'note' => 'Adjustment: ' . $in['note'], 'created_at' => now(), 'updated_at' => now()]);
        $audit($r, 'adjust', 'Affiliate', $id, $in + ['commission_id' => $cid]);
        return response()->json(['success' => true, 'id' => $cid]);
    })->whereNumber('id')->middleware('mfa.stepup');

    Route::get('/codes', function (Request $r) {
        $q = DB::table('vouchers as v')->leftJoin('affiliates as a', 'a.id', '=', 'v.affiliate_id')->orderByDesc('v.id');
        if ($r->query('type') === 'house') $q->whereNull('v.affiliate_id'); elseif ($r->query('type') === 'partner') $q->whereNotNull('v.affiliate_id');
        return response()->json(['codes' => $q->limit(2000)->get(['v.*', 'a.display_name as affiliate', 'a.handle'])->map(function ($v) {
            $v->describe = PartnerProgram::describe($v);
            $v->saved_minor = (int) DB::table('voucher_redemptions')->where('voucher_id', $v->id)->sum('amount_saved_minor');
            return $v;
        })]);
    });

    Route::post('/codes', function (Request $r) use ($audit) {
        // a house promotion: discount only, no affiliate
        $in = $r->validate(['code' => 'required|string|max:24', 'label' => 'nullable|string|max:120', 'monthly' => 'required|integer|min:0|max:5000', 'yearly' => 'required|integer|min:0|max:5000', 'domain' => 'required|integer|min:0|max:5000',
            'months' => 'required|integer|min:1|max:24', 'max' => 'nullable|integer|min:1', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'new_customers_only' => 'boolean']);
        $code = PartnerProgram::normalizeCode($in['code']);
        if (! $code) return response()->json(['error' => 'Use 4 to 20 letters and numbers.'], 422);
        if (DB::table('vouchers')->where('code', $code)->exists()) return response()->json(['error' => 'That code exists.'], 422);
        foreach (['monthly', 'yearly', 'domain'] as $k) if ($in[$k] % 100) return response()->json(['error' => 'Whole percent only.'], 422);
        if ($in['domain'] > 1000) return response()->json(['error' => 'Domains carry about 23% margin: a house domain discount is capped at 10%.'], 422);
        $id = DB::table('vouchers')->insertGetId(['code' => $code, 'affiliate_id' => null, 'label' => $in['label'] ?? null, 'discount_monthly_bps' => $in['monthly'], 'discount_yearly_bps' => $in['yearly'], 'discount_domain_bps' => $in['domain'],
            'applies_plans' => ($in['monthly'] + $in['yearly']) > 0, 'applies_domains' => $in['domain'] > 0, 'months' => $in['months'], 'max_redemptions' => $in['max'] ?? null, 'redemptions' => 0,
            'starts_at' => $in['starts_at'] ?? null, 'ends_at' => ! empty($in['ends_at']) ? \Carbon\Carbon::parse($in['ends_at'])->endOfDay() : null, 'new_customers_only' => $in['new_customers_only'] ?? true,
            'status' => 'active', 'created_by' => (int) $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $audit($r, 'promo_created', 'Voucher', $id, ['code' => $code] + $in);
        return response()->json(['success' => true, 'id' => $id, 'code' => $code]);
    })->middleware('mfa.stepup');

    Route::put('/codes/{id}', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['status' => 'required|in:active,paused,archived']);
        $v = DB::table('vouchers')->where('id', $id)->first();
        if (! $v) return response()->json(['error' => 'Not found'], 404);
        DB::table('vouchers')->where('id', $id)->update(['status' => $in['status'], 'updated_at' => now()]);
        $audit($r, 'code_status', 'Voucher', $id, ['code' => $v->code, 'from' => $v->status, 'to' => $in['status']]);
        return response()->json(['success' => true]);
    })->whereNumber('id');

    Route::get('/commissions', function (Request $r) {
        $q = DB::table('commissions as c')->leftJoin('affiliates as a', 'a.id', '=', 'c.affiliate_id')->leftJoin('workspaces as w', 'w.id', '=', 'c.workspace_id')->orderByDesc('c.id');
        if ($s = $r->query('status')) $q->where('c.status', $s);
        if ($af = (int) $r->query('affiliate')) $q->where('c.affiliate_id', $af);
        return response()->json(['commissions' => $q->limit(2000)->get(['c.*', 'a.display_name as affiliate', 'a.handle', 'w.name as workspace_name'])]);
    });

    Route::post('/commissions/{id}', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['action' => 'required|in:hold,release,void', 'note' => 'required|string|min:3|max:255']);
        $c = DB::table('commissions')->where('id', $id)->first();
        if (! $c) return response()->json(['error' => 'Not found'], 404);
        if ($c->status === 'paid') return response()->json(['error' => 'Already paid. Add an adjustment on the affiliate instead.'], 422);
        $upd = ['hold' => ['status' => 'pending', 'payable_at' => now()->addYears(10)], 'release' => ['status' => 'payable', 'payable_at' => now()], 'void' => ['status' => 'void']][$in['action']];
        DB::table('commissions')->where('id', $id)->update($upd + ['note' => mb_substr(trim(($c->note ? $c->note . ' · ' : '') . $in['action'] . ': ' . $in['note']), 0, 255), 'updated_at' => now()]);
        $audit($r, 'commission_' . $in['action'], 'Commission', $id, ['note' => $in['note'], 'from' => $c->status, 'amount' => $c->amount_minor]);
        return response()->json(['success' => true]);
    })->whereNumber('id')->middleware('mfa.stepup');

    // RFC-0026 section 7: the monthly payout run
    Route::get('/payouts', fn () => response()->json(['preview' => \App\Core\Partners\PartnerPayouts::preview(), 'bank_available' => \App\Core\Partners\PartnerPayouts::connectEnabled(),
        'min_minor' => PartnerProgram::MIN_PAYOUT_MINOR, 'period' => now()->format('Y-m'),
        'history' => DB::table('payouts as p')->leftJoin('affiliates as a', 'a.id', '=', 'p.affiliate_id')->orderByDesc('p.id')->limit(500)->get(['p.*', 'a.display_name as affiliate', 'a.payout_email'])]));
    Route::post('/payouts/run', function (Request $r) use ($audit) {
        $in = $r->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $res = \App\Core\Partners\PartnerPayouts::run($in['period'], (int) $r->user()->id);
        $audit($r, 'payout_run', 'Payout', 0, $in + $res);
        return response()->json(['success' => true] + $res);
    })->middleware('mfa.stepup');
    Route::post('/payouts/{id}/record', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['reference' => 'required|string|min:3|max:120']);
        $res = \App\Core\Partners\PartnerPayouts::record($id, $in['reference'], (int) $r->user()->id);
        if ($res['ok']) $audit($r, 'payout_recorded', 'Payout', $id, $in);
        return response()->json($res + ['success' => $res['ok']], $res['ok'] ? 200 : 422);
    })->whereNumber('id')->middleware('mfa.stepup');
    Route::post('/payouts/{id}/cancel', function (Request $r, int $id) use ($audit) {
        $res = \App\Core\Partners\PartnerPayouts::cancel($id);
        if ($res['ok']) $audit($r, 'payout_cancelled', 'Payout', $id);
        return response()->json($res + ['success' => $res['ok']], $res['ok'] ? 200 : 422);
    })->whereNumber('id');

    Route::get('/flags', fn (Request $r) => response()->json(['flags' => DB::table('affiliate_flags as f')->leftJoin('affiliates as a', 'a.id', '=', 'f.affiliate_id')
        ->when($r->query('status', 'open') !== 'all', fn ($q) => $q->where('f.status', $r->query('status', 'open')))->orderByDesc('f.id')->limit(1000)->get(['f.*', 'a.display_name as affiliate', 'a.handle'])]));

    Route::post('/flags/{id}', function (Request $r, int $id) use ($audit) {
        $in = $r->validate(['status' => 'required|in:resolved,dismissed', 'resolution' => 'required|string|min:3|max:255']);
        DB::table('affiliate_flags')->where('id', $id)->update(['status' => $in['status'], 'resolution' => $in['resolution'], 'resolved_by' => (int) $r->user()->id, 'updated_at' => now()]);
        $audit($r, 'flag_' . $in['status'], 'AffiliateFlag', $id, $in);
        return response()->json(['success' => true]);
    })->whereNumber('id');
});
