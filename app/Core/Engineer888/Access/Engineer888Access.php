<?php

namespace App\Core\Engineer888\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides who may touch Engineer888.
 *
 * Every surface — admin web, admin chat, the companion app's API, the workflow
 * controller, notification delivery — asks this class. None of them re-derives
 * the answer, because a permission implemented twice is a permission that will
 * eventually disagree with itself, and the disagreement will be discovered by
 * whoever gets more access than they should.
 *
 * WHAT THIS IS NOT. It is not a claim that Engineer888 cannot be reached by a
 * determined attacker with a foothold in the application. It is a set of
 * independent conditions, each of which must hold, so that no single failure —
 * a leaked API key, a stale session, a mis-set admin flag, a client that lies
 * about its role — is sufficient on its own.
 *
 * V1 POLICY. One human: user #1, admin@levelupgrowth.io. Identity is checked by
 * ID *and* email *and* an explicit grant row; matching one is never enough,
 * because each of the three fails differently. An email is reassignable, an ID
 * is reusable if a table is ever rebuilt, and a grant can be revoked without
 * touching either.
 */
final class Engineer888Access
{
    public const POLICY_VERSION = 'e888-access-v1';

    /** The only human this system answers to in V1. */
    public const CANONICAL_USER_ID = 1;
    public const CANONICAL_EMAIL = 'admin@levelupgrowth.io';

    // Every reason access can be refused, named. A denial that says only
    // "forbidden" teaches nobody anything and hides the one that is a bug.
    public const DENY_NO_SESSION = 'NO_AUTHENTICATED_HUMAN';
    public const DENY_NOT_CANONICAL_USER = 'NOT_CANONICAL_USER';
    public const DENY_EMAIL_MISMATCH = 'CANONICAL_EMAIL_MISMATCH';
    public const DENY_INACTIVE = 'ACCOUNT_NOT_ACTIVE';
    public const DENY_NOT_PLATFORM_ADMIN = 'NOT_PLATFORM_ADMIN';
    public const DENY_NO_GRANT = 'NO_EXPLICIT_GRANT';
    public const DENY_GRANT_REVOKED = 'GRANT_REVOKED';
    public const DENY_CAPABILITY = 'CAPABILITY_NOT_GRANTED';
    public const DENY_MACHINE = 'MACHINE_PRINCIPAL';
    public const DENY_SHARED_TOKEN = 'SHARED_TOKEN';
    public const DENY_MFA = 'BLOCKED_MFA_ENROLLMENT_REQUIRED';

    /**
     * May this request perform this capability?
     *
     * @return array{allowed:bool,reason:?string,detail:string,actor:?array,mfa:array}
     */
    public function check(?Request $request, string $capability): array
    {
        $user = $request?->user();

        if ($user === null) {
            return $this->deny(self::DENY_NO_SESSION, 'no authenticated human session');
        }

        // A machine credential has no name to put on an approval. This is
        // checked here as well as by DenyApiKeyAuth on the routes: the two are
        // deliberately redundant, because one of them will eventually be
        // omitted from a new route.
        $via = $request->attributes->get('auth_via');
        if ($via === 'api_key') {
            return $this->deny(self::DENY_MACHINE, 'API keys represent a machine, not a person');
        }

        if ($request->attributes->get('auth_via_claim') === 'shared_admin_token') {
            return $this->deny(self::DENY_SHARED_TOKEN,
                'the shared admin token attributes every action to user 1 regardless of who used it');
        }

        if ((int) ($user->id ?? 0) !== self::CANONICAL_USER_ID) {
            return $this->deny(self::DENY_NOT_CANONICAL_USER,
                'Engineer888 is private to one account in V1');
        }

        // ID and email are both checked. Either alone is a single point of
        // failure: an email can be reassigned, and an ID can be reused.
        if (! hash_equals(self::CANONICAL_EMAIL, strtolower(trim((string) ($user->email ?? ''))))) {
            return $this->deny(self::DENY_EMAIL_MISMATCH,
                'the canonical user ID does not carry the canonical email');
        }

        $status = strtolower((string) ($user->status ?? 'active'));
        if ($status !== 'active') {
            return $this->deny(self::DENY_INACTIVE, "the account is {$status}");
        }

        if (! ($user->is_platform_admin ?? false)) {
            return $this->deny(self::DENY_NOT_PLATFORM_ADMIN, 'the account is not a platform admin');
        }

        $grant = $this->grantFor((int) $user->id);

        if ($grant === null) {
            return $this->deny(self::DENY_NO_GRANT,
                'no Engineer888 access grant exists for this account. Platform-admin status alone '
                . 'never confers Engineer888 access.');
        }

        if ($grant->revoked_at !== null) {
            return $this->deny(self::DENY_GRANT_REVOKED, 'the grant was revoked at ' . $grant->revoked_at);
        }

        $capabilities = json_decode((string) $grant->capabilities, true) ?: [];

        if (! in_array($capability, $capabilities, true)) {
            return $this->deny(self::DENY_CAPABILITY, "the grant does not include {$capability}");
        }

        $mfa = $this->mfaState($user, $capability);

        // MFA is REPORTED, not faked. If policy required it and enrolment were
        // absent, this would deny with BLOCKED_MFA_ENROLLMENT_REQUIRED rather
        // than quietly pass — and it would never enrol on anybody's behalf.
        if ($mfa['required'] && ! $mfa['satisfied']) {
            return $this->deny(self::DENY_MFA, $mfa['detail'], $mfa);
        }

        return [
            'allowed' => true,
            'reason'  => null,
            'detail'  => 'canonical account, active, granted ' . $capability,
            'actor'   => [
                'user_id' => (int) $user->id,
                'email'   => (string) $user->email,
                'name'    => (string) ($user->name ?? ''),
                'via'     => (string) ($via ?? 'jwt'),
            ],
            'mfa'     => $mfa,
        ];
    }

