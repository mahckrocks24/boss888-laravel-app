<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\WorkspaceSwitchService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** BIZ-1 / INC-0004 (2026-08-31). Changing the active business is the one thing that decides what EVERY module
 *  shows, so it has exactly two rules: only a member may do it, and only an explicit request may cause it.
 *  INC-0004 was the second rule broken in the client; this covers the first, which is the server's job. */
class WorkspaceSwitchTest extends TestCase
{
    /** @return array{0:User,1:int,2:int,3:int} owner, their workspace A, their workspace B, a stranger's workspace */
    private function seedAccount(): array
    {
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'biz-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $other = (int) DB::table('users')->insertGetId(['name' => 'Stranger', 'email' => 'biz-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);

        $mk = function (string $label, int $creator) {
            return (int) DB::table('workspaces')->insertGetId(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid(), 'created_by' => $creator, 'created_at' => now(), 'updated_at' => now()]);
        };
        $wsA = $mk('BizA', $uid);
        $wsB = $mk('BizB', $uid);
        $wsForeign = $mk('NotYours', $other);

        DB::table('workspace_users')->insert([
            ['workspace_id' => $wsA, 'user_id' => $uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $wsB, 'user_id' => $uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $wsForeign, 'user_id' => $other, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [User::find($uid), $wsA, $wsB, $wsForeign];
    }

    public function test_a_member_may_switch_and_the_new_token_is_bound_to_that_business(): void
    {
        [$user, $wsA, $wsB] = $this->seedAccount();

        $res = app(WorkspaceSwitchService::class)->switchWorkspace($user, $wsB);

        $this->assertSame($wsB, $res['current_workspace_id'], 'the switch reports the business asked for');
        $this->assertNotEmpty($res['access_token'] ?? null, 'a switch mints a token for the new business');

        $ids = array_map(static fn ($w) => (int) $w['id'], $res['workspaces']);
        sort($ids);
        $expected = [$wsA, $wsB];
        sort($expected);
        $this->assertSame($expected, $ids, 'only the businesses this account belongs to are ever listed back');
    }

    public function test_switching_into_a_business_you_do_not_belong_to_is_refused(): void
    {
        [$user, , , $foreign] = $this->seedAccount();

        try {
            app(WorkspaceSwitchService::class)->switchWorkspace($user, $foreign);
            $this->fail('a non-member was allowed to switch into another account\'s business');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'membership is enforced server-side, not by hiding the option');
        }
    }

    public function test_a_business_that_does_not_exist_is_refused_the_same_way(): void
    {
        [$user] = $this->seedAccount();

        try {
            app(WorkspaceSwitchService::class)->switchWorkspace($user, 2147483000);
            $this->fail('switching into a non-existent business was allowed');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
