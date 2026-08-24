<?php

namespace App\Core\Email888\Http;

use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\WebhookIngressRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EMAIL888 EM-6 — the receipt for attempts that never reached the real handler.
 *
 * THE GAP THIS CLOSES, MEASURED BEFORE IT WAS BUILT
 * The live endpoint constrains its secret to `[A-Za-z0-9_-]{24,128}`. A probe
 * that does not match that shape is refused by the ROUTER, so the controller —
 * where every receipt was written — never runs. Proven against staging:
 *
 *     malformed secret (too short)      404   no durable trace
 *     malformed secret (illegal chars)  404   no durable trace
 *     well-formed wrong secret          404   receipt: refused_bad_secret
 *
 * The first two are the shape a scanner actually uses. EM-6 exists to answer
 * "is someone probing this endpoint?", and those were exactly the requests it
 * could not see. The strict constraint is still worth having — it keeps
 * nonsense out of the authentication path — so the fix is a sibling route that
 * catches what the strict one rejects, rather than a looser strict route.
 *
 * IT MUST NOT BECOME AN ORACLE
 * The response is byte-identical to the real controller's refusal: same status,
 * same body, no timing work, nothing that would let a prober tell "wrong shape"
 * from "wrong secret" and so learn the secret's length or alphabet.
 */
class WebhookIngressFallbackController
{
    public function __construct(private readonly WebhookIngressRecorder $recorder)
    {
    }

    public function __invoke(Request $request, string $provider = ''): JsonResponse
    {
        // Say what is actually true. This route catches two different mistakes,
        // and calling a wrong HTTP method a "malformed secret" would put a false
        // sentence in front of an operator at exactly the wrong moment. The
        // secret is never compared here, so nothing below can imply it was
        // right or wrong.
        $reason = $request->isMethod('POST')
            ? 'Rejected at the router: the URL did not carry a secret of the expected shape. A provider always does — this is almost certainly a scanner.'
            : sprintf('Rejected at the router: the webhook accepts POST only, and this was a %s. Providers only ever POST.', strtoupper($request->method()));

        $this->recorder->refuse(
            request:        $request,
            reason:         WebhookReceipt::AUTH_MALFORMED_PATH,
            httpStatus:     404,
            operatorReason: $reason,
            // The pattern, not the URI. The URI is what we are refusing to trust.
            endpoint:       '/api/webhooks/email/{provider}/{unmatched}',
            provider:       $provider !== '' ? $provider : null,
        );

        return response()->json(['error' => 'not_found'], 404);
    }
}
