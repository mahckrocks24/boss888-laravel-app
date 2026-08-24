<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MISSION-018 WS-2 (2026-08-24). Refuses money-path actions for accounts
 * that have never confirmed their email address.
 *
 * Deliberately NOT applied as a wall in front of the whole app: the Owner's
 * directed journey is "after signup the customer meets Sarah" (MISSION-018
 * §7), and a verification interstitial before that first conversation is
 * friction the Owner did not ask for. Enforcement sits where the risk is —
 * the top of the money funnel — and can be added to further routes by alias.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->email_verified_at === null) {
            return response()->json([
                'success' => false,
                'error'   => 'email_unverified',
                'message' => 'Please confirm your email address first — we sent you a link when you signed up. You can request a new one from Settings.',
            ], 403);
        }

        return $next($request);
    }
}
