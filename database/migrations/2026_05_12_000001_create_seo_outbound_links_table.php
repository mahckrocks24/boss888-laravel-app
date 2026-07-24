<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('seo_outbound_links')) { return; }
        Schema::create('seo_outbound_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('source_url', 500);
            $table->string('target_url', 500);
            $table->string('target_host', 255)->nullable()->index();
            $table->string('anchor_text', 300)->nullable();
            $table->string('rel', 100)->nullable();
            $table->string('status', 20)->default('unchecked');
            $table->integer('http_status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'source_url']);
        });

        // 2026-07-18 CLEAN-INSTALL REPAIR (INFRA888 Phase 1D §8) — identical
        // defect to seo_images. 8 + 2000 + 2000 = 4008 bytes exceeds InnoDB's
        // 3072-byte key limit, aborting the chain on a FRESH database. Existing
        // environments never hit it because the hasTable guard above returns
        // early, which also means the constraint was never actually created.
        // Prefix lengths: 8 + (255*4) + (255*4) = 2048 bytes.
        DB::statement(
            'ALTER TABLE `seo_outbound_links` ADD UNIQUE INDEX `unique_outbound_link` '
            . '(`workspace_id`, `source_url`(255), `target_url`(255))'
        );
    }
    public function down(): void { Schema::dropIfExists('seo_outbound_links'); }
};
