<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'business_name')) {
                $table->string('business_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('workspaces', 'industry')) {
                $table->string('industry')->nullable()->after('business_name');
            }
            if (! Schema::hasColumn('workspaces', 'services_json')) {
                $table->json('services_json')->nullable()->after('industry');
            }
            if (! Schema::hasColumn('workspaces', 'goal')) {
                $table->string('goal', 50)->nullable()->after('services_json');
            }
            if (! Schema::hasColumn('workspaces', 'location')) {
                $table->string('location')->nullable()->after('goal');
            }
            if (! Schema::hasColumn('workspaces', 'onboarded')) {
                $table->boolean('onboarded')->default(false)->after('location');
            }
            if (! Schema::hasColumn('workspaces', 'onboarded_at')) {
                $table->timestamp('onboarded_at')->nullable()->after('onboarded');
            }
        });
    }

    public function down(): void
    {
        $cols = ['business_name', 'industry', 'services_json', 'goal', 'location', 'onboarded', 'onboarded_at'];
        Schema::table('workspaces', function (Blueprint $table) use ($cols) {
            $existing = array_filter($cols, fn($c) => Schema::hasColumn('workspaces', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
