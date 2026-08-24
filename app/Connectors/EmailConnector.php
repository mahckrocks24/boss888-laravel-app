<?php

namespace App\Connectors;

/**
 * EmailConnector — RETIRED AS AN OUTBOUND PATH (EM-7).
 *
 * WHAT THIS USED TO BE
 * A second, complete outbound email architecture. It built Postmark payloads by
 * hand, held the server token, posted to api.postmarkapp.com directly, and fell
 * back to SMTP. Marketing, the email builder and the campaign job all sent
 * through it.
 *
 * WHY IT IS GONE
 * It was not merely duplication. It was actively wrong in three ways that could
 * not be seen from the outside:
 *
 *   1. It never set a Postmark MessageStream, so every campaign inherited the
 *      server default — `outbound`, the TRANSACTIONAL stream that also carries
 *      password resets. Bulk mail was accumulating complaint risk against the
 *      reputation account recovery depends on.
 *   2. It sent From: noreply@levelupgrowth.io — an address absent from the
 *      sender registry, unmonitored, never verified. Bounces to it were read by
 *      nobody.
 *   3. It wrote no ledger row, so a campaign was invisible to the delivery
 *      screen, to webhook diagnosis, and to reconciliation. There was no way to
 *      answer "did that campaign arrive?" at all.
 *
 * None of those are configuration mistakes. They are what happens when a second
 * transport exists beside the governed one: the policy layer simply does not
 * apply to it.
 *
 * WHAT REPLACES IT
 * `App\Core\Email888\EmailDispatcher`, driven by a `SendEmailCommand` carrying a
 * PURPOSE. The registries decide sender identity and message stream; the ledger
 * records every outcome; the webhook supplies terminal delivery evidence.
 *
 * WHY THE CLASS SURVIVES AT ALL
 * `ConnectorResolver` still maps `email`, and `healthCheckAll()` walks every
 * connector for the system-health matrix. Deleting the class would have taken
 * that surface down for an unrelated reason. What remains carries no credential,
 * makes no provider call, and supports no send action — so it cannot quietly
 * regrow into a second outbound system.
 */
class EmailConnector extends BaseConnector
{
    /**
     * Deliberately empty. An empty action list is what makes regrowth loud: any
     * attempt to send through this connector fails validation immediately rather
     * than silently taking a second, ungoverned route to the provider.
     */
    public function supportedActions(): array
    {
        return [];
    }

    public function validationRules(string $action): array
    {
        return [];
    }

    /**
     * Refuses everything, by design, and says where to go instead.
     *
     * The two actions this used to support were `send_email` and `send_campaign`.
     * Both now belong to Email888, which applies the sender registry, the stream
     * registry, idempotency and the delivery ledger — none of which this class
     * ever did.
     */
    public function execute(string $action, array $params): array
    {
        return $this->failure(
            "EmailConnector no longer sends email. Outbound mail goes through "
            . "App\\Core\\Email888\\EmailDispatcher with a SendEmailCommand declaring a purpose. "
            . "Attempted action: {$action}"
        );
    }

    /**
     * Reports whether the CANONICAL outbound path is configured.
     *
     * This used to be a live GET to api.postmarkapp.com carrying the server
     * token. It no longer touches the provider or reads a credential: a health
     * probe is not worth keeping a second copy of the provider integration alive
     * for, and the provider's own reachability is already covered by Email888's
     * delivery ledger and webhook health, which observe real traffic rather than
     * a synthetic ping.
     */
    public function healthCheck(): bool
    {
        return (string) config('mail.default') !== ''
            && is_array(config('email888.purposes'))
            && config('email888.purposes') !== [];
    }
}
