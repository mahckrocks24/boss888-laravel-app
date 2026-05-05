<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FIX v1.0.2: This migration previously tried to CREATE meetings,
     * meeting_messages, and meeting_participants — all three already exist
     * from migrations 000018, 000019, and 000020. That caused a fatal
     * "Table already exists" error on every fresh deploy.
     *
     * Corrected approach:
     *   - strategy_proposals → new CREATE (first time it exists)
     *   - meetings           → ALTER TABLE, add missing columns: type, metadata_json, total_credits_used
     *   - meeting_messages   → ALTER TABLE, add missing column: tokens_used
     *   - meeting_participants→ ALTER TABLE, add missing column: joined_at
     *
     * All column additions are guarded with Schema::hasColumn() for idempotency.
     */
    public function up(): void
    {
        // ── strategy_proposals (NEW TABLE) ───────────────────────────────
        // Every Sarah action is gated by a proposal — user must approve before
        // any credits are spent or tasks dispatched.
        if (! Schema::hasTable('strategy_proposals')) {
            Schema::create('strategy_proposals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->string('type', 50);
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status', 30)->default('pending_approval');
                $table->json('cost_breakdown_json');
                $table->integer('total_credits')->default(0);
                $table->string('reservation_ref')->nullable();
                $table->unsignedBigInteger('meeting_id')->nullable();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->index(['workspace_id', 'status'], 'strat_proposals_ws_status_idx');
            });
        }

        // ── meetings (ALTER — add columns missing from 000018 schema) ────
        Schema::table('meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('meetings', 'type')) {
                $table->string('type', 30)->default('strategy')->after('title');
            }
            if (! Schema::hasColumn('meetings', 'metadata_json')) {
                $table->json('metadata_json')->nullable()->after('status');
            }
            if (! Schema::hasColumn('meetings', 'total_credits_used')) {
                $table->integer('total_credits_used')->default(0)->after('metadata_json');
            }
        });

        // ── meeting_messages (ALTER — add tokens_used, missing from 000019) ──
        Schema::table('meeting_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('meeting_messages', 'tokens_used')) {
                $table->integer('tokens_used')->default(0)->after('attachments_json');
            }
        });

        // ── meeting_participants (ALTER — add joined_at, missing from 000020) ─
        Schema::table('meeting_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('meeting_participants', 'joined_at')) {
                $table->timestamp('joined_at')->nullable()->after('participant_id');
            }
        });
    }

    public function down(): void
    {
        // Remove strategy_proposals
        Schema::dropIfExists('strategy_proposals');

        // Revert ALTER TABLE additions
        Schema::table('meetings', function (Blueprint $table) {
            $cols = array_filter(['type', 'metadata_json', 'total_credits_used'], fn($c) => Schema::hasColumn('meetings', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });

        Schema::table('meeting_messages', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_messages', 'tokens_used')) {
                $table->dropColumn('tokens_used');
            }
        });

        Schema::table('meeting_participants', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_participants', 'joined_at')) {
                $table->dropColumn('joined_at');
            }
        });
    }
};
