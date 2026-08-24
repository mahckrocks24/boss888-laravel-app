<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Engines\Infrastructure\Email\States\EmailAliasState;
use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailForwarderState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\States\StateMachine;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * INFRA888 · E1-E — LIFECYCLE PROOF.
 *
 * Every valid transition is asserted, and so is every INVALID one: the tests
 * walk the full cartesian product of states and require the machine's answer to
 * match its declared map exactly. A machine tested only on its happy paths says
 * nothing about what it permits by accident, and "what it permits by accident"
 * is the whole risk — an unintended edge from `provisioning` back to
 * `provisioning` is how a mailbox gets created twice.
 */
class BusinessEmailLifecycleTest extends TestCase
{
    /** @return array<string,class-string<StateMachine>> */
    private function machines(): array
    {
        return [
            'domain'       => EmailDomainState::class,
            'verification' => EmailVerificationState::class,
            'mailbox'      => EmailMailboxState::class,
            'alias'        => EmailAliasState::class,
            'forwarder'    => EmailForwarderState::class,
            'catchall'     => EmailCatchAllState::class,
        ];
    }

    // ── structural integrity ─────────────────────────────────────────────────

    public function test_every_machine_declares_a_complete_and_consistent_map(): void
    {
        foreach ($this->machines() as $name => $machine) {
            $states = $machine::states();

            $this->assertNotEmpty($states, "{$name}: declares no states");
            $this->assertSame(array_unique($states), $states, "{$name}: duplicate state declared");

            $this->assertContains($machine::initial(), $states, "{$name}: initial state is not a declared state");

            foreach ($machine::terminal() as $terminal) {
                $this->assertContains($terminal, $states, "{$name}: terminal '{$terminal}' is not a declared state");
            }

            $transitions = $machine::transitions();

            // Every state must appear as a key, or the map is silently
            // incomplete and canTransition() answers false for reasons nobody
            // decided.
            foreach ($states as $state) {
                $this->assertArrayHasKey($state, $transitions, "{$name}: '{$state}' has no transition entry");
            }

            foreach ($transitions as $from => $targets) {
                $this->assertContains($from, $states, "{$name}: transition source '{$from}' is not a declared state");

                foreach ($targets as $to) {
                    $this->assertContains($to, $states, "{$name}: '{$from}' targets undeclared state '{$to}'");
                }
            }
        }
    }

    public function test_terminal_states_permit_nothing(): void
    {
        foreach ($this->machines() as $name => $machine) {
            foreach ($machine::terminal() as $terminal) {
                $this->assertSame(
                    [],
                    $machine::transitions()[$terminal],
                    "{$name}: terminal '{$terminal}' declares outgoing transitions"
                );

                foreach ($machine::states() as $to) {
                    $this->assertFalse(
                        $machine::canTransition($terminal, $to),
                        "{$name}: terminal '{$terminal}' permitted a move to '{$to}'"
                    );
                }
            }
        }
    }

    public function test_every_non_terminal_state_is_reachable_from_the_initial_state(): void
    {
        foreach ($this->machines() as $name => $machine) {
            $reached = [$machine::initial() => true];
            $frontier = [$machine::initial()];

            while ($frontier !== []) {
                $current = array_pop($frontier);

                foreach ($machine::transitions()[$current] ?? [] as $next) {
                    if (! isset($reached[$next])) {
                        $reached[$next] = true;
                        $frontier[] = $next;
                    }
                }
            }

            foreach ($machine::states() as $state) {
                $this->assertArrayHasKey(
                    $state,
                    $reached,
                    "{$name}: '{$state}' is declared but unreachable — dead states hide intent."
                );
            }
        }
    }

    // ── exhaustive validity ──────────────────────────────────────────────────

    public function test_every_state_pair_is_answered_exactly_as_declared(): void
    {
        $checked = 0;

        foreach ($this->machines() as $name => $machine) {
            $states = $machine::states();
            $transitions = $machine::transitions();
            $terminal = $machine::terminal();

            foreach ($states as $from) {
                foreach ($states as $to) {
                    $expected = ! in_array($from, $terminal, true)
                        && in_array($to, $transitions[$from] ?? [], true);

                    $this->assertSame(
                        $expected,
                        $machine::canTransition($from, $to),
                        "{$name}: '{$from}' -> '{$to}' disagrees with the declared map"
                    );

                    $checked++;
                }
            }
        }

        // Guards against the map being trivially small.
        $this->assertGreaterThan(250, $checked, 'Too few state pairs examined for this to be exhaustive.');
    }

