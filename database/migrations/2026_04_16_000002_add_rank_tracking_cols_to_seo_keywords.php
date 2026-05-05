<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_keywords', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_keywords', 'rank_change')) {
                $table->integer('rank_change')->nullable()->after('previous_rank');
            }
            if (!Schema::hasColumn('seo_keywords', 'last_rank_check')) {
                $table->timestamp('last_rank_check')->nullable()->after('rank_change');
            }
            if (!Schema::hasColumn('seo_keywords', 'rank_url')) {
                $table->string('rank_url', 2048)->nullable()->after('last_rank_check');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->dropColumn(['rank_change', 'last_rank_check', 'rank_url']);
        });
    }
};
