<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-R3 — credential roles (WS2) + governance state machine (WS4).
 *
 * TWO GAPS, BOTH IDENTIFIED IN EARLIER PHASES, CLOSED HERE. ADDITIVE.
 *
 * (WS2) `credential_role` on infra_provider_credentials
 *   Phase 2B-R2 surfaced that a diagnostics credential cannot be stored: the
 *   `readonly_diagnostics` purpose has an empty capability set, but create()
 *   requires a non-empty scope. The real distinction is not "which capability"
 *   but "what KIND of credential" — a diagnostics credential is a different
 *   thing from a provisioning credential and must NEVER participate in provider
 *   selection. A role column makes that structural.
 *
 *   Existing rows default to `provisioning`, preserving today's behaviour exactly.
 *
 * (WS4) `infra_governance_state` — bootstrap → activated → recovery
 *   Phase 2B-R2's RequireMfaStepUp SELF-DISABLES below the two-admin bar. That is
 *   correct DURING bootstrap (you cannot enrol the second admin if step-up locks
 *   the enrolment route), but it is a FAIL-OPEN once governance has been
 *   activated: offboard an admin, drop below the bar, and MFA enforcement would
 *   silently vanish. The directive is explicit — "falling below the governance
 *   bar must block privileged operations rather than automatically disabling MFA."
 *
 *   This single-row state records whether governance was EVER activated. Once
 *   `activated`, the middleware enforces permanently and FAILS CLOSED below the
 *   bar. Restoring a lost admin requires an explicit, audited, time-boxed
 *   `recovery` transition — never an automatic silent relaxation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('infra_provider_credentials', 'credential_role')) {
            Schema::table('infra_provider_credentials', function (Blueprint $table) {
                $table->string('credential_role', 32)
                    ->default('provisioning')
                    ->after('purpose');
                $table->index('credential_role', 'infra_cred_role_idx');
            });
        }

        if (!Schema::hasTable('infra_governance_state')) {
            Schema::create('infra_governance_state', function (Blueprint $table) {
                // Deliberately a single logical row (id=1). A state machine for
                // the platform, not per-anything.
                $table->bigIncrements('id');

                // bootstrap | activated | recovery
                $table->string('mode', 24)->default('bootstrap');

                // Set ONCE, when the two-MFA-admin bar is first met. Never cleared
                // automatically — its presence is the "governance was activated"
                // fact the middleware keys on.
                $table->timestamp('activated_at')->nullable();
                $table->unsignedBigInteger('activated_by_user_id')->nullable();

                // Recovery is explicit, audited and time-boxed.
                $table->timestamp('recovery_entered_at')->nullable();
                $table->unsignedBigInteger('recovery_by_user_id')->nullable();
                $table->string('recovery_reason', 191)->nullable();
                $table->timestamp('recovery_expires_at')->nullable();

                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });

            // Seed the single row in bootstrap mode. Matches today's reality:
            // zero enrolled MFA admins on staging.
            DB::table('infra_governance_state')->insert([
                'id'         => 1,
                'mode'       => 'bootstrap',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_governance_state');

        if (Schema::hasColumn('infra_provider_credentials', 'credential_role')) {
            Schema::table('infra_provider_credentials', function (Blueprint $table) {
                $table->dropIndex('infra_cred_role_idx');
                $table->dropColumn('credential_role');
            });
        }
    }
};
