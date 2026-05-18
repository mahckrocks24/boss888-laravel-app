<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role', 20);
            $table->text('content');
            $table->json('action_proposed_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at'], 'idx_sam_ws_created');
            $table->index('created_at', 'idx_sam_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_assistant_messages');
    }
};
