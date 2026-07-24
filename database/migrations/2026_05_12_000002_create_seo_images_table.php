<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('seo_images')) { return; }
        Schema::create('seo_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('page_url', 500);
            $table->string('image_url', 500);
            $table->string('alt_text', 500)->nullable();
            $table->string('title_text', 500)->nullable();
            $table->boolean('missing_alt')->default(false);
            $table->boolean('empty_alt')->default(false);
            $table->string('suggested_alt', 500)->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'page_url']);
        });

        // 2026-07-18 CLEAN-INSTALL REPAIR (INFRA888 Phase 1D §8).
        //
        // The original definition was:
        //   $table->unique(['workspace_id','page_url','image_url'], 'unique_page_image');
        //
        // Three utf8mb4 varchar(500) columns need 8 + 2000 + 2000 = 4008 bytes of
        // key space; InnoDB's limit is 3072. On a FRESH database this aborted the
        // entire migration chain with SQLSTATE[42000] 1071, which is why the
        // documented disaster-recovery "rebuild" path could not complete.
        //
        // It never surfaced on existing environments because the hasTable guard
        // above short-circuits there — the index was in fact NEVER created on
        // staging, so the intended uniqueness was silently absent.
        //
        // A prefix index preserves the constraint within MySQL's limits:
        //   8 + (255*4) + (255*4) = 2048 bytes.
        // Longest URLs observed in real data: 113 and 124 characters, so 255 is
        // ample. Two URLs identical for their first 255 characters but differing
        // later would collide — accepted and documented.
        //
        // Additive-only repair was impossible: a clean install aborts INSIDE this
        // migration, so no later migration can run to fix it.
        DB::statement(
            'ALTER TABLE `seo_images` ADD UNIQUE INDEX `unique_page_image` '
            . '(`workspace_id`, `page_url`(255), `image_url`(255))'
        );
    }
    public function down(): void { Schema::dropIfExists('seo_images'); }
};
