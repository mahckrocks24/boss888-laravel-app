<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Convert credits.balance from INT to DECIMAL(10,2) so the meta-
        // optimize service can charge fractional credits (0.5 per page).
        // Existing integer balances cast cleanly to decimal — no data loss.
        DB::statement('ALTER TABLE credits MODIFY COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    }

    public function down(): void
    {
        // Revert to integer; any fractional values floor (Laravel default cast).
        DB::statement('ALTER TABLE credits MODIFY COLUMN balance INT NOT NULL DEFAULT 0');
    }
};
