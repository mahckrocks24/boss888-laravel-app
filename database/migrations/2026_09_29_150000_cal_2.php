<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CAL-2 (Owner 2026-09-29: "calendar is user's calendar ... notifications, emails, reminders must be set in place").
 * calendar_events becomes the owner's own schedule: each item can say who it is with (lead_id), where (location),
 * how it went (status), and when to remind (remind_minutes / reminded_at). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $t) {
            if (! Schema::hasColumn('calendar_events', 'lead_id')) $t->unsignedBigInteger('lead_id')->nullable()->after('business_id')->index();
            if (! Schema::hasColumn('calendar_events', 'status')) $t->string('status', 20)->nullable()->after('category');
            if (! Schema::hasColumn('calendar_events', 'location')) $t->string('location', 255)->nullable()->after('description');
            if (! Schema::hasColumn('calendar_events', 'remind_minutes')) $t->unsignedInteger('remind_minutes')->nullable()->after('all_day');
            if (! Schema::hasColumn('calendar_events', 'reminded_at')) $t->timestamp('reminded_at')->nullable()->after('remind_minutes');
            if (! Schema::hasColumn('calendar_events', 'created_by')) $t->unsignedBigInteger('created_by')->nullable();
        });
        Schema::table('calendar_events', function (Blueprint $t) {
            $t->index(['workspace_id', 'starts_at'], 'cal_ws_starts_idx');
            $t->index(['reminded_at', 'starts_at'], 'cal_remind_idx');
        });
        // a client's bookings point at the client directly
        DB::statement("UPDATE calendar_events SET lead_id = reference_id WHERE lead_id IS NULL AND reference_type IN ('Lead','lead','App\\\\Models\\\\Lead')");
        DB::statement("UPDATE calendar_events e JOIN activities a ON a.id = e.reference_id SET e.lead_id = a.lead_id WHERE e.lead_id IS NULL AND e.reference_type = 'Activity'");
        // items already in the past never get a reminder
        DB::statement("UPDATE calendar_events SET reminded_at = NOW() WHERE reminded_at IS NULL AND starts_at < NOW()");
    }

    public function down(): void {}
};
