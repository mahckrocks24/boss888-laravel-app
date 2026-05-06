<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('builder_default_assets')) {
            Schema::create('builder_default_assets', function (Blueprint $table) {
                $table->id();
                $table->string('industry', 100);
                $table->string('asset_type', 50)->default('hero');
                $table->string('url', 500);
                $table->timestamps();
                $table->unique(['industry', 'asset_type']);
                $table->index('asset_type');
            });
        }

        $industries = [
            'architecture', 'automotive', 'cafe', 'childcare', 'cleaning',
            'construction', 'consulting', 'education', 'finance', 'hospitality',
            'logistics', 'marketing_agency', 'pet_services', 'photography',
            'real_estate_broker', 'technology', 'wellness',
        ];

        foreach ($industries as $industry) {
            DB::table('builder_default_assets')->updateOrInsert(
                ['industry' => $industry, 'asset_type' => 'hero'],
                ['url' => '/storage/builder-heroes/' . $industry . '.jpg', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_default_assets');
    }
};
