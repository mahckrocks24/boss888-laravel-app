<?php

namespace App\Core\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Credit;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Agent;
use App\Core\Audit\AuditLogService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private RefreshTokenService $refreshTokenService,
        private AuditLogService $auditLogService,
    ) {}

    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $workspace = Workspace::create([
            'name' => $data['workspace_name'] ?? $data['name'] . "'s Workspace",
            'slug' => Str::slug($data['name'] . '-' . Str::random(4)),
            'created_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner']);

        // PATCH v1.0.1: balance was 100 — free users could spend AI credits (10/serp, 15/audit) without paying.
        // Correct init is 0. Trial credits (50) are added separately by TrialService::activateTrial()
        // on first website creation. Paid plans receive credits via Stripe webhook.
        Credit::create(['workspace_id' => $workspace->id, 'balance' => 0, 'reserved_balance' => 0]);

        $freePlan = Plan::where('slug', 'free')->first();
        if ($freePlan) {
            Subscription::create([
                'workspace_id' => $workspace->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);
        }

        // Sarah is the only agent attached at signup. Additional specialists
        // unlock as the user progresses onboarding / upgrades plan.
        $sarah = Agent::where('slug', 'sarah')->where('status', 'active')->first();
        if ($sarah) {
            $workspace->agents()->attach($sarah->id, ['enabled' => true]);
        }

        $tokens = $this->refreshTokenService->issueTokenPair($user, $workspace);

        $this->auditLogService->log($workspace->id, $user->id, 'user.registered');

        return $this->buildAuthResponse($user, $workspace, $tokens);
    }

    public function login(string $email, string $password, ?string $ip = null, ?string $ua = null): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            abort(401, 'Invalid credentials');
        }

        $workspace = $user->workspaces()->first();

        $tokens = $this->refreshTokenService->issueTokenPair($user, $workspace, $ip, $ua);

        $this->auditLogService->log($workspace?->id, $user->id, 'user.login');

        return $this->buildAuthResponse($user, $workspace, $tokens);
    }

    public function refresh(string $refreshToken): array
    {
        $result = $this->refreshTokenService->validateAndRotate($refreshToken);
        $user = User::find($result['user_id']);
        $workspace = $result['workspace_id'] ? Workspace::find($result['workspace_id']) : null;

        return $this->buildAuthResponse($user, $workspace, [
            'access_token' => $this->refreshTokenService->issueAccessToken($user, $workspace),
            'refresh_token' => $result['refresh_token'],
        ]);
    }

    public function logout(string $refreshToken): void
    {
        $this->refreshTokenService->revoke($refreshToken);
    }

    public function me(User $user): array
    {
        $workspaces = $user->workspaces()->with('subscription.plan')->get();
        $currentWs = $workspaces->first();

        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'is_platform_admin' => (bool) $user->is_platform_admin,
            ],
            'workspaces' => $workspaces->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'role' => $ws->pivot->role,
                'plan' => $ws->subscription?->plan?->slug ?? 'free',
            ])->toArray(),
            'current_workspace_id' => $currentWs?->id,
        ];
    }

    private function buildAuthResponse(User $user, ?Workspace $workspace, array $tokens): array
    {
        $workspaces = $user->workspaces()->with('subscription.plan')->get();

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'is_platform_admin' => (bool) $user->is_platform_admin,
            ],
            'workspaces' => $workspaces->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'role' => $ws->pivot->role,
                'plan' => $ws->subscription?->plan?->slug ?? 'free',
            ])->toArray(),
            'current_workspace_id' => $workspace?->id,
        ];
    }

    public function forgotPassword(string $email): array
    {
        $user = User::where('email', $email)->first();

        $genericResponse = [
            'success' => true,
            'message' => 'If that email exists, a reset link has been sent.',
        ];

        if (! $user) {
            return $genericResponse;
        }

        \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        $plainToken = Str::random(64);

        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $this->auditLogService->log(null, $user->id, 'user.password_reset_requested');

        $resetUrl = rtrim((string) config('app.url', env('APP_URL', '')), '/')
            . '/reset-password?token=' . urlencode($plainToken)
            . '&email=' . urlencode($email);

        try {
            Mail::send(
                'emails.password-reset',
                [
                    'user'      => $user,
                    'resetUrl'  => $resetUrl,
                    'expireMin' => 60,
                ],
                function ($m) use ($user) {
                    $m->to($user->email, $user->name)
                      ->subject('Reset your LevelUp Growth password')
                      ->from(
                          config('mail.from.address', env('MAIL_FROM_ADDRESS', 'hello@levelupgrowth.io')),
                          config('mail.from.name', env('MAIL_FROM_NAME', 'LevelUp Growth'))
                      );
                }
            );
        } catch (\Throwable $e) {
            Log::error('forgotPassword mail send failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $genericResponse;
    }

    public function resetPassword(string $token, string $password): array
    {
        // Find all unexpired reset tokens (within 60 minutes)
        $resets = \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('created_at', '>', now()->subMinutes(60))
            ->get();

        $matchedReset = null;
        foreach ($resets as $reset) {
            if (Hash::check($token, $reset->token)) {
                $matchedReset = $reset;
                break;
            }
        }

        if (! $matchedReset) {
            abort(422, 'Invalid or expired reset token');
        }

        $user = User::where('email', $matchedReset->email)->first();
        if (! $user) {
            abort(422, 'User not found');
        }

        $user->update(['password' => Hash::make($password)]);

        // Delete all tokens for this email
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $matchedReset->email)
            ->delete();

        $this->auditLogService->log(null, $user->id, 'user.password_reset_completed');

        return ['message' => 'Password reset successfully'];
    }
}
