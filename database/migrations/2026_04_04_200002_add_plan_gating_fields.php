<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'ai_access')) {
                $table->string('ai_access', 20)->default('none')->after('features_json');
            }
            if (! Schema::hasColumn('plans', 'includes_dmm')) {
                $table->boolean('includes_dmm')->default(false)->after('ai_access');
            }
            if (! Schema::hasColumn('plans', 'agent_count')) {
                $table->integer('agent_count')->default(0)->after('includes_dmm');
            }
            if (! Schema::hasColumn('plans', 'agent_level')) {
                $table->string('agent_level', 20)->nullable()->after('agent_count');
            }
            if (! Schema::hasColumn('plans', 'agent_addon_price')) {
                $table->decimal('agent_addon_price', 8, 2)->nullable()->after('agent_level');
            }
            if (! Schema::hasColumn('plans', 'max_websites')) {
                $table->integer('max_websites')->default(1)->after('agent_addon_price');
            }
            if (! Schema::hasColumn('plans', 'max_team_members')) {
                $table->integer('max_team_members')->default(1)->after('max_websites');
            }
            if (! Schema::hasColumn('plans', 'companion_app')) {
                $table->boolean('companion_app')->default(false)->after('max_team_members');
            }
            if (! Schema::hasColumn('plans', 'white_label')) {
                $table->boolean('white_label')->default(false)->after('companion_app');
            }
            if (! Schema::hasColumn('plans', 'priority_processing')) {
                $table->boolean('priority_processing')->default(false)->after('white_label');
            }
        });
    }

    public function down(): void
    {
        $cols = [
            'ai_access', 'includes_dmm', 'agent_count', 'agent_level',
            'agent_addon_price', 'max_websites', 'max_team_members',
            'companion_app', 'white_label', 'priority_processing',
        ];
        Schema::table('plans', function (Blueprint $table) use ($cols) {
            $existing = array_filter($cols, fn($c) => Schema::hasColumn('plans', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
