<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_unlinked_mention_snapshots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('target_url', 500);
            $t->string('target_url_hash', 64)->index();
            $t->string('source_url', 500);
            $t->string('source_url_hash', 64);
            $t->string('source_title', 512)->nullable();
            $t->string('matched_term', 255)->nullable();
            $t->text('snippet')->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'target_url_hash', 'source_url_hash'], 'seo_ums_unique');
            // 2026-07-18 CLEAN-INSTALL REPAIR (INFRA888 Phase 1D §8).
            // Laravel would auto-name this
            //   seo_unlinked_mention_snapshots_workspace_id_target_url_hash_index
            // = 65 characters, one over MySQL's 64-char identifier limit, which
            // aborted the chain on a FRESH database with SQLSTATE 1059. Existing
            // environments never saw it because the table was created
            // out-of-band. An explicit short name fixes clean installs and is
            // inert where the migration is already recorded as run.
            $t->index(['workspace_id', 'target_url_hash'], 'seo_ums_ws_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_unlinked_mention_snapshots');
    }
};
