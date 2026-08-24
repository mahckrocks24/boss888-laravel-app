<?php

namespace App\Engines\Infrastructure\Email\Reconciliation;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailRoutingRuleState;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\Observation\Custody;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · E2 — PROVIDER-NEUTRAL RECONCILIATION.
 *
 * Compares what INFRA888 intends against what the provider actually has, and
 * records the differences as facts.
 *
 * ─── THE THREE RULES THAT MAKE THIS TRUSTWORTHY ─────────────────────────────
 *
 * 1. IT NEVER MUTATES DESIRED STATE. Not a lifecycle, not a quota, not a
 *    binding. It writes observations and returns findings. A reconciler that
 *    repairs is one whose reports you cannot trust, because it has already
 *    changed the thing it is describing — and because "repair" against a
 *    partially-read provider means deleting customer mailboxes.
 *
 * 2. IT REFUSES TO CONCLUDE FROM AN INCOMPLETE READ. Absence-based findings —
 *    missing, unexpected — are only valid against a complete enumeration.
 *    Against a truncated one, every unlisted mailbox looks deleted. When the
 *    inventory says it is partial, this service reports what it saw and marks
 *    the pass inconclusive rather than degrading quietly.
 *
 * 3. NULL IS NOT A MISMATCH. A provider that does not report quota returns
 *    null, and comparing that against our number would generate drift about our
 *    own ignorance. Only two known values may disagree.
 *
 * ─── WHERE THE FACTS GO ─────────────────────────────────────────────────────
 *
 * `infra_observation_facts`, the same table the estate uses for DNS,
 * certificate, HTTP and registrar observations — with the same custody and
 * confidence semantics. Business Email health is therefore visible in the
 * existing estate model rather than in a second, weaker one of its own.
 *
 * It does NOT deliver notifications. Alert eligibility and delivery are S8.3
 * and S9, and duplicating them here would create a second escalation path.
 */
class EmailReconciliationService
{
    /** How old a usage sample may be before it stops being current truth. */
    public const USAGE_STALE_AFTER_HOURS = 48;

    public function __construct(
        private readonly BusinessEmailEngine $engine,
    ) {
    }

    /**
     * Compare one domain against its provider.
     *
     * @param bool $record whether to persist observation facts
     */
    public function reconcile(EmailDomain $domain, bool $record = true): ReconciliationReport
    {
        $connector = $this->engine->connector();

        if ($connector === null) {
            return ReconciliationReport::unreachable(
                (string) $domain->domain,
                'Business Email is not available on this account yet.'
            );
        }

        if (! $connector->supports(EmailProviderCapability::INVENTORY_LIST)) {
            // Not a failure: a fact about the provider. Nothing can be compared,
            // and pretending otherwise would produce findings from nothing.
            return new ReconciliationReport(
                (string) $domain->domain,
                [],
                false,
                'This service cannot list what exists, so nothing can be compared.'
            );
        }

        $callContext = new ProviderCallContext(
            workspaceId: (int) $domain->workspace_id,
            ownerType: BusinessEmailEngine::RESOURCE_DOMAIN,
            ownerId: (int) $domain->id,
            idempotencyKey: 'reconcile:' . $domain->id . ':' . $domain->domain,
        );

        $result = $connector->getInventory((string) $domain->domain, $callContext);

        if (! $result->success) {
            $report = ReconciliationReport::unreachable(
                (string) $domain->domain,
                (string) ($result->errorSummary ?: 'The service could not be reached.')
            );

            if ($record) {
                $this->recordObservation($domain, $report, false);
            }

            return $report;
        }

        /** @var ProviderInventory|null $inventory */
        $inventory = $result->data['inventory'] ?? null;

        if (! $inventory instanceof ProviderInventory) {
            $report = ReconciliationReport::unreachable(
                (string) $domain->domain,
                'The service returned an answer we could not read.'
            );

            if ($record) {
                $this->recordObservation($domain, $report, false);
            }

            return $report;
        }

        $report = $this->compare($domain, $inventory);

        if ($record) {
            $this->recordObservation($domain, $report, true);
        }

        return $report;
    }

    // ── comparison ───────────────────────────────────────────────────────────

