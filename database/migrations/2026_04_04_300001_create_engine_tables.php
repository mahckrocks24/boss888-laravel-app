<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── CRM: Pipeline Stages ─────────────────────────────────
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->integer('position')->default(0);
            $table->string('color', 10)->default('#3B82F6');
            $table->decimal('default_probability', 5, 2)->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'slug']);
        });

        // ── SEO: Audits ──────────────────────────────────────────
        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('url');
            $table->string('type', 30)->default('full'); // full, page, technical
            $table->string('status', 20)->default('pending');
            $table->integer('score')->nullable();
            $table->json('results_json')->nullable();
            $table->json('issues_json')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── SEO: Keywords ────────────────────────────────────────
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('keyword');
            $table->integer('volume')->nullable();
            $table->decimal('difficulty', 5, 2)->nullable();
            $table->decimal('cpc', 8, 2)->nullable();
            $table->integer('current_rank')->nullable();
            $table->integer('previous_rank')->nullable();
            $table->string('target_url')->nullable();
            $table->string('status', 20)->default('tracking'); // tracking, paused, archived
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── SEO: Goals ───────────────────────────────────────────
        Schema::create('seo_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active, paused, completed, failed
            $table->string('assigned_agent', 20)->default('sarah');
            $table->json('progress_json')->nullable();
            $table->json('tasks_json')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── SEO: Link Suggestions ────────────────────────────────
        Schema::create('seo_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('source_url');
            $table->string('target_url');
            $table->string('anchor_text')->nullable();
            $table->string('type', 20)->default('internal'); // internal, outbound
            $table->string('status', 20)->default('suggested'); // suggested, inserted, dismissed
            $table->integer('priority_score')->default(50);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Write: Articles ──────────────────────────────────────
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('status', 20)->default('draft'); // draft, review, approved, published
            $table->string('type', 30)->default('blog_post'); // blog_post, landing_page, email, social, product_desc
            $table->json('seo_json')->nullable(); // title, description, keywords
            $table->json('brief_json')->nullable(); // topic, keywords, tone, length, audience
            $table->integer('word_count')->default(0);
            $table->decimal('seo_score', 5, 2)->nullable();
            $table->decimal('readability_score', 5, 2)->nullable();
            $table->string('assigned_agent', 20)->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Write: Article Versions ──────────────────────────────
        Schema::create('article_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->integer('version_number');
            $table->longText('content');
            $table->string('change_summary')->nullable();
            $table->string('changed_by', 30)->nullable(); // user:{id} or agent:{slug}
            $table->timestamps();
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
        });

        // ── Creative: Assets ─────────────────────────────────────
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('type', 20); // image, video
            $table->string('title')->nullable();
            $table->string('prompt')->nullable();
            $table->string('provider', 30)->nullable(); // openai, minimax, runway
            $table->string('model', 50)->nullable(); // gpt-image-1, hailuo-02
            $table->string('status', 20)->default('generating'); // generating, completed, failed
            $table->string('url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->integer('file_size')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('duration_seconds')->nullable(); // video only
            $table->json('metadata_json')->nullable();
            $table->json('tags_json')->nullable();
            $table->string('job_id')->nullable(); // async job tracking
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Builder: Websites ────────────────────────────────────
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('subdomain')->nullable();
            $table->string('status', 20)->default('draft'); // draft, published, archived
            $table->string('template')->nullable();
            $table->json('settings_json')->nullable(); // theme, fonts, colors
            $table->json('seo_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Builder: Pages ───────────────────────────────────────
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->string('title');
            $table->string('slug');
            $table->string('type', 30)->default('page'); // page, landing, blog_post
            $table->string('status', 20)->default('draft');
            $table->json('sections_json')->nullable(); // schemaVersion:1 page→sections→containers→elements
            $table->json('seo_json')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_homepage')->default(false);
            $table->timestamps();
            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
            $table->unique(['website_id', 'slug']);
        });

        // ── Marketing: Campaigns ─────────────────────────────────
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('type', 30)->default('email'); // email, sms, whatsapp
            $table->string('status', 20)->default('draft'); // draft, scheduled, sending, sent, failed
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('recipients_json')->nullable(); // list IDs or segments
            $table->json('stats_json')->nullable(); // sent, delivered, opened, clicked, bounced
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Marketing: Email Templates ───────────────────────────
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('category', 30)->default('general');
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('variables_json')->nullable(); // merge tags
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Marketing: Automations ───────────────────────────────
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('status', 20)->default('draft'); // draft, active, paused
            $table->string('trigger_type', 50); // lead_created, deal_stage_changed, form_submitted, tag_added, date_reached
            $table->json('trigger_config_json')->nullable();
            $table->json('steps_json')->nullable(); // array of {type, config, delay_minutes}
            $table->integer('execution_count')->default(0);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Social: Accounts ─────────────────────────────────────
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('platform', 30); // instagram, facebook, twitter, linkedin, snapchat, tiktok
            $table->string('account_name');
            $table->string('account_id')->nullable();
            $table->json('credentials_json')->nullable(); // encrypted tokens
            $table->string('status', 20)->default('connected');
            $table->json('stats_json')->nullable(); // followers, posts, engagement
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Social: Posts ────────────────────────────────────────
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('social_account_id')->nullable();
            $table->string('platform', 30);
            $table->text('content');
            $table->json('media_json')->nullable(); // image/video URLs
            $table->json('hashtags_json')->nullable();
            $table->string('status', 20)->default('draft'); // draft, scheduled, published, failed
            $table->string('external_post_id')->nullable();
            $table->json('stats_json')->nullable(); // impressions, reach, engagement, clicks
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Calendar: Events ─────────────────────────────────────
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 30)->default('general'); // meeting, task_deadline, campaign_launch, content_publish, social_post
            $table->string('engine', 30)->nullable(); // which engine created it
            $table->unsignedBigInteger('reference_id')->nullable(); // linked entity
            $table->string('reference_type', 50)->nullable();
            $table->string('color', 10)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('recurrence', 30)->nullable(); // daily, weekly, monthly, yearly
            $table->json('recurrence_config_json')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── BeforeAfter: Designs ─────────────────────────────────
        Schema::create('ba_designs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('room_type', 30)->default('living_room');
            $table->string('style', 30)->default('modern');
            $table->string('before_image_url');
            $table->string('after_image_url')->nullable();
            $table->string('status', 20)->default('processing'); // processing, completed, failed
            $table->json('geometry_json')->nullable(); // GPT-4o Vision analysis
            $table->json('report_json')->nullable(); // 7-section design report
            $table->string('aspect_ratio', 10)->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Traffic Defense: Rules ────────────────────────────────
        Schema::create('traffic_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('type', 30); // ip_block, country_block, ua_block, rate_limit, referrer_block
            $table->json('config_json'); // rule parameters
            $table->boolean('enabled')->default(true);
            $table->integer('hits')->default(0);
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // ── Traffic Defense: Logs ─────────────────────────────────
        Schema::create('traffic_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('ip', 45);
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->string('country', 5)->nullable();
            $table->string('action', 20); // allowed, blocked, flagged
            $table->string('rule_name')->nullable();
            $table->integer('quality_score')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'created_at']);
        });

        // ── ManualEdit: Canvas States ────────────────────────────
        Schema::create('canvas_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('name')->nullable();
            $table->json('state_json'); // canvas data: layers, elements, dimensions
            $table->json('operations_json')->nullable(); // operation history for undo/redo
            $table->string('status', 20)->default('draft'); // draft, exported
            $table->string('export_url')->nullable();
            $table->string('export_format', 10)->nullable(); // png, jpg, webp, pdf
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_states');
        Schema::dropIfExists('traffic_logs');
        Schema::dropIfExists('traffic_rules');
        Schema::dropIfExists('ba_designs');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('automations');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('websites');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('article_versions');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('seo_links');
        Schema::dropIfExists('seo_goals');
        Schema::dropIfExists('seo_keywords');
        Schema::dropIfExists('seo_audits');
        Schema::dropIfExists('pipeline_stages');
    }
};
