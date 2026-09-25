<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOCIAL-PROFILE-1 (2026-09-25): a post may say which business it is about, so the right Page is chosen
 * in a workspace with several businesses. social_accounts.business_id already exists; this is its
 * counterpart on the post. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_posts') && ! Schema::hasColumn('social_posts', 'business_id')) {
            Schema::table('social_posts', fn (Blueprint $t) => $t->unsignedBigInteger('business_id')->nullable()->index()->after('website_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('social_posts') && Schema::hasColumn('social_posts', 'business_id')) {
            Schema::table('social_posts', fn (Blueprint $t) => $t->dropColumn('business_id'));
        }
    }
};
