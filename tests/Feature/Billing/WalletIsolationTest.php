<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\CreditService;
use App\Engines\Builder\Services\ArthurService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1-U1 (2026-08-30, REPORT-0023 UX-001): a website-workspace may share a wallet only with a pool its own
 * creator owns that is not a house account; ledger rows carry the pool they hit; the isolate-wallet
 * command re-parents through the ledger.
 */
class WalletIsolationTest extends TestCase
{
    private function mkUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => $email, 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mkWorkspace(string $name, int $creator, bool $house = false, ?int $pool = null): int
    {
        $id = (int) DB::table('workspaces')->insertGetId(['name' => $name, 'slug' => strtolower($name) . '-' . uniqid(), 'created_by' => $creator, 'is_house_account' => $house, 'billing_workspace_id' => $pool, 'created_at' => now(), 'updated_at' => now()]);
        if ($pool === null) { DB::table('workspaces')->where('id', $id)->update(['billing_workspace_id' => $id]); }
        DB::table('credits')->insert(['workspace_id' => $id, 'balance' => 40, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_provisioning_refuses_house_pool_and_gives_own_wallet(): void
    {
        $owner = $this->mkUser('house-' . uniqid() . '@example.test');
        $house = $this->mkWorkspace('House', $owner, true);
        $qaUser = $this->mkUser('qa-' . uniqid() . '@example.test');

        $newWs = app(ArthurService::class)->provisionWebsiteWorkspace($house, $qaUser, $house, 'Scratch Site');

        $this->assertSame($newWs, (int) DB::table('workspaces')->where('id', $newWs)->value('billing_workspace_id'));
        $this->assertTrue(DB::table('credits')->where('workspace_id', $newWs)->exists());
        $this->assertSame(0, (int) DB::table('credits')->where('workspace_id', $newWs)->value('balance'));
    }

    public function test_provisioning_pools_into_own_non_house_workspace(): void
    {
        $owner = $this->mkUser('own-' . uniqid() . '@example.test');
        $primary = $this->mkWorkspace('Primary', $owner, false);

        $newWs = app(ArthurService::class)->provisionWebsiteWorkspace($primary, $owner, $primary, 'Second Site');

        $this->assertSame($primary, (int) DB::table('workspaces')->where('id', $newWs)->value('billing_workspace_id'));
        $this->assertFalse(DB::table('credits')->where('workspace_id', $newWs)->exists());
    }

    public function test_ledger_rows_carry_pool_provenance_and_isolate_command_reparents(): void
    {
        $owner = $this->mkUser('prov-' . uniqid() . '@example.test');
        $pool = $this->mkWorkspace('Pool', $owner, false);
        $child = (int) DB::table('workspaces')->insertGetId(['name' => 'Child', 'slug' => 'child-' . uniqid(), 'created_by' => $owner, 'billing_workspace_id' => $pool, 'created_at' => now(), 'updated_at' => now()]);

        $svc = app(CreditService::class);
        $rsv = $svc->reserveCredits($child, 3, 'test/reserve', null);
        $this->assertSame($pool, (int) ($rsv->metadata_json['pool_workspace_id'] ?? 0));
        $svc->commitReservedCredits($rsv->reservation_reference);
        $this->assertSame(37, (int) DB::table('credits')->where('workspace_id', $pool)->value('balance'));

        $this->artisan('workspace:isolate-wallet', ['workspace' => $child, '--seed' => 20, '--reason' => 'test'])->assertExitCode(0);

        $this->assertSame($child, (int) DB::table('workspaces')->where('id', $child)->value('billing_workspace_id'));
        $this->assertSame(37, (int) DB::table('credits')->where('workspace_id', $pool)->value('balance'), 'old pool untouched');
        $this->assertSame(20, (int) DB::table('credits')->where('workspace_id', $child)->value('balance'));
        $row = DB::table('credit_transactions')->where('workspace_id', $child)->where('type', 'credit')->orderByDesc('id')->first();
        $this->assertSame('adjustment/test', $row->reference_type);
        $meta = json_decode($row->metadata_json, true);
        $this->assertSame($pool, (int) $meta['previous_pool_workspace_id']);
        $this->assertSame($child, (int) $meta['pool_workspace_id']);
        $this->assertSame(3, (int) $meta['committed_against_previous_pool']);
    }
}
