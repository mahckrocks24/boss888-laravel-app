<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (!Schema::hasColumn('workspaces', 'trial_expires_at')) {
                $table->timestamp('trial_expires_at')->nullable()->after('trial_started_at');
            }
            if (!Schema::hasColumn('workspaces', 'is_trial')) {
                $table->boolean('is_trial')->default(false)->after('trial_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            foreach (['trial_expires_at', 'is_trial'] as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
