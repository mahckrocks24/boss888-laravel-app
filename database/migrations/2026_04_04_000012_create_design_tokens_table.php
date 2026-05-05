<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->json('tokens_json');
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_tokens');
    }
};
