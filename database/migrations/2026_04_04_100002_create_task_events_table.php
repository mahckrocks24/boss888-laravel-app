<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('status')->nullable();
            $table->unsignedSmallInteger('step')->default(0);
            $table->string('connector')->nullable();
            $table->string('action')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('data_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
    }
};