    public function allows(?Request $request, string $capability): bool
    {
        return $this->check($request, $capability)['allowed'];
    }

    /** Convenience readers, one per question the surfaces ask. */
    public function mayDiscover(?Request $r): bool { return $this->allows($r, Engineer888Capability::DISCOVER); }
    public function mayView(?Request $r): bool { return $this->allows($r, Engineer888Capability::VIEW); }
    public function mayMessage(?Request $r): bool { return $this->allows($r, Engineer888Capability::MESSAGE); }
    public function mayCreateTask(?Request $r): bool { return $this->allows($r, Engineer888Capability::CREATE_TASK); }
    public function mayReviewCandidate(?Request $r): bool { return $this->allows($r, Engineer888Capability::REVIEW_CANDIDATE); }
    public function mayApproveCandidate(?Request $r): bool { return $this->allows($r, Engineer888Capability::APPROVE_CANDIDATE); }
    public function mayExecute(?Request $r): bool { return $this->allows($r, Engineer888Capability::EXECUTE); }
    public function mayApproveRecovery(?Request $r): bool { return $this->allows($r, Engineer888Capability::APPROVE_RECOVERY); }
    public function mayApproveMigration(?Request $r): bool { return $this->allows($r, Engineer888Capability::APPROVE_MIGRATION); }

    /**
     * May this actor change who has access?
     *
     * Always false. The capability exists so the question is answerable and
     * auditable; V1 has no delegation and no self-service, and the grant table
     * is written by migration under a human's hand.
     */
    public function mayManageAccess(?Request $r): bool
    {
        return false;
    }

    private function grantFor(int $userId): ?object
    {
        return DB::table('engineering_access_grants')
            ->where('user_id', $userId)
            ->where('policy_version', self::POLICY_VERSION)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What MFA actually protects right now, stated plainly.
     *
     * The platform records that `mfa.stepup` does not protect surfaces and that
     * no admin has enrolled. Claiming MFA guards these mutations would be false,
     * so the state is surfaced instead and the hook is left in place: the moment
     * enrolment exists, `required` becomes true and the deny path is live
     * without another deployment.
     *
     * @return array<string,mixed>
     */
    public function mfaState(object $user, string $capability): array
    {
        $enrolled = (bool) ($user->mfa_enabled ?? $user->two_factor_confirmed_at ?? false);
        $highRisk = Engineer888Capability::isHighRisk($capability);
        $policyRequires = (bool) config('engineer888_access.require_mfa', false);

        return [
            'high_risk'  => $highRisk,
            'enrolled'   => $enrolled,
            'required'   => $highRisk && $policyRequires,
            'satisfied'  => $enrolled,
            'gap'        => $highRisk && ! $enrolled,
            'detail'     => $highRisk && ! $enrolled
                ? 'this is a high-risk action and the canonical account has no MFA enrolled. Exact '
                . 'fingerprint approval still applies; MFA does not currently add a second factor here.'
                : 'no additional factor required for this capability',
        ];
    }

    /** @return array<int,string> */
    public static function capabilitiesForCanonicalAdmin(): array
    {
        // Everything except manage_access. Nobody in V1 may change who has
        // access from inside the application.
        return array_values(array_diff(
            Engineer888Capability::ALL, [Engineer888Capability::MANAGE_ACCESS]
        ));
    }

    /** @return array<string,mixed> */
    private function deny(string $reason, string $detail, array $mfa = []): array
    {
        return ['allowed' => false, 'reason' => $reason, 'detail' => $detail, 'actor' => null,
                'mfa' => $mfa ?: ['high_risk' => false, 'enrolled' => false, 'required' => false,
                                  'satisfied' => false, 'gap' => false, 'detail' => 'not evaluated']];
    }
}
