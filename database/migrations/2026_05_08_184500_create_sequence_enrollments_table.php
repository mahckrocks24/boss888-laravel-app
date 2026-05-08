<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedBigInteger('contact_id');
            $table->timestamp('enrolled_at')->useCurrent();
            $table->unsignedInteger('current_step_order')->default(1);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('status', 20)->default('active'); // active|completed|stopped|unsubscribed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'sequence_id', 'contact_id'], 'uniq_ws_seq_contact');
            $table->index(['status', 'sequence_id', 'current_step_order'], 'idx_active_due');
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_enrollments');
    }
};
