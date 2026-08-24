<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-B — authoritative idempotency record for chat requests.
 *
 * ONE LOGICAL REQUEST → ONE PROVIDER EXECUTION → ONE USER MESSAGE
 * → ONE FINAL ASSISTANT MESSAGE → AT MOST ONE CHARGE → ONE TRACEABLE OUTCOME
 *
 * AUTHORITATIVE SCOPE (INV-01, INV-12)
 * Uniqueness is (workspace_id, surface, idempotency_key) — NOT the key alone.
 * `tasks.idempotency_key` in this repo carries a GLOBAL unique index; that is
 * the anti-pattern this table rejects. A global key namespace lets one
 * workspace's key collide with another's, which is both a correctness bug and a
 * cross-tenant disclosure: workspace B replaying A's key would receive A's
 * outcome. The composite index makes that structurally impossible.
 *
 * The DATABASE is the arbiter of "who was first". An application-level
 * "does it already exist?" check loses the race between two concurrent
 * requests; a unique-constraint violation does not.
 *
 * Additive and nullable-leaning; no foreign keys (matching creative_jobs), so
 * the table can be dropped without touching anything it references.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('chat_idempotency_records')) {
            return;
        }

        Schema::create('chat_idempotency_records', function (Blueprint $t) {
            $t->id();

            // ── tenancy ──────────────────────────────────────────────────────
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id')->nullable()->index();

            // ── what was asked ───────────────────────────────────────────────
            $t->string('surface', 32);                 // s4_seo_assistant, s8_public_chatbot, …
            $t->string('agent_id', 64)->nullable();
            $t->string('conversation_type', 32)->nullable();   // agent | session | design | none
            $t->string('conversation_id', 128)->nullable();
            $t->string('client_message_id', 128)->nullable();

            // ── identity ─────────────────────────────────────────────────────
            // 191 keeps (workspace_id 8 + surface 32*4 + key 191*4) inside the
            // 3072-byte InnoDB key limit with utf8mb4.
            $t->string('idempotency_key', 191);
            $t->string('request_fingerprint', 64);     // sha256 hex of the canonical request
            $t->string('correlation_id', 64);

            // ── lifecycle ────────────────────────────────────────────────────
            // acquired · processing · completed · failed_retryable
            // · failed_final · conflict · expired
            $t->string('status', 24)->default('acquired');
            $t->json('response_reference')->nullable();   // user_message_id, final_message_id, body
            $t->string('error_code', 48)->nullable();     // CHAT-CONTRACT-v1 §8.1 taxonomy only

            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamp('expires_at')->nullable();      // lease; drives stale recovery

            $t->json('metadata')->nullable();
            $t->timestamps();

            // INV-01 / INV-12 — the authoritative scope.
            $t->unique(['workspace_id', 'surface', 'idempotency_key'], 'chat_idem_ws_surface_key_unique');

            $t->index(['workspace_id', 'status'], 'chat_idem_ws_status_idx');   // stale sweep
            $t->index('correlation_id', 'chat_idem_correlation_idx');           // tracing
            $t->index('expires_at', 'chat_idem_expires_idx');                   // expiry sweep
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_idempotency_records');
    }
};
