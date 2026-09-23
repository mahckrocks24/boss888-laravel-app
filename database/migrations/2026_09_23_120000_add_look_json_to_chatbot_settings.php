<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHATBOT-LOOK-1 (2026-09-23, Owner: "On Chatbot888 icon, when long pressed on editor, show and add options to change
 * icon and colour"). Additive: one nullable JSON column holding the launcher's look for a website's chatbot row —
 * {"icon": "<image url or data uri>", "color": "#RRGGBB"}. Absent = the site's own brand colour and the default glyph,
 * exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_settings') && ! Schema::hasColumn('chatbot_settings', 'look_json')) {
            Schema::table('chatbot_settings', function (Blueprint $t) { $t->text('look_json')->nullable()->after('theme'); });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatbot_settings', 'look_json')) {
            Schema::table('chatbot_settings', function (Blueprint $t) { $t->dropColumn('look_json'); });
        }
    }
};
