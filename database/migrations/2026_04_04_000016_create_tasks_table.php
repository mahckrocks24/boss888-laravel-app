<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('engine');
            $table->string('action');
            $table->json('payload_json')->nullable();
            $table->enum('status', ['pending', 'queued', 'running', 'failed', 'completed'])->default('pending');
            $table->boolean('requires_approval')->default(false);
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'revised'])->nullable();
            $table->enum('source', ['manual', 'agent', 'system'])->default('manual');
            $table->json('assigned_agents_json')->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->json('result_json')->nullable();
            $table->text('error_text')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->integer('credit_cost')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'engine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
