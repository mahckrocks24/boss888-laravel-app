<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_transactions', 'reservation_status')) {
                $table->string('reservation_status', 20)->nullable()->after('metadata_json');
            }
            if (! Schema::hasColumn('credit_transactions', 'reservation_reference')) {
                $table->string('reservation_reference', 128)->nullable()->after('reservation_status');
            }
            if (! Schema::hasColumn('credit_transactions', 'finalized_at')) {
                $table->timestamp('finalized_at')->nullable()->after('reservation_reference');
            }
            if (! Schema::hasColumn('credit_transactions', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('finalized_at');
            }
        });

        // Add indexes only if not already present
        if (! Schema::hasIndex('credit_transactions', 'credit_transactions_reservation_reference_index')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->index('reservation_reference');
            });
        }
        if (! Schema::hasIndex('credit_transactions', 'credit_transactions_workspace_id_reservation_status_index')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->index(['workspace_id', 'reservation_status']);
            });
        }

        // Extend type enum to include 'commit'
        try {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE credit_transactions MODIFY COLUMN type " .
                "ENUM('credit','debit','reserve','release','refund','commit') DEFAULT 'debit'"
            );
        } catch (\Throwable) {}
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex('credit_transactions_reservation_reference_index');
            $table->dropIndex('credit_transactions_workspace_id_reservation_status_index');
            $table->dropColumn(['reservation_status', 'reservation_reference', 'finalized_at', 'released_at']);
        });

        try {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE credit_transactions MODIFY COLUMN type " .
                "ENUM('credit','debit','reserve','release','refund') DEFAULT 'debit'"
            );
        } catch (\Throwable) {}
    }
};
