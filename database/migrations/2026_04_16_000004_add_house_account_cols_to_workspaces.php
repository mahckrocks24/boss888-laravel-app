<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (!Schema::hasColumn('workspaces', 'is_house_account')) {
                $table->boolean('is_house_account')->default(false)->after('settings_json');
            }
            if (!Schema::hasColumn('workspaces', 'credits_auto_replenish')) {
                $table->boolean('credits_auto_replenish')->default(false)->after('is_house_account');
            }
            if (!Schema::hasColumn('workspaces', 'monthly_credit_allowance')) {
                $table->integer('monthly_credit_allowance')->default(0)->after('credits_auto_replenish');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['is_house_account', 'credits_auto_replenish', 'monthly_credit_allowance']);
        });
    }
};
