<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Domains (Sprint 3) — Cloudflare for SaaS custom-hostname lifecycle.
 *
 * Additive: a dedicated table so the full lifecycle (ownership / SSL / routing,
 * provider ids, error + timestamps) is representable independently, and so the
 * DB can record "provider create succeeded but local persistence/validation
 * later failed". The legacy websites.custom_domain / domain_verified columns
 * are LEFT INTACT and kept in sync for existing reads (backward compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_domains')) {
            return;
        }

        Schema::create('custom_domains', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('website_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();

            // Raw input + normalized hostname (lowercased, no scheme/port/trailing dot).
            $table->string('domain');
            $table->string('hostname')->index();
            $table->boolean('is_apex')->default(false);

            // Provider abstraction (portability).
            $table->string('provider')->default('cloudflare_saas');
            $table->string('provider_hostname_id')->nullable()->index();

            // Explicit lifecycle state machine (customer-safe vocabulary).
            // pending_setup|awaiting_dns|validating|ssl_pending|active|failed|disconnecting|disconnected
            $table->string('state')->default('pending_setup')->index();

            // Sub-statuses synchronised from the provider (kept separate on purpose).
            $table->string('ownership_status')->nullable();
            $table->string('ssl_status')->nullable();
            $table->string('routing_status')->nullable();

            // What the customer must do (dns target, TXT ownership record, etc.).
            $table->string('verification_method')->nullable(); // txt|http
            $table->json('verification_records')->nullable();

            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable(); // last raw provider snapshot

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('ssl_issued_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->timestamps();

            // A website has at most one live custom domain; a hostname is globally
            // unique at Cloudflare. Uniqueness among *live* rows is enforced in the
            // application layer (partial unique indexes aren't portable in MySQL).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_domains');
    }
};
