<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('block_order')->default(0);
            $table->string('block_type', 60);
            $table->longText('content_json')->nullable();
            $table->longText('styles_json')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index('template_id');
            $table->index(['template_id', 'block_order']);
            $table->foreign('template_id')
                  ->references('id')->on('email_templates')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_blocks');
    }
};
