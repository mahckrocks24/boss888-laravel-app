<?php

namespace App\Core\Governance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central approval authorization (directive §4).
 *
 * Before this existed, `ApprovalController::approve()` checked only that the
 * approval belonged to the caller's workspace and was pending — no role check,
 * no self-approval check. A viewer could approve a protected action, and a
 * requester could approve their own.
 *
 * All approval authorization now resolves here, so controllers cannot drift
 * apart. It answers the two questions separately:
 *
 *   1. Is this actor allowed to approve THIS capability at all?
 *   2. Is this actor a DIFFERENT person from the requester, where required?
 *
 * FAILS CLOSED throughout: unauthenticated actor, unknown role, machine
 * credential, unclassified protected capability, or an indeterminate requester
 * on a separation-of-duties capability all deny.
 */
class ApprovalAuthorizationService
{
    public const DENY_UNAUTHENTICATED   = 'approval_not_authorized';
    public const DENY_NOT_AUTHORIZED    = 'approval_not_authorized';
    public const DENY_SELF_APPROVAL     = 'self_approval_not_permitted';
    public const DENY_SHARED_TOKEN      = 'shared_admin_token_not_permitted';
    public const DENY_API_KEY           = 'api_key_not_permitted';
    public const DENY_REQUESTER_UNKNOWN = 'approval_requester_unverifiable';

    /**
     * @param  object  $approval  row from `approvals`
     * @return array{allowed:bool, code:?string, message:?string, policy:array, role:?string}
     */
    public function authorize(object $approval, Request $request): array
    {
        $user = $request->user();
        $policy = ApprovalPolicyRegistry::forCapability(
            $approval->engine ?? null,
            $approval->action ?? null,
            $this->approvalModeFor($approval)
        );

        // ---- machine credentials may never approve -------------------------
        if ($request->attributes->get('auth_via') === 'api_key') {
            return $this->deny(self::DENY_API_KEY,
                'Approvals require a signed-in user session.', $policy);
        }

        if ($request->attributes->get('auth_via_claim') === 'shared_admin_token') {
            return $this->deny(self::DENY_SHARED_TOKEN,
                'Approvals require an individually authenticated administrator. Please sign in with your own account.',
                $policy);
        }

        if (!$user || !$user->id) {
            return $this->deny(self::DENY_UNAUTHENTICATED, 'You are not authorized to approve this request.', $policy);
        }

        // ---- role in the approval's workspace -------------------------------
        $role = DB::table('workspace_users')
            ->where('user_id', $user->id)
            ->where('workspace_id', $approval->workspace_id)
            ->value('role');

        $isPlatformAdmin = (bool) ($user->is_platform_admin ?? false);

        if (!$role && !$isPlatformAdmin) {
            return $this->deny(self::DENY_NOT_AUTHORIZED, 'You are not authorized to approve this request.', $policy);
        }

        // Viewers may never approve, in any classification.
        if ($role && in_array($role, ApprovalPolicyRegistry::rolesNeverPermitted(), true)) {
            return $this->deny(self::DENY_NOT_AUTHORIZED,
                'Your role does not permit approving requests.', $policy, $role);
        }

        $effectiveRoles = [];
        if ($role) { $effectiveRoles[] = $role; }
        if ($isPlatformAdmin) { $effectiveRoles[] = ApprovalPolicyRegistry::ROLE_PLATFORM_ADMIN; }

        if (!array_intersect($effectiveRoles, $policy['approval_roles'])) {
            return $this->deny(self::DENY_NOT_AUTHORIZED,
                'Your role does not permit approving this type of request.', $policy, $role);
        }

        // ---- separation of duties -------------------------------------------
        if ($policy['self_approval_allowed'] === false) {
            $requester = $approval->requested_by ?? null;

            if ($requester === null) {
                // We cannot prove this is NOT self-approval. Fail closed rather
                // than assume. Affects legacy rows created before the requester
                // column existed.
                return $this->deny(self::DENY_REQUESTER_UNKNOWN,
                    'This request cannot be approved because the original requester could not be verified. Please raise a new request.',
                    $policy, $role);
            }

            // Canonical ACTOR identity, not token identity: a refreshed token,
            // second browser or alternate session is still the same person.
            if ((int) $requester === (int) $user->id) {
                return $this->deny(self::DENY_SELF_APPROVAL,
                    'This request must be approved by someone other than the person who requested it.',
                    $policy, $role);
            }
        }

        return [
            'allowed' => true,
            'code'    => null,
            'message' => null,
            'policy'  => $policy,
            'role'    => $role ?: ApprovalPolicyRegistry::ROLE_PLATFORM_ADMIN,
        ];
    }

    /**
     * Is anyone in this workspace, other than the requester, able to approve?
     * Drives the single-owner escalation message (§2) instead of silently
     * self-approving or downgrading the action.
     */
    public function hasEligibleApprover(object $approval): bool
    {
        $policy = ApprovalPolicyRegistry::forCapability(
            $approval->engine ?? null,
            $approval->action ?? null,
            $this->approvalModeFor($approval)
        );

        $roles = array_diff($policy['approval_roles'], [ApprovalPolicyRegistry::ROLE_PLATFORM_ADMIN]);

        $q = DB::table('workspace_users')
            ->where('workspace_id', $approval->workspace_id)
            ->whereIn('role', $roles);

        if ($policy['self_approval_allowed'] === false && ($approval->requested_by ?? null)) {
            $q->where('user_id', '!=', $approval->requested_by);
        }

        return $q->exists();
    }

    private function approvalModeFor(object $approval): string
    {
        try {
            $cap = app(\App\Core\EngineKernel\CapabilityMapService::class);
            return $cap->getApprovalMode((string) ($approval->action ?? ''));
        } catch (\Throwable) {
            return 'review';
        }
    }

    private function deny(string $code, string $message, array $policy, ?string $role = null): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message, 'policy' => $policy, 'role' => $role];
    }
}
