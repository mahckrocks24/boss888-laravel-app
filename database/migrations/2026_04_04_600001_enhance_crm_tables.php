<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── activities ────────────────────────────────────────────────────
        Schema::table('activities', function (Blueprint $table) {
            if (! Schema::hasColumn('activities', 'subject')) {
                $table->string('subject')->nullable()->after('type');
            }
            if (! Schema::hasColumn('activities', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('metadata_json');
            }
            if (! Schema::hasColumn('activities', 'completed')) {
                $table->boolean('completed')->default(false)->after('scheduled_at');
            }
            if (! Schema::hasColumn('activities', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('completed');
            }
        });

        // ── contacts ─────────────────────────────────────────────────────
        Schema::table('contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('contacts', 'lead_id')) {
                $table->unsignedBigInteger('lead_id')->nullable()->after('workspace_id');
            }
            if (! Schema::hasColumn('contacts', 'source')) {
                $table->string('source')->nullable()->after('position');
            }
            if (! Schema::hasColumn('contacts', 'tags_json')) {
                $table->json('tags_json')->nullable()->after('metadata_json');
            }
        });

        // ── leads ─────────────────────────────────────────────────────────
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'website')) {
                $table->string('website')->nullable()->after('company');
            }
            if (! Schema::hasColumn('leads', 'city')) {
                $table->string('city')->nullable()->after('website');
            }
            if (! Schema::hasColumn('leads', 'country')) {
                $table->string('country')->nullable()->after('city');
            }
            if (! Schema::hasColumn('leads', 'tags_json')) {
                $table->json('tags_json')->nullable()->after('metadata_json');
            }
            if (! Schema::hasColumn('leads', 'last_contacted_at')) {
                $table->timestamp('last_contacted_at')->nullable()->after('tags_json');
            }
            if (! Schema::hasColumn('leads', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('last_contacted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $cols = array_filter(['scheduled_at', 'completed', 'completed_at', 'subject'], fn($c) => Schema::hasColumn('activities', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
        Schema::table('contacts', function (Blueprint $table) {
            $cols = array_filter(['lead_id', 'source', 'tags_json'], fn($c) => Schema::hasColumn('contacts', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
        Schema::table('leads', function (Blueprint $table) {
            $cols = array_filter(['website', 'city', 'country', 'tags_json', 'last_contacted_at', 'converted_at'], fn($c) => Schema::hasColumn('leads', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
    }
};
