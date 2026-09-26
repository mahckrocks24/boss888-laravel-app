<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PREVIEW-DISMISS-1 (2026-09-26, Owner: "laravel no longer has the preview ... but app still shows it. proper synching"):
 * "Not now" on a draft preview was remembered only in the screen that pressed it. The dismissal is now a fact on the
 * post, so the web chat, the companion app and every device hide the same previews. The draft itself stays a draft.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('social_posts', 'preview_dismissed_at')) {
            Schema::table('social_posts', function (Blueprint $t) { $t->timestamp('preview_dismissed_at')->nullable()->after('published_at'); });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_posts', 'preview_dismissed_at')) {
            Schema::table('social_posts', function (Blueprint $t) { $t->dropColumn('preview_dismissed_at'); });
        }
    }
};
