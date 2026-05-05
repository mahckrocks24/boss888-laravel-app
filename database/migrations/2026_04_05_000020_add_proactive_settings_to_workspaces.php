<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'proactive_enabled')) {
                $table->boolean('proactive_enabled')->default(true)->after('onboarded_at');
            }
            if (! Schema::hasColumn('workspaces', 'proactive_frequency')) {
                $table->string('proactive_frequency', 20)->default('daily')->after('proactive_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $cols = array_filter(['proactive_enabled', 'proactive_frequency'], fn($c) => Schema::hasColumn('workspaces', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
    }
};
