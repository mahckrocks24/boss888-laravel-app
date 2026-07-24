<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferences_json')) {
                // Per-user preference bag. v1 carries only visibility_mode
                // (basic|advanced) for the sidebar toggle; future preferences
                // (theme, default landing tab, etc.) ride the same column.
                $table->longText('preferences_json')->nullable()->after('avatar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferences_json')) {
                $table->dropColumn('preferences_json');
            }
        });
    }
};
