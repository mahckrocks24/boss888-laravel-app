<?php

namespace App\Http\Controllers\Api;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OnboardingController
{
    public function businessInfo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name'  => 'required|string|max:255',
            'industry'       => 'required|string|max:100',
            'city'           => 'required|string|max:120',
            'country'        => 'required|string|max:120',
            'website'        => 'nullable|string|max:2048',
            'primary_goal'   => 'required|string|max:64',
            'customer_type'  => 'required|string|max:64',
            'employees'      => 'nullable|string|max:32',
            'budget'         => 'nullable|string|max:32',
            'brand_color'    => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo_url'       => 'nullable|string|max:2048',
        ]);

        $wsId = $request->attributes->get('workspace_id');
        $ws = Workspace::findOrFail($wsId);

        $location = trim($data['city'] . ', ' . $data['country']);

        $existing = $ws->onboarding_data ?? [];
        $merged = array_merge(is_array($existing) ? $existing : [], [
            'business_name' => $data['business_name'],
            'industry'      => $data['industry'],
            'city'          => $data['city'],
            'country'       => $data['country'],
            'website'       => $data['website'] ?? null,
            'primary_goal'  => $data['primary_goal'],
            'customer_type' => $data['customer_type'],
            'employees'     => $data['employees'] ?? null,
            'budget'        => $data['budget'] ?? null,
            'saved_at'      => now()->toIso8601String(),
        ]);

        $ws->update([
            'business_name'   => $data['business_name'],
            'industry'        => $data['industry'],
            'location'        => $location,
            'goal'            => $data['primary_goal'],
            'brand_color'     => $data['brand_color'] ?? null,
            'logo_url'        => $data['logo_url'] ?? null,
            'onboarding_step' => 3,
            'onboarding_data' => $merged,
        ]);

        // Kick Sarah's initial analysis — async, non-blocking.
        // Proactive proposal is template-based, zero credits.
        $proposal = null;
        try {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            $proposal = $proactive->onOnboardingComplete($ws->id, $request->user()->id);
        } catch (\Throwable $e) {
            Log::warning('Sarah initial analysis failed at Step 2', [
                'workspace_id' => $ws->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success'   => true,
            'workspace' => [
                'id'            => $ws->id,
                'business_name' => $ws->business_name,
                'industry'      => $ws->industry,
                'location'      => $ws->location,
                'brand_color'   => $ws->brand_color,
                'logo_url'      => $ws->logo_url,
            ],
            'next_step' => 3,
            'proposal'  => $proposal,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $wsId = $request->attributes->get('workspace_id');
        $ws = Workspace::findOrFail($wsId);

        if ((bool) $ws->onboarded) {
            return response()->json([
                'step'           => 'complete',
                'workspace_data' => [
                    'id'            => $ws->id,
                    'business_name' => $ws->business_name,
                    'industry'      => $ws->industry,
                    'location'      => $ws->location,
                ],
            ]);
        }

        $step = (int) ($ws->onboarding_step ?? 1);
        if ($step < 1) $step = 1;
        if ($step > 3) $step = 3;

        return response()->json([
            'step'           => $step,
            'workspace_data' => [
                'id'            => $ws->id,
                'business_name' => $ws->business_name,
                'industry'      => $ws->industry,
                'location'      => $ws->location,
                'brand_color'   => $ws->brand_color,
                'logo_url'      => $ws->logo_url,
                'onboarding_data' => $ws->onboarding_data,
            ],
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $wsId = $request->attributes->get('workspace_id');
        $ws = Workspace::findOrFail($wsId);

        $ws->update([
            'onboarded'       => true,
            'onboarded_at'    => now(),
            'onboarding_step' => 4,
        ]);

        // Additional specialist agents (James, Priya, Marcus, Elena, Alex)
        // unlock on paid plans. Free workspaces keep Sarah only. Paid-plan
        // attach is handled by the Stripe webhook on subscription upgrade
        // — not wired here, per the hands-vs-brain split.

        return response()->json([
            'success'  => true,
            'redirect' => 'dashboard',
        ]);
    }
}