    public function test_an_illegal_transition_throws_rather_than_being_ignored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // A connected domain has not been verified and cannot be live.
        EmailDomainState::assertTransition(EmailDomainState::CONNECTED, EmailDomainState::ACTIVE);
    }

    public function test_an_unknown_state_is_rejected_rather_than_treated_as_new(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailDomainState::assertTransition(EmailDomainState::CONNECTED, 'probably_fine');
    }

    public function test_moving_out_of_a_terminal_state_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailMailboxState::assertTransition(EmailMailboxState::DELETED, EmailMailboxState::ACTIVE);
    }

    // ── the rules that make this engine safe ─────────────────────────────────

    public function test_a_domain_cannot_go_live_without_passing_through_verification(): void
    {
        $this->assertFalse(EmailDomainState::canTransition(EmailDomainState::CONNECTED, EmailDomainState::ACTIVE));
        $this->assertFalse(EmailDomainState::canTransition(EmailDomainState::CONNECTED, EmailDomainState::PROVISIONING));
        $this->assertFalse(EmailDomainState::canTransition(EmailDomainState::VERIFYING_DNS, EmailDomainState::ACTIVE));

        // The only route: verify, then provision.
        $this->assertTrue(EmailDomainState::canTransition(EmailDomainState::CONNECTED, EmailDomainState::VERIFYING_DNS));
        $this->assertTrue(EmailDomainState::canTransition(EmailDomainState::VERIFYING_DNS, EmailDomainState::DNS_VERIFIED));
        $this->assertTrue(EmailDomainState::canTransition(EmailDomainState::DNS_VERIFIED, EmailDomainState::PROVISIONING));
        $this->assertTrue(EmailDomainState::canTransition(EmailDomainState::PROVISIONING, EmailDomainState::ACTIVE));
    }

    public function test_a_failed_verification_cannot_shortcut_back_to_verified(): void
    {
        $this->assertFalse(EmailVerificationState::canTransition(
            EmailVerificationState::FAILED,
            EmailVerificationState::VERIFIED
        ));

        // Only a fresh observation can conclude anything.
        $this->assertTrue(EmailVerificationState::canTransition(
            EmailVerificationState::FAILED,
            EmailVerificationState::CHECKING
        ));
        $this->assertTrue(EmailVerificationState::canTransition(
            EmailVerificationState::CHECKING,
            EmailVerificationState::VERIFIED
        ));
    }

    public function test_issuing_dns_records_is_not_evidence_that_they_exist(): void
    {
        // accepted != verified. There is no edge from "we told the customer
        // what to add" to "we know they added it".
        $this->assertFalse(EmailVerificationState::canTransition(
            EmailVerificationState::PENDING_RECORDS,
            EmailVerificationState::VERIFIED
        ));
    }

    public function test_reconciliation_never_re_issues_the_mutation(): void
    {
        // The S5 rule. An ambiguous outcome is resolved by reading provider
        // truth, never by retrying the create — that is how a mailbox gets
        // created twice.
        foreach ([
            [EmailDomainState::class,   EmailDomainState::RECONCILING,   EmailDomainState::PROVISIONING],
            [EmailMailboxState::class,  EmailMailboxState::RECONCILING,  EmailMailboxState::PROVISIONING],
            [EmailAliasState::class,    EmailAliasState::RECONCILING,    EmailAliasState::PROVISIONING],
            [EmailForwarderState::class, EmailForwarderState::RECONCILING, EmailForwarderState::PROVISIONING],
            [EmailCatchAllState::class, EmailCatchAllState::RECONCILING, EmailCatchAllState::CONFIGURING],
        ] as [$machine, $from, $to]) {
            $this->assertFalse(
                $machine::canTransition($from, $to),
                "{$machine}: reconciliation must not return to the mutating state."
            );
        }
    }

    public function test_every_mutating_state_can_reach_reconciliation(): void
    {
        // If a mutating state had no path to reconciliation, an ambiguous
        // provider answer would have nowhere honest to go.
        $this->assertTrue(EmailDomainState::canTransition(EmailDomainState::PROVISIONING, EmailDomainState::RECONCILING));
        $this->assertTrue(EmailMailboxState::canTransition(EmailMailboxState::PROVISIONING, EmailMailboxState::RECONCILING));
        $this->assertTrue(EmailMailboxState::canTransition(EmailMailboxState::DELETING, EmailMailboxState::RECONCILING));
        $this->assertTrue(EmailAliasState::canTransition(EmailAliasState::PROVISIONING, EmailAliasState::RECONCILING));
        $this->assertTrue(EmailForwarderState::canTransition(EmailForwarderState::REMOVING, EmailForwarderState::RECONCILING));
        $this->assertTrue(EmailCatchAllState::canTransition(EmailCatchAllState::CONFIGURING, EmailCatchAllState::RECONCILING));
    }

    public function test_no_route_reaches_deleted_without_passing_through_deleting(): void
    {
        foreach (EmailMailboxState::states() as $from) {
            if ($from === EmailMailboxState::DELETING) {
                continue;
            }

            $this->assertFalse(
                EmailMailboxState::canTransition($from, EmailMailboxState::DELETED),
                "'{$from}' reaches DELETED directly, bypassing the governed deleting stage."
            );
        }

        $this->assertTrue(EmailMailboxState::canTransition(EmailMailboxState::DELETING, EmailMailboxState::DELETED));
    }

    public function test_destructive_transitions_are_declared_as_requiring_governance(): void
    {
        $this->assertTrue(EmailMailboxState::requiresGovernance(
            EmailMailboxState::DELETING,
            EmailMailboxState::DELETED
        ));
        $this->assertTrue(EmailMailboxState::requiresGovernance(
            EmailMailboxState::ACTIVE,
            EmailMailboxState::DELETING
        ));
        $this->assertTrue(EmailDomainState::requiresGovernance(
            EmailDomainState::ACTIVE,
            EmailDomainState::TERMINATED
        ));

        // Every declared governed transition must be a legal transition; a
        // governance rule on an impossible edge protects nothing.
        foreach (EmailDomainState::governedTransitions() as $edge) {
            [$from, $to] = explode('->', $edge);
            $this->assertTrue(
                EmailDomainState::canTransition($from, $to),
                "Domain governance declared for an impossible edge: {$edge}"
            );
        }

        foreach (EmailMailboxState::governedTransitions() as $edge) {
            [$from, $to] = explode('->', $edge);
            $this->assertTrue(
                EmailMailboxState::canTransition($from, $to),
                "Mailbox governance declared for an impossible edge: {$edge}"
            );
        }
    }

    public function test_success_transitions_are_declared_as_requiring_evidence(): void
    {
        $this->assertTrue(EmailDomainState::requiresEvidence(
            EmailDomainState::VERIFYING_DNS,
            EmailDomainState::DNS_VERIFIED
        ));
        $this->assertTrue(EmailDomainState::requiresEvidence(
            EmailDomainState::PROVISIONING,
            EmailDomainState::ACTIVE
        ));
        $this->assertTrue(EmailMailboxState::requiresEvidence(
            EmailMailboxState::PROVISIONING,
            EmailMailboxState::ACTIVE
        ));

        // Every edge INTO an operational state must require evidence. A state
        // that means "this is working" may never be entered on a claim.
        foreach ($this->machines() as $name => $machine) {
            if (! method_exists($machine, 'evidenceRequired')) {
                continue;
            }

            foreach ($machine::transitions() as $from => $targets) {
                foreach ($targets as $to) {
                    if (! in_array($to, $machine::operational(), true)) {
                        continue;
                    }

                    $this->assertTrue(
                        $machine::requiresEvidence($from, $to),
                        "{$name}: '{$from}' -> '{$to}' enters an operational state without requiring evidence."
                    );
                }
            }
        }
    }

    public function test_states_needing_a_human_are_declared_so_they_can_be_given_a_screen(): void
    {
        // S3's NEEDS_MANUAL lesson: a state requiring a human decision with no
        // operator surface is a dead end. Declaring them is what makes the E3
        // admin screen requirement checkable.
        foreach ([EmailDomainState::class, EmailMailboxState::class, EmailAliasState::class,
                  EmailForwarderState::class, EmailCatchAllState::class] as $machine) {
            $needsHuman = $machine::needsHuman();

            $this->assertNotEmpty($needsHuman, "{$machine} declares no states needing a human decision.");

            foreach ($needsHuman as $state) {
                $this->assertContains($state, $machine::states(), "{$machine}: '{$state}' is not a declared state");
                $this->assertNotContains(
                    $state,
                    $machine::terminal(),
                    "{$machine}: '{$state}' needs a human but is terminal — there is nothing they could do."
                );
            }
        }
    }

    public function test_verification_has_no_terminal_state_because_dns_keeps_changing(): void
    {
        $this->assertSame([], EmailVerificationState::terminal());
        $this->assertTrue(EmailVerificationState::canTransition(
            EmailVerificationState::VERIFIED,
            EmailVerificationState::DRIFTED
        ));
    }

    public function test_a_catchall_is_off_by_default_and_can_always_be_switched_back(): void
    {
        $this->assertSame(EmailCatchAllState::DISABLED, EmailCatchAllState::initial());
        $this->assertSame([], EmailCatchAllState::terminal());
        $this->assertTrue(EmailCatchAllState::canTransition(
            EmailCatchAllState::ENABLED,
            EmailCatchAllState::DISABLING
        ));
        $this->assertTrue(EmailCatchAllState::canTransition(
            EmailCatchAllState::DISABLING,
            EmailCatchAllState::DISABLED
        ));
    }

    public function test_only_active_is_operational_everywhere(): void
    {
        foreach ([EmailDomainState::class, EmailMailboxState::class,
                  EmailAliasState::class, EmailForwarderState::class] as $machine) {
            $this->assertSame(
                ['active'],
                $machine::operational(),
                "{$machine}: exactly one state may mean 'this is working'."
            );
        }
    }

    public function test_a_suspended_mailbox_is_still_billable_but_not_operational(): void
    {
        $this->assertContains(EmailMailboxState::SUSPENDED, EmailMailboxState::billable());
        $this->assertNotContains(EmailMailboxState::SUSPENDED, EmailMailboxState::operational());
        $this->assertNotContains(EmailMailboxState::REQUESTED, EmailMailboxState::billable());
        $this->assertNotContains(EmailMailboxState::DELETED, EmailMailboxState::billable());
    }
}
