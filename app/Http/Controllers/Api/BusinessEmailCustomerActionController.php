<?php

namespace App\Http\Controllers\Api;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Customer\BusinessEmailCustomerGate as Gate;
use App\Engines\Infrastructure\Email\Customer\CustomerEntitlements;
use App\Engines\Infrastructure\Email\Customer\CustomerStatus;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailRoutingRuleState;
use App\Engines\Infrastructure\Email\Support\BusinessEmailFailure as Failure;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Email\Support\ForwarderLoopSafety;
use App\Engines\Infrastructure\Models\InfraEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INFRA888 · E4 — the customer Business Email portal. WRITE SIDE.
 *
 * ─── SAME RULE AS E3, AND ONE MORE ──────────────────────────────────────────
 *
 * It never talks to a provider: every action builds an `EmailOperationContext`
 * and hands it to `BusinessEmailEngine`. And it never manufactures success: the
 * response is derived from the engine's `ProviderResult` and nothing else.
 *
 * The extra rule is about APPROVAL, and it is the one design decision in this
 * file worth reading:
 *
 *   automatic       the customer acts on their own data. Executed directly.
 *   protected       the customer's own consent IS the approval. It is their
 *                   mailbox; requiring a second party to approve a customer
 *                   pausing their own mail would be theatre.
 *   protected_sod   the customer CANNOT approve. Separation of duties means a
 *                   second party, and a customer approving their own deletion
 *                   is exactly the thing SoD exists to prevent.
 *
 * `email.mailbox.delete` is the only SoD capability, and this controller does
 * NOT try to execute it. It records a deletion REQUEST and tells the customer
 * it is queued for review. Calling the engine would simply be refused, and
 * reporting that refusal as an error would be misleading — the request is
 * valid, it just needs a human at LevelUp. Operators see and complete it
 * through the E3 console.
 *
 * ─── AND THE WORKSPACE IS NEVER TAKEN FROM THE REQUEST ──────────────────────
 *
 * There is no `workspace_id` parameter on any endpoint here. It comes from the
 * verified token claim, so a customer cannot name someone else's workspace.
 */
class BusinessEmailCustomerActionController
{
    public function __construct(
        private readonly BusinessEmailEngine $engine,
    ) {
    }

    /**
     * Customer action name => engine capability.
     *
     * An explicit map. A caller cannot name a capability, so the portal cannot
     * become a way to invoke arbitrary engine operations.
     *
     * @return array<string,string>
     */
    public static function actionMap(): array
    {
        return [
            'start-setup'        => Registry::DOMAIN_ONBOARD,
            'check-dns'          => Registry::DOMAIN_VERIFY,
            'create-mailbox'     => Registry::MAILBOX_CREATE,
            'update-mailbox'     => Registry::MAILBOX_UPDATE,
            'suspend-mailbox'    => Registry::MAILBOX_SUSPEND,
            'restore-mailbox'    => Registry::MAILBOX_RESTORE,
            'delete-mailbox'     => Registry::MAILBOX_DELETE,
            'reset-password'     => Registry::PASSWORD_RESET,
            'create-alias'       => Registry::ALIAS_CREATE,
            'delete-alias'       => Registry::ALIAS_DELETE,
            'create-forwarder'   => Registry::FORWARDER_CREATE,
            'delete-forwarder'   => Registry::FORWARDER_DELETE,
            'configure-catchall' => Registry::CATCHALL_CONFIGURE,
            'clear-catchall'     => Registry::CATCHALL_CLEAR,
        ];
    }

