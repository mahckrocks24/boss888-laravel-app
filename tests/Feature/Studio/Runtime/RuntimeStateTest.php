<?php

namespace Tests\Feature\Studio\Runtime;

use App\Engines\Studio\Runtime\RuntimeState;
use App\Engines\Studio\Runtime\Viewport;
use PHPUnit\Framework\TestCase;

/** Pure - immutable state snapshots, viewport model, metadata. */
final class RuntimeStateTest extends TestCase
{
    public function test_state_snapshot_is_immutable(): void
    {
        $rt = RuntimeFixture::runtime();
        $rt->loadDocument('d1', RuntimeFixture::graph());
        $snap1 = $rt->state();
        $this->assertInstanceOf(RuntimeState::class, $snap1);
        $this->assertSame([], $snap1->selectedIds);

        // Mutate the runtime AFTER taking the snapshot.
        $rt->setSelection(RuntimeFixture::selectHeadline(RuntimeFixture::graph()));
        $snap2 = $rt->state();

        $this->assertSame([], $snap1->selectedIds);           // earlier snapshot unchanged
        $this->assertSame(['headline'], $snap2->selectedIds); // new snapshot reflects the change
        $this->assertNotSame($snap1, $snap2);
    }

    public function test_viewport_is_pure_and_immutable(): void
    {
        $vp = new Viewport();
        $zoomed = $vp->withZoom(2.5)->withPan(10.0, 20.0)->withPage(3);
        $this->assertSame(1.0, $vp->zoom);   // original unchanged
        $this->assertSame(2.5, $zoomed->zoom);
        $this->assertSame(3, $zoomed->page);

        $rt = RuntimeFixture::runtime();
        $rt->updateViewport($zoomed);
        $this->assertSame(2.5, $rt->state()->viewport->zoom);
    }

    public function test_metadata(): void
    {
        $rt = RuntimeFixture::runtime();
        $this->assertNull($rt->metadata('source'));
        $rt->setMetadata('source', 'studio');
        $this->assertSame('studio', $rt->metadata('source'));
        $this->assertSame('studio', $rt->state()->metadata['source']);
    }

    public function test_transaction_id_appears_in_state(): void
    {
        $rt = RuntimeFixture::runtime();
        $this->assertNull($rt->state()->transactionId);
        $txn = $rt->beginTransaction();
        $this->assertSame($txn->id, $rt->state()->transactionId);
    }
}
