<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CRM-UX-2: a client's stage in its business's pack (the status stays as the lifecycle behind it). Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'stage')) {
            Schema::table('leads', function (Blueprint $t) {
                $t->string('stage', 40)->nullable()->after('status');
            });
        }
    }

    public function down(): void {}
};
