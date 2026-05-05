<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bella_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('role'); // user or bella
            $table->text('content');
            $table->string('action_executed')->nullable();
            $table->json('action_result')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('bella_memory', function (Blueprint $table) {
            $table->id();
            $table->string('category'); // fact, preference, insight, note
            $table->string('key');
            $table->text('value');
            $table->decimal('confidence', 3, 2)->default(1.00);
            $table->string('source'); // conversation, system, learned
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['category', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bella_memory');
        Schema::dropIfExists('bella_conversations');
    }
};