    /** POST /api/infrastructure/business-email/domains/{id}/actions/{action} */
    public function act(Request $request, int $domainId, string $action): JsonResponse
    {
        if (! Gate::isEnabled()) {
            return response()->json([
                'success' => false, 'available' => false, 'reason' => Gate::unavailableReason(),
            ], 404);
        }

        $map = self::actionMap();

        if (! array_key_exists($action, $map)) {
            return response()->json([
                'success' => false,
                'reason'  => 'That action is not available.',
            ], 404);
        }

        $capability = $map[$action];
        $meta = Registry::get($capability);

        // The registry's own fact decides. An action it marks admin-only is not
        // re-decided here, so the two can never disagree.
        if (! Gate::allowsCapability($capability)) {
            return response()->json([
                'success' => false,
                'reason'  => 'That action is managed by LevelUp Growth support.',
            ], 403);
        }

        $workspaceId = (int) $request->attributes->get('workspace_id', 0);

        if ($workspaceId <= 0) {
            return response()->json(['success' => false, 'reason' => 'No workspace.'], 403);
        }

        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        if (($entitlements[CustomerEntitlements::ACCESS] ?? false) !== true) {
            return response()->json([
                'success' => false, 'reason' => 'Business Email is not included in your plan.',
            ], 403);
        }

        $domain = WorkspaceContext::run($workspaceId, fn () => EmailDomain::query()->find($domainId));

        if ($domain === null) {
            return response()->json(['success' => false, 'reason' => 'Not found.'], 404);
        }

        // Provider capability: refuse before anything is recorded, and say it
        // in product terms rather than naming a provider limitation.
        $flag = $meta['provider_capability'] ?? null;

        if ($flag !== null && ! $this->supports($flag)) {
            return response()->json([
                'success' => false,
                'reason'  => 'That action is not available on this account.',
            ], 501);
        }

        // PREFLIGHT RUNS FIRST, AND THE ORDER IS NOT COSMETIC.
        //
        // resolveSubject() CREATES the local record for a create action. Running
        // it first meant the duplicate-address check then found the row it had
        // just inserted and refused every create with 409 — and where the unique
        // index caught it first, the customer got a 500 instead of the polite
        // "that address already exists". Validate the request, then build.
        if (($guard = $this->preflight($request, $workspaceId, $domain, $capability, $entitlements)) !== null) {
            return $guard;
        }

        $subject = $this->resolveSubject($request, $workspaceId, $domain, $capability);

        if ($subject === false) {
            return response()->json(['success' => false, 'reason' => 'Not found.'], 404);
        }

        // ── separation of duties: a request, not an execution ───────────────
        if (($meta['approval_mode'] ?? null) === Registry::APPROVAL_SEPARATION_OF_DUTIES) {
            return $this->recordRequest($request, $workspaceId, $domain, $subject, $capability, $action);
        }

        $context = new EmailOperationContext(
            workspaceId: $workspaceId,
            capability: $capability,
            domain: $domain,
            subject: $subject ?: null,
            actorUserId: (int) ($request->user()?->id ?? 0) ?: null,
            source: 'customer',
            entitlements: $entitlements,
            input: $this->inputFor($request),
            idempotencyKey: $this->idempotencyKey($capability, $domainId, $subject ?: null, $request),
            // The customer's own consent. See the class docblock for why this
            // is honest for `protected` and impossible for `protected_sod`.
            approved: true,
            approvedBy: (int) ($request->user()?->id ?? 0) ?: null,
        );

        $result = $this->engine->execute($context);

        return $this->respond($result, $capability, $subject ?: null);
    }

    // ── customer-side preflight ──────────────────────────────────────────────

