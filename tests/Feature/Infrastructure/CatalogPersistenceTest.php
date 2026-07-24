<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraEntitlementDefinition;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraPlanEntitlement;
use App\Engines\Infrastructure\Models\InfraProduct;
use App\Engines\Infrastructure\Models\InfraRenewal;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\Models\InfraUsageRecord;
use App\Engines\Infrastructure\Services\EntitlementResolver;
use App\Engines\Infrastructure\States\RenewalState;
use App\Engines\Infrastructure\States\SubscriptionState;
use App\Engines\Infrastructure\States\UsageBehavior;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 2A-1 persistence: catalog, versioning, entitlement resolution, usage
 * recording and renewal transitions against a real database.
 *
 * Uses DatabaseTransactions (not RefreshDatabase) consistent with the other
 * INFRA888 DB tests; every row created here is rolled back.
 *
 * NOTE: this does NOT test enforcement. Phase 2A-1 records and resolves; usage
 * enforcement is Phase 2A-4.
 */
class CatalogPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    private const WS = 970001;

    protected function tearDown(): void
    {
        WorkspaceContext::reset();
        parent::tearDown();
    }

    private function product(array $attrs = []): InfraProduct
    {
        return InfraProduct::create(array_merge([
            'slug'             => 'test-hosting-' . uniqid(),
            'name'             => 'Test Managed Hosting',
            'category'         => InfraProduct::CATEGORY_HOSTING,
            'sku'              => 'SKU-' . strtoupper(uniqid()),
            'version'          => 1,
            'lifecycle_status' => 'active',
            'visibility'       => 'public',
        ], $attrs));
    }

    private function plan(int $productId, int $version = 1, array $attrs = []): InfraPlan
    {
        return InfraPlan::create(array_merge([
            'product_id'             => $productId,
            'slug'                   => 'managed-business',
            'name'                   => 'Managed Business',
            'version'                => $version,
            'is_current'             => true,
            'is_public'              => true,
            'lifecycle_status'       => 'current',
            'currency'               => 'USD',
            'recurring_amount_minor' => 4900,
            'billing_period'         => 'monthly',
            'allows_monthly'         => true,
            'allows_annual'          => true,
            'allows_triennial'       => false,
            'included_sites'         => 5,
            'included_storage_mb'    => 51200,
            'included_bandwidth_mb'  => 512000,
            'included_mailboxes'     => 5,
            'included_domains'       => 1,
            'backup_retention_days'  => 30,
            'included_staging_environments' => 1,
            'included_restores_per_month'   => 3,
            'included_migrations'    => 1,
            'compute_class'          => 'shared-standard',
            'monitoring_level'       => 'uptime_perf',
            'support_tier'           => 'standard',
        ], $attrs));
    }

    // ------------------------------------------------------------- catalog

    public function test_product_persists_with_lifecycle_fields(): void
    {
        $p = $this->product();

        $this->assertSame(1, (int) $p->version);
        $this->assertSame('active', $p->lifecycle_status);
        $this->assertSame('public', $p->visibility);
        $this->assertNotNull($p->sku);
    }

    public function test_product_sku_is_unique(): void
    {
        $sku = 'SKU-DUPLICATE-' . uniqid();
        $this->product(['sku' => $sku]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->product(['sku' => $sku]);
    }

    public function test_plan_persists_money_in_minor_units_with_currency(): void
    {
        $plan = $this->plan($this->product()->id);

        // No decimal money anywhere in the commercial model.
        $this->assertIsInt((int) $plan->recurring_amount_minor);
        $this->assertSame(4900, (int) $plan->recurring_amount_minor);
        $this->assertSame('USD', $plan->currency);
    }

    public function test_plan_reports_available_terms(): void
    {
        $plan = $this->plan($this->product()->id, 1, [
            'allows_monthly' => false, 'allows_annual' => true, 'allows_triennial' => true,
        ]);

        $this->assertEqualsCanonicalizing(['annual', 'triennial'], $plan->availableTerms());
    }

    // -------------------------------------------------- version coexistence

    public function test_multiple_plan_versions_coexist(): void
    {
        $product = $this->product();

        $v1 = $this->plan($product->id, 1, ['recurring_amount_minor' => 4900, 'lifecycle_status' => 'superseded', 'is_current' => false]);
        $v2 = $this->plan($product->id, 2, ['recurring_amount_minor' => 5900, 'lifecycle_status' => 'current']);

        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame(4900, (int) $v1->fresh()->recurring_amount_minor, 'v1 price must not change when v2 is published');
        $this->assertSame(5900, (int) $v2->fresh()->recurring_amount_minor);

        $all = InfraPlan::where('product_id', $product->id)->where('slug', 'managed-business')->get();
        $this->assertCount(2, $all);
    }

    // ---------------------------------------------------- entitlement model

    public function test_entitlement_definition_and_typed_values(): void
    {
        $product = $this->product();
        $plan = $this->plan($product->id);

        $boolDef = InfraEntitlementDefinition::create([
            'key' => 'premium_monitoring_' . uniqid(), 'name' => 'Premium monitoring',
            'value_type' => InfraEntitlementDefinition::TYPE_BOOL, 'default_behavior' => 'none',
        ]);
        $intDef = InfraEntitlementDefinition::create([
            'key' => 'malware_scans_' . uniqid(), 'name' => 'Malware scans', 'unit' => 'count',
            'value_type' => InfraEntitlementDefinition::TYPE_INT, 'default_behavior' => 'soft',
        ]);

        $peBool = InfraPlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_definition_id' => $boolDef->id, 'bool_value' => true,
        ]);
        $peInt = InfraPlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_definition_id' => $intDef->id, 'int_value' => 12,
            'behavior' => UsageBehavior::BILLABLE,
        ]);

        // Values resolve according to the DECLARED type, not by guessing.
        $this->assertTrue($peBool->fresh()->load('definition')->value());
        $this->assertSame(12, $peInt->fresh()->load('definition')->value());

        // Plan-level behaviour overrides the definition default.
        $this->assertSame('none', $peBool->fresh()->load('definition')->effectiveBehavior());
        $this->assertSame(UsageBehavior::BILLABLE, $peInt->fresh()->load('definition')->effectiveBehavior());
    }

    public function test_same_entitlement_cannot_be_attached_twice_to_a_plan(): void
    {
        $plan = $this->plan($this->product()->id);
        $def = InfraEntitlementDefinition::create([
            'key' => 'staging_addon_' . uniqid(), 'name' => 'Staging add-on',
            'value_type' => InfraEntitlementDefinition::TYPE_BOOL, 'default_behavior' => 'none',
        ]);

        InfraPlanEntitlement::create(['plan_id' => $plan->id, 'entitlement_definition_id' => $def->id, 'bool_value' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        InfraPlanEntitlement::create(['plan_id' => $plan->id, 'entitlement_definition_id' => $def->id, 'bool_value' => false]);
    }

    // ------------------------------------------------ entitlement resolution

    public function test_resolver_returns_allowances_and_features(): void
    {
        $product = $this->product();
        $plan = $this->plan($product->id);

        $def = InfraEntitlementDefinition::create([
            'key' => 'priority_restore_' . uniqid(), 'name' => 'Priority restore',
            'value_type' => InfraEntitlementDefinition::TYPE_BOOL, 'default_behavior' => 'none',
        ]);
        InfraPlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_definition_id' => $def->id, 'bool_value' => true,
        ]);

        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
            'plan_version_at_purchase' => 1, 'support_tier' => 'standard',
        ]));

        $resolved = app(EntitlementResolver::class)->forSubscription(self::WS, $sub->id);

        $this->assertTrue($resolved['entitled']);
        $this->assertSame(5, $resolved['allowances']['site_count']['limit']);
        $this->assertSame(UsageBehavior::HARD, $resolved['allowances']['site_count']['behavior']);
        $this->assertSame(UsageBehavior::BILLABLE, $resolved['allowances']['storage_mb']['behavior']);
        $this->assertTrue($resolved['features'][$def->key]['value']);
        $this->assertSame('shared-standard', $resolved['compute_class']);
    }

    public function test_suspended_subscription_is_not_entitled(): void
    {
        $product = $this->product();
        $plan = $this->plan($product->id);

        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'state' => SubscriptionState::SUSPENDED, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $this->assertFalse(app(EntitlementResolver::class)->forSubscription(self::WS, $sub->id)['entitled']);
    }

    public function test_grandfathering_resolves_the_purchased_version_not_the_current_one(): void
    {
        $product = $this->product();
        $v1 = $this->plan($product->id, 1, ['included_sites' => 5, 'lifecycle_status' => 'superseded', 'is_current' => false]);
        $this->plan($product->id, 2, ['included_sites' => 10, 'lifecycle_status' => 'current']);

        // Customer bought v1 and stays on v1's allowances.
        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $v1->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly', 'plan_version_at_purchase' => 1,
        ]));

        $resolved = app(EntitlementResolver::class)->forSubscription(self::WS, $sub->id);

        $this->assertSame(5, $resolved['allowances']['site_count']['limit'], 'grandfathered subscriber must keep v1 allowances');
        $this->assertSame(1, $resolved['plan_version']);
    }

    // --------------------------------------------------------------- usage

    public function test_usage_record_stores_behaviour_at_metering_time(): void
    {
        $rec = WorkspaceContext::run(self::WS, fn () => InfraUsageRecord::create([
            'workspace_id' => self::WS, 'metric' => 'storage_mb', 'quantity' => 60000,
            'unit' => 'mb', 'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'included_quantity' => 51200, 'overage_quantity' => 8800,
            'overage_amount_minor' => 880, 'currency' => 'USD',
            'is_billable' => true, 'behavior' => UsageBehavior::BILLABLE,
            'warning_threshold_pct' => 80,
        ]));

        $fresh = WorkspaceContext::run(self::WS, fn () => InfraUsageRecord::find($rec->id));

        $this->assertSame(UsageBehavior::BILLABLE, $fresh->behavior);
        $this->assertSame(8800, (int) $fresh->overage_quantity);
        $this->assertTrue((bool) $fresh->is_billable);
    }

    // ------------------------------------------------------------ renewals

    public function test_renewal_lifecycle_persists_and_validates_transitions(): void
    {
        $product = $this->product();
        $plan = $this->plan($product->id);

        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $renewal = WorkspaceContext::run(self::WS, fn () => InfraRenewal::create([
            'workspace_id' => self::WS, 'subscription_id' => $sub->id,
            'state' => RenewalState::SCHEDULED, 'renewal_mode' => InfraRenewal::MODE_MANUAL,
            'scheduled_for' => now()->addMonth(), 'billing_period' => 'monthly',
            'currency' => 'USD', 'amount_minor' => 4900,
        ]));

        WorkspaceContext::run(self::WS, function () use ($renewal) {
            $renewal->transitionTo(RenewalState::DUE)->save();
            $renewal->transitionTo(RenewalState::ATTEMPTING)->save();
            $renewal->transitionTo(RenewalState::FAILED)->save();
            $renewal->transitionTo(RenewalState::DUNNING)->save();
        });

        $this->assertSame(RenewalState::DUNNING, $renewal->fresh()->state);
        $this->assertSame(RenewalState::FAILED, $renewal->fresh()->previous_state);
    }

    public function test_illegal_renewal_transition_is_rejected(): void
    {
        $product = $this->product();
        $plan = $this->plan($product->id);

        $sub = WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $renewal = WorkspaceContext::run(self::WS, fn () => InfraRenewal::create([
            'workspace_id' => self::WS, 'subscription_id' => $sub->id,
            'state' => RenewalState::SCHEDULED, 'scheduled_for' => now()->addMonth(),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        WorkspaceContext::run(self::WS, fn () => $renewal->transitionTo(RenewalState::RENEWED));
    }

    // ------------------------------------------------------------ isolation

    public function test_commercial_records_are_workspace_isolated(): void
    {
        $product = $this->product();
        $plan = $this->plan($product->id);

        WorkspaceContext::run(self::WS, fn () => InfraSubscription::create([
            'workspace_id' => self::WS, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'state' => SubscriptionState::ACTIVE, 'quantity' => 1, 'currency' => 'USD',
            'unit_amount_minor' => 4900, 'billing_period' => 'monthly',
        ]));

        $otherWs = self::WS + 1;
        $seen = WorkspaceContext::run($otherWs, fn () => InfraSubscription::where('plan_id', $plan->id)->count());

        $this->assertSame(0, $seen, 'another workspace must not see this subscription');
    }
}
