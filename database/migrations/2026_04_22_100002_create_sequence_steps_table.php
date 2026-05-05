<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedInteger('step_order')->default(0);
            $table->string('type', 20)->default('email'); // email | delay | condition
            $table->unsignedInteger('delay_hours')->default(0);
            $table->string('email_subject', 255)->nullable();
            $table->longText('email_body_html')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();

            $table->index('sequence_id');
            $table->index(['sequence_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_steps');
    }
};
