<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->enum('participant_type', ['user', 'agent']);
            $table->unsignedBigInteger('participant_id');
            $table->timestamps();

            // FIX: auto-generated name was 70 chars (MySQL limit = 64).
            // Explicit short name enforced.
            $table->unique(
                ['meeting_id', 'participant_type', 'participant_id'],
                'mtg_parts_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
    }
};
