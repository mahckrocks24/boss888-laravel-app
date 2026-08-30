<?php

namespace Tests\Feature\Account;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LT-1 (2026-08-30): the appearance preference is whitelisted on PUT /api/user/preferences and read back via /auth/me. */
class ThemePreferenceTest extends TestCase
{
    private function userWithToken(): array
    {
        $uid = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'theme-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws  = (int) DB::table('workspaces')->insertGetId(['name' => 'Theme', 'slug' => 'theme-' . uniqid(), 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['user_id' => $uid, 'workspace_id' => $ws, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $user = \App\Models\User::find($uid);
        $pair = app(\App\Core\Auth\RefreshTokenService::class)->issueTokenPair($user, \App\Models\Workspace::find($ws));
        return [$uid, (string) ($pair['access_token'] ?? $pair['token'] ?? '')];
    }

    public function test_theme_is_stored_and_invalid_values_are_rejected(): void
    {
        [$uid, $token] = $this->userWithToken();
        $h = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        $this->putJson('/api/user/preferences', ['theme' => 'light'], $h)->assertOk()->assertJsonPath('preferences.theme', 'light');
        $this->assertSame('light', json_decode((string) DB::table('users')->where('id', $uid)->value('preferences_json'), true)['theme'] ?? null);

        $this->putJson('/api/user/preferences', ['theme' => 'system'], $h)->assertOk()->assertJsonPath('preferences.theme', 'system');
        $this->putJson('/api/user/preferences', ['theme' => 'neon'], $h)->assertStatus(422);

        // visibility_mode is untouched by a theme write
        $this->putJson('/api/user/preferences', ['visibility_mode' => 'advanced'], $h)->assertOk();
        $this->putJson('/api/user/preferences', ['theme' => 'dark'], $h)->assertOk()->assertJsonPath('preferences.visibility_mode', 'advanced');

        $this->getJson('/api/auth/me', $h)->assertOk()->assertJsonPath('preferences.theme', 'dark');
    }
}
