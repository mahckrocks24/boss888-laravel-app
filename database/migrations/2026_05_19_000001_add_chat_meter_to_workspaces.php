<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 22 — chat_meter for the 10-chat-per-credit batched billing.
 *
 * Counts assistant + agent chat turns since last credit deduction.
 * When the counter reaches 10, CreditService::meterChat() debits 1 cr
 * and resets the counter to 0. Effective price: 0.1 cr per chat.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedSmallInteger('chat_meter')->default(0)->after('monthly_credit_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('chat_meter');
        });
    }
};