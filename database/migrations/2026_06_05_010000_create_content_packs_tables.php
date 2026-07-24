<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * content_packs — cross-engine campaign asset bundle.
 *   - One pack = one campaign theme. Bound to optional campaigns.id.
 *   - brand_kit_snapshot is FROZEN at creation time (json) so every asset
 *     within the pack stays brand-consistent even if the workspace later
 *     edits its brand kit. This is the reproducibility contract.
 *
 * content_pack_assets — N-row asset table per pack.
 *   - engine + asset_kind identify what kind of asset (article, email,
 *     social_post, design, image). entity_type + entity_id point at the
 *     engine-native row (e.g., articles.id, email_campaigns.id).
 *   - status tracks draft → approved → published per asset.
 *   - sequence preserves the orchestration order.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_packs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('campaign_id')->nullable();   // optional FK to campaigns
            $t->string('name', 255);
            $t->string('theme', 255)->nullable();                // e.g., "Q3 gym new-year challenge"
            $t->string('status', 30)->default('draft');          // draft → assembling → ready → published → archived
            $t->json('brand_kit_snapshot');                      // frozen resolver output at create time
            $t->json('meta_json')->nullable();                   // free-form (audience, channels, schedule_window, etc.)
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index('workspace_id');
            $t->index('campaign_id');
            $t->index(['workspace_id', 'status']);
        });

        Schema::create('content_pack_assets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('pack_id');
            $t->unsignedBigInteger('workspace_id');              // denormalized for fast tenant scoping
            $t->string('engine', 40);                             // social, marketing, write, studio, creative
            $t->string('asset_kind', 40);                         // article, email, social_post, design, image
            $t->string('entity_type', 80)->nullable();            // FQCN or table name
            $t->unsignedBigInteger('entity_id')->nullable();      // engine-native PK
            $t->unsignedInteger('sequence')->default(0);
            $t->string('status', 30)->default('pending');         // pending → draft → approved → published → failed
            $t->json('meta_json')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();

            $t->index('pack_id');
            $t->index(['workspace_id', 'engine']);
            $t->index(['pack_id', 'sequence']);
            $t->foreign('pack_id')->references('id')->on('content_packs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pack_assets');
        Schema::dropIfExists('content_packs');
    }
};