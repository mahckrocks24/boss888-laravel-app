<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publisher provider hardening (2026-07-22).
 *
 * Adds what real provider execution needs and what the approval contract must
 * bind to. Additive and reversible; historical rows keep NULLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $t) {
            // Connection health is NOT the same as `status`. A connection can be
            // 'connected' yet unusable because the token expired or the user
            // revoked a permission. Telling someone "Facebook is not connected"
            // when the truth is "your token was revoked" sends them down the
            // wrong path, so we model the real states.
            $t->string('health_state', 32)->default('connected')->after('status');
            $t->timestamp('token_expires_at')->nullable()->after('health_state');
            $t->json('granted_scopes_json')->nullable()->after('token_expires_at');
            $t->timestamp('health_checked_at')->nullable()->after('granted_scopes_json');
            $t->string('health_detail', 191)->nullable()->after('health_checked_at');
            $t->unsignedBigInteger('connected_by')->nullable()->after('health_detail');

            // Provider-side identity, kept separate from credentials so it can be
            // displayed and audited without ever touching a secret.
            $t->string('provider_account_name', 191)->nullable()->after('connected_by');
            $t->string('linked_page_id', 64)->nullable()->after('provider_account_name');

            // Encrypted credential envelope. credentials_json is retained for the
            // dormant historical rows; new connections write here instead.
            $t->text('credentials_encrypted')->nullable()->after('credentials_json');

            $t->index(['workspace_id', 'platform', 'health_state'], 'social_accounts_ws_plat_health_idx');
        });

        Schema::table('social_posts', function (Blueprint $t) {
            // The approval binds to the EXACT content that was approved. If the
            // caption or the media changes afterwards, these no longer match and
            // the approval is void — enforced in PublisherService::execute().
            $t->char('approved_caption_hash', 64)->nullable()->after('approval_state');
            $t->char('approved_media_hash', 64)->nullable()->after('approved_caption_hash');

            // Normalised provider outcome.
            $t->string('provider_error_code', 64)->nullable()->after('failure_class');
            $t->string('provider_status_class', 32)->nullable()->after('provider_error_code');
            $t->unsignedSmallInteger('reconcile_attempts')->default(0)->after('attempt_count');
            $t->string('provider_request_id', 128)->nullable()->after('external_post_id');
        });

        Schema::table('publisher_posts', function (Blueprint $t) {
            // Binds the approval to the schedule, so moving the time voids it.
            $t->string('approved_schedule_key', 32)->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('publisher_posts', fn (Blueprint $t) => $t->dropColumn('approved_schedule_key'));

        Schema::table('social_posts', function (Blueprint $t) {
            $t->dropColumn([
                'approved_caption_hash', 'approved_media_hash', 'provider_error_code',
                'provider_status_class', 'reconcile_attempts', 'provider_request_id',
            ]);
        });

        Schema::table('social_accounts', function (Blueprint $t) {
            $t->dropIndex('social_accounts_ws_plat_health_idx');
            $t->dropColumn([
                'health_state', 'token_expires_at', 'granted_scopes_json', 'health_checked_at',
                'health_detail', 'connected_by', 'provider_account_name', 'linked_page_id',
                'credentials_encrypted',
            ]);
        });
    }
};
