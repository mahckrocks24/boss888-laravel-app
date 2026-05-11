<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Link graph — internal/outbound link relationships per workspace.
        if (!Schema::hasTable('seo_link_graph')) {
            Schema::create('seo_link_graph', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('source_url', 500);
                $table->string('target_url', 500);
                $table->string('anchor_text', 300)->nullable();
                $table->boolean('is_internal')->default(true);
                $table->timestamps();
                $table->index(['workspace_id', 'source_url']);
                $table->index(['workspace_id', 'target_url']);
            });
        }

        // Proactive insights cache — populated by `php artisan seo:insights`.
        if (!Schema::hasTable('seo_insights')) {
            Schema::create('seo_insights', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('type', 50)->index();
                $table->enum('priority', ['critical', 'warning', 'opportunity']);
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->json('data_json')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();
            });
        }

        // Augment seo_content_index with Phase 1 columns.
        Schema::table('seo_content_index', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_content_index', 'inbound_links')) {
                $table->unsignedInteger('inbound_links')->default(0)->after('external_link_count');
            }
            if (!Schema::hasColumn('seo_content_index', 'inbound_weight')) {
                $table->decimal('inbound_weight', 8, 4)->default(0)->after('inbound_links');
            }
            if (!Schema::hasColumn('seo_content_index', 'authority_score')) {
                $table->decimal('authority_score', 8, 4)->default(0)->after('inbound_weight');
            }
            if (!Schema::hasColumn('seo_content_index', 'cluster_id')) {
                $table->string('cluster_id', 64)->nullable()->after('authority_score');
            }
            if (!Schema::hasColumn('seo_content_index', 'ctr_potential_score')) {
                $table->unsignedTinyInteger('ctr_potential_score')->nullable()->after('cluster_id');
            }
            if (!Schema::hasColumn('seo_content_index', 'ctr_label')) {
                $table->string('ctr_label', 30)->nullable()->after('ctr_potential_score');
            }
            if (!Schema::hasColumn('seo_content_index', 'readability_score')) {
                $table->decimal('readability_score', 5, 1)->nullable()->after('ctr_label');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_link_graph');
        Schema::dropIfExists('seo_insights');
        Schema::table('seo_content_index', function (Blueprint $table) {
            foreach (['inbound_links','inbound_weight','authority_score','cluster_id',
                      'ctr_potential_score','ctr_label','readability_score'] as $col) {
                if (Schema::hasColumn('seo_content_index', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
