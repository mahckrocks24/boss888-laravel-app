<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAMPAIGNS-1 (RFC-0018, Owner 2026-09-27: "design and build based on the main objective of helping the user build and
 * grow its business"). A campaign replaces the unused Projects object: a business goal with dates, channels, phases of
 * dated work (task groups), one plan-level approval (DEC-0018 mandate) and results the next ideas learn from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->string('title', 160);
                $t->text('objective')->nullable();          // the business outcome
                $t->text('why_now')->nullable();            // the moment: season, event, signal
                $t->string('audience', 300)->nullable();
                $t->string('offer', 300)->nullable();
                $t->json('channels_json')->nullable();
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->string('status', 20)->default('idea')->index();   // idea | launching | active | paused | completed | declined | archived
                $t->json('kpi_json')->nullable();           // {metric, target, label}
                $t->json('results_json')->nullable();
                $t->string('source', 30)->default('sarah'); // sarah_monthly | sarah_chat | sarah_page | owner
                $t->unsignedBigInteger('mandate_id')->nullable()->index();
                $t->integer('credit_estimate')->nullable();
                $t->string('decline_reason', 300)->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamp('launched_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('campaign_items')) {
            Schema::create('campaign_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('phase', 60);                     // task group: e.g. Build-up, Launch week, Follow-up
                $t->unsignedTinyInteger('phase_order')->default(1);
                $t->string('kind', 20);                      // post | article | email | image | event | owner_task
                $t->string('channel', 30)->nullable();       // facebook | instagram | linkedin | website | email | in_person
                $t->string('title', 200);
                $t->text('brief')->nullable();
                $t->dateTime('scheduled_at')->nullable()->index();
                $t->string('status', 20)->default('planned')->index();   // planned | in_progress | needs_you | done | skipped | failed | held
                $t->unsignedBigInteger('task_id')->nullable()->index();
                $t->string('result_type', 30)->nullable();   // social_post | article | email_campaign | media | calendar_event
                $t->unsignedBigInteger('result_id')->nullable();
                $t->string('result_url', 1024)->nullable();
                $t->unsignedBigInteger('calendar_event_id')->nullable();
                $t->string('note', 500)->nullable();
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_items');
        Schema::dropIfExists('marketing_campaigns');
    }
};
