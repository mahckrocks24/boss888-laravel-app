<?php

namespace App\Engines\Infrastructure\Http\Controllers;

use App\Engines\Infrastructure\Models\InfraEntitlementDefinition;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraProduct;
use App\Engines\Infrastructure\Services\EntitlementResolver;
use App\Engines\Infrastructure\States\PlanState;
use App\Engines\Infrastructure\States\ProductState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INTERNAL, READ-ONLY commercial catalog inspection (Phase 2A-1 §10).
 *
 * Purpose: validate the commercial model. Not a customer surface, not a UI
 * backend, and deliberately without any write endpoint — product/plan authoring
 * is Phase 2A-2 and needs approval governance of its own.
 *
 * Gated behind auth.jwt + admin (platform administrator), and the route group
 * also carries DenyApiKeyAuth so neither an API key nor the shared admin token
 * can read the catalog.
 */
class InfrastructureCatalogController
{
    public function __construct(private readonly EntitlementResolver $entitlements)
    {
    }

    /** GET /api/admin/infrastructure/catalog/products */
    public function products(Request $r): JsonResponse
    {
        $products = InfraProduct::query()
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $products->map(fn ($p) => [
                    'id'               => $p->id,
                    'sku'              => $p->sku,
                    'slug'             => $p->slug,
                    'name'             => $p->name,
                    'category'         => $p->category,
                    'version'          => (int) $p->version,
                    'lifecycle_status' => $p->lifecycle_status,
                    'visibility'       => $p->visibility,
                    'sellable'         => in_array($p->lifecycle_status, ProductState::sellable(), true),
                    'serviceable'      => in_array($p->lifecycle_status, ProductState::serviceable(), true),
                    'successor_product_id' => $p->successor_product_id,
                    'available_from'   => optional($p->available_from)->toIso8601String(),
                    'available_until'  => optional($p->available_until)->toIso8601String(),
                ])->values()->all(),
                'total' => $products->count(),
            ],
        ]);
    }

    /** GET /api/admin/infrastructure/catalog/plans */
    public function plans(Request $r): JsonResponse
    {
        $query = InfraPlan::query()->with(['product:id,slug,name', 'entitlements.definition']);

        if ($r->filled('product_id')) {
            $query->where('product_id', (int) $r->input('product_id'));
        }

        // Version coexistence: by default show ALL versions, because
        // grandfathered subscribers live on superseded ones.
        if ($r->boolean('current_only')) {
            $query->where('lifecycle_status', PlanState::CURRENT);
        }

        $plans = $query->orderBy('product_id')->orderBy('slug')->orderBy('version')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $plans->map(fn ($p) => $this->presentPlan($p))->values()->all(),
                'total' => $plans->count(),
            ],
        ]);
    }

    /** GET /api/admin/infrastructure/catalog/plans/{id} */
    public function plan(Request $r, int $id): JsonResponse
    {
        $plan = InfraPlan::query()->with(['product:id,slug,name', 'entitlements.definition'])->find($id);

        if (!$plan) {
            return response()->json(['success' => false, 'error' => 'Not found', 'code' => 'NOT_FOUND'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->presentPlan($plan, true)]);
    }

    /** GET /api/admin/infrastructure/catalog/entitlement-definitions */
    public function entitlementDefinitions(Request $r): JsonResponse
    {
        $defs = InfraEntitlementDefinition::query()
            ->orderBy('category')->orderBy('sort_order')->orderBy('key')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $defs->map(fn ($d) => [
                    'id'               => $d->id,
                    'key'              => $d->key,
                    'name'             => $d->name,
                    'category'         => $d->category,
                    'value_type'       => $d->value_type,
                    'unit'             => $d->unit,
                    'allowed_values'   => $d->allowed_values,
                    'default_behavior' => $d->default_behavior,
                    'is_active'        => (bool) $d->is_active,
                ])->values()->all(),
                'total' => $defs->count(),
            ],
        ]);
    }

    /**
     * GET /api/admin/infrastructure/catalog/subscriptions/{id}/entitlements
     *
     * Resolves EFFECTIVE entitlements for one subscription, honouring
     * grandfathering. Requires an explicit workspace_id because this is a
     * platform-admin surface reading across tenants; the resolver still runs
     * inside that workspace's context.
     */
    public function subscriptionEntitlements(Request $r, int $id): JsonResponse
    {
        $wsId = (int) $r->input('workspace_id');

        if ($wsId <= 0) {
            return response()->json([
                'success' => false,
                'error'   => 'workspace_id is required.',
                'code'    => 'VALIDATION_FAILED',
            ], 422);
        }

        try {
            return response()->json([
                'success' => true,
                'data'    => $this->entitlements->forSubscription($wsId, $id),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Subscription entitlements could not be resolved.',
                'code'    => 'NOT_FOUND',
            ], 404);
        }
    }

    private function presentPlan(InfraPlan $p, bool $detailed = false): array
    {
        $base = [
            'id'               => $p->id,
            'product'          => $p->product?->slug,
            'slug'             => $p->slug,
            'name'             => $p->name,
            'version'          => (int) $p->version,
            'lifecycle_status' => $p->lifecycle_status,
            'is_current'       => (bool) $p->is_current,
            'is_public'        => (bool) $p->is_public,
            'region'           => $p->region,
            'currency'         => $p->currency,
            'recurring_amount_minor' => (int) $p->recurring_amount_minor,
            'available_terms'  => $p->availableTerms(),
            'compute_class'    => $p->compute_class,
            'support_tier'     => $p->support_tier,
            'successor_plan_id'=> $p->successor_plan_id,
        ];

        if (!$detailed) {
            return $base;
        }

        return $base + [
            'allowances' => $this->entitlements->allowances($p),
            'features'   => $this->entitlements->features($p),
            'fees' => [
                'setup_fee_minor'     => (int) $p->setup_fee_minor,
                'migration_fee_minor' => (int) $p->migration_fee_minor,
                'renewal_amount_minor'=> $p->renewalAmountMinor(),
            ],
        ];
    }
}
