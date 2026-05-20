<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\PlanGating\PlanGatingService;

/**
 * Wave 47 — AEO Plan Gate.
 *
 * Gates AEO premium endpoints (enrichment, settings, llms.txt control) to
 * workspaces on $69+ plans (WP Bundle, Growth, Pro, Agency, and the higher
 * WP variants). Lower tiers see a 402 with an upgrade prompt.
 *
 * Audit endpoints (read-only scoring) are intentionally NOT gated — they're
 * free for all tiers as the upsell hook (you can see what's missing; pay
 * to fix it).
 */
class AeoPlanGate
{
    private const MIN_PRICE_FOR_AEO = 69.00;

    public function __construct(private PlanGatingService $planGating) {}

    public function handle(Request $request, Closure $next)
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if (!$wsId) {
            return response()->json([
                'success' => false,
                'error' => 'workspace_required',
                'message' => 'No workspace context for AEO request',
            ], 401);
        }

        $rules = $this->planGating->getPlanRules($wsId);
        $planSlug = $rules['plan_slug'] ?? 'free';

        // Resolve plan price to compare against the $69 floor.
        $price = (float) \Illuminate\Support\Facades\DB::table('plans')
            ->where('slug', $planSlug)
            ->value('price') ?: 0.0;

        if ($price < self::MIN_PRICE_FOR_AEO) {
            return response()->json([
                'success' => false,
                'error' => 'aeo_plan_required',
                'message' => 'AEO Mode requires WP Bundle ($69) or higher. Free, Starter, and AI Lite tiers can view the AEO audit but cannot enrich content or change crawler settings.',
                'current_plan' => $rules['plan_name'] ?? 'Free',
                'current_plan_slug' => $planSlug,
                'required_price' => self::MIN_PRICE_FOR_AEO,
                'upgrade_url' => '/app/#billing',
            ], 402);
        }

        return $next($request);
    }
}
