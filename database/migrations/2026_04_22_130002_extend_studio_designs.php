<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('studio_designs', function (Blueprint $t) {
            if (!Schema::hasColumn('studio_designs', 'background_type')) {
                $t->enum('background_type', ['color','image','gradient'])->default('color')->after('canvas_height');
            }
            if (!Schema::hasColumn('studio_designs', 'background_value')) {
                $t->text('background_value')->nullable()->after('background_type');
            }
            if (!Schema::hasColumn('studio_designs', 'is_template')) {
                $t->boolean('is_template')->default(false);
            }
            if (!Schema::hasColumn('studio_designs', 'template_category')) {
                $t->string('template_category', 60)->nullable();
            }
            if (!Schema::hasColumn('studio_designs', 'tags_json')) {
                $t->json('tags_json')->nullable();
            }
            if (!Schema::hasColumn('studio_designs', 'last_exported_at')) {
                $t->timestamp('last_exported_at')->nullable();
            }
            if (!Schema::hasColumn('studio_designs', 'published_to_social')) {
                $t->boolean('published_to_social')->default(false);
            }
            // thumbnail_url already exists — leave alone.
        });
    }

    public function down(): void
    {
        // Non-destructive
    }
};