    /**
     * Checks the ENGINE cannot make, because they are product rules rather than
     * platform rules: LevelUp allowances, duplicate addresses and forwarding
     * loops the customer is about to create.
     *
     * The engine still enforces its own; this is about refusing early with
     * wording a customer can act on, instead of a typed engine failure.
     */
    private function preflight(
        Request $request,
        int $workspaceId,
        EmailDomain $domain,
        string $capability,
        array $entitlements
    ): ?JsonResponse {
        return WorkspaceContext::run($workspaceId, function () use ($request, $domain, $capability, $entitlements) {
            if ($capability === Registry::MAILBOX_CREATE) {
                $used = EmailMailbox::query()->whereIn('lifecycle_state', EmailMailboxState::billable())->count();
                $allowance = CustomerEntitlements::allowance($used, $entitlements[CustomerEntitlements::MAILBOX_LIMIT]);

                if ($allowance['at_limit']) {
                    return $this->refuse('You have used all the mailboxes included in your plan.', 409, [
                        'allowance' => $allowance,
                    ]);
                }

                $local = strtolower(trim((string) $request->input('local_part', '')));

                if (! EmailAddress::isValidLocalPart($local)) {
                    return $this->refuse('That address is not valid. Use letters, numbers, dots, hyphens or underscores.', 422);
                }

                if (EmailAddress::isReservedLocalPart($local)) {
                    return $this->refuse('That address is reserved. Contact LevelUp Growth support if you need it.', 422);
                }

                $exists = EmailMailbox::query()
                    ->where('email_domain_id', $domain->id)
                    ->whereRaw('LOWER(local_part) = ?', [$local])
                    ->whereNotIn('lifecycle_state', [EmailMailboxState::DELETED])
                    ->exists();

                if ($exists) {
                    return $this->refuse('A mailbox with that address already exists.', 409);
                }
            }

            if ($capability === Registry::ALIAS_CREATE) {
                $used = EmailAlias::query()->where('lifecycle_state', EmailRoutingRuleState::ACTIVE)->count();
                $allowance = CustomerEntitlements::allowance($used, $entitlements[CustomerEntitlements::ALIAS_LIMIT]);

                if ($allowance['at_limit']) {
                    return $this->refuse('You have used all the aliases included in your plan.', 409, ['allowance' => $allowance]);
                }

                $local = strtolower(trim((string) $request->input('source_local_part', '')));

                if (EmailAlias::query()->where('email_domain_id', $domain->id)
                    ->whereRaw('LOWER(source_local_part) = ?', [$local])
                    ->whereNotIn('lifecycle_state', [EmailRoutingRuleState::REMOVED])->exists()) {
                    return $this->refuse('An alias with that address already exists.', 409);
                }
            }

            if ($capability === Registry::FORWARDER_CREATE) {
                $used = EmailForwarder::query()->where('lifecycle_state', EmailRoutingRuleState::ACTIVE)->count();
                $allowance = CustomerEntitlements::allowance($used, $entitlements[CustomerEntitlements::FORWARDER_LIMIT]);

                if ($allowance['at_limit']) {
                    return $this->refuse('You have used all the forwarding rules included in your plan.', 409, ['allowance' => $allowance]);
                }

                $local = strtolower(trim((string) $request->input('source_local_part', '')));
                $destination = strtolower(trim((string) $request->input('destination_address', '')));

                if (! EmailAddress::isValid($destination)) {
                    return $this->refuse('That forwarding address is not valid.', 422);
                }

                // Loop safety BEFORE anything is created. The customer is told
                // in plain terms; the verdict name never travels.
                $verdict = ForwarderLoopSafety::evaluate(
                    (string) EmailAddress::compose($local, (string) $domain->domain),
                    $destination,
                    [(string) $domain->domain],
                    $this->existingForwarderMap($domain)
                );

                if (ForwarderLoopSafety::isBlocking($verdict['state'])
                    && $verdict['state'] !== ForwarderLoopSafety::UNCHECKED) {
                    return $this->refuse(
                        'That would send mail round in circles. Forward to an address outside this domain.',
                        422
                    );
                }

                if (EmailForwarder::query()->where('email_domain_id', $domain->id)
                    ->whereRaw('LOWER(source_local_part) = ?', [$local])
                    ->whereRaw('LOWER(destination_address) = ?', [$destination])
                    ->whereNotIn('lifecycle_state', [EmailRoutingRuleState::REMOVED])->exists()) {
                    return $this->refuse('That forwarding rule already exists.', 409);
                }
            }

            return null;
        });
    }

    /** @return array<string,string> */
    private function existingForwarderMap(EmailDomain $domain): array
    {
        $map = [];

        foreach (EmailForwarder::query()->where('lifecycle_state', EmailRoutingRuleState::ACTIVE)->get() as $f) {
            $source = EmailAddress::compose((string) $f->source_local_part, (string) $domain->domain);

            if ($source !== null) {
                $map[$source] = (string) $f->destination_address;
            }
        }

        return $map;
    }

    // ── separation of duties ─────────────────────────────────────────────────

    /**
     * Record a request the customer may not approve for themselves.
     *
     * It is NOT sent to the engine: the engine would refuse it (correctly), and
     * surfacing that refusal as an error would tell the customer their valid
     * request had failed. It is recorded as an auditable request and reported
     * as awaiting review. Operators pick it up in the E3 console.
     */
    private function recordRequest(
        Request $request,
        int $workspaceId,
        EmailDomain $domain,
        Model|false|null $subject,
        string $capability,
        string $action
    ): JsonResponse {
        WorkspaceContext::run($workspaceId, function () use ($request, $workspaceId, $domain, $subject, $capability, $action) {
            InfraEvent::create([
                'workspace_id'  => $workspaceId,
                'owner_type'    => $subject instanceof Model ? EmailMailbox::OWNER_TYPE : EmailDomain::OWNER_TYPE,
                'owner_id'      => $subject instanceof Model ? $subject->getKey() : $domain->getKey(),
                'event'         => 'email.customer_request',
                'severity'      => InfraEvent::SEVERITY_WARNING,
                'actor_user_id' => $request->user()?->id,
                'source'        => 'customer',
                'provider'      => null,
                'summary'       => "Customer requested '{$action}'. Awaiting LevelUp review.",
                'context_json'  => [
                    'capability'   => $capability,
                    'action'       => $action,
                    'requires'     => 'separation_of_duties',
                    'subject_id'   => $subject instanceof Model ? $subject->getKey() : null,
                ],
                'created_at'    => now(),
            ]);
        });

        return response()->json([
            'success'  => true,
            'verified' => false,
            'state'    => 'awaiting_review',
            'message'  => 'Your request has been received and is awaiting review by LevelUp Growth. '
                . 'Nothing has been deleted yet.',
        ], 202);
    }

