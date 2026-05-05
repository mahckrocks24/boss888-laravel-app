<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'blog_category')) {
                $table->string('blog_category', 50)->nullable()->after('type');
            }
            if (!Schema::hasColumn('articles', 'is_marketing_blog')) {
                $table->boolean('is_marketing_blog')->default(false)->after('blog_category');
            }
            if (!Schema::hasColumn('articles', 'featured_image_url')) {
                $table->string('featured_image_url', 2048)->nullable()->after('is_marketing_blog');
            }
        });
    }
    public function down(): void {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['blog_category', 'is_marketing_blog', 'featured_image_url']);
        });
    }
};
