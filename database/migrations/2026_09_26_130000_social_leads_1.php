<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOCIAL-LEADS-1 (RFC-0016 P1, Owner 2026-09-26 "go all"): buying signals on comments, tracked links from social to the
 * business's website, and the CRM lead each hot comment becomes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('social_comments', function (Blueprint $t) {
            if (! Schema::hasColumn('social_comments', 'intent')) $t->string('intent', 8)->nullable()->after('sentiment');          // hot | warm | none
            if (! Schema::hasColumn('social_comments', 'signals_json')) $t->json('signals_json')->nullable()->after('intent');       // event, date, guests, location, budget
            if (! Schema::hasColumn('social_comments', 'lead_id')) $t->unsignedBigInteger('lead_id')->nullable()->index()->after('signals_json');
            if (! Schema::hasColumn('social_comments', 'link_code')) $t->string('link_code', 16)->nullable()->after('lead_id');
        });
        if (! Schema::hasTable('tracked_links')) {
            Schema::create('tracked_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('website_id')->nullable()->index();
                $t->string('code', 16)->unique();
                $t->string('target_url', 1000);
                $t->string('source_type', 32);               // facebook_comment | facebook_messenger | social_post | campaign
                $t->unsignedBigInteger('source_id')->nullable();
                $t->unsignedBigInteger('lead_id')->nullable()->index();
                $t->unsignedInteger('clicks')->default(0);
                $t->timestamp('last_click_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('tracked_link_clicks')) {
            Schema::create('tracked_link_clicks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tracked_link_id')->index();
                $t->string('ip_hash', 64)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('clicked_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_link_clicks');
        Schema::dropIfExists('tracked_links');
        Schema::table('social_comments', function (Blueprint $t) {
            foreach (['link_code', 'lead_id', 'signals_json', 'intent'] as $c) if (Schema::hasColumn('social_comments', $c)) $t->dropColumn($c);
        });
    }
};
