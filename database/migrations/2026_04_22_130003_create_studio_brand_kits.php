<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('studio_brand_kits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->unique();
            $t->string('primary_color',    7)->default('#6C5CE7');
            $t->string('secondary_color',  7)->default('#00E5A8');
            $t->string('accent_color',     7)->default('#F59E0B');
            $t->string('background_color', 7)->default('#FFFFFF');
            $t->string('text_color',       7)->default('#0F172A');
            $t->string('heading_font',   100)->default('Syne');
            $t->string('body_font',      100)->default('DM Sans');
            $t->string('logo_url',      2048)->nullable();
            $t->string('logo_dark_url', 2048)->nullable();
            $t->string('brand_name',     255)->nullable();
            $t->string('tagline',        500)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_brand_kits');
    }
};
