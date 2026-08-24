<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery evidence, and room for the states that describe it.
 *
 * `engineering_tasks.status` was varchar(24). FAILED_RECOVERY_INCOMPLETE is 26
 * characters, and MySQL in this configuration would have truncated it rather
 * than complained — a task whose real state is "writes remain, recovery did not
 * finish" would have been stored as something shorter and read as something
 * else. Widened before the states exist, not after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineering_tasks', function (Blueprint $table) {
            $table->string('status', 40)->default('received')->change();
        });

        Schema::create('engineering_recoveries', function (Blueprint $table) {
            $table->id();
            $table->char('task_uuid', 36);
            $table->char('candidate_uuid', 36)->nullable();

            // Identity of the recovery plan, so a human approving a manual
            // recovery approves one exact set of restorations.
            $table->char('fingerprint', 64);

            // FAILED_RECOVERED | FAILED_RECOVERY_INCOMPLETE | FAILED_RECOVERY_BLOCKED
            $table->string('status', 40);

            $table->longText('manifest')->nullable();
            $table->longText('evidence')->nullable();
            $table->string('actor', 128)->nullable();

            // Set when a human authorised a recovery the automatic path refused.
            $table->string('approved_by', 255)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['task_uuid', 'status'], 'er_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_recoveries');

        Schema::table('engineering_tasks', function (Blueprint $table) {
            $table->string('status', 24)->default('received')->change();
        });
    }
};
