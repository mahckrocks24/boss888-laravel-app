<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAGE-ONE-1 (RFC-0020): Sarah as every business's SEO / AEO / GEO specialist.
 *   search_pages    every public URL of every managed website: when it appeared, search engines told, in the sitemap,
 *                   indexed, the search it targets, when it was optimized, and its performance bucket from Search Console
 *   search_targets  the keyword universe Sarah picked for a website (value, difficulty, intent, cluster, the top results)
 *   search_plans    the Search Roadmap and each monthly tune-up (campaigns the owner approves once)
 *   heatmap_events  clicks, taps and scroll depth on published pages — no personal data, no cookies
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('search_pages')) Schema::create('search_pages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('website_id');
            $t->string('url', 700);
            $t->char('url_hash', 40);
            $t->string('kind', 16)->default('page');            // page | article | job | wp
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('title', 300)->nullable();
            $t->boolean('baseline')->default(false);             // present when Sarah first looked: not "new"
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('gone_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->string('notify_result', 60)->nullable();
            $t->boolean('in_sitemap')->nullable();
            $t->timestamp('sitemap_checked_at')->nullable();
            $t->boolean('indexed')->nullable();
            $t->string('index_state', 120)->nullable();
            $t->timestamp('index_checked_at')->nullable();
            $t->string('target_query', 200)->nullable();
            $t->timestamp('optimized_at')->nullable();
            $t->text('optimize_json')->nullable();
            $t->string('bucket', 24)->nullable();
            $t->text('bucket_json')->nullable();
            $t->timestamp('bucket_at')->nullable();
            $t->unsignedInteger('clicks_28')->nullable();
            $t->unsignedInteger('impressions_28')->nullable();
            $t->decimal('position_28', 6, 2)->nullable();
            $t->string('top_query', 200)->nullable();
            $t->timestamp('told_at')->nullable();
            $t->timestamps();
            $t->unique(['website_id', 'url_hash']);
            $t->index(['website_id', 'bucket']);
        });
        if (! Schema::hasTable('search_targets')) Schema::create('search_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('website_id');
            $t->unsignedBigInteger('business_id')->nullable();
            $t->string('keyword', 200);
            $t->unsignedInteger('volume')->nullable();
            $t->unsignedTinyInteger('difficulty')->nullable();
            $t->string('intent', 16)->nullable();                 // learn | compare | buy | local
            $t->string('cluster', 120)->nullable();
            $t->boolean('pillar')->default(false);
            $t->unsignedTinyInteger('score')->nullable();          // value x winnability, 0-100
            $t->string('why', 300)->nullable();
            $t->string('status', 16)->default('target');           // target | planned | written | ranking | dropped
            $t->text('serp_json')->nullable();
            $t->timestamp('serp_at')->nullable();
            $t->unsignedBigInteger('article_id')->nullable();
            $t->decimal('position', 6, 2)->nullable();
            $t->timestamp('position_at')->nullable();
            $t->timestamps();
            $t->unique(['website_id', 'keyword']);
        });
        if (! Schema::hasTable('search_plans')) Schema::create('search_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('website_id');
            $t->unsignedBigInteger('business_id')->nullable();
            $t->string('kind', 16);                                // foundation | roadmap | tuneup | merge | report
            $t->string('period', 20)->nullable();
            $t->string('status', 16)->default('proposed');
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->longText('facts_json')->nullable();
            $t->timestamps();
            $t->index(['website_id', 'kind']);
        });
        if (! Schema::hasTable('heatmap_events')) Schema::create('heatmap_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('website_id');
            $t->string('path', 300);
            $t->char('device', 1);                                 // m | d
            $t->char('type', 1);                                   // c = click, v = view (with scroll depth)
            $t->unsignedSmallInteger('x')->nullable();             // per mille of the viewport width
            $t->unsignedInteger('y')->nullable();                  // px from the top of the document
            $t->unsignedInteger('doc_h')->nullable();
            $t->unsignedSmallInteger('depth')->nullable();         // per mille of the document seen
            $t->string('sel', 160)->nullable();
            $t->boolean('link')->nullable();
            $t->char('pv', 12)->nullable();                         // random per page view, never stored on the device
            $t->timestamp('created_at')->nullable();
            $t->index(['website_id', 'path', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['heatmap_events', 'search_plans', 'search_targets', 'search_pages'] as $t) Schema::dropIfExists($t);
    }
};
