<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INC-0006 — record WHY a subscription was cancelled, on the subscription itself.
 *
 * Cancelling a row without saying why turns an auditable decision into an unexplained one. The failed-build
 * workspaces each carry a subscription the provisioner minted alongside them; retiring that entitlement is
 * correct, but only if the record still explains itself years later, without depending on a log that rotates.
 *
 * Nothing is deleted and no history is rewritten: `cancelled_reason` is additive and null for every existing
 * row, including subscriptions that were cancelled normally by a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'cancelled_reason')) {
            DB::statement('ALTER TABLE `subscriptions`
                           ADD COLUMN `cancelled_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `cancelled_at`');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'cancelled_reason')) {
            DB::statement('ALTER TABLE `subscriptions` DROP COLUMN `cancelled_reason`');
        }
    }
};
