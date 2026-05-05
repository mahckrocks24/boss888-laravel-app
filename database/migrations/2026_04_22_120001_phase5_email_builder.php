<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $t) {
            if (!Schema::hasColumn('email_templates', 'font_family')) {
                $t->string('font_family', 120)->default('Inter, Arial, Helvetica')->after('brand_color');
            }
        });

        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $t) {
                if (!Schema::hasColumn('leads', 'email_unsubscribed')) {
                    $t->boolean('email_unsubscribed')->default(false);
                }
                if (!Schema::hasColumn('leads', 'email_unsubscribed_at')) {
                    $t->timestamp('email_unsubscribed_at')->nullable();
                }
                if (!Schema::hasColumn('leads', 'unsubscribe_token')) {
                    $t->string('unsubscribe_token', 64)->nullable()->unique();
                }
            });
        }

        // Campaign_id index on email_campaigns_log (assist analytics queries)
        // Already indexed from Phase 1 — noop.

        // subject_variant column on log so A/B tests can report per-variant
        Schema::table('email_campaigns_log', function (Blueprint $t) {
            if (!Schema::hasColumn('email_campaigns_log', 'subject_variant')) {
                $t->string('subject_variant', 1)->nullable()->after('subject');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive — no down() that drops data
    }
};
