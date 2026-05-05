<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->enum('sender_type', ['user', 'agent', 'system']);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('message');
            $table->json('attachments_json')->nullable();
            $table->timestamps();

            $table->index('meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_messages');
    }
};
