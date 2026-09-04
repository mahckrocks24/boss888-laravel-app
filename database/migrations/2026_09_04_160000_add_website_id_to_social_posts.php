<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/**
 * SOCIAL-888 provenance: forward-looking per-website attribution for social_posts.
 * Nullable + additive. NO backfill — classification (2026-09-04) showed all existing
 * rows are manual/studio with no derivable website link, so guessing would be a blind
 * migration. Existing rows stay NULL (workspace-level); new rows capture website_id
 * when the caller provides it. Per-website TARGETING remains an architecture decision.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('social_posts', function (Blueprint $t) {
            if (!Schema::hasColumn('social_posts', 'website_id')) {
                $t->unsignedBigInteger('website_id')->nullable()->after('workspace_id')->index();
            }
        });
    }
    public function down(): void {
        Schema::table('social_posts', function (Blueprint $t) {
            if (Schema::hasColumn('social_posts', 'website_id')) $t->dropColumn('website_id');
        });
    }
};
