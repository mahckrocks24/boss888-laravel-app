<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraEntitlementDefinition;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraPlanEntitlement;
use App\Engines\Infrastructure\Models\InfraProduct;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\States\PlanState;
use App\Engines\Infrastructure\States\ProductState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Catalog authoring — the write half of the commercial operating system.
 *
 * GOVERNANCE MODEL
 * ----------------
 * Not every catalog change carries the same risk, so not every change is
 * approval-gated:
 *
 *   DRAFT work is ungoverned. A draft product or plan is not sellable, has no
 *   subscribers and cannot affect a customer. Requiring approval to type a name
 *   into a draft would make the catalog unusable without improving safety.
 *
 *   PUBLISHING and RETIRING are approval-gated (`protected` capabilities). These
 *   are the moments a change becomes commercially real: a plan becomes sellable,
 *   or a product stops being sold.
 *
 * IMMUTABILITY
 * ------------
 * Three progressively stricter locks:
 *
 *   1. Commercial identity (slug, SKU, currency, term availability) locks at
 *      PUBLISH. Changing a published plan's currency is a different product.
 *   2. Everything locks at FREEZE — the moment a subscriber attaches. Editing
 *      price or allowances then would retroactively change what someone bought.
 *   3. Version numbers and lineage are never editable.
 *
 * The escape hatch is always the same: create a NEW VERSION. That is what plan
 * versioning is for, and it is why subscribers can be grandfathered.
 */
class CatalogAuthoringService
{
    public function __construct(private readonly InfraEventRecorder $events)
    {
    }

    // ------------------------------------------------------------- products

    /** Create a DRAFT product. Ungoverned: drafts are not sellable. */
    public function createProduct(array $data, ?int $actorUserId = null): InfraProduct
    {
        $slug = $this->requireSlug($data['slug'] ?? null);

        if (InfraProduct::where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("A product with slug '{$slug}' already exists.");
        }

        $category = (string) ($data['category'] ?? '');
        if (!in_array($category, InfraProduct::categories(), true)) {
            throw new InvalidArgumentException('Unknown product category.');
        }

        return InfraProduct::create([
            'slug'             => $slug,
            'name'             => $this->requireName($data['name'] ?? null),
            'category'         => $category,
            'description'      => $data['description'] ?? null,
            'sku'              => $data['sku'] ?? $this->generateSku($category, $slug),
            'version'          => 1,
            'lifecycle_status' => ProductState::DRAFT,
            'visibility'       => $data['visibility'] ?? 'public',
            'is_active'        => false,
            'sort_order'       => (int) ($data['sort_order'] ?? 0),
            'created_by'       => $actorUserId,
        ]);
    }

    /** Edit a DRAFT product. Refused once published. */
    public function updateDraftProduct(int $productId, array $data, ?int $actorUserId = null): InfraProduct
    {
        $product = $this->findProduct($productId);

        if ($product->lifecycle_status !== ProductState::DRAFT) {
            throw new RuntimeException(
                'Only draft products can be edited. Published products change by versioning.'
            );
        }

        // slug and sku are commercial identifiers — immutable from creation.
        foreach (['name', 'description', 'visibility', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = $data[$field];
            }
        }

        $product->save();

        return $product;
    }

    /** APPROVAL-GATED. Makes a product sellable. */
    public function publishProduct(int $productId, ?int $actorUserId = null): InfraProduct
    {
        $product = $this->findProduct($productId);

        ProductState::assertTransition((string) $product->lifecycle_status, ProductState::ACTIVE);

        if (!$product->plans()->where('lifecycle_status', PlanState::CURRENT)->exists()) {
            throw new RuntimeException('A product cannot be published with no current plan.');
        }

        $from = $product->lifecycle_status;
        $product->lifecycle_status = ProductState::ACTIVE;
        $product->is_active = true;
        $product->published_at = $product->published_at ?: now();
        $product->save();

        $this->recordCatalogEvent('product_published', $product->id, $from, ProductState::ACTIVE, $actorUserId, [
            'product_slug' => $product->slug,
            'sku'          => $product->sku,
        ]);

        return $product;
    }

