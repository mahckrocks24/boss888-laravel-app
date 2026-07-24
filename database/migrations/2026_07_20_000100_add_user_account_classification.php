<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-R2 — user account classification (WS14).
 *
 * REPLACES EMAIL-STRING MATCHING WITH STRUCTURAL CLASSIFICATION.
 *
 * Phase 2B-G created a staging validation identity (user 990016) and Phase 2B-R1
 * kept it out of real governance by matching its EMAIL STRING in
 * AdminGovernanceService. That works, but it is fragile in the way string
 * matching always is: rename the account, create a second validation identity,
 * or typo the constant, and the guard silently stops applying.
 *
 * The directive is explicit — "prefer an explicit account-classification field
 * or policy over permanent email-string matching." This adds that field.
 *
 *   standard          a normal human user/admin (default)
 *   validation_only   a staging-only identity that proves mechanisms and may
 *                     NEVER hold production governance
 *   service           a machine/service account, never a human decision-maker
 *
 * The column is a hard structural fact the governance layer reads, independent
 * of the account's email. Additive and backfilled: every existing user becomes
 * `standard`, and user 990016 is reclassified `validation_only` so the existing
 * protection is preserved through the new mechanism rather than lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'account_classification')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('account_classification', 24)
                    ->default('standard')
                    ->after('is_platform_admin');
                $table->index('account_classification', 'users_acct_class_idx');
            });
        }

        // Backfill: reclassify the known validation identity structurally, so the
        // guard no longer depends on its email string. Matched by email ONCE here
        // (the last legitimate use of the string) to seed the classification.
        DB::table('users')
            ->where('email', 'infra-approver-staging@levelupgrowth.io')
            ->update(['account_classification' => 'validation_only']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'account_classification')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_acct_class_idx');
                $table->dropColumn('account_classification');
            });
        }
    }
};
