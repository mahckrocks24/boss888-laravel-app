<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOCIAL-LEADS-2/3 (RFC-0016 P2 + P3, Owner 2026-09-26 "go all"): a private message to a hot commenter (sent with the
 * approved public reply), the Messenger conversation that follows, and Facebook Lead Ads submissions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('social_comments', function (Blueprint $t) {
            if (! Schema::hasColumn('social_comments', 'dm_draft')) $t->text('dm_draft')->nullable()->after('draft_reply');
            if (! Schema::hasColumn('social_comments', 'dm_status')) $t->string('dm_status', 24)->nullable()->after('dm_draft');   // sent | refused | skipped
            if (! Schema::hasColumn('social_comments', 'psid')) $t->string('psid', 64)->nullable()->index()->after('dm_status');   // Messenger id Facebook returns
        });
        if (! Schema::hasTable('social_messages')) {
            Schema::create('social_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('social_account_id')->index();
                $t->string('psid', 64)->index();                  // the person, as the Page sees them
                $t->string('direction', 3);                        // in | out
                $t->string('mid', 190)->nullable()->unique();      // Facebook message id
                $t->text('text')->nullable();
                $t->unsignedBigInteger('lead_id')->nullable()->index();
                $t->string('status', 24)->default('received');     // received | draft_waiting | sent | refused
                $t->unsignedBigInteger('task_id')->nullable();
                $t->json('extracted_json')->nullable();              // email / phone / date / guests found in an inbound message
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::table('social_comments', function (Blueprint $t) {
            foreach (['psid', 'dm_status', 'dm_draft'] as $c) if (Schema::hasColumn('social_comments', $c)) $t->dropColumn($c);
        });
    }
};
