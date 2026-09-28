<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-DATA-1 (Clients revamp, Phase 1 — Owner "go" 2026-09-28): every CRM record belongs to a business, and one real
 * human is one PERSON in the workspace however many businesses they deal with.
 *
 *   people            — the identity (name, emails, phones), one per human per workspace
 *   leads             — stays the per-business CLIENT PROFILE: + business_id, person_id, channel, business_source
 *   activities        — the one timeline: + business_id, lead_id (direct, whatever the polymorphic type spelling)
 *   deals, booking_submissions, contacts — + business_id (contacts also get lead_id when they mirror a lead)
 *
 * Additive only: no column is dropped or renamed, so the old code keeps working while the backfill runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('people')) {
            Schema::create('people', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('name')->nullable();
                $t->string('email')->nullable();          // primary, lower-cased
                $t->string('phone_key', 32)->nullable();  // last 10 digits, for matching
                $t->string('phone', 64)->nullable();      // as given
                $t->json('emails_json')->nullable();
                $t->json('phones_json')->nullable();
                $t->timestamps();
                $t->index(['workspace_id', 'email']);
                $t->index(['workspace_id', 'phone_key']);
            });
        }
        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'business_id')) $t->unsignedBigInteger('business_id')->nullable()->after('workspace_id');
            if (! Schema::hasColumn('leads', 'person_id')) $t->unsignedBigInteger('person_id')->nullable()->after('business_id');
            if (! Schema::hasColumn('leads', 'channel')) $t->string('channel', 24)->nullable()->after('source');
            if (! Schema::hasColumn('leads', 'business_source')) $t->string('business_source', 16)->nullable()->after('person_id'); // website|social|chatbot|explicit|only|default
        });
        Schema::table('leads', function (Blueprint $t) {
            $t->index(['workspace_id', 'business_id'], 'leads_ws_biz_idx');
            $t->index('person_id', 'leads_person_idx');
        });
        Schema::table('activities', function (Blueprint $t) {
            if (! Schema::hasColumn('activities', 'business_id')) $t->unsignedBigInteger('business_id')->nullable()->after('workspace_id');
            if (! Schema::hasColumn('activities', 'lead_id')) $t->unsignedBigInteger('lead_id')->nullable()->after('business_id');
        });
        Schema::table('activities', function (Blueprint $t) {
            $t->index(['workspace_id', 'lead_id'], 'activities_ws_lead_idx');
        });
        foreach (['deals', 'booking_submissions', 'contacts'] as $tbl) {
            Schema::table($tbl, function (Blueprint $t) use ($tbl) {
                if (! Schema::hasColumn($tbl, 'business_id')) $t->unsignedBigInteger('business_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Additive migration; columns are left in place on rollback so no data is lost.
    }
};
