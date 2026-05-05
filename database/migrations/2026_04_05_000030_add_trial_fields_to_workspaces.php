<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'trial_started_at')) {
                $table->timestamp('trial_started_at')->nullable()->after('proactive_frequency');
            }
            if (! Schema::hasColumn('workspaces', 'trial_credits')) {
                $table->unsignedSmallInteger('trial_credits')->default(0)->after('trial_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $cols = array_filter(['trial_started_at', 'trial_credits'], fn($c) => Schema::hasColumn('workspaces', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
    }
};
