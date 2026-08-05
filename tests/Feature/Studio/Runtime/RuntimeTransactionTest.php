<?php

namespace Tests\Feature\Studio\Runtime;

use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Runtime\Events\RuntimeEventType;
use App\Engines\Studio\Runtime\RuntimeTransaction;
use PHPUnit\Framework\TestCase;

/** Pure - runtime transaction ownership (no undo/history yet). */
final class RuntimeTransactionTest extends TestCase
{
    private function readyRuntime()
    {
        $rt = RuntimeFixture::runtime();
        $graph = RuntimeFixture::graph();
        $rt->loadDocument('d1', $graph);
        $rt->attachRenderer(RuntimeFixture::adapter());
        $rt->setSelection(RuntimeFixture::selectYellowNumber($graph));

        return $rt;
    }

    public function test_begin_creates_open_transaction(): void
    {
        $rt = RuntimeFixture::runtime();
        $txn = $rt->beginTransaction('edit');
        $this->assertSame(RuntimeTransaction::STATUS_OPEN, $txn->status());
        $this->assertSame($txn, $rt->currentTransaction());
    }

    public function test_begin_twice_returns_the_same_open_transaction(): void
    {
        $rt = RuntimeFixture::runtime();
        $this->assertSame($rt->beginTransaction(), $rt->beginTransaction());
    }

    public function test_execution_belongs_to_the_current_transaction(): void
    {
        $rt = $this->readyRuntime();
        $txn = $rt->beginTransaction();
        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'o1'));

        $this->assertSame(1, $txn->operationCount());
        $this->assertSame(1, $txn->appliedCount());
    }

    public function test_execute_auto_opens_a_transaction(): void
    {
        $rt = $this->readyRuntime();
        $this->assertNull($rt->currentTransaction());
        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'o1'));
        $this->assertNotNull($rt->currentTransaction());
    }

    public function test_commit_closes_and_emits_history_committed(): void
    {
        $rt = $this->readyRuntime();
        $committedId = null;
        $rt->events()->on(RuntimeEventType::HISTORY_COMMITTED, function ($e) use (&$committedId) {
            $committedId = $e->get('transaction_id');
        });

        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'o1'));
        $txn = $rt->commitTransaction();

        $this->assertSame(RuntimeTransaction::STATUS_COMMITTED, $txn->status());
        $this->assertNull($rt->currentTransaction());
        $this->assertSame($txn->id, $committedId);
        $this->assertCount(1, $rt->committedTransactions());
    }
}
