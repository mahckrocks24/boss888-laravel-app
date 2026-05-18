<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // Wave 11 (2026-05-18) — every new article must have a featured
            // image with alt text per AI Assistant Operating Rules.
            // featured_image_alt holds the AI-generated alt text (SEO-friendly).
            // featured_image_error captures the reason image gen failed after
            // 3 retries — so the user has visibility into why their draft
            // is image-less and can manually retry.
            $table->string('featured_image_alt', 500)->nullable()->after('featured_image_url');
            $table->text('featured_image_error')->nullable()->after('featured_image_alt');
            $table->unsignedTinyInteger('featured_image_attempts')->default(0)->after('featured_image_error');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['featured_image_alt', 'featured_image_error', 'featured_image_attempts']);
        });
    }
};
