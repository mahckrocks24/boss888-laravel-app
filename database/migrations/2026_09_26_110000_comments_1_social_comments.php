<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMENTS-1 (2026-09-26, Owner: "Are we capable of getting Sarah or an agent to constantly check comments to respond to
 * them accordingly?" → "updated use cases, now expand it. approval gating"). One row per comment on the business's Page:
 * what was said, how Sarah read it, the reply she drafted, and the Owner's decision. A reply reaches the Page only
 * through an approved social_reply_comment task.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('social_comments')) return;
        Schema::create('social_comments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('business_id')->nullable()->index();
            $t->unsignedBigInteger('social_account_id')->index();
            $t->string('platform', 20)->default('facebook');
            $t->string('external_comment_id', 120)->unique();
            $t->string('external_post_id', 120)->index();
            $t->string('parent_comment_id', 120)->nullable();
            $t->string('author_name', 190)->nullable();
            $t->string('author_external_id', 120)->nullable();
            $t->text('message')->nullable();
            $t->text('post_excerpt')->nullable();
            $t->string('post_permalink', 500)->nullable();
            $t->timestamp('commented_at')->nullable();
            $t->string('category', 20)->nullable();        // question | enquiry | praise | complaint | spam | other
            $t->string('sentiment', 12)->nullable();       // positive | neutral | negative
            $t->boolean('needs_owner')->default(false);    // a complaint or anything sensitive
            $t->text('draft_reply')->nullable();
            $t->text('triage_note')->nullable();
            // new → awaiting_approval → replied | declined | no_reply | failed
            $t->string('status', 24)->default('new')->index();
            $t->unsignedBigInteger('task_id')->nullable()->index();
            $t->string('reply_external_id', 120)->nullable();
            $t->text('reply_sent')->nullable();
            $t->timestamp('replied_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comments');
    }
};
