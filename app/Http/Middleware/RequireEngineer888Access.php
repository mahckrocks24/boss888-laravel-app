<?php

namespace App\Http\Middleware;

use App\Core\Engineer888\Access\AccessAudit;
use App\Core\Engineer888\Access\Engineer888Access;
use Closure;
use Illuminate\Http\Request;

/**
 * The only door into Engineer888.
 *
 * It contains no policy. It asks Engineer888Access one question, records the
 * answer, and either continues or behaves as though the route does not exist.
 * Everything about WHO may do WHAT lives in the policy; putting any of it here
 * would create the second source of truth this sprint exists to eliminate.
 *
 * 404, NOT 403. A 403 confirms the feature exists, which is itself a
 * disclosure — an unauthorised admin learns there is an internal engineering
 * system worth attacking. The response is indistinguishable from a wrong URL,
 * and it never says which check failed. The reason is recorded in the audit,
 * where the people entitled to see it can.
 */
final class RequireEngineer888Access
{
    public function handle(Request $request, Closure $next, string $capability)
    {
        $decision = (new Engineer888Access())->check($request, $capability);

        // Recorded before the response is produced, so a denial cannot be lost
        // by whatever happens next.
        AccessAudit::record($request, $capability, $decision);

        if (! $decision['allowed']) {
            return $this->absent();
        }

        // Downstream controllers re-ask the policy rather than trusting these.
        // They exist so an action can attribute itself without a second lookup,
        // not so it can skip one.
        $request->attributes->set('e888_actor', $decision['actor']);
        $request->attributes->set('e888_capability', $capability);
        $request->attributes->set('e888_mfa', $decision['mfa']);

        return $next($request);
    }

    /**
     * The response for everybody who is not the canonical account.
     *
     * Identical whatever failed: not authenticated, wrong user, wrong email,
     * revoked grant, missing capability, machine credential. Any variation
     * between them is an oracle.
     */
    private function absent()
    {
        return response()->json(['message' => 'Not Found'], (int) config('engineer888_access.deny_status', 404));
    }
}
