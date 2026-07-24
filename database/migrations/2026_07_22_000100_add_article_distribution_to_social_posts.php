<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W5 (2026-07-22) — retained article-distribution columns on social_posts.
 *
 * Deliberately ADDITIVE and reversible. social_posts / social_accounts are NOT
 * dropped or duplicated: the dormant historical social product keeps its rows,
 * and every new column is nullable or carries a default that describes the
 * pre-existing rows truthfully (source_type='manual').
 *
 * A row created by ArticleDistributionService is identified by
 * source_type='article'. Those rows carry immutable distribution context
 * (article_id + canonical_url) which the caption editor may never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $t) {
            // What produced this row. 'manual' describes every pre-existing row.
            $t->string('source_type', 20)->default('manual')->after('workspace_id');

            // Immutable distribution context (source_type='article' only).
            $t->unsignedBigInteger('article_id')->nullable()->after('source_type');
            $t->string('canonical_url', 2048)->nullable()->after('article_id');

            // Duplicate suppression. Unique across the table; NULL is allowed
            // repeatedly in MySQL, so historical rows are unaffected.
            $t->string('idempotency_key', 191)->nullable()->after('canonical_url');

            // Review / execution lifecycle.
            $t->string('approval_state', 20)->default('none')->after('status');
            $t->string('execution_status', 24)->default('pending')->after('approval_state');
            $t->string('failure_class', 32)->nullable()->after('execution_status');
            $t->unsignedSmallInteger('attempt_count')->default(0)->after('failure_class');

            // Audit / provider evidence. Never stores tokens or secrets.
            $t->json('provider_payload_json')->nullable()->after('stats_json');
            $t->string('correlation_id', 64)->nullable()->after('provider_payload_json');

            $t->unique('idempotency_key', 'social_posts_idem_unq');
            $t->index(['workspace_id', 'source_type'], 'social_posts_ws_source_idx');
            $t->index(['workspace_id', 'article_id'], 'social_posts_ws_article_idx');
            $t->index('correlation_id', 'social_posts_corr_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $t) {
            $t->dropUnique('social_posts_idem_unq');
            $t->dropIndex('social_posts_ws_source_idx');
            $t->dropIndex('social_posts_ws_article_idx');
            $t->dropIndex('social_posts_corr_idx');
            $t->dropColumn([
                'source_type', 'article_id', 'canonical_url', 'idempotency_key',
                'approval_state', 'execution_status', 'failure_class', 'attempt_count',
                'provider_payload_json', 'correlation_id',
            ]);
        });
    }
};
