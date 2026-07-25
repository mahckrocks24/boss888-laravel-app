<?php

namespace Database\Seeders;

use App\Engines\Infrastructure\Models\InfraEntitlementDefinition;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraPlanEntitlement;
use App\Engines\Infrastructure\Models\InfraProduct;
use App\Engines\Infrastructure\States\PlanState;
use App\Engines\Infrastructure\States\ProductState;
use App\Engines\Infrastructure\States\UsageBehavior;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ILLUSTRATIVE hosting catalog — the four approved tiers.
 *
 *   Starter · Growth · Business · Agency
 *
 * ⚠️ THE PRICES AND ALLOWANCES BELOW ARE ILLUSTRATIVE, NOT COMMERCIAL DECISIONS.
 *
 * Pricing cannot responsibly be set until real provider costs are observed, and
 * no provider is connected (Cloudflare remains blocked on zone ownership and
 * token scope). These figures exist so the commercial model can be exercised
 * end to end — versioning, entitlements, grandfathering, migration planning —
 * not so they can be sold.
 *
 * Seeding is IDEMPOTENT and OPT-IN. It is never run automatically, and it is not
 * run on staging by default: staging's infra_* tables are deliberately empty so
 * nothing can be mistaken for a real customer catalog.
 *
 * Run explicitly:
 *   php artisan db:seed --class=Database\\Seeders\\InfraCatalogSeeder
 */
class InfraCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $definitions = $this->seedEntitlementDefinitions();
            $product     = $this->seedProduct();

            $tiers = [
                'starter'  => $this->starter(),
                'growth'   => $this->growth(),
                'business' => $this->business(),
                'agency'   => $this->agency(),
            ];

            $order = 0;

            foreach ($tiers as $slug => $spec) {
                $plan = $this->seedPlan($product, $slug, $spec, ++$order);
                $this->seedPlanEntitlements($plan, $spec['features'], $definitions);
            }

            // The product is publishable because it now has current plans.
            $product->lifecycle_status = ProductState::ACTIVE;
            $product->is_active        = true;
            $product->published_at     = $product->published_at ?: now();
            $product->save();
        });

        $this->command?->info('INFRA888 illustrative catalog seeded: Starter, Growth, Business, Agency.');
        $this->command?->warn('Prices are ILLUSTRATIVE. Real pricing requires observed provider costs.');
    }

    // ------------------------------------------------------------ definitions

    private function seedEntitlementDefinitions(): array
    {
        $defs = [
            ['key' => 'staging_environments', 'name' => 'Staging environments', 'value_type' => 'bool',
             'category' => 'delivery', 'default_behavior' => UsageBehavior::HARD],
            ['key' => 'priority_restore', 'name' => 'Priority restore', 'value_type' => 'bool',
             'category' => 'support', 'default_behavior' => UsageBehavior::NONE],
            ['key' => 'managed_migrations', 'name' => 'Managed migrations', 'value_type' => 'int',
             'unit' => 'count', 'category' => 'services', 'default_behavior' => UsageBehavior::HARD],
            ['key' => 'premium_monitoring', 'name' => 'Premium monitoring', 'value_type' => 'bool',
             'category' => 'monitoring', 'default_behavior' => UsageBehavior::NONE],
            ['key' => 'malware_removal', 'name' => 'Malware removal', 'value_type' => 'bool',
             'category' => 'security', 'default_behavior' => UsageBehavior::NONE],
            ['key' => 'white_label_reporting', 'name' => 'White-label reporting', 'value_type' => 'bool',
             'category' => 'agency', 'default_behavior' => UsageBehavior::NONE],
            ['key' => 'client_sub_accounts', 'name' => 'Client sub-accounts', 'value_type' => 'int',
             'unit' => 'count', 'category' => 'agency', 'default_behavior' => UsageBehavior::HARD],
            ['key' => 'support_response_hours', 'name' => 'Support response target', 'value_type' => 'int',
             'unit' => 'hours', 'category' => 'support', 'default_behavior' => UsageBehavior::ADMINISTRATIVE],
        ];

        $out = [];

        foreach ($defs as $i => $d) {
            $out[$d['key']] = InfraEntitlementDefinition::firstOrCreate(
                ['key' => $d['key']],
                array_merge($d, ['is_active' => true, 'sort_order' => $i])
            );
        }

        return $out;
    }

    private function seedProduct(): InfraProduct
    {
        return InfraProduct::firstOrCreate(
            ['slug' => 'managed-hosting'],
            [
                'name'             => 'Managed Hosting',
                'category'         => InfraProduct::CATEGORY_HOSTING,
                'description'      => 'Managed website hosting with SSL, backups and monitoring included.',
                'sku'              => 'HOST-MANAGED-0001',
                'version'          => 1,
                'lifecycle_status' => ProductState::DRAFT,
                'visibility'       => 'public',
                'is_active'        => false,
                'sort_order'       => 1,
            ]
        );
    }

    private function seedPlan(InfraProduct $product, string $slug, array $spec, int $order): InfraPlan
    {
        $plan = InfraPlan::firstOrCreate(
            ['slug' => $slug, 'version' => 1],
            array_merge($spec['plan'], [
                'product_id'       => $product->id,
                'version'          => 1,
                'lifecycle_status' => PlanState::CURRENT,
                'is_current'       => true,
                'is_public'        => true,
                'sort_order'       => $order,
                'published_at'     => now(),
            ])
        );

        return $plan;
    }

    private function seedPlanEntitlements(InfraPlan $plan, array $features, array $definitions): void
    {
        foreach ($features as $key => $value) {
            $def = $definitions[$key] ?? null;

            if (!$def) {
                continue;
            }

            $payload = ['plan_id' => $plan->id, 'entitlement_definition_id' => $def->id];

            $typed = match ($def->value_type) {
                'bool' => ['bool_value' => (bool) $value],
                'int'  => ['int_value' => (int) $value],
                default => ['string_value' => (string) $value],
            };

            InfraPlanEntitlement::firstOrCreate($payload, $typed);
        }
    }

    // ----------------------------------------------------------------- tiers
    // Amounts are MINOR UNITS (cents). Illustrative only.

    private function starter(): array
    {
        return [
            'plan' => [
                'name' => 'Starter', 'currency' => 'USD',
                'recurring_amount_minor' => 1900,      // $19.00 illustrative
                'billing_period' => 'monthly',
                'setup_fee_minor' => 0, 'migration_fee_minor' => 0,
                'allows_monthly' => true, 'allows_annual' => true, 'allows_triennial' => false,
                'included_sites' => 1,
                'included_storage_mb' => 10240,        // 10 GB
                'included_bandwidth_mb' => 102400,     // 100 GB
                'included_mailboxes' => 0,
                'included_domains' => 0,
                'backup_retention_days' => 7,
                'included_staging_environments' => 0,
                'included_restores_per_month' => 1,
                'included_migrations' => 0,
                'overage_storage_minor_per_gb' => 50,
                'compute_class' => 'shared-small',
                'monitoring_level' => 'uptime',
                'support_tier' => 'standard',
            ],
            'features' => [
                'support_response_hours' => 48,
            ],
        ];
    }

    private function growth(): array
    {
        return [
            'plan' => [
                'name' => 'Growth', 'currency' => 'USD',
                'recurring_amount_minor' => 4900,      // $49.00 illustrative
                'billing_period' => 'monthly',
                'setup_fee_minor' => 0, 'migration_fee_minor' => 0,
                'allows_monthly' => true, 'allows_annual' => true, 'allows_triennial' => false,
                'included_sites' => 5,
                'included_storage_mb' => 51200,        // 50 GB
                'included_bandwidth_mb' => 512000,     // 500 GB
                'included_mailboxes' => 5,
                'included_domains' => 1,
                'backup_retention_days' => 30,
                'included_staging_environments' => 1,
                'included_restores_per_month' => 3,
                'included_migrations' => 1,
                'overage_storage_minor_per_gb' => 40,
                'compute_class' => 'shared-standard',
                'monitoring_level' => 'uptime_perf',
                'support_tier' => 'standard',
            ],
            'features' => [
                'staging_environments'   => true,
                'managed_migrations'     => 1,
                'support_response_hours' => 24,
            ],
        ];
    }

    private function business(): array
    {
        return [
            'plan' => [
                'name' => 'Business', 'currency' => 'USD',
                'recurring_amount_minor' => 14900,     // $149.00 illustrative
                'billing_period' => 'monthly',
                'setup_fee_minor' => 0, 'migration_fee_minor' => 0,
                'allows_monthly' => true, 'allows_annual' => true, 'allows_triennial' => true,
                'included_sites' => 15,
                'included_storage_mb' => 153600,       // 150 GB
                'included_bandwidth_mb' => 2097152,    // 2 TB
                'included_mailboxes' => 25,
                'included_domains' => 5,
                'backup_retention_days' => 90,
                'included_staging_environments' => 5,
                'included_restores_per_month' => 10,
                'included_migrations' => 3,
                'overage_storage_minor_per_gb' => 30,
                'compute_class' => 'shared-large',
                'monitoring_level' => 'full_alerting',
                'support_tier' => 'priority',
            ],
            'features' => [
                'staging_environments'   => true,
                'priority_restore'       => true,
                'managed_migrations'     => 3,
                'premium_monitoring'     => true,
                'malware_removal'        => true,
                'support_response_hours' => 8,
            ],
        ];
    }

    private function agency(): array
    {
        return [
            'plan' => [
                'name' => 'Agency', 'currency' => 'USD',
                'recurring_amount_minor' => 39900,     // $399.00 illustrative
                'billing_period' => 'monthly',
                'setup_fee_minor' => 0, 'migration_fee_minor' => 0,
                'allows_monthly' => true, 'allows_annual' => true, 'allows_triennial' => true,
                'included_sites' => 50,
                'included_storage_mb' => 512000,       // 500 GB
                'included_bandwidth_mb' => 5242880,    // 5 TB
                'included_mailboxes' => 100,
                'included_domains' => 25,
                'backup_retention_days' => 180,
                'included_staging_environments' => 25,
                'included_restores_per_month' => 30,
                'included_migrations' => 10,
                'overage_storage_minor_per_gb' => 20,
                'compute_class' => 'isolated',
                'monitoring_level' => 'full_alerting',
                'support_tier' => 'managed',
            ],
            'features' => [
                'staging_environments'   => true,
                'priority_restore'       => true,
                'managed_migrations'     => 10,
                'premium_monitoring'     => true,
                'malware_removal'        => true,
                'white_label_reporting'  => true,
                'client_sub_accounts'    => 25,
                'support_response_hours' => 4,
            ],
        ];
    }
}
