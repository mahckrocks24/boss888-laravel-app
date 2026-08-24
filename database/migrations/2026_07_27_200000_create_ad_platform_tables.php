<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 P0 — platform tables: settings and audit.
 *
 * ad_settings   Every operational knob in §7.A of the enterprise plan. Stored as
 *               key → JSON so a new setting needs no migration. The master
 *               kill-switch lives here and SHIPS FALSE: no ad can render until
 *               someone deliberately turns the platform on.
 *
 * ad_audit_log  Every settings change, creative approval, campaign state change
 *               and site block, with actor and a before/after diff. An ad
 *               platform without an audit trail cannot answer "who approved
 *               this creative and when", which is the first question asked when
 *               something goes wrong.
 *
 * ADDITIVE ONLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_settings')) {
            Schema::create('ad_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 96)->unique();
                $table->json('value')->nullable();
                $table->string('description', 255)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ad_audit_log')) {
            Schema::create('ad_audit_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable()
                    ->comment('null = system/automation');
                $table->string('actor_label', 96)->nullable();
                $table->string('action', 64)
                    ->comment('e.g. settings.update, creative.approve, campaign.pause');
                $table->string('subject_type', 64)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamp('occurred_at')->useCurrent();

                $table->index(['subject_type', 'subject_id'], 'ad_audit_subject_idx');
                $table->index('action', 'ad_audit_action_idx');
                $table->index('occurred_at', 'ad_audit_time_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_audit_log');
        Schema::dropIfExists('ad_settings');
    }
};
