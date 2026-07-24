<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_watchlist', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('label', 200);
            $t->string('term', 200);
                // canonical query string used to scan
            $t->json('variants_json')->nullable();
                // ["LevelUp", "Level Up", "levelupgrowth.io"]
            $t->json('negative_keywords_json')->nullable();
                // ["pizza", "fruit"] — exclude false positives
            $t->string('scope', 30)->default('brand');
                // brand | product | person | hashtag | competitor
            $t->string('priority', 10)->default('normal');
                // low | normal | high
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_scanned_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->json('metadata_json')->nullable();
                // {blocked_domains:[], location_code:2840, language:"en"}
            $t->timestamps();

            $t->index(['workspace_id', 'is_active']);
            $t->index(['workspace_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_watchlist');
    }
};