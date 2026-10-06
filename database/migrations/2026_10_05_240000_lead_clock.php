<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS-W1 (DEC-0089 wave 1): the response clock. One row per lead per step Sarah took to get it answered
 * (nudge = in-app at 1 h, push = companion app at 2 h, email = owner email at 24 h, old = the weekly decision card,
 * hot = the immediate push for a hot enquiry). The unique key makes every step happen once per lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lead_clock')) return;
        Schema::create('lead_clock', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('lead_id');
            $t->string('step', 16);
            $t->unsignedBigInteger('message_id')->nullable();
            $t->boolean('dry_run')->default(false);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['lead_id', 'step']);
            $t->index(['workspace_id', 'step', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_clock');
    }
};
