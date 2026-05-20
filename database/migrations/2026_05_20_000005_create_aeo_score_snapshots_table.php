<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aeo_score_snapshots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedTinyInteger('avg_score')->nullable();
            $t->unsignedInteger('audits_count')->default(0);
            $t->unsignedInteger('articles_total')->default(0);
            $t->unsignedInteger('articles_enriched')->default(0);
            $t->timestamp('captured_at')->useCurrent();
            $t->index(['workspace_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aeo_score_snapshots');
    }
};