    private function compare(EmailDomain $domain, ProviderInventory $inventory): ReconciliationReport
    {
        return WorkspaceContext::run((int) $domain->workspace_id, function () use ($domain, $inventory) {
            $findings = [];

            $mailboxes = EmailMailbox::query()->where('email_domain_id', $domain->id)->get();
            $aliases = EmailAlias::query()->where('email_domain_id', $domain->id)->get();
            $forwarders = EmailForwarder::query()->where('email_domain_id', $domain->id)->get();
            $catchAll = EmailCatchAll::query()->where('email_domain_id', $domain->id)->first();

            $observedMailboxes = $inventory->mailboxesByLocalPart();
            $observedAliases = $inventory->aliasesBySourceLocalPart();
            $observedForwarders = $inventory->forwardersByRoute();

            // ── mailboxes we intend ──────────────────────────────────────────
            foreach ($mailboxes as $mailbox) {
                if (! in_array($mailbox->lifecycle_state, EmailMailboxState::billable(), true)) {
                    // Only things we believe should EXIST are compared. A
                    // mailbox still provisioning is not yet a promise.
                    continue;
                }

                $local = strtolower((string) $mailbox->local_part);
                $observed = $observedMailboxes[$local] ?? null;

                if ($observed === null) {
                    if ($inventory->complete) {
                        $findings[] = new ReconciliationFinding(
                            ReconciliationFinding::MISSING_MAILBOX,
                            $local . '@' . $domain->domain,
                            'We hold an active mailbox record the service does not have.',
                            ['expected_local_part' => $local, 'lifecycle_state' => $mailbox->lifecycle_state]
                        );
                    }

                    continue;
                }

                // Null is not a mismatch — see rule 3.
                if ($observed->quotaMb !== null && (int) $observed->quotaMb !== (int) $mailbox->quota_mb) {
                    $findings[] = new ReconciliationFinding(
                        ReconciliationFinding::QUOTA_MISMATCH,
                        $local . '@' . $domain->domain,
                        'The storage limit at the service differs from ours.',
                        ['desired_mb' => (int) $mailbox->quota_mb, 'observed_mb' => (int) $observed->quotaMb]
                    );
                }

                if ($observed->suspended !== null) {
                    $desiredSuspended = $mailbox->lifecycle_state === EmailMailboxState::SUSPENDED;

                    if ($observed->suspended !== $desiredSuspended) {
                        $findings[] = new ReconciliationFinding(
                            ReconciliationFinding::SUSPENSION_MISMATCH,
                            $local . '@' . $domain->domain,
                            'The mailbox status at the service differs from ours.',
                            ['desired_suspended' => $desiredSuspended, 'observed_suspended' => $observed->suspended]
                        );
                    }
                }
            }

            // ── mailboxes we do not ──────────────────────────────────────────
            if ($inventory->complete) {
                $desiredLocals = $mailboxes
                    ->filter(fn (EmailMailbox $m) => in_array($m->lifecycle_state, EmailMailboxState::billable(), true))
                    ->map(fn (EmailMailbox $m) => strtolower((string) $m->local_part))
                    ->all();

                foreach ($observedMailboxes as $local => $observed) {
                    if (! in_array($local, $desiredLocals, true)) {
                        $findings[] = new ReconciliationFinding(
                            ReconciliationFinding::UNEXPECTED_MAILBOX,
                            $local . '@' . $domain->domain,
                            'The service has a mailbox we have no record of.',
                            ['observed_local_part' => $local]
                        );
                    }
                }
            }

            // ── aliases ──────────────────────────────────────────────────────
            $desiredAliasLocals = [];

            foreach ($aliases as $alias) {
                if ($alias->lifecycle_state !== EmailRoutingRuleState::ACTIVE) {
                    continue;
                }

                $local = strtolower((string) $alias->source_local_part);
                $desiredAliasLocals[] = $local;

                if (! isset($observedAliases[$local]) && $inventory->complete) {
                    $findings[] = new ReconciliationFinding(
                        ReconciliationFinding::MISSING_ALIAS,
                        $local . '@' . $domain->domain,
                        'We hold an active alias the service does not have.',
                        ['expected_local_part' => $local]
                    );
                }
            }

            if ($inventory->complete) {
                foreach ($observedAliases as $local => $observed) {
                    if (! in_array($local, $desiredAliasLocals, true)) {
                        $findings[] = new ReconciliationFinding(
                            ReconciliationFinding::UNEXPECTED_ALIAS,
                            $local . '@' . $domain->domain,
                            'The service has an alias we have no record of.',
                            ['observed_local_part' => $local, 'observed_target' => $observed->targetAddress]
                        );
                    }
                }
            }

            // ── forwarders ───────────────────────────────────────────────────
            $desiredRoutes = [];

            foreach ($forwarders as $forwarder) {
                if ($forwarder->lifecycle_state !== EmailRoutingRuleState::ACTIVE) {
                    continue;
                }

                $route = strtolower((string) $forwarder->source_local_part)
                    . '->' . strtolower((string) $forwarder->destination_address);
                $desiredRoutes[] = $route;

                if (! isset($observedForwarders[$route]) && $inventory->complete) {
                    $findings[] = new ReconciliationFinding(
                        ReconciliationFinding::MISSING_FORWARDER,
                        strtolower((string) $forwarder->source_local_part) . '@' . $domain->domain,
                        'We hold an active forwarding rule the service does not have.',
                        ['expected_route' => $route]
                    );
                }
            }

            if ($inventory->complete) {
                foreach ($observedForwarders as $route => $observed) {
                    if (! in_array($route, $desiredRoutes, true)) {
                        $findings[] = new ReconciliationFinding(
                            ReconciliationFinding::UNEXPECTED_FORWARDER,
                            $observed->normalizedSourceLocalPart() . '@' . $domain->domain,
                            'The service is relaying mail we have no record of.',
                            ['observed_route' => $route]
                        );
                    }
                }
            }

            // ── catch-all ────────────────────────────────────────────────────
            $observedCatchAll = $inventory->catchAll;

            if ($observedCatchAll !== null && $observedCatchAll->isKnown()) {
                $desiredEnabled = $catchAll !== null && $catchAll->state === EmailCatchAllState::ENABLED;

                if ($observedCatchAll->enabled !== $desiredEnabled) {
                    $findings[] = new ReconciliationFinding(
                        ReconciliationFinding::CATCHALL_MISMATCH,
                        (string) $domain->domain,
                        'Catch-all delivery at the service differs from ours.',
                        ['desired_enabled' => $desiredEnabled, 'observed_enabled' => $observedCatchAll->enabled]
                    );
                }
            }

            // ── bindings whose provider object is gone ───────────────────────
            $knownRefs = array_merge(
                array_map(fn ($m) => $m->providerRef, $inventory->mailboxes),
                array_map(fn ($a) => $a->providerRef, $inventory->aliases),
                array_map(fn ($f) => $f->providerRef, $inventory->forwarders),
            );

            if ($inventory->complete) {
                $bindings = InfraProviderResource::withoutWorkspaceScope()
                    ->where('workspace_id', $domain->workspace_id)
                    ->whereIn('owner_type', [
                        BusinessEmailEngine::RESOURCE_MAILBOX,
                        BusinessEmailEngine::RESOURCE_ALIAS,
                        BusinessEmailEngine::RESOURCE_FORWARDER,
                    ])
                    ->get();

                foreach ($bindings as $binding) {
                    if ($binding->provider_resource_id !== null
                        && ! in_array($binding->provider_resource_id, $knownRefs, true)) {
                        $findings[] = new ReconciliationFinding(
                            ReconciliationFinding::PROVIDER_OBJECT_MISSING,
                            (string) $domain->domain,
                            'We hold a binding whose object no longer exists at the service.',
                            ['owner_type' => $binding->owner_type, 'owner_id' => $binding->owner_id]
                        );
                    }
                }
            }

            // ── duplicate provider identity ──────────────────────────────────
            foreach ($inventory->duplicateProviderRefs() as $duplicate) {
                $findings[] = new ReconciliationFinding(
                    ReconciliationFinding::DUPLICATE_PROVIDER_OBJECT,
                    (string) $domain->domain,
                    'The service reported one object identity for more than one object.',
                    ['count' => 2, 'reference_seen_more_than_once' => true]
                );
            }

            // ── stale usage ──────────────────────────────────────────────────
            $latestUsage = EmailUsage::query()
                ->where('email_domain_id', $domain->id)
                ->orderByDesc('observed_at')
                ->first();

            if ($latestUsage === null || $latestUsage->observed_at?->lt(now()->subHours(self::USAGE_STALE_AFTER_HOURS))) {
                $findings[] = new ReconciliationFinding(
                    ReconciliationFinding::USAGE_STALE,
                    (string) $domain->domain,
                    'Usage has not been sampled recently enough to be current truth.',
                    [
                        'last_observed_at' => $latestUsage?->observed_at?->toIso8601String(),
                        'stale_after_hours' => self::USAGE_STALE_AFTER_HOURS,
                    ]
                );
            }

            return new ReconciliationReport(
                (string) $domain->domain,
                $findings,
                $inventory->complete,
                $inventory->complete ? '' : $inventory->incompleteReason,
                $mailboxes->count() + $aliases->count() + $forwarders->count(),
                $inventory->objectCount(),
            );
        });
    }

