<?php

namespace App\Core\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Credit;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Agent;
use App\Core\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
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

        // MISSION-018 WS-2 (2026-08-24): the Owner's policy is that NEW
        // ACCOUNTS receive the 3-day / 50-credit trial (MISSION-018 §11).
        // TrialService shipped complete in May — activation guards, trialing
        // Growth subscription, ledgered credits, a scheduled expiry sweep —
        // and was never called here; the only trigger was first website
        // creation (the historical accident REPORT-0012 §15 measured:
        // is_trial=0 on all 47 workspaces ever). This is the one-line switch:
        // to move the trigger, remove this call — the BuilderService call
        // remains and the already_trialed guard prevents doubles.
        try {
            app(\App\Core\Billing\TrialService::class)->activateTrial($workspace->id);
        } catch (\Throwable $e) {
            Log::error('trial activation at registration failed', [
                'workspace_id' => $workspace->id, 'error' => $e->getMessage(),
            ]);
        }

        // Sarah is the only agent attached at signup. Additional specialists
        // unlock as the user progresses onboarding / upgrades plan.
        $sarah = Agent::where('slug', 'sarah')->where('status', 'active')->first();
        if ($sarah) {
            $workspace->agents()->attach($sarah->id, ['enabled' => true]);
        }

        // EV-1043 (Owner, 2026-09-15): the sign-up page already asked for the business name and industry — keep them
        // on the workspace, and let Sarah introduce herself as the FIRST MESSAGE OF HER OWN THREAD. That thread is
        // the one surface every Sarah door reads (Basic Sarah view, Advanced Messages floater), so the introduction
        // happens once, for new accounts only, inside her real interface — never on a separate onboarding screen.
        try {
            $facts = [];
            if (!empty($data['workspace_name'])) $facts['business_name'] = (string) $data['workspace_name'];
            if (!empty($data['industry']))       $facts['industry']      = mb_substr(trim((string) $data['industry']), 0, 120);
            if ($facts) $workspace->update($facts);
            $first = trim((string) (explode(' ', trim((string) $data['name']))[0] ?? ''));
            $biz   = !empty($data['workspace_name']) ? (string) $data['workspace_name'] : 'your business';
            $intro = "Hi" . ($first !== '' ? ' ' . $first : '') . ", I'm Sarah, your Digital Marketing Manager. I plan your growth, brief the specialists and bring you the work to approve — nothing goes live without you. Arthur builds and edits your website.\n\nTo start, tell me a bit about " . $biz . ": what you do, who you do it for, and what you would like to achieve first.";
            DB::table('agent_messages')->insert([
                'workspace_id'  => $workspace->id,
                'agent_slug'    => 'sarah',
                'sender'        => 'Sarah',
                'content'       => $intro,
                'role'          => 'agent',
                'metadata_json' => json_encode(['notification_type' => 'sarah_intro']),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('sarah intro at registration failed', ['workspace_id' => $workspace->id, 'error' => $e->getMessage()]);
        }

        $tokens = $this->refreshTokenService->issueTokenPair($user, $workspace);

        $this->auditLogService->log($workspace->id, $user->id, 'user.registered');

        // MISSION-018 WS-2 (2026-08-24): registration previously issued a
        // usable token with NO verification step anywhere in the journey
        // (REPORT-0012 §1b). The email is queued so signup latency never
        // waits on the mail provider; delivery failure is logged, never
        // allowed to break registration itself.
        $this->sendVerificationEmail($user);

        return $this->buildAuthResponse($user, $workspace, $tokens);
    }

    /**
     * Queue the address-confirmation email carrying a 72-hour signed URL.
     * Safe to call repeatedly (resend); a no-op for already-verified users.
     */
    public function sendVerificationEmail(User $user): bool
    {
        if ($user->email_verified_at !== null) {
            return false;
        }

        try {
            $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'verification.verify',
                now()->addHours(72),
                ['id' => $user->id, 'hash' => sha1($user->email)]
            );
            Mail::to($user->email)->queue(
                new \App\Core\Auth\Mail\VerifyEmailAddress($user->name ?? 'there', $url)
            );
            return true;
        } catch (\Throwable $e) {
            Log::error('verification email queue failed', [
                'user_id' => $user->id, 'error' => $e->getMessage(),
            ]);
            return false;
        }
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
            // Provenance comes from the SERVER-SIDE session record, never from the
            // client. A shared-admin session stays restricted across refreshes.
            'access_token' => $this->refreshTokenService->issueAccessToken(
                $user, $workspace, $result['auth_via'] ?? null, $result['session_id'] ?? null
            ),
            'refresh_token' => $result['refresh_token'],
        ]);
    }

    public function logout(string $refreshToken): void
    {
        // b19/b20 (2026-07-24) — resolve the session BEFORE revoking so we can
        // clean up the push registrations belonging to THIS device.
        $hash    = hash('sha256', $refreshToken);
        $session = \Illuminate\Support\Facades\DB::table('sessions')
            ->where('refresh_token_hash', $hash)
            ->first(['id', 'user_id']);

        $this->refreshTokenService->revoke($refreshToken);

        if (! $session) {
            return;
        }
        $userId = $session->user_id;

        // b20 — PER-DEVICE REVOCATION. device_tokens.session_id binds a
        // registration to the sign-in that created it (and follows it across
        // refresh rotation), so signing out on one phone silences exactly that
        // phone and leaves the user's other signed-in devices working.
        $removedThisDevice = \Illuminate\Support\Facades\DB::table('device_tokens')
            ->where('session_id', $session->id)
            ->delete();

        if ($removedThisDevice > 0) {
            \Illuminate\Support\Facades\Log::info('[Auth] revoked push for the device signing out', [
                'user_id' => $userId, 'session_id' => $session->id, 'removed' => $removedThisDevice,
            ]);
        }

        // Backstop for registrations with no session binding — rows created by
        // an app still holding a pre-b20 access token (no `sid` claim). Those
        // cannot be matched to a device, so they are cleared once the user is
        // signed out everywhere, at which point every registration is
        // unreachable by definition and the app re-registers on next login.
        $stillSignedIn = \Illuminate\Support\Facades\DB::table('sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($stillSignedIn) {
            return;
        }

        $removed = \Illuminate\Support\Facades\DB::table('device_tokens')
            ->where('user_id', $userId)
            ->delete();

        if ($removed > 0) {
            \Illuminate\Support\Facades\Log::info('[Auth] purged device tokens on full logout', [
                'user_id' => $userId, 'removed' => $removed,
            ]);
        }
    }

    public function me(User $user, ?int $activeWorkspaceId = null): array
    {
        $workspaces = $user->workspaces()->with('subscription.plan')->get();

        // AUTHORITATIVE ACTIVE WORKSPACE (2026-08-13). JwtAuthMiddleware scopes
        // every request from the token's `ws` claim and has already validated it
        // against live membership, so that claim — not the order of the
        // membership list — is the tenant the caller is actually in. Reporting
        // $workspaces->first() meant that after a workspace switch /auth/me kept
        // naming the first membership: the UI showed one tenant's identity while
        // every API call was scoped to another, so plan, credits and entitlements
        // silently belonged to a different workspace. Proven live: JWT ws=999926
        // (BUILDER888 Journey A3) vs /auth/me current_workspace_id=2 (Chef Red).
        $currentWs = $activeWorkspaceId !== null
            ? ($workspaces->firstWhere('id', $activeWorkspaceId) ?? $workspaces->first())
            : $workspaces->first();

        // 2026-05-28 — Per-user preferences (sidebar visibility mode, etc.).
        // Pulled directly via DB::table so we don't have to add preferences_json
        // to the User model's $fillable (preferences is admin-curated, not
        // exposed to mass-assign).
        $rawPrefs = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('preferences_json');
        $prefs = is_string($rawPrefs) ? (json_decode($rawPrefs, true) ?: []) : [];
        if (! is_array($prefs)) $prefs = [];

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
            // The request's resolved workspace wins. Falling back to the first
            // membership only when the caller had no resolved scope at all.
            'current_workspace_id' => $activeWorkspaceId ?? $currentWs?->id,
            'preferences' => $prefs,
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
                    // EM-3: a password reset is security mail. The purpose pins it to
                    // the transactional stream, so a marketing unsubscribe can never
                    // stop a customer regaining access to their account.
                    $m->getSymfonyMessage()->getHeaders()
                        ->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, 'password_reset');

                    $m->to($user->email, $user->name)
                      ->subject('Reset your LevelUpGrowth password')
                      ->from(
                          config('mail.from.address', env('MAIL_FROM_ADDRESS', 'hello@levelupgrowth.io')),
                          config('mail.from.name', env('MAIL_FROM_NAME', 'LevelUpGrowth'))
                      );
                }
            );
        } catch (\Throwable $e) {
            $__ledgerId = app(\App\Core\Email888\DeliveryLedger::class)->markLastRecordedFailed($e); // F-EM-C2
            Log::error('forgotPassword mail send failed', [
                'user_id' => $user->id,
                'delivery_id' => $__ledgerId,
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
