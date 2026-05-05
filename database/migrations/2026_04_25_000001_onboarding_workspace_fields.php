<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'brand_color')) {
                $table->string('brand_color', 7)->nullable()->after('location');
            }
            if (! Schema::hasColumn('workspaces', 'logo_url')) {
                $table->string('logo_url', 2048)->nullable()->after('brand_color');
            }
            if (! Schema::hasColumn('workspaces', 'onboarding_step')) {
                $table->unsignedTinyInteger('onboarding_step')->default(1)->after('onboarded_at');
            }
            if (! Schema::hasColumn('workspaces', 'onboarding_data')) {
                $table->json('onboarding_data')->nullable()->after('onboarding_step');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            foreach (['brand_color', 'logo_url', 'onboarding_step', 'onboarding_data'] as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
