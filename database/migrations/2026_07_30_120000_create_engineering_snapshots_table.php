<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineer888 — engineering snapshots.
 *
 * PURPOSE
 * Stores one row per engineering brief so the brief can report CHANGE rather
 * than only state. "159 uncommitted files" is a number; "+8 since yesterday" is
 * a signal. Trend is the whole reason this table exists.
 *
 * SCHEMA DECISION — signals stored as JSON, deliberately.
 * A normalised column per metric would require a migration every time a signal
 * is added, which in practice means signals do not get added. The JSON payload
 * lets Engineer888 grow its own sensor set without schema churn. The trade-off
 * accepted: no SQL aggregation over individual metrics. When a specific metric
 * earns continuous querying, it gets promoted to a real column — and that
 * promotion will be driven by evidence rather than anticipated here.
 *
 * log_offset is a real column because it is READ on the next run (to count only
 * new errors), not merely reported. Anything the next run depends on must be
 * queryable without decoding JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('captured_at')->index();

            // Full signal payload. See the schema decision above.
            $table->json('signals');

            // Deterministic rule output. Stored so a brief can be re-read
            // exactly as it was issued, rather than re-derived from signals
            // whose rules may since have changed.
            $table->json('recommendations')->nullable();

            // Byte offset into storage/logs/laravel.log at capture time, so the
            // next run counts only errors written since. Read by the collector,
            // therefore a column and not a JSON key.
            $table->unsignedBigInteger('log_offset')->default(0);

            // Highest severity present, for cheap "was yesterday bad?" queries.
            $table->string('worst_severity', 12)->default('ok')->index();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_snapshots');
    }
};
