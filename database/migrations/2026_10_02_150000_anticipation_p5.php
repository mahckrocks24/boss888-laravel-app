<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0023 P5 (2026-10-02): anticipation proposals - what Sarah proposed before being asked (calendar, opportunity, risk,
 * readiness, question), with its evidence, confidence, cost, the action a "yes" runs, and the owner's decision, so the
 * engine learns per owner what is worth proposing (acceptance scoring). DEC-0073 D2: draft-and-ask, nothing spends on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anticipation_proposals')) return;
        Schema::create('anticipation_proposals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('business_id')->nullable();
            $t->string('kind', 16);                      // calendar|opportunity|risk|readiness|question
            $t->string('key', 96);                       // dedupe: calendar:halloween:2026 | question:fact:7:2026w40
            $t->string('title', 160);
            $t->text('body');                            // Sarah's words as posted
            $t->json('evidence_json')->nullable();
            $t->decimal('confidence', 3, 2)->default(0.50);
            $t->integer('cost_credits')->default(0);
            $t->json('action_json')->nullable();         // {type: task|reply_note|none, ...}
            $t->string('status', 12)->default('proposed')->index();   // proposed|accepted|declined|ignored|done|failed
            $t->unsignedBigInteger('message_id')->nullable();
            $t->unsignedBigInteger('result_task_id')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'key']);
            $t->index(['workspace_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('anticipation_proposals'); }
};
