<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for manual recovery approval and migration rehearsal.
 *
 * Index names stay short; MySQL's limit is 64 and a 65-character name broke
 * migrate:fresh across the whole suite on 2026-08-01.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_recovery_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recovery_id');
            $table->string('recovery_uuid', 64);
            $table->char('task_uuid', 36);

            // Covers the CURRENT state of every affected file, which is what
            // makes an approval expire by itself when the tree moves.
            $table->char('fingerprint', 64);

            // PENDING | APPROVED | REJECTED | EXPIRED | REVOKED | SUPERSEDED | EXECUTED | FAILED
            $table->string('status', 24);

            $table->longText('candidate')->nullable();
            $table->longText('evidence')->nullable();

            $table->unsignedBigInteger('approver_user_id')->nullable();
            $table->string('approver_name', 255)->nullable();
            $table->text('statement')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->timestamps();

            $table->index(['recovery_id', 'status'], 'era_recovery_status_idx');
            $table->index('recovery_uuid', 'era_uuid_idx');
        });

        Schema::create('engineering_migration_rehearsals', function (Blueprint $table) {
            $table->id();
            $table->char('task_uuid', 36)->nullable();
            $table->char('candidate_uuid', 36)->nullable();
            $table->string('migration_path', 255);

            // REVERSIBLE_AUTOMATIC | REVERSIBLE_WITH_DATA_RISK | IRREVERSIBLE | UNKNOWN
            $table->string('classification', 32);

            // PASSED | FAILED | REFUSED
            $table->string('status', 16);

            $table->string('database', 64);
            $table->char('schema_before', 64)->nullable();
            $table->char('schema_after_up', 64)->nullable();
            $table->char('schema_after_down', 64)->nullable();

            $table->longText('evidence')->nullable();
            $table->longText('recovery_plan')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamps();

            $table->index(['task_uuid', 'status'], 'emr_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_migration_rehearsals');
        Schema::dropIfExists('engineering_recovery_approvals');
    }
};