    /** APPROVAL-GATED. Stops new sales; existing subscribers keep being served. */
    public function deprecateProduct(int $productId, ?int $actorUserId = null): InfraProduct
    {
        $product = $this->findProduct($productId);

        ProductState::assertTransition((string) $product->lifecycle_status, ProductState::DEPRECATED);

        $from = $product->lifecycle_status;
        $product->lifecycle_status = ProductState::DEPRECATED;
        $product->is_active = false;
        $product->save();

        $this->recordCatalogEvent('product_deprecated', $product->id, $from, ProductState::DEPRECATED, $actorUserId);

        return $product;
    }

    /**
     * APPROVAL-GATED. Retiring a product with active subscribers REQUIRES a
     * successor — the catalog must never strand a paying customer.
     */
    public function retireProduct(int $productId, ?int $successorProductId = null, ?int $actorUserId = null): InfraProduct
    {
        $product = $this->findProduct($productId);

        ProductState::assertTransition((string) $product->lifecycle_status, ProductState::RETIRED);

        $activeSubscribers = $this->activeSubscriberCountForProduct($productId);

        if ($activeSubscribers > 0) {
            if (!$successorProductId) {
                throw new RuntimeException(
                    "This product has {$activeSubscribers} active subscription(s). "
                    . 'Retiring it requires a successor product so no customer is stranded.'
                );
            }

            $successor = $this->findProduct($successorProductId);

            if (!in_array($successor->lifecycle_status, ProductState::serviceable(), true)) {
                throw new RuntimeException('The successor product must itself be active or deprecated.');
            }

            $product->successor_product_id = $successor->id;
        }

        $from = $product->lifecycle_status;
        $product->lifecycle_status = ProductState::RETIRED;
        $product->is_active = false;
        $product->retired_at = now();
        $product->save();

        $this->recordCatalogEvent('product_retired', $product->id, $from, ProductState::RETIRED, $actorUserId, [
            'active_subscribers'   => $activeSubscribers,
            'successor_product_id' => $product->successor_product_id,
        ]);

        return $product;
    }

    // ---------------------------------------------------------------- plans

    /** Create a DRAFT plan (version 1). Ungoverned. */
    public function createPlan(int $productId, array $data, ?int $actorUserId = null): InfraPlan
    {
        $product = $this->findProduct($productId);
        $slug = $this->requireSlug($data['slug'] ?? null);

        if (InfraPlan::where('slug', $slug)->where('version', 1)->exists()) {
            throw new InvalidArgumentException("A plan with slug '{$slug}' version 1 already exists.");
        }

        return InfraPlan::create(array_merge($this->planDefaults(), $this->sanitisePlanInput($data), [
            'product_id'       => $product->id,
            'slug'             => $slug,
            'version'          => 1,
            'lifecycle_status' => PlanState::DRAFT,
            'is_current'       => false,
            'is_public'        => (bool) ($data['is_public'] ?? true),
            'created_by'       => $actorUserId,
        ]));
    }

    /** Edit a DRAFT plan. Refused once published or frozen. */
    public function updateDraftPlan(int $planId, array $data, ?int $actorUserId = null): InfraPlan
    {
        $plan = $this->findPlan($planId);
        $this->assertEditable($plan);

        foreach ($this->sanitisePlanInput($data) as $field => $value) {
            $plan->{$field} = $value;
        }

        $plan->save();

        return $plan;
    }

    /**
     * Create the NEXT VERSION of a plan by cloning it, including entitlements.
     * This is the ONLY way to change a published plan's commercial terms.
     * Ungoverned — the result is a draft. Publishing it is governed.
     */
    public function createPlanVersion(int $planId, array $changes = [], ?int $actorUserId = null): InfraPlan
    {
        $source = $this->findPlan($planId);

        $nextVersion = (int) InfraPlan::where('slug', $source->slug)->max('version') + 1;

        return DB::transaction(function () use ($source, $nextVersion, $changes, $actorUserId) {
            $clone = $source->replicate([
                'created_at', 'updated_at', 'published_at', 'frozen_at', 'withdrawn_at',
            ]);

            $clone->version             = $nextVersion;
            $clone->lifecycle_status    = PlanState::DRAFT;
            $clone->is_current          = false;
            $clone->derived_from_plan_id= $source->id;
            $clone->successor_plan_id   = null;
            $clone->created_by          = $actorUserId;
            $clone->published_at        = null;
            $clone->frozen_at           = null;
            $clone->withdrawn_at        = null;

            foreach ($this->sanitisePlanInput($changes) as $field => $value) {
                $clone->{$field} = $value;
            }

            $clone->save();

            // Entitlements travel with the version, or a "price-only" change
            // would silently drop the plan's features.
            foreach ($source->entitlements as $ent) {
                InfraPlanEntitlement::create([
                    'plan_id'                   => $clone->id,
                    'entitlement_definition_id' => $ent->entitlement_definition_id,
                    'bool_value'                => $ent->bool_value,
                    'int_value'                 => $ent->int_value,
                    'string_value'              => $ent->string_value,
                    'behavior'                  => $ent->behavior,
                    'addon_amount_minor'        => $ent->addon_amount_minor,
                    'addon_currency'            => $ent->addon_currency,
                ]);
            }

            return $clone;
        });
    }

