<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RFC-0028 / DEC-0085 - Team Leader (Owner 2026-10-05). An affiliate who pays $99 a month gets 25 / 20 / 15 budgets on their own
// referrals and 5% of every payment their recruits bring in (the recruit keeps 20 / 15 / 10). One level. Applications are approved
// by Bella or by hand. The fee is taken from commission on hand when there is any; a failed fee gives 30 days, then the team dissolves.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $t) {
            $t->string('tier', 12)->default('affiliate')->after('status');            // affiliate | leader
            $t->unsignedBigInteger('leader_id')->nullable()->after('tier')->index();   // the Team Leader whose team this affiliate is on
            $t->string('team_code', 24)->nullable()->unique()->after('leader_id');     // what a recruit types when applying
            $t->string('leader_status', 16)->nullable()->after('team_code');          // Stripe subscription status
            $t->string('leader_sub_id', 64)->nullable()->after('leader_status');
            $t->string('leader_customer_id', 64)->nullable()->after('leader_sub_id');
            $t->boolean('leader_cancel_at_end')->default(false)->after('leader_customer_id');
            $t->timestamp('leader_since')->nullable()->after('leader_cancel_at_end');
            $t->timestamp('leader_until')->nullable()->after('leader_since');          // end of the paid month
            $t->timestamp('leader_grace_until')->nullable()->after('leader_until');    // D20: 30 days to pay or earn the fee
            $t->json('pre_leader_budgets')->nullable()->after('leader_grace_until');
            $t->string('leader_recommendation', 12)->nullable()->after('pre_leader_budgets');   // recommend | decline (the leader's view of an applicant)
            $t->string('leader_rec_note', 300)->nullable()->after('leader_recommendation');
            $t->string('decided_by', 12)->nullable()->after('approved_by');              // bella | admin
            $t->json('bella_review')->nullable()->after('decided_by');
            $t->timestamp('bella_reviewed_at')->nullable()->after('bella_review');
        });
        Schema::table('commissions', function (Blueprint $t) {
            $t->unsignedBigInteger('override_of')->nullable()->after('reverses_id')->index();   // the recruit's commission this 5% rides on
        });
        Schema::create('affiliate_leader_prices', function (Blueprint $t) {
            $t->id(); $t->string('mode', 8)->unique(); $t->string('product_id', 64); $t->string('price_id', 64); $t->timestamps();
        });
        Schema::create('affiliate_leader_fees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->string('period_key', 40);                 // the renewal it pays: period end date, or the failed invoice id
            $t->bigInteger('from_commission_minor')->default(0);
            $t->bigInteger('card_minor')->default(0);
            $t->string('credit_txn_id', 64)->nullable();
            $t->string('invoice_id', 64)->nullable();
            $t->unsignedBigInteger('commission_id')->nullable();   // the -fee row in the ledger
            $t->string('status', 12)->default('credited');           // credited | applied | reversed | out_of_band
            $t->timestamps();
            $t->unique(['affiliate_id', 'period_key']);
        });
        Schema::create('affiliate_team_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('leader_id')->index(); $t->unsignedBigInteger('recruit_id')->nullable();
            $t->string('kind', 32); $t->json('data_json')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('affiliate_team_invites', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('leader_id'); $t->string('email', 190); $t->string('status', 12)->default('sent');
            $t->unsignedSmallInteger('sent_count')->default(1); $t->timestamp('last_sent_at')->nullable(); $t->timestamps();
            $t->unique(['leader_id', 'email']);
        });
        Schema::create('affiliate_team_presets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('leader_id')->index(); $t->string('label', 60);
            $t->unsignedSmallInteger('monthly_bps'); $t->unsignedSmallInteger('yearly_bps'); $t->unsignedSmallInteger('domain_bps'); $t->timestamps();
        });
        Schema::create('affiliate_team_messages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('leader_id')->index(); $t->string('subject', 120); $t->text('body');
            $t->unsignedInteger('recipients')->default(0); $t->timestamps();
        });
        Schema::create('affiliate_team_notes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('leader_id'); $t->unsignedBigInteger('recruit_id'); $t->text('note')->nullable();
            $t->unsignedSmallInteger('goal_signups')->nullable(); $t->timestamps();
            $t->unique(['leader_id', 'recruit_id']);
        });
    }

    public function down(): void
    {
        foreach (['affiliate_team_notes', 'affiliate_team_messages', 'affiliate_team_presets', 'affiliate_team_invites', 'affiliate_team_events', 'affiliate_leader_fees', 'affiliate_leader_prices'] as $tb) Schema::dropIfExists($tb);
        Schema::table('commissions', fn (Blueprint $t) => $t->dropColumn('override_of'));
        Schema::table('affiliates', function (Blueprint $t) {
            $t->dropUnique(['team_code']); $t->dropIndex(['leader_id']);
            $t->dropColumn(['tier', 'leader_id', 'team_code', 'leader_status', 'leader_sub_id', 'leader_customer_id', 'leader_cancel_at_end', 'leader_since', 'leader_until', 'leader_grace_until',
                'pre_leader_budgets', 'leader_recommendation', 'leader_rec_note', 'decided_by', 'bella_review', 'bella_reviewed_at']);
        });
    }
};