    // ── subject resolution ───────────────────────────────────────────────────

    /** @return Model|false|null false = not found; null = no subject needed */
    private function resolveSubject(Request $request, int $workspaceId, EmailDomain $domain, string $capability): Model|false|null
    {
        $domainScoped = in_array($capability, [Registry::DOMAIN_ONBOARD, Registry::DOMAIN_VERIFY], true);

        if ($domainScoped) {
            return null;
        }

        // A create has no subject yet; the portal creates the local record and
        // the engine provisions it.
        if (in_array($capability, [Registry::MAILBOX_CREATE, Registry::ALIAS_CREATE,
            Registry::FORWARDER_CREATE, Registry::CATCHALL_CONFIGURE], true)) {
            return $this->createLocalRecord($request, $workspaceId, $domain, $capability);
        }

        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return false;
        }

        $model = WorkspaceContext::run($workspaceId, fn () => match (true) {
            str_starts_with($capability, 'email.mailbox'), $capability === Registry::PASSWORD_RESET
                => EmailMailbox::query()->find($id),
            str_starts_with($capability, 'email.alias')     => EmailAlias::query()->find($id),
            str_starts_with($capability, 'email.forwarder') => EmailForwarder::query()->find($id),
            str_starts_with($capability, 'email.catchall')  => EmailCatchAll::query()->find($id),
            default => null,
        });

        // Must belong to THIS domain as well as this workspace. Two independent
        // checks, because a customer with two domains must not act across them
        // through the wrong URL.
        if ($model === null || (int) $model->email_domain_id !== (int) $domain->id) {
            return false;
        }

