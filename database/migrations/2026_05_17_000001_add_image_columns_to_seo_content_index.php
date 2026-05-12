<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_content_index', 'featured_image_url')) {
                $table->string('featured_image_url', 500)->nullable()->after('meta_description');
            }
            if (!Schema::hasColumn('seo_content_index', 'has_featured_image')) {
                $table->tinyInteger('has_featured_image')->default(0)->after('featured_image_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            foreach (['featured_image_url', 'has_featured_image'] as $col) {
                if (Schema::hasColumn('seo_content_index', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
