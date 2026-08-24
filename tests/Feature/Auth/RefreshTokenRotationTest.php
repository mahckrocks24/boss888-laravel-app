<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\RefreshTokenService;
use App\Models\Session;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Regression cover for refresh-token rotation.
 *
 * 2026-08-02 — before this suite existed, reuse of an already-rotated refresh
 * token more than 60s after rotation revoked EVERY session belonging to the
 * user. On live traffic that branch fired 36 times and destroyed 336 sessions;
 * it was the cause of the "I always get signed out" reports. Because each tab
 * and each device holds its own copy of the token in localStorage, ordinary
 * multi-device use triggered it.
 *
 * The invariant these tests defend: a refresh that cannot be honoured fails
 * THAT REQUEST ONLY. It must never revoke a session it was not presented.
 */
class RefreshTokenRotationTest extends TestCase
{
    private RefreshTokenService $svc;
    private User $user;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(RefreshTokenService::class);

        $suffix = Str::random(12);
        $this->user = User::create([
            'name'     => 'Rotation Test User',
            'email'    => "rotation-{$suffix}@test.invalid",
            'password' => bcrypt(Str::random(16)),
        ]);
        $this->ws = Workspace::create([
            'name'       => "Rotation Test WS {$suffix}",
            'slug'       => "rotation-test-{$suffix}",
            'created_by' => $this->user->id,
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => $this->ws->id,
            'user_id'      => $this->user->id,
            'role'         => 'owner',
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::table('device_tokens')->where('user_id', $this->user->id)->delete();
        Session::where('user_id', $this->user->id)->delete();
        DB::table('workspace_users')->where('user_id', $this->user->id)->delete();
        Workspace::where('id', $this->ws->id)->delete();
        User::where('id', $this->user->id)->delete();

        parent::tearDown();
    }

    /** Simulates the replay window having elapsed. */
    private function expireReplayWindow(string $refreshToken): void
    {
        Cache::forget('auth:refresh_replay:' . hash('sha256', $refreshToken));
    }

    public function test_normal_rotation_revokes_the_old_session_and_mints_one_successor(): void
    {
        $pair = $this->svc->issueTokenPair($this->user, $this->ws);
        $before = Session::where('user_id', $this->user->id)->count();

        $result = $this->svc->validateAndRotate($pair['refresh_token']);

        $this->assertNotSame($pair['refresh_token'], $result['refresh_token']);
        $this->assertSame($this->user->id, (int) $result['user_id']);
        $this->assertSame($this->ws->id, (int) $result['workspace_id']);
        $this->assertSame(
            $before + 1,
            Session::where('user_id', $this->user->id)->count(),
            'rotation must create exactly one successor session'
        );
        $this->assertNotNull(
            Session::find($pair['session_id'])->revoked_at,
            'the presented session must be revoked'
        );
    }

    public function test_replay_inside_the_window_returns_the_same_successor(): void
    {
        $pair = $this->svc->issueTokenPair($this->user, $this->ws);

        $first  = $this->svc->validateAndRotate($pair['refresh_token']);
        $count  = Session::where('user_id', $this->user->id)->count();
        $second = $this->svc->validateAndRotate($pair['refresh_token']);

        $this->assertSame(
            $first['session_id'],
            $second['session_id'],
            'a lagging tab must receive the winner\'s successor, not a 401'
        );
        $this->assertSame($first['refresh_token'], $second['refresh_token']);
        $this->assertSame(
            $count,
            Session::where('user_id', $this->user->id)->count(),
            'a replay must not mint an additional session'
        );
    }

    /**
     * THE regression. A stale token is refused, but every other session the
     * user holds — other tabs, other devices — must survive untouched.
     */
    public function test_replay_outside_the_window_does_not_revoke_other_sessions(): void
    {
        $deviceA = $this->svc->issueTokenPair($this->user, $this->ws, '10.0.0.1', 'device-a');
        $deviceB = $this->svc->issueTokenPair($this->user, $this->ws, '10.0.0.2', 'device-b');
        $deviceC = $this->svc->issueTokenPair($this->user, $this->ws, '10.0.0.3', 'device-c');

        $stale = $this->svc->issueTokenPair($this->user, $this->ws, '10.0.0.4', 'stale-device');
        $this->svc->validateAndRotate($stale['refresh_token']);

        // Push the rotation well outside the replay window.
        Session::where('refresh_token_hash', hash('sha256', $stale['refresh_token']))
            ->update(['revoked_at' => now()->subMinutes(30)]);
        $this->expireReplayWindow($stale['refresh_token']);

        $liveBefore = Session::where('user_id', $this->user->id)->whereNull('revoked_at')->count();

        $status = null;
        try {
            $this->svc->validateAndRotate($stale['refresh_token']);
            $this->fail('a stale refresh token must be rejected');
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
        }

        $this->assertSame(401, $status);

        $liveAfter = Session::where('user_id', $this->user->id)->whereNull('revoked_at')->count();
        $this->assertSame(
            $liveBefore,
            $liveAfter,
            'rejecting one stale token must not revoke any other session'
        );

        foreach (['A' => $deviceA, 'B' => $deviceB, 'C' => $deviceC] as $label => $device) {
            $this->assertNull(
                Session::find($device['session_id'])->revoked_at,
                "device {$label} must still be signed in"
            );
        }
    }

    public function test_each_surviving_device_can_still_refresh_after_a_stale_replay(): void
    {
        $device = $this->svc->issueTokenPair($this->user, $this->ws, '10.0.0.9', 'survivor');
        $stale  = $this->svc->issueTokenPair($this->user, $this->ws, '10.0.0.8', 'stale');

        $this->svc->validateAndRotate($stale['refresh_token']);
        Session::where('refresh_token_hash', hash('sha256', $stale['refresh_token']))
            ->update(['revoked_at' => now()->subMinutes(30)]);
        $this->expireReplayWindow($stale['refresh_token']);

        try {
            $this->svc->validateAndRotate($stale['refresh_token']);
        } catch (HttpException) {
            // expected
        }

        $rotated = $this->svc->validateAndRotate($device['refresh_token']);
        $this->assertNotEmpty($rotated['refresh_token']);
        $this->assertSame($this->user->id, (int) $rotated['user_id']);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->svc->validateAndRotate(str_repeat('z', 64));
    }

    public function test_expired_session_is_rejected(): void
    {
        $pair = $this->svc->issueTokenPair($this->user, $this->ws);
        Session::find($pair['session_id'])->update(['expires_at' => now()->subDay()]);

        $status = null;
        try {
            $this->svc->validateAndRotate($pair['refresh_token']);
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
        }
        $this->assertSame(401, $status);
    }

    /**
     * Security invariant: a restricted session must not become unrestricted by
     * refreshing — including via the replay path.
     */
    public function test_auth_via_provenance_survives_rotation_and_replay(): void
    {
        $pair = $this->svc->issueTokenPair(
            $this->user, $this->ws, '10.0.0.1', 'ua', 'shared_admin_token'
        );

        $rotated = $this->svc->validateAndRotate($pair['refresh_token']);
        $this->assertSame('shared_admin_token', $rotated['auth_via']);

        $replayed = $this->svc->validateAndRotate($pair['refresh_token']);
        $this->assertSame('shared_admin_token', $replayed['auth_via']);

        $this->assertSame(
            'shared_admin_token',
            Session::find($rotated['session_id'])->auth_via,
            'provenance must be persisted on the successor session'
        );
    }

    public function test_device_token_binding_follows_the_rotation(): void
    {
        $pair = $this->svc->issueTokenPair($this->user, $this->ws);

        DB::table('device_tokens')->insert([
            'user_id'         => $this->user->id,
            'workspace_id'    => $this->ws->id,
            'session_id'      => $pair['session_id'],
            'expo_push_token' => 'ExponentPushToken[' . Str::random(18) . ']',
            'platform'        => 'ios',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $rotated = $this->svc->validateAndRotate($pair['refresh_token']);

        $this->assertSame(
            (int) $rotated['session_id'],
            (int) DB::table('device_tokens')->where('user_id', $this->user->id)->value('session_id'),
            'push registration must follow the successor session'
        );
    }
}
