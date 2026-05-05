<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->integer('reserved_balance')->default(0);
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credits');
    }
};
