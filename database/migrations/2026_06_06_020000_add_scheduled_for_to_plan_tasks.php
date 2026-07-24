<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add scheduled_for to plan_tasks — when this task should run per its
 * parent plan's calendar-first schedule. NULL means "no specific date,
 * run as soon as approved + dependencies met" (the existing behavior).
 *
 * Indexed for cron-style "find tasks due now" queries.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plan_tasks', function (Blueprint $t) {
            $t->timestamp('scheduled_for')->nullable()->after('credits_used');
            $t->unsignedBigInteger('calendar_event_id')->nullable()->after('scheduled_for');
            $t->index(['plan_id', 'scheduled_for']);
            $t->index('calendar_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('plan_tasks', function (Blueprint $t) {
            $t->dropIndex(['plan_id', 'scheduled_for']);
            $t->dropIndex(['calendar_event_id']);
            $t->dropColumn(['scheduled_for', 'calendar_event_id']);
        });
    }
};