<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_campaigns_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('workspace_id');
            $table->string('recipient_email', 255);
            $table->string('recipient_name', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->enum('status', ['pending','sent','bounced','opened','clicked','unsubscribed'])
                  ->default('pending');
            $table->string('postmark_message_id', 255)->nullable();
            $table->string('tracking_token', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->json('click_data')->nullable();
            $table->string('device_type', 16)->nullable(); // desktop|mobile|tablet
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('workspace_id');
            $table->index(['campaign_id', 'status']);
            $table->index('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns_log');
    }
};
