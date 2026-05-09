<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D — Dynamic agent-capability registry.
 *
 * One row per (agent_slug, tool_id) pair. Mirrors the static
 * AgentCapabilityService::CAPABILITY_MAP so behaviour is identical until
 * an admin grants/revokes via API. The static array remains as a runtime
 * fallback so a corrupted / empty table never breaks the platform.
 *
 * Spec deviation: original spec used engine-buckets with action=null to
 * mean "all actions". canUse() is called with TOOL IDS (e.g. serp_analysis),
 * not engine slugs, so engine-bucket rows never matched. This schema stores
 * one row per real tool the agent can call — same key shape as canUse().
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_capabilities', function (Blueprint $t) {
            $t->id();
            $t->string('agent_slug', 50);
            $t->string('tool_id', 100);
            $t->boolean('is_active')->default(true);
            $t->json('constraints')->nullable();
            $t->timestamp('granted_at')->useCurrent();
            $t->string('granted_by', 100)->nullable();
            $t->timestamps();

            $t->unique(['agent_slug', 'tool_id']);
            $t->index(['agent_slug', 'is_active']);
            $t->index('tool_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_capabilities');
    }
};
