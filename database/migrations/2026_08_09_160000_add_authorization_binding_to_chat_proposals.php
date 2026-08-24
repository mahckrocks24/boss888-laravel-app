<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SARAH888 — the pending authorization object, completed.
 *
 * The 2026_08_09_090000 migration bound a proposal to its conversation and
 * execution. That was enough to stop an approval crossing between two
 * conversations, and not enough to say what, exactly, a later "yes" authorises.
 *
 * Measured on the live chat path this morning: five publish phrasings, five
 * replies promising "confirm and I will handle it", and zero rows of any kind.
 * The promise and the system state disagreed, so a later "yes" had nothing to
 * bind to and whatever the planner did next became the authorised thing.
 *
 * Closing that needs the pending object to carry the whole of what was
 * promised, because every one of these fields is something a later
 * authorisation has to be checked AGAINST:
 *
 *   source_message_id  which user message asked for it — an approval that
 *                      cannot name its request is not auditable
 *   capability         the governance key, so policy is queryable without
 *                      re-deriving it from prose
 *   entity_type/_id    the exact thing. "Yes" must not publish a different
 *                      page than the one that was offered
 *   scope_hash         a digest of the resolved parameters. If the scope moves
 *                      between offer and approval, consent no longer covers it
 *   risk_tier          the policy band the offer was made under
 *   expires_at         explicit, not computed at read time. A TTL that lives
 *                      only in PHP cannot be queried, audited, or indexed, and
 *                      a stale row looks identical to a live one in the table
 *
 * All nullable and additive. The 1,783 existing proposals (all proactive
 * engine, zero chat_action) carry NULL and behave exactly as before. Only
 * chat-originated proposals populate them.
 *
 * These are columns rather than keys inside cost_breakdown_json for the same
 * reason the previous migration gave: an authorisation boundary has to be
 * queryable and auditable, and a key buried in a JSON blob is neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_proposals', function (Blueprint $t) {
            if (!Schema::hasColumn('strategy_proposals', 'source_message_id')) {
                $t->unsignedBigInteger('source_message_id')->nullable()->after('execution_id');
            }
            if (!Schema::hasColumn('strategy_proposals', 'capability')) {
                $t->string('capability', 128)->nullable()->after('source_message_id');
            }
            if (!Schema::hasColumn('strategy_proposals', 'entity_type')) {
                $t->string('entity_type', 64)->nullable()->after('capability');
            }
            if (!Schema::hasColumn('strategy_proposals', 'entity_id')) {
                $t->string('entity_id', 64)->nullable()->after('entity_type');
            }
            if (!Schema::hasColumn('strategy_proposals', 'scope_hash')) {
                $t->string('scope_hash', 64)->nullable()->after('entity_id');
            }
            if (!Schema::hasColumn('strategy_proposals', 'risk_tier')) {
                $t->string('risk_tier', 32)->nullable()->after('scope_hash');
            }
            if (!Schema::hasColumn('strategy_proposals', 'expires_at')) {
                $t->timestamp('expires_at')->nullable()->after('approved_at')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('strategy_proposals', function (Blueprint $t) {
            foreach (['expires_at', 'risk_tier', 'scope_hash', 'entity_id',
                      'entity_type', 'capability', 'source_message_id'] as $col) {
                if (Schema::hasColumn('strategy_proposals', $col)) $t->dropColumn($col);
            }
        });
    }
};
