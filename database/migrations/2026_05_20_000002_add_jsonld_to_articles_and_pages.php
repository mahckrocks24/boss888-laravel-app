<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $t) {
            $t->longText('jsonld_json')->nullable()->after('seo_json');
            $t->timestamp('aeo_enriched_at')->nullable()->after('jsonld_json');
        });
        Schema::table('pages', function (Blueprint $t) {
            $t->longText('jsonld_json')->nullable()->after('seo_json');
            $t->timestamp('aeo_enriched_at')->nullable()->after('jsonld_json');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $t) {
            $t->dropColumn(['jsonld_json', 'aeo_enriched_at']);
        });
        Schema::table('pages', function (Blueprint $t) {
            $t->dropColumn(['jsonld_json', 'aeo_enriched_at']);
        });
    }
};
