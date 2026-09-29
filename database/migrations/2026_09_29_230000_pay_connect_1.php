<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-CONNECT-1 (Owner 2026-09-29: "charge 1% go ahead"). A business connects its own Stripe account through the
 * platform (Stripe Connect, Standard account, direct charges); every online payment carries a platform fee of
 * fee_bps (100 = 1%). A pasted key (DEC-0051) keeps working, with no fee. Additive: new columns, and secret_key
 * becomes nullable (a Connect row holds no key of the business).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_payment_accounts', function (Blueprint $t) {
            if (! Schema::hasColumn('workspace_payment_accounts', 'connect_account_id')) $t->string('connect_account_id', 40)->nullable()->after('provider');
            if (! Schema::hasColumn('workspace_payment_accounts', 'fee_bps')) $t->unsignedSmallInteger('fee_bps')->default(100)->after('connect_account_id');
            if (! Schema::hasColumn('workspace_payment_accounts', 'connect_state')) $t->string('connect_state', 24)->nullable()->after('fee_bps');
        });
        DB::statement('ALTER TABLE workspace_payment_accounts MODIFY secret_key TEXT NULL');
        Schema::table('catalogue_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('catalogue_orders', 'platform_fee')) $t->decimal('platform_fee', 12, 2)->nullable()->after('amount');
        });
        Schema::table('crm_payment_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('crm_payment_requests', 'platform_fee')) $t->decimal('platform_fee', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_payment_accounts', function (Blueprint $t) { $t->dropColumn(['connect_account_id', 'fee_bps', 'connect_state']); });
        Schema::table('catalogue_orders', function (Blueprint $t) { $t->dropColumn('platform_fee'); });
        Schema::table('crm_payment_requests', function (Blueprint $t) { $t->dropColumn('platform_fee'); });
    }
};