    /**
     * APPROVAL-GATED. Makes a plan version sellable and supersedes the previous
     * current version. Existing subscribers stay on what they bought.
     */
    public function publishPlan(int $planId, ?int $actorUserId = null): InfraPlan
    {
        $plan = $this->findPlan($planId);

        PlanState::assertTransition((string) $plan->lifecycle_status, PlanState::CURRENT);
        $this->assertPlanIsPublishable($plan);

        return DB::transaction(function () use ($plan, $actorUserId) {
            $previous = InfraPlan::where('slug', $plan->slug)
                ->where('id', '!=', $plan->id)
                ->where('lifecycle_status', PlanState::CURRENT)
                ->get();

            foreach ($previous as $old) {
                $old->lifecycle_status  = PlanState::SUPERSEDED;
                $old->is_current        = false;
                $old->successor_plan_id = $plan->id;
                $old->save();
            }

            $from = $plan->lifecycle_status;
            $plan->lifecycle_status = PlanState::CURRENT;
            $plan->is_current       = true;
            $plan->published_at     = $plan->published_at ?: now();
            $plan->save();

            $this->recordCatalogEvent('plan_published', $plan->id, $from, PlanState::CURRENT, $actorUserId, [
                'plan_slug'        => $plan->slug,
                'version'          => $plan->version,
                'superseded_count' => $previous->count(),
                'currency'         => $plan->currency,
                'amount_minor'     => (int) $plan->recurring_amount_minor,
            ]);

            return $plan;
        });
    }

    /**
     * APPROVAL-GATED. Withdrawing a plan with subscribers requires a successor.
     */
    public function withdrawPlan(int $planId, ?int $successorPlanId = null, ?int $actorUserId = null): InfraPlan
    {
        $plan = $this->findPlan($planId);

        PlanState::assertTransition((string) $plan->lifecycle_status, PlanState::WITHDRAWN);

        $subscribers = $this->activeSubscriberCountForPlan($planId);

        if ($subscribers > 0) {
            if (!$successorPlanId) {
                throw new RuntimeException(
                    "This plan version has {$subscribers} active subscription(s). "
                    . 'Withdrawing it requires a successor plan so no customer is stranded.'
                );
            }

            $successor = $this->findPlan($successorPlanId);

            if (!in_array($successor->lifecycle_status, PlanState::resolvable(), true)) {
                throw new RuntimeException('The successor plan must be current or superseded.');
            }

            $plan->successor_plan_id = $successor->id;
        }

        $from = $plan->lifecycle_status;
        $plan->lifecycle_status = PlanState::WITHDRAWN;
        $plan->is_current       = false;
        $plan->is_public        = false;
        $plan->withdrawn_at     = now();
        $plan->save();

        $this->recordCatalogEvent('plan_withdrawn', $plan->id, $from, PlanState::WITHDRAWN, $actorUserId, [
            'active_subscribers' => $subscribers,
            'successor_plan_id'  => $plan->successor_plan_id,
        ]);

        return $plan;
    }

    /**
     * Freeze a plan version because a subscriber attached. Called by the
     * subscription flow; idempotent.
     */
    public function freezePlan(int $planId): void
    {
        $plan = InfraPlan::find($planId);

        if ($plan && !$plan->frozen_at) {
            $plan->frozen_at = now();
            $plan->save();
        }
    }

    // ------------------------------------------------------------ internals

    /** Editable only while DRAFT and never after a subscriber attached. */
    private function assertEditable(InfraPlan $plan): void
    {
        if ($plan->frozen_at) {
            throw new RuntimeException(
                'This plan version has subscribers and is immutable. Create a new version instead.'
            );
        }

        if ($plan->lifecycle_status !== PlanState::DRAFT) {
            throw new RuntimeException(
                'Only draft plans can be edited. Published plans change by versioning.'
            );
        }
    }

