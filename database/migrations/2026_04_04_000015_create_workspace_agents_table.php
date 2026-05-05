<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('custom_name')->nullable();
            $table->string('custom_avatar')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_agents');
    }
};
