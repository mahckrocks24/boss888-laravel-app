<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0023 P2 (2026-10-02): the Outcome Ledger - one row per unit of work with a result (campaign, post, article, image,
 * seo change, crm, task): what was planned, what was delivered, how the owner judged it, what it produced at 24 h / 7 d /
 * 30 d (from connected sources only, else "not measurable"), and a one-line lesson written from the numbers and the verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outcome_ledger')) return;
        Schema::create('outcome_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('business_id')->nullable()->index();
            $t->string('kind', 24)->index();                 // campaign|post|article|image|seo_change|crm|site_change|task
            $t->string('ref', 80)->unique();                 // task:33274 | campaign:45
            $t->string('goal_ref', 80)->nullable();          // mandate:12 | campaign:45
            $t->string('status', 16)->default('delivered')->index();   // planned|delivered|failed
            $t->json('planned_json')->nullable();
            $t->json('delivered_json')->nullable();
            $t->string('verdict', 16)->default('none')->index();       // none|approved|edited|rejected|ignored|declined
            $t->json('verdict_json')->nullable();
            $t->boolean('measurable')->default(true);
            $t->json('result_24h_json')->nullable();
            $t->json('result_7d_json')->nullable();
            $t->json('result_30d_json')->nullable();
            $t->timestamp('measured_24h_at')->nullable();
            $t->timestamp('measured_7d_at')->nullable();
            $t->timestamp('measured_30d_at')->nullable();
            $t->text('lesson')->nullable();
            $t->string('lesson_source', 16)->default('rule');
            $t->timestamp('delivered_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('outcome_ledger'); }
};
