<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-R1 — administrator governance + MFA scaffolding.
 *
 * TWO CONCERNS, ONE MIGRATION, BOTH ADDITIVE:
 *
 *   admin_appointments  — an auditable record of WHO made WHOM a platform
 *                         administrator, WHEN and WHY. Today someone becomes an
 *                         admin by a raw UPDATE to users.is_platform_admin with
 *                         no trail. That is the D3 governance gap in miniature.
 *
 *   user MFA columns    — enrolment, encrypted TOTP secret, recovery codes,
 *                         confirmation timestamp. Added to `users` because MFA is
 *                         a platform authentication concern, not INFRA888-only.
 *
 * 🔴 NOTHING HERE ENABLES MFA OR APPOINTS ANYONE. The columns default to
 * disabled/null; no user is enrolled; no admin is appointed. This is the
 * implementation-ready substrate the phase was asked to design, deployed inert
 * so the governance code has something to write to WHEN a real human is
 * authorized. Enabling enforcement is a separate, explicit step.
 *
 * MFA SECRET STORAGE: the column is `mfa_secret_encrypted`, written only through
 * Laravel's `encrypted` cast. There is deliberately no plaintext column — a
 * plaintext TOTP seed is as dangerous as a plaintext password.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_appointments')) {
            Schema::create('admin_appointments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('appointment_uid')->unique('admin_appt_uid_uq');

                // The human being granted or revoked administrator authority.
                $table->unsignedBigInteger('user_id');
                // The administrator who performed the appointment. NULL only for
                // the genesis record (the first admin, appointed out-of-band).
                $table->unsignedBigInteger('appointed_by_user_id')->nullable();

                // grant | revoke — an offboarding is a new record, never an edit.
                $table->string('action', 16);
                $table->string('role', 32)->default('platform_admin');
                $table->string('environment', 32)->default('production');

                $table->text('reason')->nullable();
                // The appointee's own acknowledgement, captured separately from
                // the grant so "granted" and "accepted" are distinguishable.
                $table->timestamp('acknowledged_at')->nullable();

                // Point-in-time snapshots, so the record shows the security
                // posture AT appointment rather than whatever is true now.
                $table->boolean('mfa_enabled_at_appointment')->default(false);
                $table->boolean('recovery_verified_at_appointment')->default(false);
                $table->boolean('production_eligible')->default(false);

                $table->timestamp('effective_at')->nullable();
                $table->timestamp('revoked_at')->nullable();

                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'action'], 'admin_appt_user_idx');
                $table->index('role', 'admin_appt_role_idx');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'mfa_enabled')) {
                $table->boolean('mfa_enabled')->default(false)->after('is_platform_admin');
            }
            if (!Schema::hasColumn('users', 'mfa_secret_encrypted')) {
                $table->text('mfa_secret_encrypted')->nullable()->after('mfa_enabled');
            }
            if (!Schema::hasColumn('users', 'mfa_recovery_codes_encrypted')) {
                $table->text('mfa_recovery_codes_encrypted')->nullable()->after('mfa_secret_encrypted');
            }
            if (!Schema::hasColumn('users', 'mfa_confirmed_at')) {
                $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_recovery_codes_encrypted');
            }
            // Freshness anchor for step-up: the last time this user proved a
            // second factor. Privileged operations demand this be recent.
            if (!Schema::hasColumn('users', 'mfa_last_verified_at')) {
                $table->timestamp('mfa_last_verified_at')->nullable()->after('mfa_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_appointments');

        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'mfa_enabled', 'mfa_secret_encrypted', 'mfa_recovery_codes_encrypted',
                'mfa_confirmed_at', 'mfa_last_verified_at',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
