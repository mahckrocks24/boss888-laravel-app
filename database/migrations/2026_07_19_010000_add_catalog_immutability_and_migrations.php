<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2A-2 — commercial immutability + subscriber migration planning.
 *
 * IMMUTABILITY
 * A plan version becomes IMMUTABLE the moment it has a subscriber. After that
 * point, editing price or allowances would retroactively change what somebody
 * bought. `frozen_at` records when that happened; the authoring service refuses
 * edits to a frozen version and requires a new version instead.
 *
 * `published_at` records when a version became sellable, which is the point at
 * which its commercial identity (slug, SKU, currency, term availability) stops
 * being editable even before the first subscriber arrives.
 *
 * SUBSCRIBER MIGRATION
 * `infra_subscription_migrations` models a PLANNED move of subscribers between
 * plan versions. Phase 2A-2 plans and approves; it deliberately does not
 * execute. Execution moves real customers between commercial terms and belongs
 * behind its own proven workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('infra_products') && !Schema::hasColumn('infra_products', 'published_at')) {
            Schema::table('infra_products', function (Blueprint $table) {
                $table->timestamp('published_at')->nullable()->after('lifecycle_status');
                $table->timestamp('retired_at')->nullable()->after('published_at');
                $table->unsignedBigInteger('created_by')->nullable()->after('metadata_json');
            });
        }

        if (Schema::hasTable('infra_plans')) {
            Schema::table('infra_plans', function (Blueprint $table) {
                if (!Schema::hasColumn('infra_plans', 'published_at')) {
                    // Sellable from this moment. Commercial identity locks here.
                    $table->timestamp('published_at')->nullable()->after('lifecycle_status');
                }
                if (!Schema::hasColumn('infra_plans', 'frozen_at')) {
                    // First subscriber attached. Fully immutable thereafter.
                    $table->timestamp('frozen_at')->nullable()->after('published_at');
                }
                if (!Schema::hasColumn('infra_plans', 'withdrawn_at')) {
                    $table->timestamp('withdrawn_at')->nullable()->after('frozen_at');
                }
                if (!Schema::hasColumn('infra_plans', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('metadata_json');
                }
                if (!Schema::hasColumn('infra_plans', 'derived_from_plan_id')) {
                    // Clone/version lineage: which version this one was created from.
                    $table->unsignedBigInteger('derived_from_plan_id')->nullable()->after('successor_plan_id');
                }
            });
        }

        if (!Schema::hasTable('infra_subscription_migrations')) {
            Schema::create('infra_subscription_migrations', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Platform-level operation: nullable workspace because a migration
                // batch normally spans tenants. Per-subscription scoping is
                // resolved at execution time, which Phase 2A-2 does not perform.
                $table->unsignedBigInteger('workspace_id')->nullable();

                $table->unsignedBigInteger('from_plan_id');
                $table->unsignedBigInteger('to_plan_id');

                // planned | approved | rejected | cancelled | executing | completed | failed
                // Phase 2A-2 reaches `approved` and stops.
                $table->string('state', 24)->default('planned');
                $table->string('previous_state', 24)->nullable();

                // immediate | at_renewal | at_term_end
                // Moving a customer's commercial terms mid-term is a different
                // decision from moving them at renewal; the strategy is explicit.
                $table->string('strategy', 24)->default('at_renewal');

                // Impact snapshot taken at planning time, so an approver sees the
                // blast radius that was assessed, not a number that drifted since.
                $table->unsignedInteger('affected_subscription_count')->default(0);
                $table->json('impact_json')->nullable();

                $table->boolean('price_increases')->default(false);
                $table->boolean('allowances_decrease')->default(false);

                $table->text('reason')->nullable();
                $table->unsignedBigInteger('planned_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approval_id')->nullable();

                $table->unsignedInteger('executed_count')->default(0);
                $table->timestamp('executed_at')->nullable();
                $table->text('failure_summary')->nullable();

                $table->timestamps();

                $table->index(['state', 'created_at'], 'infra_sub_mig_state_idx');
                $table->index('from_plan_id', 'infra_sub_mig_from_idx');
                $table->index('to_plan_id', 'infra_sub_mig_to_idx');

                $table->foreign('from_plan_id', 'infra_sub_mig_from_fk')
                      ->references('id')->on('infra_plans')->restrictOnDelete();
                $table->foreign('to_plan_id', 'infra_sub_mig_to_fk')
                      ->references('id')->on('infra_plans')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_subscription_migrations');

        if (Schema::hasTable('infra_plans')) {
            Schema::table('infra_plans', function (Blueprint $table) {
                foreach (['published_at', 'frozen_at', 'withdrawn_at', 'created_by', 'derived_from_plan_id'] as $c) {
                    if (Schema::hasColumn('infra_plans', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }

        if (Schema::hasTable('infra_products')) {
            Schema::table('infra_products', function (Blueprint $table) {
                foreach (['published_at', 'retired_at', 'created_by'] as $c) {
                    if (Schema::hasColumn('infra_products', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
