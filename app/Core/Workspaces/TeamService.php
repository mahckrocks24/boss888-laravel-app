<?php

namespace App\Core\Workspaces;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TeamService — Workspace team management
 *
 * Handles invitations, role management, and seat enforcement.
 *
 * Seat limits by plan:
 *   Free / AI Lite: 1 (owner only)
 *   Starter:        3
 *   Growth:         5
 *   Pro:            10
 *   Agency:         999 (unlimited)
 *
 * Roles:
 *   owner  — full access, billing, team management (one per workspace)
 *   admin  — full access to features, no billing/plan changes
 *   member — access to assigned engines only, no settings
 *   viewer — read-only (for future use)
 *
 * Invite flow:
 *   1. Owner/admin calls inviteMember() → creates pending_invite with secure token
 *   2. Invitee receives email link: /invite/{token}
 *   3. Invitee calls acceptInvite(token) → creates/links user, adds to workspace_users
 *   4. Invite marked accepted
 */
class TeamService
{
    private const INVITE_EXPIRY_HOURS = 72;  // 3 days

    // ═══════════════════════════════════════════════════════════
    // INVITE MANAGEMENT
    // ═══════════════════════════════════════════════════════════

    /**
     * Invite a new member to the workspace.
     * Creates a pending invite record. Sending the email is handled by
     * the notification layer (caller's responsibility).
     */
    public function inviteMember(int $wsId, int $invitedByUserId, string $email, string $role = 'member'): array
    {
        $role = $this->sanitizeRole($role);

        // Validate seat quota before creating invite
        $seatCheck = $this->checkSeatQuota($wsId);
        if (!$seatCheck['allowed']) {
            return ['success' => false, 'error' => $seatCheck['reason'], 'code' => 'SEAT_LIMIT_REACHED'];
        }

        // Check if already a member
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            $alreadyMember = DB::table('workspace_users')
                ->where('workspace_id', $wsId)
                ->where('user_id', $existingUser->id)
                ->exists();

            if ($alreadyMember) {
                return ['success' => false, 'error' => 'User is already a member of this workspace', 'code' => 'ALREADY_MEMBER'];
            }
        }

