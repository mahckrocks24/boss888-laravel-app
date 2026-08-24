<?php

/**
 * EMAIL888 — public provider webhook.
 *
 * Deliberately outside every auth group: the caller is a mail provider, which
 * has no platform identity. Its authentication is the path secret verified
 * inside the controller, which refuses everything while the secret is unset.
 *
 * Rate limited because it is public. Real provider volume is far below this;
 * the limit exists to bound an attacker guessing secrets, not to shape traffic.
 *
 * EM-8 — THE PROVIDER IS NOW A ROUTE PARAMETER.
 * The URL was `/webhooks/email/postmark/{secret}`; it is now
 * `/webhooks/email/{provider}/{secret}`. The live Postmark URL is
 * BYTE-IDENTICAL under the new pattern, so no provider configuration changed
 * and no delivery event was at risk — but a second adapter no longer needs a
 * second route, and the controller no longer has a vendor in its name.
 *
 * THERE ARE TWO ROUTES HERE, AND THE ORDER MATTERS.
 * The first is the real endpoint. The second exists only so that requests the
 * first REJECTS BY SHAPE still leave a durable receipt: measured against
 * staging, a too-short or illegal-character secret was refused by the router
 * with no trace at all, which is precisely the request a scanner makes.
 */

use App\Core\Email888\Http\Middleware\RecordThrottledIngress;
use App\Core\Email888\Http\ProviderWebhookController;
use App\Core\Email888\Http\WebhookIngressFallbackController;
use Illuminate\Support\Facades\Route;

// RecordThrottledIngress is OUTSIDE throttle so it can catch what throttle
// throws; a 429 is otherwise the one refusal that leaves no receipt.
Route::post('/webhooks/email/{provider}/{secret}', ProviderWebhookController::class)
    ->where('provider', '[a-z0-9-]{3,32}')
    ->where('secret', '[A-Za-z0-9_-]{24,128}')
    ->middleware([RecordThrottledIngress::class, 'throttle:120,1'])
    ->name('email888.webhook.provider');

// Any method, any remaining shape — including no secret segment at all, and
// including GET, which providers never send but scanners always do. A smaller
// bucket than the real route: this traffic is by definition not ours.
Route::any('/webhooks/email/{provider}/{unmatched?}', WebhookIngressFallbackController::class)
    ->where('provider', '[^/]+')
    ->where('unmatched', '.*')
    ->middleware([RecordThrottledIngress::class, 'throttle:60,1'])
    ->name('email888.webhook.unmatched');
