<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_assistant_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 40);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('action_link', 500)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'user_id', 'read_at'], 'idx_san_unread');
            $table->index(['workspace_id', 'created_at'], 'idx_san_ws_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_assistant_notifications');
    }
};