    private function assertPlanIsPublishable(InfraPlan $plan): void
    {
        if ((int) $plan->recurring_amount_minor < 0) {
            throw new InvalidArgumentException('Recurring amount cannot be negative.');
        }

        if (!preg_match('/^[A-Z]{3}$/', (string) $plan->currency)) {
            throw new InvalidArgumentException('A valid three-letter currency is required.');
        }

        if ($plan->availableTerms() === []) {
            throw new InvalidArgumentException('A plan must allow at least one billing term.');
        }

        $product = $this->findProduct((int) $plan->product_id);

        if ($product->lifecycle_status === ProductState::RETIRED) {
            throw new RuntimeException('Cannot publish a plan under a retired product.');
        }
    }

    /**
     * Whitelist of writable plan fields. Anything absent here — version, slug,
     * lifecycle, frozen_at, lineage — is not settable through authoring at all.
     */
    private function sanitisePlanInput(array $data): array
    {
        $allowed = [
            'name', 'description', 'currency', 'region', 'recurring_amount_minor',
            'billing_period', 'setup_fee_minor', 'migration_fee_minor', 'renewal_amount_minor',
            'allows_monthly', 'allows_annual', 'allows_triennial',
            'included_sites', 'included_storage_mb', 'included_bandwidth_mb',
            'included_mailboxes', 'included_domains', 'backup_retention_days',
            'included_staging_environments', 'included_restores_per_month', 'included_migrations',
            'overage_storage_minor_per_gb', 'overage_bandwidth_minor_per_gb',
            'compute_class', 'monitoring_level', 'support_tier',
            'is_public', 'sort_order', 'features_json', 'metadata_json',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    private function planDefaults(): array
    {
        return [
            'currency'               => 'USD',
            'recurring_amount_minor' => 0,
            'billing_period'         => 'monthly',
            'setup_fee_minor'        => 0,
            'migration_fee_minor'    => 0,
            'allows_monthly'         => true,
            'allows_annual'          => true,
            'allows_triennial'       => false,
            'support_tier'           => 'standard',
            'sort_order'             => 0,
        ];
    }

    private function generateSku(string $category, string $slug): string
    {
        return strtoupper(substr($category, 0, 4)) . '-' . strtoupper(str_replace('-', '', substr($slug, 0, 12)))
             . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    private function requireSlug(?string $slug): string
    {
        $slug = trim((string) $slug);

        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,62}$/', $slug)) {
            throw new InvalidArgumentException('Slug must be lowercase alphanumeric with hyphens, 2-63 characters.');
        }

        return $slug;
    }

    private function requireName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('A name between 1 and 120 characters is required.');
        }

        return $name;
    }

    private function findProduct(int $id): InfraProduct
    {
        $p = InfraProduct::find($id);

        if (!$p) {
            throw new RuntimeException('Product not found.');
        }

        return $p;
    }

    private function findPlan(int $id): InfraPlan
    {
        $p = InfraPlan::with('entitlements')->find($id);

        if (!$p) {
            throw new RuntimeException('Plan not found.');
        }

        return $p;
    }

    public function activeSubscriberCountForPlan(int $planId): int
    {
        return InfraSubscription::withoutWorkspaceScope()
            ->where('plan_id', $planId)
            ->whereIn('state', \App\Engines\Infrastructure\States\SubscriptionState::entitled())
            ->count();
    }

    public function activeSubscriberCountForProduct(int $productId): int
    {
        return InfraSubscription::withoutWorkspaceScope()
            ->where('product_id', $productId)
            ->whereIn('state', \App\Engines\Infrastructure\States\SubscriptionState::entitled())
            ->count();
    }

    /**
     * Catalog changes are platform-level, not workspace-owned, so they are
     * recorded to the platform audit log rather than infra_events (which is
     * workspace-scoped).
     */
    private function recordCatalogEvent(
        string $event, int $entityId, ?string $from, ?string $to, ?int $actorUserId, array $context = []
    ): void {
        try {
            app(\App\Core\Audit\AuditLogService::class)->log(
                null, $actorUserId, "infrastructure.catalog.{$event}", 'infra_catalog', $entityId,
                array_merge(['from' => $from, 'to' => $to], $this->events->redact($context))
            );
        } catch (\Throwable) {
            // Audit must never block a catalog operation, but the failure is
            // surfaced by the audit service's own logging.
        }
    }
}