        return $model;
    }

    private function createLocalRecord(Request $request, int $workspaceId, EmailDomain $domain, string $capability): Model|false
    {
        return WorkspaceContext::run($workspaceId, function () use ($request, $domain, $capability) {
            return match ($capability) {
                Registry::MAILBOX_CREATE => EmailMailbox::create([
                    'email_domain_id' => $domain->id,
                    'local_part'      => strtolower(trim((string) $request->input('local_part'))),
                    'display_name'    => $request->input('display_name'),
                    'quota_mb'        => (int) ($request->input('quota_mb') ?: 1024),
                ]),

                Registry::ALIAS_CREATE => EmailAlias::create([
                    'email_domain_id'   => $domain->id,
                    'source_local_part' => strtolower(trim((string) $request->input('source_local_part'))),
                    'target_type'       => EmailAlias::TARGET_MAILBOX,
                    'target_mailbox_id' => (int) $request->input('target_mailbox_id'),
                ]),

                Registry::FORWARDER_CREATE => tap(EmailForwarder::create([
                    'email_domain_id'     => $domain->id,
                    'source_local_part'   => strtolower(trim((string) $request->input('source_local_part'))),
                    'destination_address' => strtolower(trim((string) $request->input('destination_address'))),
                ]), function (EmailForwarder $f) use ($domain) {
                    // The engine refuses an unevaluated forwarder, so the
                    // verdict is recorded here rather than left `unchecked`.
                    $f->recordLoopVerdict(ForwarderLoopSafety::evaluate(
                        (string) EmailAddress::compose((string) $f->source_local_part, (string) $domain->domain),
                        (string) $f->destination_address,
                        [(string) $domain->domain],
                        $this->existingForwarderMap($domain)
                    ))->save();
                }),

                Registry::CATCHALL_CONFIGURE => EmailCatchAll::firstOrCreate(
                    ['email_domain_id' => $domain->id],
                    [
                        'target_type'       => EmailCatchAll::TARGET_MAILBOX,
                        'target_mailbox_id' => (int) $request->input('target_mailbox_id'),
                    ]
                ),

                default => false,
            };
        });
    }

    // ── response ─────────────────────────────────────────────────────────────

    /**
     * Derived entirely from the engine's answer, then translated.
     *
     * `verified` and `accepted` stay different things, and an ambiguous outcome
     * is reported as under review rather than as a failure — because it is
     * neither, and telling a customer their mailbox failed when it may exist
     * would produce a duplicate the moment they retried.
     */
    private function respond($result, string $capability, ?Model $subject): JsonResponse
    {
        $meta = Registry::get($capability);

        if ($result->verified) {
            return response()->json([
                'success'  => true,
                'verified' => true,
                'state'    => 'done',
                'message'  => $this->doneMessage($capability),
                'status'   => $subject !== null ? $this->subjectStatus($subject) : null,
            ], 200)->header('Cache-Control', 'no-store, private');
        }

        if ($result->success) {
            return response()->json([
                'success'  => true,
                'verified' => false,
                'state'    => 'pending',
                'message'  => 'Requested. We are setting this up and will confirm shortly.',
                'status'   => $subject !== null ? $this->subjectStatus($subject) : null,
            ], 202)->header('Cache-Control', 'no-store, private');
        }

        $code = (string) $result->errorCode;

        // Ambiguity is not failure. The customer is told it is under review,
        // and NOT invited to retry — a retry is what duplicates a mailbox.
        if (in_array($code, ['provider_timeout', 'provider_indeterminate', 'provider_malformed_response'], true)) {
            return response()->json([
                'success'  => false,
                'verified' => false,
                'state'    => 'under_review',
                'message'  => 'We could not confirm this completed. We are checking now — please do not try again yet.',
            ], 202)->header('Cache-Control', 'no-store, private');
        }

        return response()->json([
            'success'  => false,
            'verified' => false,
            'state'    => 'refused',
            // The typed customer-safe message from E1. Never a provider code.
            'message'  => Failure::message($code),
        ], $this->statusFor($code))->header('Cache-Control', 'no-store, private');
    }

    private function subjectStatus(Model $subject): array
    {
        $state = (string) ($subject->getAttribute('lifecycle_state') ?? $subject->getAttribute('state'));

        return CustomerStatus::describe(match (true) {
            $subject instanceof EmailMailbox   => CustomerStatus::forMailbox($state),
            $subject instanceof EmailCatchAll  => CustomerStatus::forCatchAll($state),
            default                            => CustomerStatus::forRoutingRule($state),
        });
    }

    private function doneMessage(string $capability): string
    {
        return match ($capability) {
            Registry::DOMAIN_ONBOARD    => 'Setup started. Add the DNS records shown, then run a DNS check.',
            Registry::DOMAIN_VERIFY     => 'Your DNS records were found and verified.',
            Registry::MAILBOX_CREATE    => 'Mailbox created.',
            Registry::MAILBOX_UPDATE    => 'Mailbox updated.',
            Registry::MAILBOX_SUSPEND   => 'Mailbox paused.',
            Registry::MAILBOX_RESTORE   => 'Mailbox resumed.',
            Registry::PASSWORD_RESET    => 'Password reset requested. Follow the recovery instructions provided for this mailbox.',
            Registry::ALIAS_CREATE      => 'Alias created.',
            Registry::ALIAS_DELETE      => 'Alias removed.',
            Registry::FORWARDER_CREATE  => 'Forwarding rule created.',
            Registry::FORWARDER_DELETE  => 'Forwarding rule removed.',
            Registry::CATCHALL_CONFIGURE => 'Catch-all updated.',
            Registry::CATCHALL_CLEAR    => 'Catch-all turned off.',
            default                     => 'Done.',
        };
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            Failure::PROVIDER_NOT_CONFIGURED, Failure::PROVIDER_UNAVAILABLE => 503,
            Failure::CAPABILITY_NOT_SUPPORTED => 501,
            Failure::NOT_ENTITLED, Failure::QUOTA_EXCEEDED, Failure::CROSS_TENANT => 403,
            Failure::APPROVAL_REQUIRED => 202,
            default => 422,
        };
    }

    private function refuse(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false, 'verified' => false, 'state' => 'refused', 'message' => $message,
        ] + $extra, $status);
    }

    private function inputFor(Request $request): array
    {
        // Only fields the engine understands, and nothing that could carry a
        // credential.
        return array_filter(
            $request->only(['local_part', 'display_name', 'quota_mb', 'target_address', 'destination_address', 'source_local_part']),
            fn ($v) => $v !== null && $v !== ''
        );
    }

    private function idempotencyKey(string $capability, int $domainId, ?Model $subject, Request $request): ?string
    {
        $meta = Registry::get($capability);

        if (($meta['idempotency'] ?? null) !== Registry::IDEMPOTENCY_CALLER_KEY) {
            return null;
        }

        // Derived from the SUBJECT, so a double-click is one intent. The client
        // does not supply it — a customer-supplied key would let a customer
        // defeat their own idempotency.
        return 'customer:' . $capability . ':' . $domainId . ':' . ($subject?->getKey() ?? 'none');
    }

    private function supports(string $capability): bool
    {
        $connector = $this->engine->connector();

        return $connector !== null && $connector->supports($capability);
    }
}
