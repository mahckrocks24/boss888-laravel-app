<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            $table->unsignedSmallInteger('score_version')->default(1)->after('score_breakdown_json');
            $table->timestamp('scored_at')->nullable()->after('score_version');
            $table->index(['workspace_id', 'score_version'], 'idx_sci_score_version');
        });
    }

    public function down(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            $table->dropIndex('idx_sci_score_version');
            $table->dropColumn(['score_version', 'scored_at']);
        });
    }
};
