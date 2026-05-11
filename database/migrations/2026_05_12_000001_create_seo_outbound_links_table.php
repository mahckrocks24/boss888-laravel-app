<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('seo_outbound_links')) { return; }
        Schema::create('seo_outbound_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('source_url', 500);
            $table->string('target_url', 500);
            $table->string('target_host', 255)->nullable()->index();
            $table->string('anchor_text', 300)->nullable();
            $table->string('rel', 100)->nullable();
            $table->string('status', 20)->default('unchecked');
            $table->integer('http_status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'source_url']);
            $table->unique(['workspace_id', 'source_url', 'target_url'], 'unique_outbound_link');
        });
    }
    public function down(): void { Schema::dropIfExists('seo_outbound_links'); }
};
