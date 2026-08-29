<?php

namespace App\Http\Middleware;

use App\Core\LaunchScope\LaunchScopePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * LaunchScopeRoutes — Workstream 4 (routes & public endpoints).
 *
 * Created 2026-07-21 per the launch-scope remediation. Social automation /
 * social intelligence / email marketing are OUT of launch scope. W1 put a
 * hard-stop in the engine kernel and W2 disabled the crons and jobs, so the
 * WRITE paths were already execution-dead.
 *
 * WHAT THIS CLOSES
 * ----------------
 * The kernel deny only fires for calls that route through
 * EngineExecutionService. A large number of route closures call their service
 * class DIRECTLY — listCampaigns(), listSequences(), listTemplates(),
 * getCampaignAnalytics(), /mentions/stats and friends. Those bypass the kernel
 * entirely and still return real data, advertising a product we do not ship.
 * Public OAuth connect/callback endpoints for Facebook/Instagram/LinkedIn/X
 * were likewise still live and still able to store platform tokens.
 *
 * WHY MIDDLEWARE AND NOT ~60 CLOSURE EDITS
 * ----------------------------------------
 * routes/api.php is a single 1.1 MB file. Editing ~60 closures inside it is
 * high-risk and, more importantly, would drift from LaunchScopePolicy the
 * moment either side changes. One guard, reading the same policy class every
 * other enforcement layer reads, cannot drift.
 *
 * RESPONSE SHAPE
 * --------------
 * 404, not 403. A 403 says "this exists and you may not have it", which is a
 * discoverability leak and reads to a customer as a permissions bug. 404 says
 * the surface is not part of the product, which is the truth.
 *
 * PROTECT-LIST (never blocked — see $protected)
 * ---------------------------------------------
 * Transactional email (password reset, receipts, verification), the Stripe
 * billing webhook, and the WordPress plugin webhooks. Breaking any of those
 * would be a far worse outcome than leaving a marketing read endpoint open.
 */
class LaunchScopeRoutes
{
    /**
     * Path patterns that are OUT of launch scope.
     *
     * Matched against the normalised request path (no leading slash, no query).
     * `*` matches within a segment run, per Str::is / fnmatch semantics.
     */
    private const BLOCKED = [
        // ── Email marketing: campaigns ──
        'api/marketing/campaigns',
        'api/marketing/campaigns/*',

        // ── Email marketing: sequences / drip ──
        'api/marketing/sequences',
        'api/marketing/sequences/*',

        // ── Email marketing: settings + test send ──
        'api/marketing/email/settings',
        'api/marketing/email/test',

        // ── Email marketing: the whole Email Builder suite ──
        // templates, blocks, AI generate/rewrite/subject/spam-check, send
        // pipeline, analytics. NOTE: this is customer-facing email MARKETING
        // composition; it is not the transactional mailer.
        'api/marketing/email-builder',
        'api/marketing/email-builder/*',

        // ── Email marketing: automations ──
        'api/marketing/automations',
        'api/marketing/automations/*',

        // ── Social: RE-INCLUDED (DEC-0028, 2026-08-25; RISK-0099 fix 2026-08-29) ──
        // Social automation is IN launch. This middleware was a fourth launch-scope layer
        // nobody mirrored when DEC-0028 un-gated the kernel policy: every /api/social/*
        // call (reads, AI, scheduling, publishing, OAuth connect) still answered
        // 404 not_in_product, so the engine was dead for customers regardless of the
        // kernel decision. Social entries removed; mentions + email marketing stay out.

        // ── Social listening / mentions (fully removed engine) ──
        'api/mentions',
        'api/mentions/*',
        'api/watchlists',
        'api/watchlists/*',

        // W6: campaign surfaces that exist only on the mobile namespace.
        // Written in canonical api/ form; namespace normalisation below maps
        // exec-api/* onto these so there is exactly ONE blocklist.
        'api/campaigns',
        'api/campaigns/*',
    ];

    /**
     * W6 - every customer-facing API namespace.
     *
     * The mobile app (APP888) is served from exec-api/*. Before W6 this
     * middleware only matched paths beginning api/, so the ENTIRE mobile
     * surface sat outside the perimeter - Str::is('api/social', 'exec-api/x')
     * is always false. Rather than maintain a second blocklist that will
     * drift, a request path is normalised across these namespaces and every
     * alias is tested against the single BLOCKED list above.
     */
    private const CUSTOMER_NAMESPACES = ['api', 'exec-api'];

    /**
     * Never blocked, regardless of the patterns above. Checked FIRST.
     *
     * Transactional mail and billing are explicitly protected by the accepted
     * audit. The WP plugin webhooks carry SEO data, not marketing.
     */
    private const PROTECTED = [
        'api/webhook/stripe',
        'api/webhooks/stripe',
        'api/password/*',
        'api/auth/*',
        'api/email/verify',
        'api/email/verify/*',
        'api/email/resend',
        'api/wp/*',
        'api/plugin/*',
        'api/internal/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');

        // W6: normalise the path across every customer namespace so one
        // blocklist covers api/*, exec-api/* and any future alias.
        $candidates = [$path];
        foreach (self::CUSTOMER_NAMESPACES as $ns) {
            $prefix = $ns . '/';
            if (str_starts_with($path, $prefix)) {
                $tail = substr($path, strlen($prefix));
                foreach (self::CUSTOMER_NAMESPACES as $alias) {
                    $candidates[] = $alias . '/' . $tail;
                }
                break;
            }
        }
        $candidates = array_values(array_unique($candidates));

        // Protect-list wins over everything.
        foreach (self::PROTECTED as $pattern) {
            foreach ($candidates as $candidate) {
                if (\Illuminate\Support\Str::is($pattern, $candidate)) {
                    return $next($request);
                }
            }
        }

        foreach (self::BLOCKED as $pattern) {
            $hit = false;
            foreach ($candidates as $candidate) {
                if (\Illuminate\Support\Str::is($pattern, $candidate)) { $hit = true; break; }
            }
            if (! $hit) {
                continue;
            }

            // Log at info, not warning: after the frontend is cleaned up in W6
            // these should stop appearing. Until then this is the signal that
            // tells us which surfaces the UI is still calling.
            Log::info('[launch-scope] blocked out-of-scope route', [
                'path'         => $path,
                'method'       => $request->method(),
                'workspace_id' => $request->attributes->get('workspace_id'),
                'user_id'      => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'not_in_product',
                'message' => 'This feature is not part of LevelUp Growth.',
            ], 404);
        }

        return $next($request);
    }
}
