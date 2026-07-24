<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotfix 2026-06-30 — chatbot_sessions schema/code drift.
 *
 * ChatbotResponseService (lead capture) writes visitor_name/email/phone +
 * lead_id, and the sessions list query reads those plus ended_at. The original
 * create_chatbot_tables migration (2026_05_06_200000) never declared them, so
 * every lead capture 500'd ("Unknown column 'lead_id'" / 'visitor_name').
 * Visitor data was only ever in captured_fields_json. This adds the dedicated
 * columns the live code expects. All guarded — safe to re-run, no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_sessions')) {
            return;
        }

        Schema::table('chatbot_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('chatbot_sessions', 'visitor_name')) {
                $table->string('visitor_name')->nullable()->after('state');
            }
            if (!Schema::hasColumn('chatbot_sessions', 'visitor_email')) {
                $table->string('visitor_email')->nullable()->after('visitor_name');
            }
            if (!Schema::hasColumn('chatbot_sessions', 'visitor_phone')) {
                $table->string('visitor_phone', 64)->nullable()->after('visitor_email');
            }
            if (!Schema::hasColumn('chatbot_sessions', 'lead_id')) {
                $table->unsignedBigInteger('lead_id')->nullable()->after('visitor_phone');
                $table->index('lead_id');
            }
            if (!Schema::hasColumn('chatbot_sessions', 'ended_at')) {
                $table->timestamp('ended_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_sessions')) {
            return;
        }

        Schema::table('chatbot_sessions', function (Blueprint $table) {
            foreach (['visitor_name', 'visitor_email', 'visitor_phone', 'lead_id', 'ended_at'] as $col) {
                if (Schema::hasColumn('chatbot_sessions', $col)) {
                    if ($col === 'lead_id') {
                        // drop index first (Laravel default name)
                        try { $table->dropIndex('chatbot_sessions_lead_id_index'); } catch (\Throwable $e) {}
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
