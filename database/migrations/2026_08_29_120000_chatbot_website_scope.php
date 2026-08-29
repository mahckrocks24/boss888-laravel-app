<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RISK-0118 (2026-08-29) — CHATBOT888 is website-scoped BY DEFAULT.
 * Sessions and the leads they create carry the website the visitor was on (provenance);
 * knowledge sources/chunks already carry website_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_sessions') && ! Schema::hasColumn('chatbot_sessions', 'website_id')) {
            Schema::table('chatbot_sessions', function (Blueprint $t) {
                $t->unsignedBigInteger('website_id')->nullable()->after('workspace_id')->index();
            });
        }
        if (Schema::hasTable('leads') && ! Schema::hasColumn('leads', 'website_id')) {
            Schema::table('leads', function (Blueprint $t) {
                $t->unsignedBigInteger('website_id')->nullable()->after('workspace_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatbot_sessions', 'website_id')) {
            Schema::table('chatbot_sessions', fn (Blueprint $t) => $t->dropColumn('website_id'));
        }
        if (Schema::hasColumn('leads', 'website_id')) {
            Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('website_id'));
        }
    }
};
