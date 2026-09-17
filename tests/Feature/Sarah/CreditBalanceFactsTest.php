<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\CreditBalanceFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/** RISK-0189 (2026-09-17): a credit balance Sarah states is the ledger's figure at reply time. */
class CreditBalanceFactsTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void { parent::setUp(); $this->setUpBoss888(); }

    public function test_a_remembered_figure_is_replaced_by_the_ledger_and_a_correct_one_is_left_alone(): void
    {
        $ws = $this->testWorkspace->id;
        DB::table('credits')->where('workspace_id', $ws)->update(['balance' => 1]);
        $this->assertSame('You have 1 credit left in your account.', CreditBalanceFacts::guard('You have 2 credits left in your account.', $ws));
        $this->assertSame('Balance is 1 credit now; the piece cost 2 credits.', CreditBalanceFacts::guard('Balance is 2 credits now; the piece cost 2 credits.', $ws), 'a cost figure is not a balance figure');
        DB::table('credits')->where('workspace_id', $ws)->update(['balance' => 26]);
        $this->assertSame('You have 26 credits left, 12 spent today.', CreditBalanceFacts::guard('You have 26 credits left, 12 spent today.', $ws));
        $this->assertSame('Nothing about money here.', CreditBalanceFacts::guard('Nothing about money here.', $ws));
    }
}