        // Cancel any existing pending invite for this email+workspace
        DB::table('pending_invites')
            ->where('workspace_id', $wsId)
            ->where('email', $email)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        // Create new invite
        $token = Str::random(48);
        $inviteId = DB::table('pending_invites')->insertGetId([
            'workspace_id'    => $wsId,
            'invited_by'      => $invitedByUserId,
            'email'           => strtolower(trim($email)),
            'role'            => $role,
            'token'           => $token,
            'status'          => 'pending',
            'expires_at'      => now()->addHours(self::INVITE_EXPIRY_HOURS),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $workspace = Workspace::find($wsId);
        $invitedBy = User::find($invitedByUserId);

        return [
            'success'    => true,
            'invite_id'  => $inviteId,
            'token'      => $token,
            'email'      => $email,
            'role'       => $role,
            'expires_at' => now()->addHours(self::INVITE_EXPIRY_HOURS)->toISOString(),
            'invite_url' => config('app.url') . '/invite/' . $token,
            'workspace'  => $workspace?->name,
            'invited_by' => $invitedBy?->name,
        ];
    }

    /**
     * Accept an invite. Creates the user if they don't exist.
     * Returns auth tokens so the invitee can log in immediately.
     */
    public function acceptInvite(string $token, array $userData = []): array
    {
        $invite = DB::table('pending_invites')
            ->where('token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$invite) {
            return ['success' => false, 'error' => 'Invite not found or already used', 'code' => 'INVALID_TOKEN'];
        }

        if (now()->gt($invite->expires_at)) {
            DB::table('pending_invites')->where('id', $invite->id)->update(['status' => 'expired', 'updated_at' => now()]);
            return ['success' => false, 'error' => 'This invite has expired. Ask your team admin to send a new one.', 'code' => 'EXPIRED'];
        }

        // Check seat quota again (race condition protection)
        $seatCheck = $this->checkSeatQuota($invite->workspace_id);
        if (!$seatCheck['allowed']) {
            return ['success' => false, 'error' => $seatCheck['reason'], 'code' => 'SEAT_LIMIT_REACHED'];
        }

        DB::beginTransaction();
        try {
            // Create user if they don't exist
            $user = User::where('email', $invite->email)->first();

            if (!$user) {
                if (empty($userData['name']) || empty($userData['password'])) {
                    DB::rollBack();
                    return ['success' => false, 'error' => 'name and password required to create account', 'code' => 'REGISTRATION_REQUIRED'];
                }

                $user = User::create([
                    'name'     => $userData['name'],
                    'email'    => $invite->email,
                    'password' => Hash::make($userData['password']),
                ]);
            }

            // Add to workspace (upsert in case of concurrent accepts)
            DB::table('workspace_users')->updateOrInsert(
                ['workspace_id' => $invite->workspace_id, 'user_id' => $user->id],
                ['role' => $invite->role, 'updated_at' => now(), 'created_at' => now()]
            );

            // Mark invite accepted
            DB::table('pending_invites')->where('id', $invite->id)->update([
                'status'      => 'accepted',
                'accepted_by' => $user->id,
                'accepted_at' => now(),
                'updated_at'  => now(),
            ]);

            DB::commit();

            $workspace = Workspace::find($invite->workspace_id);

            return [
                'success'      => true,
                'user_id'      => $user->id,
                'workspace_id' => $invite->workspace_id,
                'workspace'    => $workspace?->name,
                'role'         => $invite->role,
                'is_new_user'  => isset($userData['password']),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TeamService::acceptInvite failed', ['error' => $e->getMessage(), 'token' => substr($token, 0, 8) . '...']);
            return ['success' => false, 'error' => 'Failed to accept invite. Please try again.', 'code' => 'ERROR'];
        }
    }

    /**
     * Preview an invite without accepting (for showing workspace name on the invite page).
     */
    public function previewInvite(string $token): array
    {
        $invite = DB::table('pending_invites')
            ->where('token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$invite) {
            return ['valid' => false, 'error' => 'Invite not found or already used'];
        }

        if (now()->gt($invite->expires_at)) {
            return ['valid' => false, 'error' => 'Invite has expired'];
        }

        $workspace  = Workspace::find($invite->workspace_id);
        $invitedBy  = User::find($invite->invited_by);
        $userExists = User::where('email', $invite->email)->exists();

        return [
            'valid'        => true,
            'email'        => $invite->email,
            'role'         => $invite->role,
            'workspace'    => $workspace?->name,
            'workspace_id' => $invite->workspace_id,
            'invited_by'   => $invitedBy?->name,
            'expires_at'   => $invite->expires_at,
            'user_exists'  => $userExists,
        ];
    }

    /**
     * Cancel a pending invite.
     */
    public function cancelInvite(int $wsId, int $inviteId): bool
    {
        return DB::table('pending_invites')
            ->where('id', $inviteId)
            ->where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]) > 0;
    }

    /**
     * List pending invites for a workspace.
     */
    public function listPendingInvites(int $wsId): array
    {
        return DB::table('pending_invites')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    // ═══════════════════════════════════════════════════════════
    // MEMBER MANAGEMENT
    // ═══════════════════════════════════════════════════════════

    /**
     * List all members of a workspace with their roles.
     */
    public function getMembers(int $wsId): array
    {
        $members = DB::table('workspace_users')
            ->join('users', 'workspace_users.user_id', '=', 'users.id')
            ->where('workspace_users.workspace_id', $wsId)
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.avatar',
                'workspace_users.role',
                'workspace_users.created_at as joined_at'
            )
            ->orderByRaw("FIELD(workspace_users.role, 'owner', 'admin', 'member', 'viewer')")
            ->orderBy('workspace_users.created_at')
            ->get()
            ->toArray();

        $pendingInvites = $this->listPendingInvites($wsId);

        return [
            'members'         => $members,
            'pending_invites' => $pendingInvites,
            'total'           => count($members),
            'pending_count'   => count($pendingInvites),
        ];
    }

