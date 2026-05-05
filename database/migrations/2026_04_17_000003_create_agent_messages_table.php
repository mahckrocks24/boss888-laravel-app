<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('agent_messages')) {
            Schema::create('agent_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('agent_slug', 30)->index();
                $table->string('sender', 50); // 'user' or agent name
                $table->text('content');
                $table->string('role', 20)->default('user'); // user | agent
                $table->json('metadata_json')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['workspace_id', 'agent_slug', 'created_at']);
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('agent_messages');
    }
};
