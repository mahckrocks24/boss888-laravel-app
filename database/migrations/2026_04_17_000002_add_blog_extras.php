<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'tags_json')) $table->json('tags_json')->nullable()->after('blog_category');
            if (!Schema::hasColumn('articles', 'meta_title')) $table->string('meta_title', 255)->nullable()->after('seo_json');
            if (!Schema::hasColumn('articles', 'meta_description')) $table->text('meta_description')->nullable()->after('meta_title');
            if (!Schema::hasColumn('articles', 'focus_keyword')) $table->string('focus_keyword', 255)->nullable()->after('meta_description');
            if (!Schema::hasColumn('articles', 'read_time')) $table->unsignedSmallInteger('read_time')->default(0)->after('word_count');
            if (!Schema::hasColumn('articles', 'scheduled_at')) $table->timestamp('scheduled_at')->nullable()->after('published_at');
        });

        if (!Schema::hasTable('blog_categories')) {
            Schema::create('blog_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('name', 100);
                $table->string('slug', 100);
                $table->timestamps();
                $table->unique(['workspace_id', 'slug']);
            });
        }
    }
    public function down(): void {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['tags_json', 'meta_title', 'meta_description', 'focus_keyword', 'read_time', 'scheduled_at']);
        });
        Schema::dropIfExists('blog_categories');
    }
};
