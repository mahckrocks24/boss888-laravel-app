<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if (! Schema::hasColumn('agents', 'title')) {
                $table->string('title')->nullable()->after('name');
            }
            if (! Schema::hasColumn('agents', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (! Schema::hasColumn('agents', 'personality')) {
                $table->text('personality')->nullable()->after('description');
            }
            if (! Schema::hasColumn('agents', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('personality');
            }
            if (! Schema::hasColumn('agents', 'category')) {
                $table->string('category', 20)->default('general')->after('avatar_url');
            }
            if (! Schema::hasColumn('agents', 'level')) {
                $table->string('level', 20)->default('specialist')->after('category');
            }
            if (! Schema::hasColumn('agents', 'orb_type')) {
                $table->string('orb_type', 20)->default('dmm')->after('level');
            }
            if (! Schema::hasColumn('agents', 'skills_json')) {
                $table->json('skills_json')->nullable()->after('capabilities_json');
            }
            if (! Schema::hasColumn('agents', 'is_dmm')) {
                $table->boolean('is_dmm')->default(false)->after('color');
            }
        });
    }

    public function down(): void
    {
        $cols = ['title', 'description', 'personality', 'avatar_url', 'category', 'level', 'orb_type', 'skills_json', 'is_dmm'];
        Schema::table('agents', function (Blueprint $table) use ($cols) {
            $existing = array_filter($cols, fn($c) => Schema::hasColumn('agents', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
