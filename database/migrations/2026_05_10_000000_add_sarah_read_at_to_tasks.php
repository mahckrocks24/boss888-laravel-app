<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->timestamp('sarah_read_at')->nullable()->after('completed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropIndex(['sarah_read_at']);
            $t->dropColumn('sarah_read_at');
        });
    }
};
