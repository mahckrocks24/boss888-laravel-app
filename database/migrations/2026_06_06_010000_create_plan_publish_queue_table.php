<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan_publish_queue — per-asset preview review queue for publishing inside
 * an approved Plan. Rich preview metadata lives here; the actual approval
 * gate is the existing approvals row referenced by approval_id.
 *
 * Lifecycle: queued -> approved (chained to ApprovalService.approve)
 *                   -> rejected
 *                   -> expired (mirrored from approvals.expired)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_publish_queue', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('plan_id');                  // execution_plans.id
            $t->unsignedBigInteger('workspace_id');             // denormalized for fast tenant scoping
            $t->unsignedBigInteger('approval_id')->nullable();  // approvals.id — the real gate
            $t->unsignedBigInteger('source_task_id')->nullable(); // tasks.id that produced this asset
            $t->string('engine', 40);                           // social, marketing, write, content, builder
            $t->string('action', 80);                           // publish_post, send_campaign, publish_pack, etc.
            $t->string('entity_type', 80)->nullable();          // articles, social_posts, campaigns, content_packs
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('preview_json');                            // {title, excerpt, image_url, ...}
            $t->json('params_json')->nullable();                 // original action params
            $t->string('batch_id', 40)->nullable();              // groups multiple queue rows for bulk-approve
            $t->string('status', 20)->default('queued');         // queued | approved | rejected | expired
            $t->unsignedBigInteger('decision_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'status']);
            $t->index(['plan_id', 'status']);
            $t->index('approval_id');
            $t->foreign('plan_id')->references('id')->on('execution_plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_publish_queue');
    }
};