<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the grounded pre-image lives.
 *
 * The candidate row already keeps what the model said. It did not keep what the
 * model was shown, so an approval could only ever bind the bytes to be written
 * — never the bytes being replaced. This column holds SourceGrounding's own
 * observation of each target: its sha256, its length, and whether it existed at
 * all, exactly as it was at the instant the prompt was built.
 *
 * IT IS A SEPARATE COLUMN, NOT A PAYLOAD KEY. `payload` is provider output kept
 * verbatim. Evidence about the repository must not be stored anywhere a model
 * could author it, or the field an approval most needs to trust would be the
 * field the model controls.
 *
 * NULLABLE, AND BACKFILLED BY NOTHING. Every row that predates this column stays
 * NULL. Hashing those files today and writing the result here would manufacture
 * a pre-image that no model ever saw and no human ever approved — a lie that
 * would read exactly like evidence. Legacy candidates report themselves unbound
 * instead, which is both true and actionable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('engineering_candidates', 'pre_image')) { return; }

        Schema::table('engineering_candidates', function (Blueprint $table) {
            $table->longText('pre_image')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('engineering_candidates', 'pre_image')) { return; }

        Schema::table('engineering_candidates', function (Blueprint $table) {
            $table->dropColumn('pre_image');
        });
    }
};
