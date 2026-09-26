<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOCIAL-LEADS-4 (RFC-0016 P4, Owner 2026-09-26 "go all"): comment-keyword campaigns — "Comment MENU and we'll send it".
 * The Owner approves the campaign once; every matching comment then gets the campaign's public reply and private
 * message under that approval, with no model call.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('social_campaigns')) {
            Schema::create('social_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable();
                $t->string('name', 120);
                $t->string('keyword', 40);
                $t->string('public_reply', 500);          // may contain {first_name}
                $t->string('dm_message', 1000);           // may contain {first_name} and {link}
                $t->boolean('include_link')->default(true);
                $t->string('status', 16)->default('active');   // active | paused | ended
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->unsignedBigInteger('task_id')->nullable();
                $t->unsignedInteger('responses')->default(0);
                $t->timestamp('ends_at')->nullable();
                $t->timestamps();
            });
        }
        Schema::table('social_comments', function (Blueprint $t) {
            if (! Schema::hasColumn('social_comments', 'campaign_id')) $t->unsignedBigInteger('campaign_id')->nullable()->index()->after('link_code');
        });
    }

    public function down(): void
    {
        Schema::table('social_comments', function (Blueprint $t) { if (Schema::hasColumn('social_comments', 'campaign_id')) $t->dropColumn('campaign_id'); });
        Schema::dropIfExists('social_campaigns');
    }
};