    // ── observation facts ────────────────────────────────────────────────────

    /**
     * Record the pass in the estate's existing observation registry.
     *
     * Uses `infra_observation_facts` rather than a Business Email table of its
     * own, so email drift carries the same custody and confidence semantics as
     * DNS, certificate and registrar drift, and lands in the same operator view.
     */
    private function recordObservation(EmailDomain $domain, ReconciliationReport $report, bool $success): void
    {
        $severity = $report->worstSeverity();

        DB::table('infra_observation_facts')->insert([
            'workspace_id'     => $domain->workspace_id,
            'subject'          => (string) $domain->domain,
            'customer_domain_id' => $domain->customer_domain_id,
            'website_id'       => $domain->website_id,
            'dimension'        => 'email_provider',
            'custody'          => (string) ($domain->custody ?: Custody::UNKNOWN),
            // Never a vendor name. The engine records WHAT observed, not WHO.
            'provider'         => 'business_email',
            'success'          => $success,
            'confidence'       => $report->conclusive ? Custody::VERIFIED : Custody::INFERRED,
            'observed_json'    => json_encode($report->toAdminArray()),
            'has_drift'        => ! $report->isClean(),
            'drift_count'      => $report->count(),
            'highest_severity' => $severity,
            'drift_json'       => json_encode($report->kinds()),
            'observed_at'      => now(),
            'last_success_at'  => $success ? now() : null,
            'last_failure_at'  => $success ? null : now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
