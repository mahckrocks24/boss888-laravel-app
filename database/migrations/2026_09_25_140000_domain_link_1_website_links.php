<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOMAIN-LINK-1 (RFC-0015, 2026-09-25): a domain bought from us can belong to a website.
 *
 * Additive only. `website_id` is frozen on the order line at purchase and copied to the
 * ownership row at registration; the two `*_state` columns carry the automatic set-up
 * (DNS written at our registrar, then attached to the website) so the customer sees one
 * status line from Paid to Live. Nothing here is a claim that any step happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domain_order_items') && ! Schema::hasColumn('domain_order_items', 'website_id')) {
            Schema::table('domain_order_items', function (Blueprint $t) {
                $t->unsignedBigInteger('website_id')->nullable()->index()->after('workspace_id');
            });
        }

        if (Schema::hasTable('customer_domains')) {
            Schema::table('customer_domains', function (Blueprint $t) {
                if (! Schema::hasColumn('customer_domains', 'website_id')) {
                    $t->unsignedBigInteger('website_id')->nullable()->index()->after('domain_order_item_id');
                }
                if (! Schema::hasColumn('customer_domains', 'dns_state')) {
                    $t->string('dns_state', 24)->nullable()->after('status');       // pending | set | external | failed
                }
                if (! Schema::hasColumn('customer_domains', 'connect_state')) {
                    $t->string('connect_state', 24)->nullable()->after('dns_state'); // pending | waiting | connecting | active | stalled | failed
                }
                if (! Schema::hasColumn('customer_domains', 'link_error')) {
                    $t->text('link_error')->nullable()->after('connect_state');
                }
                if (! Schema::hasColumn('customer_domains', 'connect_started_at')) {
                    $t->timestamp('connect_started_at')->nullable()->after('link_error');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_domains')) {
            Schema::table('customer_domains', function (Blueprint $t) {
                foreach (['connect_started_at', 'link_error', 'connect_state', 'dns_state', 'website_id'] as $c) {
                    if (Schema::hasColumn('customer_domains', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('domain_order_items') && Schema::hasColumn('domain_order_items', 'website_id')) {
            Schema::table('domain_order_items', fn (Blueprint $t) => $t->dropColumn('website_id'));
        }
    }
};
