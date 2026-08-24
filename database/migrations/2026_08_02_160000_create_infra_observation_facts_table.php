<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · S8 — OBSERVATION REGISTRY.
 *
 * S6/S7 observed one dimension (the registrar) for domains we register. That
 * covered ZERO customer domains: chefredraymundo.com and amgtravelandtours.com
 * are GoDaddy-held under customer control, so a registrar API we do not hold
 * credentials for could never see them.
 *
 * This table is dimension-agnostic. One row = one observation of one DIMENSION
 * (dns | certificate | http | whois | registrar) of one SUBJECT, under a stated
 * CUSTODY, with the confidence that custody permits.
 *
 * Custody is recorded on every row because it changes what an observation can
 * possibly mean. "auto_renew = false" read from our own registrar account is a
 * fact; the same field inferred from public WHOIS for a customer-held domain is
 * a guess. Storing them in the same shape without recording how we learned them
 * would make the estate confidently wrong.
 *
 * `last_success_at` / `last_failure_at` are carried forward on every row so a
 * single read answers "when did we last actually know this?" without scanning
 * history — while history itself is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_observation_facts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();

            // Subject: a hostname. May or may not have a customer_domains row.
            $table->string('subject', 253);
            $table->unsignedBigInteger('customer_domain_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();

            // dns | certificate | http | whois | registrar
            $table->string('dimension', 24);
            // managed_by_us | customer_held | third_party_held | unknown
            $table->string('custody', 24);
            // who/what produced this: namecheap | dns_resolver | tls_handshake | http_probe | whois_cli
            $table->string('provider', 40);

            $table->boolean('success')->default(false);
            // verified | inferred | unknown — what custody permits us to claim
            $table->string('confidence', 16)->default('unknown');

            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_summary')->nullable();

            $table->json('observed_json')->nullable();
            $table->json('desired_json')->nullable();

            $table->boolean('has_drift')->default(false);
            $table->unsignedSmallInteger('drift_count')->default(0);
            $table->string('highest_severity', 16)->nullable();
            $table->json('drift_json')->nullable();

            $table->timestamp('observed_at');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->index(['subject', 'dimension']);
            $table->index(['dimension', 'observed_at']);
            $table->index('custody');
            $table->index('customer_domain_id');
            $table->index(['has_drift', 'highest_severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_observation_facts');
    }
};
