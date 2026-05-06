<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {

        if (!Schema::hasTable('chatbot_settings')) {
            Schema::create('chatbot_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('greeting', 500)->nullable();
                $table->string('fallback_email')->nullable();
                $table->string('primary_color', 10)->nullable()->default('#6C5CE7');
                $table->enum('theme', ['light','dark','auto'])->default('auto');
                $table->json('business_hours_json')->nullable();
                $table->string('timezone', 64)->default('UTC');
                $table->text('business_context_text')->nullable();
                $table->timestamps();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chatbot_widget_tokens')) {
            Schema::create('chatbot_widget_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('website_id')->nullable();
                $table->unsignedBigInteger('site_connection_id')->nullable();
                $table->string('token_hash')->unique();
                $table->string('token_prefix');
                $table->string('label');
                $table->json('allowed_domains_json')->nullable();
                $table->enum('status', ['active','disabled'])->default('active');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chatbot_sessions')) {
            Schema::create('chatbot_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('widget_token_id')->index();
                $table->string('page_url', 1024)->nullable();
                $table->string('visitor_fingerprint', 128)->nullable();
                $table->integer('message_count')->default(0);
                $table->string('state')->nullable();
                $table->json('captured_fields_json')->nullable();
                $table->json('intent_history_json')->nullable();
                $table->timestamps();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chatbot_messages')) {
            Schema::create('chatbot_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->enum('role', ['user','assistant','system']);
                $table->text('content');
                $table->string('intent')->nullable();
                $table->string('classifier_source')->nullable();
                $table->integer('kb_hits')->default(0);
                $table->integer('credits_used')->default(0);
                $table->json('meta_json')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('session_id')->references('id')->on('chatbot_sessions')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chatbot_escalations')) {
            Schema::create('chatbot_escalations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('session_id')->index();
                $table->string('question', 1000);
                $table->string('reason');
                $table->enum('status', ['open','closed','resolved'])->default('open');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chatbot_knowledge_sources')) {
            Schema::create('chatbot_knowledge_sources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->enum('source_type', ['file','text']);
                $table->string('label');
                $table->string('mime_type')->nullable();
                $table->integer('size_bytes')->nullable();
                $table->string('content_hash');
                $table->enum('status', ['processing','ready','failed'])->default('processing');
                $table->integer('chunk_count')->default(0);
                $table->string('stored_path')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chatbot_knowledge_chunks')) {
            Schema::create('chatbot_knowledge_chunks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('source_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->integer('chunk_index');
                $table->longText('chunk_text');
                $table->integer('char_count');
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('source_id')->references('id')->on('chatbot_knowledge_sources')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chatbot_usage_logs')) {
            Schema::create('chatbot_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->string('month_yyyymm', 6);
                $table->integer('messages_count')->default(0);
                $table->integer('credits_used')->default(0);
                $table->timestamp('last_at')->nullable();
                $table->timestamps();
                $table->unique(['workspace_id', 'month_yyyymm']);
            });
        }

        if (!Schema::hasColumn('subscriptions', 'chatbot_addon_active')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->boolean('chatbot_addon_active')->default(false)->after('status');
                $table->string('chatbot_addon_item_id')->nullable()->after('chatbot_addon_active');
            });
        }
    }

    public function down(): void {
        Schema::dropIfExists('chatbot_usage_logs');
        Schema::dropIfExists('chatbot_knowledge_chunks');
        Schema::dropIfExists('chatbot_knowledge_sources');
        Schema::dropIfExists('chatbot_escalations');
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_sessions');
        Schema::dropIfExists('chatbot_widget_tokens');
        Schema::dropIfExists('chatbot_settings');
        if (Schema::hasColumn('subscriptions', 'chatbot_addon_active')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn(['chatbot_addon_active', 'chatbot_addon_item_id']);
            });
        }
    }
};
