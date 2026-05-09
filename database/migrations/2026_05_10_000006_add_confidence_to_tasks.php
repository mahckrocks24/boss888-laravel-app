<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (!Schema::hasColumn('tasks', 'confidence_score')) {
                $t->decimal('confidence_score', 4, 3)->nullable()->after('credit_cost');
            }
            if (!Schema::hasColumn('tasks', 'confidence_reason')) {
                $t->string('confidence_reason', 500)->nullable()->after('confidence_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (Schema::hasColumn('tasks', 'confidence_score'))  $t->dropColumn('confidence_score');
            if (Schema::hasColumn('tasks', 'confidence_reason')) $t->dropColumn('confidence_reason');
        });
    }
};
