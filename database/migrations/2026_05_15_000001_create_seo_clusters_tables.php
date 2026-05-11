<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('seo_clusters')) {
            Schema::create('seo_clusters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('label', 255)->nullable();
                $table->string('pillar_url', 500)->nullable();
                $table->unsignedInteger('page_count')->default(0);
                $table->decimal('avg_score', 5, 2)->default(0);
                $table->decimal('avg_authority', 8, 4)->default(0);
                $table->json('top_terms')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('seo_cluster_members')) {
            Schema::create('seo_cluster_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cluster_id');
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('url', 500);
                $table->decimal('similarity', 6, 4)->default(0);
                $table->timestamps();
                $table->index(['cluster_id']);
                $table->index(['workspace_id', 'url']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_cluster_members');
        Schema::dropIfExists('seo_clusters');
    }
};
