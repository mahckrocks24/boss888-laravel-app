<?php

namespace App\Engines\Infrastructure\Email\States;

/**
 * INFRA888 · E1 — ALIAS LIFECYCLE.
 *
 * An alias delivers mail addressed to one local part into a mailbox (or a
 * single address) we already know about. Its lifecycle is the shared routing
 * rule machine; see EmailRoutingRuleState for why that is shared rather than
 * duplicated.
 *
 * The type exists separately so audit records, capability routing and
 * governance metadata name the right entity: "alias removed" and "forwarder
 * removed" are different operator events even though the transition is the same.
 */
final class EmailAliasState extends EmailRoutingRuleState
{
}
