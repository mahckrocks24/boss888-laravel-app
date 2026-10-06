<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN-UPDATES-1 (Owner 2026-10-06): one row per design update offered to a website. Status: building → probing → ready
 * (waiting for the owner) → agreed | cancelled; agreed → reverted; held (not offered: content would be lost, the probe found
 * a new problem, the base was never archived), no_change, superseded (a newer version came first). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('design_updates')) return;
        Schema::create('design_updates', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('website_id');
            $t->string('design_slug', 80);
            $t->string('from_version', 16);
            $t->string('to_version', 16);
            $t->string('status', 16)->default('building');
            $t->string('reason', 255)->nullable();
            $t->longText('report_json')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('revert_until')->nullable();
            $t->timestamp('reverted_at')->nullable();
            $t->timestamps();
            $t->index(['website_id', 'to_version']);
            $t->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_updates');
    }
};
