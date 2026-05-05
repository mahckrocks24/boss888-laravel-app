<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('email_templates', 'html_body')) {
                $table->longText('html_body')->nullable()->after('body_html');
            }
            if (!Schema::hasColumn('email_templates', 'thumbnail_url')) {
                $table->string('thumbnail_url', 2048)->nullable()->after('variables_json');
            }
            if (!Schema::hasColumn('email_templates', 'preview_text')) {
                $table->string('preview_text', 255)->nullable()->after('subject');
            }
            if (!Schema::hasColumn('email_templates', 'blocks_json')) {
                $table->longText('blocks_json')->nullable()->after('html_body');
            }
            if (!Schema::hasColumn('email_templates', 'brand_color')) {
                $table->string('brand_color', 7)->default('#5B5BD6')->after('blocks_json');
            }
            if (!Schema::hasColumn('email_templates', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_system');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            foreach (['html_body','thumbnail_url','preview_text','blocks_json','brand_color','is_active'] as $col) {
                if (Schema::hasColumn('email_templates', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
