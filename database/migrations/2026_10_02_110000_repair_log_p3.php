<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0023 P3 (2026-10-02): the repair log - one row per frustration episode Sarah detected (voice, behaviour or a system
 * failure she caused), what she found as the cause, what she did about it, what it cost her (refund / goodwill, DEC-0073 D1)
 * and when the owner accepted the fix. Debts live as relationship facts in owner_model_facts (key debt_<episode>).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('repair_log')) return;
        Schema::create('repair_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('business_id')->nullable();
            $t->unsignedBigInteger('user_message_id')->nullable();
            $t->unsignedTinyInteger('score')->default(0);
            $t->string('level', 12)->default('mild');            // mild|strong|severe
            $t->json('cues_json')->nullable();                     // voice / behaviour / system cues that fired
            $t->string('cause_ref', 80)->nullable();               // task:33274 | ledger:5 | none
            $t->text('cause')->nullable();                         // one plain line, what went wrong
            $t->string('compensation', 16)->default('none');       // none|refund|goodwill|escalated
            $t->integer('credits')->default(0);
            $t->json('fix_json')->nullable();                      // what Sarah said she would do / did
            $t->string('status', 12)->default('open')->index();    // open|repaired|closed|escalated
            $t->unsignedTinyInteger('hits')->default(1);           // how many frustrated turns inside this episode
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('repair_log'); }
};
