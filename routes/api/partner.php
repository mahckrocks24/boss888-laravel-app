<?php
// RFC-0026 section 8 - the partner portal API (/api/partners/apply public; /api/partner/* signed in as the affiliate).
// Included from routes/api.php at top level. Every reply is about the signed-in affiliate's own rows only.

use App\Core\Partners\PartnerPortal;
use App\Core\Partners\PartnerProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

$__partnerApply = function (Request $r, \App\Models\User $user) {
    $in = $r->validate([
        'channel_url' => 'required|string|max:255', 'audience' => 'nullable|string|max:500',
        'display_name' => 'nullable|string|max:120', 'accept_terms' => 'accepted',
    ], ['accept_terms.accepted' => 'Please accept the partner terms.', 'channel_url.required' => 'Tell us where you publish (a channel, page or site).']);
    if (DB::table('affiliates')->where('user_id', $user->id)->exists()) return response()->json(['ok' => false, 'error' => 'You have already applied.'], 409);
    $name = trim((string) ($in['display_name'] ?? '')) ?: $user->name;
    $affId = DB::table('affiliates')->insertGetId(['user_id' => $user->id, 'handle' => PartnerPortal::handleFrom($name), 'display_name' => mb_substr($name, 0, 120), 'status' => 'pending',
        'channel_url' => $in['channel_url'], 'audience' => $in['audience'] ?? null, 'terms_version' => '2026-10', 'terms_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    try {
        app(\App\Core\Notifications\NotificationService::class)->dispatch(type: \App\Core\Notifications\NotificationTypes::SYSTEM_USER_SIGNUP, userId: 1,
            title: 'New partner application', body: $name . ' (' . $user->email . ') applied to the partner program: ' . $in['channel_url'], severity: 'info');
    } catch (\Throwable $e) {}
    \App\Core\Partners\PartnerEmails::safe(fn () => \App\Core\Partners\PartnerEmails::applicationReceived((int) $affId));   // A1
    return null;
};

Route::middleware('throttle:6,10,papply')->post('/partners/apply', function (Request $r) use ($__partnerApply) {
    if (! PartnerProgram::enabled()) return response()->json(['ok' => false, 'error' => 'Applications open soon.'], 403);
    $in = $r->validate([
        'name' => 'required|string|min:2|max:120', 'email' => 'required|email|max:190',
        'password' => ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
    ], ['password.regex' => 'Password must contain at least one uppercase letter and one number.']);
    $email = strtolower(trim($in['email']));
    if (\App\Models\User::where('email', $email)->exists()) {
        return response()->json(['ok' => false, 'code' => 'has_account', 'error' => 'That email already has a LevelUpGrowth account. Sign in on this page and apply from there.'], 409);
    }
    $user = \App\Models\User::create(['name' => $in['name'], 'email' => $email, 'password' => Hash::make($in['password'])]);
    if ($resp = $__partnerApply($r, $user)) { $user->delete(); return $resp; }
    $tokens = app(\App\Core\Auth\RefreshTokenService::class)->issueTokenPair($user, null, $r->ip(), (string) $r->userAgent(), 'partner');
    return response()->json(['ok' => true, 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']], 201);
});

Route::prefix('partner')->group(function () use ($__partnerApply) {
    Route::middleware('partner.auth')->get('/me', fn (Request $r) => response()->json(PartnerPortal::me($r->user(), $r->attributes->get('affiliate'))));
    Route::middleware(['partner.auth', 'throttle:6,10,papply'])->post('/apply', function (Request $r) use ($__partnerApply) {
        if (! PartnerProgram::enabled()) return response()->json(['ok' => false, 'error' => 'Applications open soon.'], 403);
        return $__partnerApply($r, $r->user()) ?? response()->json(['ok' => true], 201);
    });
    Route::middleware('partner.auth:approved')->group(function () {
        Route::get('/overview', fn (Request $r) => response()->json(PartnerPortal::overview($r->attributes->get('affiliate'), max(0, min(3650, (int) $r->query('days', 30))))));
        Route::get('/referrals', fn (Request $r) => response()->json(['referrals' => PartnerPortal::referrals($r->attributes->get('affiliate'))]));
        Route::get('/links', fn (Request $r) => response()->json(PartnerPortal::links($r->attributes->get('affiliate'))));
        Route::post('/links', function (Request $r) {
            $r->validate(['sub' => 'required|string|max:60', 'label' => 'nullable|string|max:120']);
            return response()->json(PartnerPortal::addLink($r->attributes->get('affiliate'), (string) $r->input('sub'), $r->input('label')));
        })->middleware('throttle:30,1,plinks');
        Route::delete('/links/{id}', function (Request $r, int $id) {
            DB::table('affiliate_links')->where('id', $id)->where('affiliate_id', $r->attributes->get('affiliate')->id)->delete();
            return response()->json(['ok' => true]);
        })->whereNumber('id');
        Route::get('/codes', fn (Request $r) => response()->json(['codes' => PartnerPortal::codes($r->attributes->get('affiliate'))]));
        Route::post('/codes', fn (Request $r) => response()->json(PartnerPortal::saveCode($r->attributes->get('affiliate'), $r->all())))->middleware('throttle:20,1,pcodes');
        Route::put('/codes/{id}', fn (Request $r, int $id) => response()->json(PartnerPortal::saveCode($r->attributes->get('affiliate'), $r->all(), $id)))->whereNumber('id')->middleware('throttle:30,1,plinks');
        Route::get('/money', fn (Request $r) => response()->json(PartnerPortal::money($r->attributes->get('affiliate'))));
        // RFC-0026 section 7: how the partner is paid
        Route::get('/payout', function (Request $r) {
            $a = $r->attributes->get('affiliate'); $st = $a->payout_method === 'stripe' ? \App\Core\Partners\PartnerPayouts::stripeRefresh($a) : null;
            return response()->json(['methods' => \App\Core\Partners\PartnerPayouts::methods(), 'method' => $a->payout_method, 'email' => $a->payout_email,
                'ready' => $st ? $st['ready'] : (bool) $a->payouts_enabled, 'due' => $st['due'] ?? [], 'bank_available' => \App\Core\Partners\PartnerPayouts::connectEnabled()]);
        });
        Route::post('/payout/manual', function (Request $r) {
            $r->validate(['method' => 'required|string', 'email' => 'required|string|max:190']);
            return response()->json(\App\Core\Partners\PartnerPayouts::setManual($r->attributes->get('affiliate'), (string) $r->input('method'), (string) $r->input('email')));
        })->middleware('throttle:10,1,ppayout');
        Route::post('/payout/stripe', function (Request $r) {
            $r->validate(['country' => 'required|string|size:2']);
            return response()->json(\App\Core\Partners\PartnerPayouts::stripeStart($r->attributes->get('affiliate'), (string) $r->user()->email, (string) $r->input('country')));
        })->middleware('throttle:10,1,ppayout');
        Route::post('/payout/stripe/dashboard', function (Request $r) {
            $u = \App\Core\Partners\PartnerPayouts::stripeDashboard($r->attributes->get('affiliate'));
            return response()->json($u ? ['ok' => true, 'url' => $u] : ['ok' => false, 'error' => 'Not available.']);
        })->middleware('throttle:10,1,ppayout');
    });
});
