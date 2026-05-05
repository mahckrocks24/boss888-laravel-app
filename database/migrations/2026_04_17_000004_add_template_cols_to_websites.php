<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('websites', function (Blueprint $table) {
            if (!Schema::hasColumn('websites', 'template_industry')) {
                $table->string('template_industry', 100)->nullable()->after('type');
            }
            if (!Schema::hasColumn('websites', 'template_variables')) {
                $table->json('template_variables')->nullable()->after('template_industry');
            }
        });
    }
    public function down(): void {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['template_industry', 'template_variables']);
        });
    }
};
