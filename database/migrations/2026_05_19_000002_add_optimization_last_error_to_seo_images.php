<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_images', function (Blueprint $table) {
            $table->string('optimization_last_error', 60)->nullable()->after('optimization_provider');
        });
    }

    public function down(): void
    {
        Schema::table('seo_images', function (Blueprint $table) {
            $table->dropColumn('optimization_last_error');
        });
    }
};
