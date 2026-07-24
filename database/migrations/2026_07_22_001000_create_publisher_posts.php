<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCOPE CHANGE 2026-07-22 — Content Publisher.
 *
 * Manual content publishing is now a retained launch capability. Autonomous
 * publishing, social management and social intelligence remain removed.
 *
 * MODEL: one `publisher_posts` row is the user's unit of work (media + base
 * caption + chosen platforms + ONE approval). Each selected platform gets its
 * own `social_posts` row as the per-platform variation and publication record.
 *
 * social_posts is REUSED, not duplicated — it already carries platform, content,
 * status, scheduled_at, external_post_id, plus the W5 additions (idempotency_key,
 * approval_state, execution_status, failure_class, attempt_count,
 * provider_payload_json, correlation_id). It gains one link column.
 *
 * Additive and reversible. Dormant historical social rows keep publisher_post_id
 * NULL and are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publisher_posts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');

            // Where the media/content came from. 'article' keeps the W5
            // article-share flow inside the same model.
            $t->string('source_type', 24)->default('upload'); // upload|media_library|studio|article
            $t->unsignedBigInteger('article_id')->nullable();
            $t->string('canonical_url', 2048)->nullable();

            // Media is a list of {source, id, url, mime, width, height}.
            $t->json('media_json')->nullable();

            // The user's shared starting caption. Per-platform variations live
            // on social_posts.content and may diverge freely.
            $t->text('caption_base')->nullable();
            $t->json('target_platforms')->nullable();

            // draft | awaiting_approval | approved | scheduled | publishing
            // | published | partially_published | failed | cancelled
            $t->string('status', 24)->default('draft');

            // ── THE APPROVAL BOUNDARY ──────────────────────────────────────
            // Nothing publishes unless approval_method is set to an explicit
            // user action. Uploading media or generating a caption NEVER sets
            // these. See PublisherService::approve().
            $t->string('approval_method', 32)->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();

            $t->timestamp('scheduled_at')->nullable();
            $t->string('timezone', 64)->nullable();
            $t->timestamp('published_at')->nullable();

            $t->string('idempotency_key', 191)->nullable();
            $t->string('correlation_id', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->unique('idempotency_key', 'publisher_posts_idem_unq');
            $t->index(['workspace_id', 'status'], 'publisher_posts_ws_status_idx');
            $t->index(['workspace_id', 'scheduled_at'], 'publisher_posts_ws_sched_idx');
            $t->index('correlation_id', 'publisher_posts_corr_idx');

            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::table('social_posts', function (Blueprint $t) {
            $t->unsignedBigInteger('publisher_post_id')->nullable()->after('source_type');
            $t->index(['publisher_post_id'], 'social_posts_pub_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $t) {
            $t->dropIndex('social_posts_pub_idx');
            $t->dropColumn('publisher_post_id');
        });
        Schema::dropIfExists('publisher_posts');
    }
};
