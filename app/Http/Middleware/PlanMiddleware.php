<?php

namespace App\Http\Middleware;

use App\Core\Billing\FeatureGateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PlanMiddleware
 *
 * Route-level plan enforcement — belt-and-suspenders on top of
 * EngineExecutionService Step 2.
 *
 * Used on route groups that require a specific plan tier:
 *   'plan:app888'   — Pro+ (companion_app = true)
 *   'plan:ai'       — AI Lite+ (ai_access = research or full)
 *   'plan:full_ai'  — Growth+ (ai_access = full)
 *   'plan:agent'    — Growth+ (includes_dmm = true)
 *   'plan:api'      — Pro+ (api_access feature)
 *
 * Returns structured 403 with upgrade path so the client can display
 * the correct upgrade prompt.
 */
class PlanMiddleware
{
    public function __construct(
        private FeatureGateService $gate,
    ) {}

    public function handle(Request $request, Closure $next, string $requirement = 'ai'): Response
    {
        $wsId = (int) $request->attributes->get('workspace_id');

        if (!$wsId) {
            // No workspace context — let the auth middleware handle it
            return $next($request);
        }

        [$allowed, $requiredPlan, $message] = $this->check($wsId, $requirement);

        if (!$allowed) {
            return response()->json([
                'success'       => false,
                'error'         => $message,
                'code'          => 'PLAN_UPGRADE_REQUIRED',
                'required_plan' => $requiredPlan,
                'upgrade_url'   => '/billing',
            ], 403);
        }

        return $next($request);
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function check(int $wsId, string $requirement): array
    {
        return match ($requirement) {
            'app888'   => $this->gate->canUseApp888($wsId)
                ? [true, null, null]
                : [false, 'pro', 'Mobile companion app requires Pro plan or above'],

            'ai'       => $this->gate->canUseAI($wsId)
                ? [true, null, null]
                : [false, 'ai-lite', 'AI features require AI Lite plan or above'],

            'full_ai'  => $this->checkFullAI($wsId),

            'agent'    => $this->gate->canDispatchAgent($wsId)
                ? [true, null, null]
                : [false, 'growth', 'AI agents require Growth plan or above'],

            'video'    => $this->gate->canUseVideo($wsId)
                ? [true, null, null]
                : [false, 'pro', 'Video generation requires Pro plan or above'],

            'api'      => $this->checkApiAccess($wsId),

            default    => [true, null, null],
        };
    }

    private function checkFullAI(int $wsId): array
    {
        $caps = $this->gate->getCapabilities($wsId);
        $allowed = $caps['engines']['write']['ai'] ?? false;
        return $allowed
            ? [true, null, null]
            : [false, 'growth', 'Content generation requires Growth plan or above'];
    }

    private function checkApiAccess(int $wsId): array
    {
        $caps = $this->gate->getCapabilities($wsId);
        $allowed = $caps['features']['api_access'] ?? false;
        return $allowed
            ? [true, null, null]
            : [false, 'pro', 'API access requires Pro plan or above'];
    }
}