    /**
     * Update a member's role.
     * Cannot change the owner's role.
     */
    public function updateRole(int $wsId, int $targetUserId, string $newRole, int $requestingUserId): array
    {
        // Cannot change owner role
        $targetMember = DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->where('user_id', $targetUserId)
            ->first();

        if (!$targetMember) {
            return ['success' => false, 'error' => 'User is not a member of this workspace'];
        }

        if ($targetMember->role === 'owner') {
            return ['success' => false, 'error' => 'Cannot change the workspace owner role'];
        }

        // Cannot target yourself for demotion if you're the only admin
        if ($targetUserId === $requestingUserId && $newRole === 'member') {
            $adminCount = DB::table('workspace_users')
                ->where('workspace_id', $wsId)
                ->whereIn('role', ['owner', 'admin'])
                ->count();
            if ($adminCount <= 1) {
                return ['success' => false, 'error' => 'Cannot remove your own admin access — workspace must have at least one admin'];
            }
        }

        $sanitized = $this->sanitizeRole($newRole);
        if ($sanitized === 'owner') {
            return ['success' => false, 'error' => 'Cannot assign owner role through this endpoint'];
        }

        DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->where('user_id', $targetUserId)
            ->update(['role' => $sanitized, 'updated_at' => now()]);

        return ['success' => true, 'user_id' => $targetUserId, 'new_role' => $sanitized];
    }

    /**
     * Remove a member from the workspace.
     * Cannot remove the owner.
     */
    public function removeMember(int $wsId, int $targetUserId, int $requestingUserId): array
    {
        $targetMember = DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->where('user_id', $targetUserId)
            ->first();

        if (!$targetMember) {
            return ['success' => false, 'error' => 'User is not a member of this workspace'];
        }

        if ($targetMember->role === 'owner') {
            return ['success' => false, 'error' => 'Cannot remove the workspace owner'];
        }

        DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->where('user_id', $targetUserId)
            ->delete();

        return ['success' => true, 'user_id' => $targetUserId, 'removed' => true];
    }

    // ═══════════════════════════════════════════════════════════
    // SEAT QUOTA
    // ═══════════════════════════════════════════════════════════

    public function checkSeatQuota(int $wsId): array
    {
        $plan = $this->getActivePlan($wsId);
        $max  = $plan ? (int) $plan->max_team_members : 1;

        $current = DB::table('workspace_users')->where('workspace_id', $wsId)->count();
        $pending = DB::table('pending_invites')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->count();

        $total = $current + $pending;

        // Agency plan (999) = unlimited
        if ($max >= 999) {
            return ['allowed' => true, 'current' => $current, 'pending' => $pending, 'max' => 'unlimited'];
        }

        if ($total >= $max) {
            return [
                'allowed' => false,
                'reason'  => "Team seat limit reached ({$current} members + {$pending} pending invites / {$max} max on your plan)",
                'current' => $current,
                'pending' => $pending,
                'max'     => $max,
            ];
        }

        return [
            'allowed'   => true,
            'current'   => $current,
            'pending'   => $pending,
            'max'       => $max,
            'remaining' => $max - $total,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ROLE CHECKS (used by middleware)
    // ═══════════════════════════════════════════════════════════

    public function getUserRole(int $wsId, int $userId): ?string
    {
        $row = DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->where('user_id', $userId)
            ->first();

        return $row?->role;
    }

    public function isOwnerOrAdmin(int $wsId, int $userId): bool
    {
        return in_array($this->getUserRole($wsId, $userId), ['owner', 'admin']);
    }

    public function isOwner(int $wsId, int $userId): bool
    {
        return $this->getUserRole($wsId, $userId) === 'owner';
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════════

    private function sanitizeRole(string $role): string
    {
        $allowed = ['admin', 'member', 'viewer'];
        return in_array($role, $allowed) ? $role : 'member';
    }

    private function getActivePlan(int $wsId): ?Plan
    {
        $sub = Subscription::where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        return $sub ? Plan::find($sub->plan_id) : Plan::where('slug', 'free')->first();
    }
}
