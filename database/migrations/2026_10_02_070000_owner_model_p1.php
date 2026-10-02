<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0023 P1 (2026-10-02): the Owner Model - what Sarah knows about each owner, per business, with provenance.
 * owner_model_facts   one row per fact: group / key / value, source, confidence, evidence, dates, lifetime, status
 * owner_model_events  the observed lane: domain events that shape behaviour facts (approvals, picks, ignores...)
 * memory_events       observability: every pack build and every write (size, truncation, retrieval hits)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('owner_model_facts')) {
            Schema::create('owner_model_facts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->string('group', 24)->index();            // identity|goals|preferences|interests|behaviour|wants|relationship
                $t->string('key', 80);
                $t->text('value');
                $t->string('source', 16)->default('stated');  // stated|observed|inferred|computed
                $t->decimal('confidence', 4, 3)->default(0.800);
                $t->string('evidence_ref', 160)->nullable();  // agent_message:30882 | event:approval_rejected:412 | onboarding
                $t->string('status', 16)->default('proposed')->index(); // proposed|confirmed|dismissed|expired|erased
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_confirmed_at')->nullable();
                $t->timestamp('expires_at')->nullable()->index();
                $t->timestamps();
                $t->unique(['workspace_id', 'business_id', 'key'], 'omf_ws_biz_key');
            });
        }
        if (! Schema::hasTable('owner_model_events')) {
            Schema::create('owner_model_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('business_id')->nullable()->index();
                $t->string('event', 48)->index();             // approval_approved|approval_rejected|look_picked|card_ignored|checkin_unanswered|image_regenerated|draft_edited
                $t->string('subject', 120)->nullable();       // approval:412 | recipe:200 | message:30899
                $t->json('payload_json')->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
            });
        }
        if (! Schema::hasTable('memory_events')) {
            Schema::create('memory_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('kind', 32)->index();              // pack_built|fact_written|fact_confirmed|fact_dismissed|erased|extract
                $t->unsignedInteger('size')->default(0);
                $t->boolean('truncated')->default(false);
                $t->json('meta_json')->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_events');
        Schema::dropIfExists('owner_model_events');
        Schema::dropIfExists('owner_model_facts');
    }
};
