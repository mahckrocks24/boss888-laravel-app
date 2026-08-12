<?php

namespace App\Http\Middleware;

use App\Core\Engineer888\Access\AccessAudit;
use App\Core\Engineer888\Access\Engineer888Access;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            return $this->absent($request);
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
    private function absent(Request $request)
    {
        $status = (int) config('engineer888_access.deny_status', 404);

        if ($status !== 404) {
            return response()->json(['message' => 'Not Found'], $status);
        }

        // INDISTINGUISHABLE, NOT MERELY REFUSED (2026-08-12).
        //
        // This used to return its own {"message":"Not Found"} body. The status
        // matched a real 404, so the HTML path was byte-identical — but the
        // JSON path was not: Laravel's own miss says "The route X could not be
        // found.", and a hand-rolled body that says something else is an
        // oracle. An admin who may not use Engineer888 could sweep
        // /api/admin/engineer888/* and read off which routes exist from the
        // shape of the refusal, which is the disclosure the 404 convention
        // exists to prevent.
        //
        // Rendering the exception Laravel's own router throws, through the
        // handler that renders it, means the body, the headers and the status
        // for "you may not" and for "there is nothing here" are produced by one
        // code path and cannot drift apart. The message is built from the
        // request path exactly as RouteCollection builds it.
        //
        // Rendered rather than thrown: this middleware returns a response, and
        // several callers hold it to that. Throwing would have moved the
        // decision into the exception handler and changed a contract this
        // sprint has no reason to change — the identical bytes are the point,
        // not the mechanism.
        return app(ExceptionHandler::class)->render(
            $request,
            new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()))
        );
    }
}
