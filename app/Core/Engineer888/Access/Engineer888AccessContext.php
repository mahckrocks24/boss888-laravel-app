<?php

namespace App\Core\Engineer888\Access;

use Illuminate\Http\Request;

/**
 * Who is asking, and how they proved it.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────
 *
 * Engineer888Access asks two questions that only an HTTP request could answer:
 * `auth_via` and `auth_via_claim`, both read from Request::attributes. Those
 * attributes are set by middleware — AdminSessionIdentity sets `jwt_cookie`,
 * JwtAuthMiddleware sets `jwt` or `api_key`, ApiKeyAuth sets `api_key` — so any
 * caller outside the HTTP pipeline arrived with no provenance at all.
 *
 * Measured 2026-08-13: a Request constructed in-process, resolving the real
 * canonical user (id 1, canonical email, active, platform admin, grant present),
 * was refused MACHINE_PRINCIPAL. Not because of who it was. Because it could not
 * say HOW it had authenticated, and an unclassified credential fails closed.
 *
 * THAT REFUSAL IS CORRECT AND IS NOT BEING CHANGED. The allowlist exists
 * precisely so the authentication path nobody has classified yet is denied
 * rather than admitted. What was wrong was only that provenance lived in a
 * transport container, so the policy could not be evaluated anywhere else.
 * The rule was right; the plumbing assumed HTTP.
 *
 * This object is that plumbing, made explicit. It holds the three things the
 * policy actually reads and nothing more — no IP, no route, no headers, no
 * session — because a context that carries fields the policy ignores invites a
 * future caller to believe those fields matter.
 *
 * ── WHAT IT DOES NOT DO ──────────────────────────────────────────────
 *
 * It confers nothing. There is no "system" context, no trusted-caller flag and
 * no constructor that means "this is Mark because I said so". A server-side
 * caller acting for a human must state the same credential provenance a browser
 * would have carried, and that value is checked against the same allowlist by
 * the same code. Constructing a context is a claim, not a permission — every
 * condition in Engineer888Access::checkContext() still has to hold.
 */
final class Engineer888AccessContext
{
    /**
     * @param object|null $user       the resolved principal, or null for no session
     * @param string|null $authVia    credential type: 'jwt', 'jwt_cookie', 'api_key', …
     * @param string|null $authViaClaim the token's own `via` claim, when it carries one
     */
    public function __construct(
        public readonly ?object $user,
        public readonly ?string $authVia,
        public readonly ?string $authViaClaim = null,
    ) {}

    /**
     * The HTTP path. Reads exactly what the policy used to read itself, so the
     * decision for any real request is unchanged.
     */
    public static function fromRequest(?Request $request): self
    {
        if ($request === null) {
            return new self(null, null, null);
        }

        $via = $request->attributes->get('auth_via');
        $claim = $request->attributes->get('auth_via_claim');

        return new self(
            $request->user(),
            $via === null ? null : (string) $via,
            $claim === null ? null : (string) $claim,
        );
    }

    /**
     * A trusted server-side caller acting on behalf of a signed-in human.
     *
     * The provenance is a REQUIRED argument with no default. There is no
     * overload that omits it, because the omission is exactly the mistake this
     * class was written after: a caller that forgets to say how the human
     * authenticated must not silently inherit "human".
     *
     * Passing 'api_key' here, or a value nobody has classified, produces a
     * context that Engineer888Access will refuse — which is the point. This
     * constructor cannot manufacture access; it can only carry a claim that the
     * policy then judges.
     */
    public static function forHuman(?object $user, string $authVia, ?string $authViaClaim = null): self
    {
        return new self($user, $authVia, $authViaClaim);
    }

    /** Nothing authenticated. */
    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }
}
