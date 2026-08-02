<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineer888 — deployment intent and verification.
 *
 * TWO TABLES, because intent and observation must never be conflated.
 *
 * engineering_deployment_intents records what someone MEANT to deploy, at the
 * time they meant it. Every expected_* column is nullable, and that is the
 * design: on this platform a deployment is usually a direct file change with no
 * recorded intent at all. A null expected_commit is an honest "nobody said",
 * not a defect to be filled in with a guess.
 *
 * engineering_deployment_verifications records what production actually showed.
 * The verdict is stored alongside the evidence that produced it so a past
 * verdict can be re-read rather than re-derived — the rules will change, the
 * evidence will not.
 *
 * NOTHING SENSITIVE IS STORED. Checks record names, statuses and sources.
 * Configuration checks record whether a key is set, never its value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_deployment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('environment', 32)->index();
            $table->string('repository')->nullable();

            // All nullable on purpose. Unknown stays unknown.
            $table->string('expected_branch')->nullable();
            $table->string('expected_commit', 64)->nullable();
            $table->json('expected_artifacts')->nullable();

            $table->string('initiating_session', 64)->nullable();
            $table->string('manifest_path')->nullable();
            $table->unsignedBigInteger('related_execution_id')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->string('verification_policy', 32)->default('standard');

            // How the deployment actually happened, in the words of whoever did
            // it. Free text because the honest answer is often "edited on the
            // server", which no enum would have anticipated.
            $table->text('deployment_method')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        Schema::create('engineering_deployment_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('intent_id')->nullable()->constrained('engineering_deployment_intents')->nullOnDelete();
            $table->string('environment', 32)->index();

            // VERIFIED · HEALTHY_IDENTITY_UNPROVEN · DEGRADED · FAILED · BLOCKED
            $table->string('verdict', 32)->index();
            $table->text('verdict_reason')->nullable();

            $table->json('identity');        // field => {value, source, confidence}
            $table->json('checks');          // check => {status, source, detail}
            $table->json('failures')->nullable();
            $table->json('comparison')->nullable();

            $table->unsignedBigInteger('baseline_id')->nullable();
            $table->string('session', 64)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamps();
            // Explicit name: the auto-generated
            // `engineering_deployment_verifications_environment_created_at_index`
            // is 65 chars and exceeds MySQL's 64-char identifier limit (error 1059).
            // Same columns, same non-unique semantics — only the identifier changes.
            $table->index(['environment', 'created_at'], 'eng_deploy_verif_env_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_deployment_verifications');
        Schema::dropIfExists('engineering_deployment_intents');
    }
};
