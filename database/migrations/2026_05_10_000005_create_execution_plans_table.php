<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agent_execution_plans')) return;
        Schema::create('agent_execution_plans', function (Blueprint $t) {
            $t->string('id', 60)->primary();           // uuid-style string e.g. plan_<uniqid>
            $t->unsignedBigInteger('workspace_id')->index();
            $t->string('goal', 500);
            $t->json('task_ids')->nullable();          // [task_id, ...] in sequence order
            $t->string('status', 30)->default('running'); // running | completed | failed | cancelled
            $t->json('meta')->nullable();              // freeform: source agent, plan signature, etc
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'status']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_execution_plans');
    }
};
