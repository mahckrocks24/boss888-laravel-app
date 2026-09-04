<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** KABAYAN888 QATAR-1 — a commission targets an edition (AE, QA, ALL). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desk_commissions') && !Schema::hasColumn('desk_commissions', 'region')) {
            Schema::table('desk_commissions', function (Blueprint $t) { $t->string('region', 8)->nullable()->after('section_slug'); });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('desk_commissions') && Schema::hasColumn('desk_commissions', 'region')) {
            Schema::table('desk_commissions', function (Blueprint $t) { $t->dropColumn('region'); });
        }
    }
};
