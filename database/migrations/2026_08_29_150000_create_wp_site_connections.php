<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-1 (2026-08-29, EV-0872) — the WordPress connection store that never existed.
 *
 * The connector's consumers (AdminController listUsers/listWorkspaces/listConnectorSites/
 * getWorkspace, StripeService suspend/reactivate) shipped on 2026-05-05 against a
 * `wp_site_connections` table and an `api_keys.site_connection_id` column that no migration
 * ever created; WpConnectorSchema has guarded the six call sites since 2026-08-04. The Owner
 * (2026-08-29) ruled WordPress part of the Websites/Hosting product on the normal subscription
 * ladder — the missing storage is an engineering gap to finish, not a scope question.
 *
 * Shape follows the actual connection flow (plugin → POST /connector/register-site with
 * site_url + webhook_secret + site_name, authenticated by an api_keys row) plus what the
 * consumers read: id, workspace_id, site_url, status, last_push_at, last_push_status, timestamps.
 * status values observed: active, disconnected, failed, billing_suspended.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wp_site_connections')) {
            Schema::create('wp_site_connections', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('website_id')->nullable()->index();   // websites row (platform=wordpress)
                $table->unsignedBigInteger('api_key_id')->nullable()->index();   // the key the plugin authenticates with
                $table->string('site_url', 2048);
                $table->string('site_host', 255)->index();                       // normalised host for lookups
                $table->string('site_name', 255)->nullable();
                $table->string('webhook_secret', 255)->nullable();               // for Laravel → WP calls (lgsc/v1/*)
                $table->string('plugin_version', 32)->nullable();
                $table->string('wp_version', 32)->nullable();
                $table->enum('status', ['active', 'disconnected', 'failed', 'billing_suspended'])->default('active')->index();
                $table->timestamp('last_seen_at')->nullable();                   // last authenticated plugin call
                $table->timestamp('last_push_at')->nullable();                   // last Laravel → WP push
                $table->string('last_push_status', 32)->nullable();              // ok | failed | unreachable
                $table->text('last_error')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
                $table->unique(['workspace_id', 'site_host']);
            });
        }
        if (Schema::hasTable('api_keys') && ! Schema::hasColumn('api_keys', 'site_connection_id')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->unsignedBigInteger('site_connection_id')->nullable()->index()->after('workspace_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('api_keys') && Schema::hasColumn('api_keys', 'site_connection_id')) {
            Schema::table('api_keys', function (Blueprint $table) { $table->dropColumn('site_connection_id'); });
        }
        Schema::dropIfExists('wp_site_connections');
    }
};
