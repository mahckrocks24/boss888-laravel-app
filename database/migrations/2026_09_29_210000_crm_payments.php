<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-PACKS-4a: quotes, deposit requests and invoices a business sends its client from Clients. The client accepts or
 * pays on a page under the business's own website address; money goes to the business's own Stripe account
 * (workspace_payment_accounts, DEC-0051) — the platform never holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_payment_requests')) return;
        Schema::create('crm_payment_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('business_id')->nullable();
            $t->unsignedBigInteger('lead_id')->index();
            $t->string('kind', 12);                    // quote | deposit | invoice
            $t->string('number', 24);                  // Q-0001 / D-0001 / INV-0001, per workspace
            $t->string('title', 190);
            $t->json('items_json');                    // [{description, qty, unit}]
            $t->string('currency', 3);
            $t->decimal('total', 12, 2);
            $t->date('due_date')->nullable();
            $t->text('note')->nullable();
            $t->string('status', 12)->default('draft'); // draft | sent | viewed | accepted | paid | cancelled
            $t->string('token', 48)->unique();
            $t->string('session_id', 255)->nullable()->index();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->decimal('paid_amount', 12, 2)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void {}
};
