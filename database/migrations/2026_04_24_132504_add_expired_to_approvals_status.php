<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE approvals MODIFY status ENUM('pending','approved','rejected','revised','expired') NOT NULL DEFAULT 'pending'");
    }
    public function down(): void
    {
        DB::statement("UPDATE approvals SET status='rejected' WHERE status='expired'");
        DB::statement("ALTER TABLE approvals MODIFY status ENUM('pending','approved','rejected','revised') NOT NULL DEFAULT 'pending'");
    }
};
