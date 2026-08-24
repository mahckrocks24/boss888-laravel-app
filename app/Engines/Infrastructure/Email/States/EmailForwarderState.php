<?php

namespace App\Engines\Infrastructure\Email\States;

/**
 * INFRA888 · E1 — FORWARDER LIFECYCLE.
 *
 * A forwarder relays mail to an address outside the domain, and usually outside
 * our control entirely. Its lifecycle is the shared routing rule machine.
 *
 * WHAT IS DELIBERATELY NOT IN THIS MACHINE
 * Loop safety. A forwarder pointing back into its own domain, or into a chain
 * that returns to it, is a routing DEFECT, not a lifecycle stage — a forwarder
 * can be `active` and dangerous at the same time, and can become dangerous long
 * after creation when someone else adds the second half of the loop. Modelling
 * it as a state would force the engine to choose between reporting the loop and
 * reporting whether the rule exists.
 *
 * It therefore lives on EmailForwarder as its own assessed dimension, evaluated
 * by ForwarderLoopSafety. See that class for the rules.
 */
final class EmailForwarderState extends EmailRoutingRuleState
{
}
