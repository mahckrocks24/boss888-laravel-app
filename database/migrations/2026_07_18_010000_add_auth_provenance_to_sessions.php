<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1D — server-controlled authentication provenance on sessions.
 *
 * WHY
 * ---
 * Phase 1C added a `via` claim to the ACCESS token so infrastructure routes could
 * refuse the shared BELLA_ADMIN_TOKEN path. That claim was lost the moment the
 * client called /auth/refresh: rotation minted a fresh, untagged access token, so
 * a restricted session silently became unrestricted.
 *
 * Provenance must therefore live on the SERVER-SIDE session record and be carried
 * through every rotation. It is never read from client input.
 *
 * Additive and reversible: nullable column, no backfill required. Existing rows
 * (NULL) mean "ordinary user login", which is the correct default — the only
 * value that grants LESS access is 'shared_admin_token', and no pre-existing
 * session can claim to be more privileged by being NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sessions') || Schema::hasColumn('sessions', 'auth_via')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            // e.g. null (ordinary login) | 'shared_admin_token' | future service principals
            $table->string('auth_via', 32)->nullable()->after('workspace_id');
            $table->index('auth_via', 'sessions_auth_via_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sessions') || !Schema::hasColumn('sessions', 'auth_via')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropIndex('sessions_auth_via_idx');
            $table->dropColumn('auth_via');
        });
    }
};
