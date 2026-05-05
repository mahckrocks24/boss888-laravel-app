<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('websites', function (Blueprint $table) {
            if (!Schema::hasColumn('websites', 'type')) {
                $table->string('type', 50)->default('builder')->after('status');
            }
            if (!Schema::hasColumn('websites', 'external_url')) {
                $table->text('external_url')->nullable()->after('type');
            }
            if (!Schema::hasColumn('websites', 'thumbnail_url')) {
                $table->text('thumbnail_url')->nullable()->after('external_url');
            }
            if (!Schema::hasColumn('websites', 'connector_status')) {
                $table->string('connector_status', 20)->default('none')->after('thumbnail_url');
            }
            if (!Schema::hasColumn('websites', 'platform')) {
                $table->string('platform', 30)->nullable()->after('connector_status');
            }
        });
    }
    public function down(): void {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['type', 'external_url', 'thumbnail_url', 'connector_status', 'platform']);
        });
    }
};
