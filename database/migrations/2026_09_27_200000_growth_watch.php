<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WATCH-1 (RFC-0019, Owner 2026-09-27): Sarah acts on what happens — results inside the business and events in the
 * world — not only on a clock. She watches trends, competitors and mentions on a schedule the owner approves once,
 * checks in with the owner during the day, asks weekly how things are going, and learns the business from the answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // the owner's one approval for research, per business: how often, which areas, until stopped
        if (! Schema::hasTable('business_watch')) {
            Schema::create('business_watch', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->string('status', 16)->default('not_asked');   // not_asked | asked | on | off | declined
                $t->string('frequency', 16)->nullable();          // daily | twice_weekly | weekly
                $t->boolean('trends')->default(true);
                $t->boolean('competitors')->default(true);
                $t->boolean('listening')->default(true);
                $t->unsignedInteger('credits_per_run')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('asked_at')->nullable();
                $t->timestamp('stopped_at')->nullable();
                $t->string('stopped_by', 16)->nullable();         // owner | sarah
                $t->string('stopped_reason', 300)->nullable();
                $t->timestamp('last_run_at')->nullable();
                $t->timestamp('next_run_at')->nullable()->index();
                $t->unsignedSmallInteger('empty_runs')->default(0);
                $t->json('last_run_json')->nullable();
                $t->timestamps();
                $t->unique(['workspace_id', 'business_id']);
            });
        }
        if (! Schema::hasTable('business_competitors')) {
            Schema::create('business_competitors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->string('name', 160);
                $t->string('domain', 190)->nullable();
                $t->string('source', 16)->default('sarah');       // sarah | owner | search
                $t->string('status', 16)->default('active');      // active | removed
                $t->text('summary')->nullable();                  // what they offer, in plain words
                $t->char('snapshot_hash', 64)->nullable();
                $t->json('snapshot_json')->nullable();
                $t->timestamp('last_checked_at')->nullable();
                $t->timestamp('last_change_at')->nullable();
                $t->text('last_change')->nullable();
                $t->timestamps();
            });
        }
        // everything that happened that Sarah may act on
        if (! Schema::hasTable('growth_signals')) {
            Schema::create('growth_signals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->unsignedBigInteger('campaign_id')->nullable()->index();
                $t->string('source', 8);                          // inside | world | owner
                $t->string('kind', 32)->index();                  // new_leads | leads_quiet | buying_comment | rank_move | traffic_move | campaign_pace | waiting_on_owner | owner_said | owner_feedback | trend | moment | competitor_move | mention
                $t->unsignedTinyInteger('strength')->default(1);  // 1 note | 2 worth acting on | 3 act now
                $t->string('title', 300);
                $t->text('detail')->nullable();
                $t->json('payload_json')->nullable();
                $t->string('dedupe_key', 120);
                $t->string('status', 12)->default('new')->index();   // new | handled | brief | ignored
                $t->string('decision', 16)->nullable();           // adjust | propose | tell | brief | nothing
                $t->string('decision_note', 500)->nullable();
                $t->timestamp('handled_at')->nullable();
                $t->timestamps();
                $t->unique(['workspace_id', 'dedupe_key']);
            });
        }
        // a change Sarah proposes to a running campaign; the owner approves it (Owner: "upon approval for new or updated campaign")
        if (! Schema::hasTable('campaign_changes')) {
            Schema::create('campaign_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->json('signal_ids_json')->nullable();
                $t->text('reason');
                $t->json('changes_json');                         // [{op:add|move|drop, item_id?, kind?, channel?, date?, title?, brief?}]
                $t->integer('extra_credits')->default(0);
                $t->string('status', 12)->default('proposed')->index();   // proposed | applied | declined | expired
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }
        // Sarah's check-ins with the owner and what she learned from the answers
        if (! Schema::hasTable('owner_checkins')) {
            Schema::create('owner_checkins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable();
                $t->string('kind', 16);                           // afternoon | night | weekly_feedback
                $t->date('local_date');
                $t->unsignedBigInteger('message_id')->nullable();
                $t->text('question')->nullable();
                $t->unsignedBigInteger('answer_message_id')->nullable();
                $t->timestamp('answered_at')->nullable();
                $t->unsignedTinyInteger('satisfaction')->nullable();   // 1-5, weekly feedback only
                $t->json('learned_json')->nullable();
                $t->string('status', 12)->default('asked');       // asked | answered | unanswered
                $t->timestamps();
                $t->unique(['workspace_id', 'kind', 'local_date']);
            });
        }
        if (! Schema::hasTable('business_journal')) {
            Schema::create('business_journal', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->string('kind', 16);                           // news | sales | customers | win | problem | plan | feedback | preference
                $t->string('text', 600);
                $t->string('source', 16)->default('checkin');     // checkin | chat | feedback
                $t->unsignedBigInteger('message_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['business_journal', 'owner_checkins', 'campaign_changes', 'growth_signals', 'business_competitors', 'business_watch'] as $t) Schema::dropIfExists($t);
    }
};
