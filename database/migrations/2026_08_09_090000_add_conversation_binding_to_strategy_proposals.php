<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASK_FIRST cross-binding.
 *
 * A proposal has to remember which conversation asked for it. Without that, a
 * later "yes, proceed" cannot be checked against the request it claims to
 * authorise, and an approval given in one conversation can bind to a pending
 * action from another — one of the cross-binding cases the enterprise task
 * safety contract forbids.
 *
 * Both columns are nullable and additive. Existing proposals (1,735 rows at
 * time of writing, created by the proactive strategy engine) carry NULL and
 * behave exactly as before; only chat-originated proposals populate them.
 *
 * These live as columns rather than inside cost_breakdown_json because they are
 * a governance control. An authorisation boundary has to be queryable and
 * auditable, and a key buried in a JSON blob is neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_proposals', function (Blueprint $t) {
            if (!Schema::hasColumn('strategy_proposals', 'conversation_id')) {
                $t->string('conversation_id', 64)->nullable()->after('workspace_id')->index();
            }
            if (!Schema::hasColumn('strategy_proposals', 'execution_id')) {
                $t->string('execution_id', 64)->nullable()->after('conversation_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('strategy_proposals', function (Blueprint $t) {
            if (Schema::hasColumn('strategy_proposals', 'execution_id'))    $t->dropColumn('execution_id');
            if (Schema::hasColumn('strategy_proposals', 'conversation_id')) $t->dropColumn('conversation_id');
        });
    }
};