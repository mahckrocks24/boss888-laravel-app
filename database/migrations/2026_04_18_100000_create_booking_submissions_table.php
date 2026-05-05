<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('website_id')->index();
            $table->string('name', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('service', 150)->nullable();
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time', 50)->nullable(); // morning|afternoon|evening or HH:MM
            $table->text('notes')->nullable();
            $table->enum('status', ['new', 'confirmed', 'cancelled'])->default('new');
            $table->json('meta_json')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_submissions');
    }
};