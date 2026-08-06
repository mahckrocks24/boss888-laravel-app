<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineer888 private chat — conversations, messages and secure action cards.
 *
 * WHY SEPARATE TABLES RATHER THAN agent_messages.
 * The existing agent chat is scoped by `workspace_id` + `agent_slug` and carries
 * no `user_id` at all; `agents` has no visibility or owner column either. A row
 * there is readable by every member of the workspace. Engineer888 is private to
 * one human, so the existing model cannot represent it — registering Engineer888
 * as an ordinary agent would publish its conversation to a whole workspace.
 * `agents` and `agent_messages` are therefore left completely untouched, which
 * also means Sarah and the other nineteen agents are unaffected by this sprint.
 *
 * OWNERSHIP. `owner_user_id` exists for referential integrity and so the schema
 * does not have to be rewritten if the product policy ever widens. It is NOT an
 * invitation to multi-user tenancy: V1 admits exactly one owner, user #1, and
 * that is enforced in the service, in every query, in the policies and in the
 * tests — not by this column's existence.
 *
 * NO WORKSPACE COLUMN, DELIBERATELY. Adding one would invite a future join that
 * grants access by membership, which is the failure mode this whole design
 * exists to avoid.
 *
 * UUIDs ADDRESS EVERYTHING EXTERNALLY. Integer ids never leave the server, so a
 * guessed identifier is a 404 rather than a probe that distinguishes "not yours"
 * from "does not exist".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e888_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // The single canonical owner. Indexed because every read is scoped
            // by it — there is no query path that omits the owner.
            $table->unsignedBigInteger('owner_user_id')->index();

            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('owner_last_read_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'last_message_at']);
        });

        Schema::create('e888_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('e888_conversations')->cascadeOnDelete();

            // Denormalised owner. Redundant with the conversation's owner on
            // purpose: it means a message query can be owner-scoped without a
            // join, so a future caller that forgets the join still cannot read
            // another owner's messages.
            $table->unsignedBigInteger('owner_user_id')->index();

            // 'user' = the canonical admin speaking. 'engineer888' = the
            // department answering. 'system' = state changes worth showing.
            $table->string('role', 24);
            $table->text('body');

            // What the router decided this message MEANT. Recorded so a denial
            // or a misroute can be explained afterwards.
            $table->string('intent', 48)->nullable()->index();

            // References into the engineering records, by UUID and never by id.
            // Not foreign keys: chat must survive a task being pruned, and the
            // structured records remain the source of truth either way.
            $table->uuid('task_uuid')->nullable()->index();
            $table->uuid('candidate_uuid')->nullable()->index();

            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('e888_action_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('conversation_id')->constrained('e888_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('e888_messages')->nullOnDelete();
            $table->unsignedBigInteger('owner_user_id')->index();

            // approve_candidate | reject_candidate | request_revision |
            // execute_task | approve_recovery | revoke_approval
            $table->string('action_type', 48)->index();

            $table->uuid('task_uuid')->nullable()->index();
            $table->uuid('candidate_uuid')->nullable()->index();
            $table->uuid('recovery_uuid')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable();

            // THE BINDING. A card authorises one exact set of bytes and nothing
            // else. If the candidate is revised, the fingerprint moves and this
            // card stops matching — which is the point: approval cannot drift
            // onto content the approver never saw.
            $table->string('content_fingerprint', 128)->nullable()->index();
            $table->json('file_hashes_json')->nullable();

            // The workflow state the card was issued against. Revalidated at
            // press time; a card issued in ANALYZE cannot execute in DEPLOY.
            $table->string('workflow_state', 48)->nullable();

            // Who it was issued to, and on which sign-in. A card copied to
            // another session is not the card that was issued.
            $table->unsignedBigInteger('issued_to_user_id');
            $table->unsignedBigInteger('issued_session_id')->nullable();
            $table->unsignedBigInteger('issued_device_id')->nullable();

            // MFA is RECORDED, never simulated. When enrolment becomes required
            // the press returns BLOCKED_MFA_ENROLLMENT_REQUIRED rather than
            // silently proceeding.
            $table->boolean('mfa_required')->default(false);
            $table->boolean('mfa_satisfied')->default(false);

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_result', 48)->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['owner_user_id', 'action_type', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e888_action_cards');
        Schema::dropIfExists('e888_messages');
        Schema::dropIfExists('e888_conversations');
    }
};
