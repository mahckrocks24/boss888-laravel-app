<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            if (! Schema::hasColumn('seo_content_index', 'aeo_enriched_at')) {
                $table->timestamp('aeo_enriched_at')->nullable()->after('scored_at');
            }
            if (! Schema::hasColumn('seo_content_index', 'aeo_jsonld_json')) {
                $table->longText('aeo_jsonld_json')->nullable()->after('aeo_enriched_at');
            }
            if (! Schema::hasColumn('seo_content_index', 'aeo_tldr')) {
                $table->text('aeo_tldr')->nullable()->after('aeo_jsonld_json');
            }
            if (! Schema::hasColumn('seo_content_index', 'aeo_faq_json')) {
                $table->longText('aeo_faq_json')->nullable()->after('aeo_tldr');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            foreach (['aeo_faq_json', 'aeo_tldr', 'aeo_jsonld_json', 'aeo_enriched_at'] as $col) {
                if (Schema::hasColumn('seo_content_index', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
