<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * CRM-PACKS-4a: the client's page for a quote, deposit request or invoice — under the business's own website
 * address, white-label, no account needed. Accept a quote; pay on the business's own Stripe account.
 */
Route::middleware(['throttle:40,1'])->group(function () {
    $svc = fn () => app(\App\Engines\CRM\Services\CrmPayments::class);
    $html = fn (string $h, int $code = 200) => response($h, $code)->header('Content-Type', 'text/html; charset=utf-8')->header('X-Robots-Tag', 'noindex');
    $gone = fn () => response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;padding:40px;max-width:520px;margin:auto"><h2>This link is not valid</h2><p>Please check the link in your email, or reply to it and we will help.</p></body>', 404);

    Route::get('/public/pay/{token}', function (Request $r, $token) use ($svc, $html, $gone) {
        $p = $svc()->byToken((string) $token); if (! $p) return $gone();
        $svc()->viewed($p);
        return $html($svc()->page($svc()->byToken((string) $token)));
    });
    Route::post('/public/pay/{token}/accept', function (Request $r, $token) use ($svc, $html, $gone) {
        $p = $svc()->byToken((string) $token); if (! $p) return $gone();
        $svc()->accept($p);
        return $html($svc()->page($svc()->byToken((string) $token)));
    });
    Route::post('/public/pay/{token}/checkout', function (Request $r, $token) use ($svc, $html, $gone) {
        $p = $svc()->byToken((string) $token); if (! $p) return $gone();
        $res = $svc()->checkout($p);
        if (! empty($res['success'])) return redirect()->away((string) $res['url'], 303);
        return $html($svc()->page($p, (string) ($res['message'] ?? 'Payment is not available right now.')));
    });
    Route::get('/public/pay/{token}/done', function (Request $r, $token) use ($svc, $html, $gone) {
        $p = $svc()->byToken((string) $token); if (! $p) return $gone();
        $sid = (string) $r->query('session', '');
        if (preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sid)) $svc()->confirm($p, $sid);
        return $html($svc()->page($svc()->byToken((string) $token)));
    });
});
