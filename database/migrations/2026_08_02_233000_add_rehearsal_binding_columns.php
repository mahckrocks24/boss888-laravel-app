<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a rehearsal must bind to before it can authorise anything.
 *
 * The table recorded that a rehearsal happened. It could not answer "of which
 * bytes, for which candidate, in which project" — so a rehearsal of one thing
 * could sit next to an approval of another and nothing would notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineering_migration_rehearsals', function (Blueprint $table) {
            $table->string('rehearsal_uuid', 64)->nullable()->after('id');

            // The exact bytes. This is the binding that matters; everything
            // else describes the environment they were proved in.
            $table->char('migration_hash', 64)->nullable()->after('migration_path');

            $table->string('project_key', 64)->nullable()->after('database');
            $table->string('phpunit_config', 128)->nullable()->after('project_key');

            $table->char('evidence_fingerprint', 64)->nullable()->after('evidence');
            $table->char('recovery_plan_fingerprint', 64)->nullable()->after('recovery_plan');

            $table->longText('declared_contract')->nullable();
            $table->longText('observed_contract')->nullable();

            $table->index(['candidate_uuid', 'migration_path'], 'emr_candidate_path_idx');
            $table->index('migration_hash', 'emr_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('engineering_migration_rehearsals', function (Blueprint $table) {
            $table->dropIndex('emr_candidate_path_idx');
            $table->dropIndex('emr_hash_idx');
            $table->dropColumn([
                'rehearsal_uuid', 'migration_hash', 'project_key', 'phpunit_config',
                'evidence_fingerprint', 'recovery_plan_fingerprint',
                'declared_contract', 'observed_contract',
            ]);
        });
    }
};
