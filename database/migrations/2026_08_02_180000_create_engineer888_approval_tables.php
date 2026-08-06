<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Exact-content approval.
 *
 * Sprint 7 recorded approval on the task row — a name and a timestamp. That
 * cannot express "these bytes", which is why a human approved candidate #12 and
 * candidate #13 was installed. This migration gives approvals something to bind
 * to: a candidate identity that survives outside the database, and the hash of
 * every file the approver actually read.
 *
 * Index names stay short. A 65-character name broke migrate:fresh across the
 * whole suite on 2026-08-01; MySQL's limit is 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineering_candidates', function (Blueprint $table) {
            // A candidate needs an identity a human can read back to the system.
            // The primary key is fine for joins and useless in an approval
            // statement somebody types.
            $table->char('uuid', 36)->nullable()->after('id');

            // Bounded revision: one candidate may answer a rejection, once.
            $table->unsignedBigInteger('revision_of')->nullable()->after('project_id');
            $table->text('revision_instruction')->nullable()->after('revision_of');

            $table->unsignedBigInteger('superseded_by')->nullable()->after('status');
            $table->timestamp('superseded_at')->nullable()->after('superseded_by');
        });

        // Existing rows predate the identity. They get one so the column can be
        // unique, and so nothing in the UI has to special-case a null.
        foreach (DB::table('engineering_candidates')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('engineering_candidates')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('engineering_candidates', function (Blueprint $table) {
            $table->unique('uuid', 'ec_uuid_unique');
            $table->index('revision_of', 'ec_revision_idx');
        });

        Schema::create('engineering_candidate_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->char('candidate_uuid', 36);
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('project_id');

            // PENDING | APPROVED | REJECTED | SUPERSEDED | EXPIRED | REVOKED
            $table->string('state', 16);

            // The one value IMPLEMENT compares against. sha256 over the binding.
            $table->char('fingerprint', 64);

            // The binding in full, so a refusal can name WHICH field moved
            // rather than only reporting that the hash differs.
            $table->longText('binding')->nullable();
            $table->text('approved_paths')->nullable();
            $table->longText('approved_hashes')->nullable();

            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();

            $table->unsignedBigInteger('approver_user_id')->nullable();
            $table->string('approver_name', 255)->nullable();
            $table->text('statement')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 255)->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            // One approval record per candidate. Two would mean two answers to
            // the same question, and nothing could say which one governs.
            $table->unique('candidate_uuid', 'eca_candidate_unique');
            $table->index(['task_id', 'state'], 'eca_task_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_candidate_approvals');

        Schema::table('engineering_candidates', function (Blueprint $table) {
            $table->dropUnique('ec_uuid_unique');
            $table->dropIndex('ec_revision_idx');
            $table->dropColumn(['uuid', 'revision_of', 'revision_instruction', 'superseded_by', 'superseded_at']);
        });
    }
};
