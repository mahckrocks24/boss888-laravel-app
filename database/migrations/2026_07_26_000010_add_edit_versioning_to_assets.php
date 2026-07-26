<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STUDIO888 Phase O — non-destructive edit versioning for assets.
 * Purely ADDITIVE (nullable columns + indexes). No existing data is touched;
 * originals keep version=1 and null parent/root. Safe to run on production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            if (! Schema::hasColumn('assets', 'parent_asset_id')) {
                $t->unsignedBigInteger('parent_asset_id')->nullable()->after('creative_job_id')->index();
            }
            if (! Schema::hasColumn('assets', 'root_asset_id')) {
                $t->unsignedBigInteger('root_asset_id')->nullable()->after('parent_asset_id')->index();
            }
            if (! Schema::hasColumn('assets', 'version')) {
                $t->unsignedInteger('version')->default(1)->after('root_asset_id');
            }
            if (! Schema::hasColumn('assets', 'edit_mode')) {
                $t->string('edit_mode', 20)->nullable()->after('version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            foreach (['edit_mode', 'version', 'root_asset_id', 'parent_asset_id'] as $col) {
                if (Schema::hasColumn('assets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
