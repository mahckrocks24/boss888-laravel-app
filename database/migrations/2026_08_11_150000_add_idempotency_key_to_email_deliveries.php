<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL888 - idempotency key on the delivery ledger.
 *
 * The uniqueness is enforced by the DATABASE, not by a read-then-write in PHP.
 * A duplicate submit under load is precisely when a check-then-insert loses,
 * and losing means the customer is emailed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $t) {
            $t->string('idempotency_key', 191)->nullable()->after('correlation_id');
            $t->unique('idempotency_key', 'email_deliveries_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $t) {
            $t->dropUnique('email_deliveries_idem_uq');
            $t->dropColumn('idempotency_key');
        });
    }
};
