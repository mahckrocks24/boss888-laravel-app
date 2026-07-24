<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Governance\ApprovalPolicyRegistry;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraEntitlementDefinition;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraPlanEntitlement;
use App\Engines\Infrastructure\Models\InfraProduct;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\Models\InfraSubscriptionMigration;
use App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Services\CatalogAuthoringService;
use App\Engines\Infrastructure\Services\SubscriberMigrationPlanner;
use App\Engines\Infrastructure\States\PlanState;
use App\Engines\Infrastructure\States\ProductState;
use App\Engines\Infrastructure\States\SubscriptionState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 2A-2 — catalog authoring, versioning, immutability, governance
 * registration and subscriber-migration PLANNING.
 *
 * All rows are rolled back. Nothing executes a real migration.
 */
class CatalogAuthoringTest extends TestCase
{
    use DatabaseTransactions;

    private const WS = 980001;

    private CatalogAuthoringService $catalog;
    private SubscriberMigrationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = app(CatalogAuthoringService::class);
        $this->planner = app(SubscriberMigrationPlanner::class);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();
        parent::tearDown();
    }

    private function draftProduct(): InfraProduct
    {
        return $this->catalog->createProduct([
            'slug' => 'hosting-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'name' => 'Test Managed Hosting',
            'category' => InfraProduct::CATEGORY_HOSTING,
        ], 1);
    }

    private function draftPlan(InfraProduct $product, array $overrides = []): InfraPlan
    {
        return $this->catalog->createPlan($product->id, array_merge([
            'slug' => 'tier-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'name' => 'Growth',
            'currency' => 'USD',
            'recurring_amount_minor' => 4900,
            'included_sites' => 5,
            'included_storage_mb' => 51200,
        ], $overrides), 1);
    }

    // -------------------------------------------------------- product authoring

    public function test_new_product_starts_as_draft_and_is_not_sellable(): void
    {
        $p = $this->draftProduct();

        $this->assertSame(ProductState::DRAFT, $p->lifecycle_status);
        $this->assertFalse((bool) $p->is_active);
        $this->assertNotEmpty($p->sku, 'a SKU must be generated');
    }

    public function test_product_cannot_be_published_without_a_current_plan(): void
    {
        $p = $this->draftProduct();

        $this->expectException(\RuntimeException::class);
        $this->catalog->publishProduct($p->id, 1);
    }

    public function test_product_publishes_once_it_has_a_current_plan(): void
    {
        $p = $this->draftProduct();
        $plan = $this->draftPlan($p);
        $this->catalog->publishPlan($plan->id, 1);

        $published = $this->catalog->publishProduct($p->id, 1);

        $this->assertSame(ProductState::ACTIVE, $published->lifecycle_status);
        $this->assertNotNull($published->published_at);
    }

    public function test_published_product_cannot_be_edited_as_a_draft(): void
    {
        $p = $this->draftProduct();
        $plan = $this->draftPlan($p);
        $this->catalog->publishPlan($plan->id, 1);
        $this->catalog->publishProduct($p->id, 1);

        $this->expectException(\RuntimeException::class);
        $this->catalog->updateDraftProduct($p->id, ['name' => 'Renamed'], 1);
    }

    public function test_retiring_a_product_with_subscribers_requires_a_successor(): void
    {
        $p = $this->draftProduct();
        $plan = $this->draftPlan($p);
        $this->catalog->publishPlan($plan->id, 1);
        $this->catalog->publishProduct($p->id, 1);
        $this->catalog->deprecateProduct($p->id, 1);

        WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $plan->id, 'product_id' => $p->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        // The catalog must never strand a paying customer.
        $this->expectException(\RuntimeException::class);
        $this->catalog->retireProduct($p->id, null, 1);
    }

    // ----------------------------------------------------------- plan versioning

    public function test_publishing_a_new_version_supersedes_the_previous_one(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product, ['recurring_amount_minor' => 4900]);
        $this->catalog->publishPlan($v1->id, 1);

        $v2 = $this->catalog->createPlanVersion($v1->id, ['recurring_amount_minor' => 5900], 1);
        $this->assertSame(2, (int) $v2->version);
        $this->assertSame(PlanState::DRAFT, $v2->lifecycle_status);
        $this->assertSame($v1->id, (int) $v2->derived_from_plan_id);

        $this->catalog->publishPlan($v2->id, 1);

        $v1 = $v1->fresh();
        $this->assertSame(PlanState::SUPERSEDED, $v1->lifecycle_status);
        $this->assertSame($v2->id, (int) $v1->successor_plan_id);
        // v1's price is untouched — grandfathered subscribers keep it.
        $this->assertSame(4900, (int) $v1->recurring_amount_minor);
        $this->assertSame(5900, (int) $v2->fresh()->recurring_amount_minor);
    }

    public function test_new_version_clones_entitlements(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product);

        $def = InfraEntitlementDefinition::create([
            'key' => 'staging_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'name' => 'Staging', 'value_type' => 'bool', 'default_behavior' => 'none',
        ]);
        InfraPlanEntitlement::create([
            'plan_id' => $v1->id, 'entitlement_definition_id' => $def->id, 'bool_value' => true,
        ]);

        $v2 = $this->catalog->createPlanVersion($v1->id, [], 1);

        // A "price-only" change must not silently drop the plan's features.
        $this->assertCount(1, $v2->fresh()->entitlements);
        $this->assertSame($def->id, (int) $v2->fresh()->entitlements->first()->entitlement_definition_id);
    }

    // ------------------------------------------------------------- immutability

    public function test_published_plan_cannot_be_edited(): void
    {
        $product = $this->draftProduct();
        $plan = $this->draftPlan($product);
        $this->catalog->publishPlan($plan->id, 1);

        $this->expectException(\RuntimeException::class);
        $this->catalog->updateDraftPlan($plan->id, ['recurring_amount_minor' => 9900], 1);
    }

    public function test_frozen_plan_cannot_be_edited_even_as_draft(): void
    {
        $product = $this->draftProduct();
        $plan = $this->draftPlan($product);

        // A subscriber attached -> frozen. Editing would retroactively change
        // what somebody bought.
        $this->catalog->freezePlan($plan->id);

        $this->expectException(\RuntimeException::class);
        $this->catalog->updateDraftPlan($plan->id, ['recurring_amount_minor' => 100], 1);
    }

    public function test_draft_plan_is_editable(): void
    {
        $product = $this->draftProduct();
        $plan = $this->draftPlan($product);

        $updated = $this->catalog->updateDraftPlan($plan->id, ['recurring_amount_minor' => 7900], 1);

        $this->assertSame(7900, (int) $updated->recurring_amount_minor);
    }

    public function test_immutable_identifiers_cannot_be_changed_through_authoring(): void
    {
        $product = $this->draftProduct();
        $plan = $this->draftPlan($product);
        $originalSlug = $plan->slug;
        $originalVersion = (int) $plan->version;

        // slug / version / lifecycle are not in the writable whitelist.
        $this->catalog->updateDraftPlan($plan->id, [
            'slug' => 'hijacked', 'version' => 99, 'lifecycle_status' => 'current',
        ], 1);

        $fresh = $plan->fresh();
        $this->assertSame($originalSlug, $fresh->slug);
        $this->assertSame($originalVersion, (int) $fresh->version);
        $this->assertSame(PlanState::DRAFT, $fresh->lifecycle_status);
    }

    public function test_plan_without_a_billing_term_cannot_publish(): void
    {
        $product = $this->draftProduct();
        $plan = $this->draftPlan($product, [
            'allows_monthly' => false, 'allows_annual' => false, 'allows_triennial' => false,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->publishPlan($plan->id, 1);
    }

    // ------------------------------------------------------------- governance

    public function test_all_catalog_capabilities_are_protected_and_cost_no_credits(): void
    {
        foreach (Registry::catalogOperations() as $op) {
            $meta = Registry::get($op);
            $this->assertSame('protected', $meta['approval_mode'], "{$op} must be protected");
            $this->assertSame(0, $meta['credit_cost'], "{$op} must cost zero AI credits");
            $this->assertFalse($meta['provider_mutation'], "{$op} must not mutate a provider");
        }
    }

    public function test_catalog_capabilities_are_registered_in_the_platform_capability_map(): void
    {
        $map = app(\App\Core\EngineKernel\CapabilityMapService::class);

        foreach (Registry::catalogOperations() as $op) {
            $this->assertSame('protected', $map->getApprovalMode($op), "{$op} missing from capability map");
        }
    }

    public function test_catalog_capabilities_have_an_explicit_approval_policy(): void
    {
        foreach (Registry::catalogOperations() as $op) {
            $policy = ApprovalPolicyRegistry::forCapability('infrastructure', $op, 'protected');

            // Must be deliberately classified, never falling through to the
            // strict default by accident.
            $this->assertNotSame(
                'strict_default_unclassified',
                $policy['classification'],
                "{$op} has no explicit approval classification"
            );

            // Catalog changes are platform-level: only platform admins.
            $this->assertSame([ApprovalPolicyRegistry::ROLE_PLATFORM_ADMIN], $policy['approval_roles']);
        }
    }

    // ------------------------------------------------- migration PLANNING only

    public function test_migration_assessment_reports_blast_radius(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product, ['recurring_amount_minor' => 4900, 'included_sites' => 5]);
        $this->catalog->publishPlan($v1->id, 1);
        $v2 = $this->catalog->createPlanVersion($v1->id, ['recurring_amount_minor' => 5900, 'included_sites' => 3], 1);
        $this->catalog->publishPlan($v2->id, 1);

        WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $v1->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $impact = $this->planner->assess($v1->id, $v2->id);

        $this->assertSame(1, $impact['affected_count']);
        $this->assertTrue($impact['price_increases']);
        $this->assertTrue($impact['allowances_decrease']);
        $this->assertTrue($impact['adverse']);
        $this->assertArrayHasKey('included_sites', $impact['allowance_deltas']);
        $this->assertNotEmpty($impact['warnings']);
    }

    public function test_adverse_migration_cannot_be_applied_immediately(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product, ['recurring_amount_minor' => 4900]);
        $this->catalog->publishPlan($v1->id, 1);
        $v2 = $this->catalog->createPlanVersion($v1->id, ['recurring_amount_minor' => 9900], 1);
        $this->catalog->publishPlan($v2->id, 1);

        // Customers must not be moved to worse terms mid-term.
        $this->expectException(\RuntimeException::class);
        $this->planner->plan($v1->id, $v2->id, InfraSubscriptionMigration::STRATEGY_IMMEDIATE, 'price rise', 1);
    }

    public function test_cross_currency_migration_is_refused(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product, ['currency' => 'USD']);
        $this->catalog->publishPlan($v1->id, 1);
        $other = $this->draftPlan($product, ['currency' => 'EUR']);
        $this->catalog->publishPlan($other->id, 1);

        $this->expectException(\RuntimeException::class);
        $this->planner->plan($v1->id, $other->id, InfraSubscriptionMigration::STRATEGY_AT_RENEWAL, null, 1);
    }

    public function test_migration_plan_is_recorded_but_never_executed(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product, ['recurring_amount_minor' => 4900]);
        $this->catalog->publishPlan($v1->id, 1);
        $v2 = $this->catalog->createPlanVersion($v1->id, ['recurring_amount_minor' => 4900], 1);
        $this->catalog->publishPlan($v2->id, 1);

        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $v1->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $migration = $this->planner->plan($v1->id, $v2->id, InfraSubscriptionMigration::STRATEGY_AT_RENEWAL, 'version bump', 1);

        $this->assertSame(InfraSubscriptionMigration::STATE_PLANNED, $migration->state);
        $this->assertSame(1, (int) $migration->affected_subscription_count);
        $this->assertSame(0, (int) $migration->executed_count);
        $this->assertNull($migration->executed_at);

        // Crucially: the subscription has NOT moved.
        $this->assertSame($v1->id, (int) $sub->fresh()->plan_id);
    }

    public function test_approving_a_migration_still_does_not_move_anyone(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product);
        $this->catalog->publishPlan($v1->id, 1);
        $v2 = $this->catalog->createPlanVersion($v1->id, [], 1);
        $this->catalog->publishPlan($v2->id, 1);

        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $v1->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $m = $this->planner->plan($v1->id, $v2->id, InfraSubscriptionMigration::STRATEGY_AT_RENEWAL, null, 1);
        $approved = $this->planner->markApproved($m->id, 2);

        $this->assertSame(InfraSubscriptionMigration::STATE_APPROVED, $approved->state);
        $this->assertSame(2, (int) $approved->approved_by);
        // Execution is Phase 2B+. Approval authorises; it does not act.
        $this->assertSame($v1->id, (int) $sub->fresh()->plan_id);
        $this->assertSame(0, (int) $approved->executed_count);
    }

    public function test_migration_target_cannot_be_a_draft_plan(): void
    {
        $product = $this->draftProduct();
        $v1 = $this->draftPlan($product);
        $this->catalog->publishPlan($v1->id, 1);
        $draft = $this->catalog->createPlanVersion($v1->id, [], 1);   // stays draft

        $this->expectException(\RuntimeException::class);
        $this->planner->assess($v1->id, $draft->id);
    }
}
