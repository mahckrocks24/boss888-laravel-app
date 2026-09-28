<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-SARAH-3 (Clients revamp Phase 3): Sarah in Clients.
 *   crm_autoreply      the owner's one "yes" per business: Sarah answers new enquiries for them (send) or shows each
 *                      reply first (draft). Asked once, stoppable any time in chat.
 *   crm_reply_drafts   every reply Sarah writes to a client: from a new enquiry or the daily "who to contact" list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_autoreply')) {
            Schema::create('crm_autoreply', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id');
                $t->unsignedBigInteger('business_id');
                $t->string('status', 16)->default('not_asked');   // not_asked | asked | on | off | declined
                $t->string('mode', 8)->default('draft');           // send | draft
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('stopped_by')->nullable();
                $t->timestamp('stopped_at')->nullable();
                $t->unsignedInteger('sent_count')->default(0);
                $t->timestamp('last_sent_at')->nullable();
                $t->timestamps();
                $t->unique(['workspace_id', 'business_id']);
            });
        }
        if (! Schema::hasTable('crm_reply_drafts')) {
            Schema::create('crm_reply_drafts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable();
                $t->unsignedBigInteger('lead_id')->index();
                $t->string('source', 16);                  // enquiry | daily
                $t->string('channel', 16)->default('email');
                $t->string('subject', 190)->nullable();
                $t->text('body');
                $t->string('reason', 255)->nullable();     // why Sarah suggests contacting them (daily list)
                $t->string('status', 12)->default('draft'); // draft | sent | skipped | failed
                $t->unsignedBigInteger('message_id')->nullable();   // Sarah's chat message that carries it
                $t->unsignedTinyInteger('position')->nullable();    // its number in that message
                $t->unsignedBigInteger('sent_by')->nullable();      // null = Sarah under the owner's standing yes
                $t->timestamp('sent_at')->nullable();
                $t->string('error', 255)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void {}
};
