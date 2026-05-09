<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('strategy_outcomes')) return;
        Schema::create('strategy_outcomes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('strategy_type', 100);
            $t->json('strategy_data');
            $t->json('outcome_data');
            $t->decimal('success_score', 4, 3)->default(0.500);
            $t->text('sarah_notes')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'strategy_type']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_outcomes');
    }
};
