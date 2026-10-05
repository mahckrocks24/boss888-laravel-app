<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MANAGED-1 (RFC-0029, DEC-0086, Owner 2026-10-05) — the Managed Hosting package.
 *
 * workspaces.workspace_type: 'standard' (every workspace today) or 'managed' — a client with a custom-built
 * website whose people see only the managed portal (Email, Billing, Account, a status line). Set by us, never by
 * the client: it is not in Workspace::$fillable.
 *
 * managed_contracts: the package a managed client bought, recorded by hand (PTAA paid PHP 180,000 for three
 * years by bank transfer; nothing here is charged). Shown read-only on the portal's Billing page.
 *
 * Additive only: one column with a default, one new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'workspace_type')) {
            Schema::table('workspaces', function (Blueprint $t) {
                $t->string('workspace_type', 16)->default('standard')->after('lifecycle_state')->index();
            });
        }

        if (! Schema::hasTable('managed_contracts')) {
            Schema::create('managed_contracts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('package_name', 120);
                $t->json('inclusions_json')->nullable();
                $t->char('currency', 3);
                $t->unsignedBigInteger('amount_minor');
                $t->date('paid_at')->nullable();
                $t->date('term_start');
                $t->date('term_end');
                $t->date('support_until')->nullable();
                $t->string('support_label', 120)->nullable();
                $t->string('invoice_ref', 40)->nullable();
                $t->string('receipt_ref', 40)->nullable();
                $t->string('receipt_path', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_contracts');
        if (Schema::hasColumn('workspaces', 'workspace_type')) {
            Schema::table('workspaces', function (Blueprint $t) {
                $t->dropIndex(['workspace_type']);
                $t->dropColumn('workspace_type');
            });
        }
    }
};
