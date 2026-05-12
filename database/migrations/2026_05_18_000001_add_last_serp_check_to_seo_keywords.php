<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_keywords', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_keywords', 'last_serp_check')) {
                $table->timestamp('last_serp_check')->nullable()->after('last_rank_check');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_keywords', function (Blueprint $table) {
            if (Schema::hasColumn('seo_keywords', 'last_serp_check')) {
                $table->dropColumn('last_serp_check');
            }
        });
    }
};
