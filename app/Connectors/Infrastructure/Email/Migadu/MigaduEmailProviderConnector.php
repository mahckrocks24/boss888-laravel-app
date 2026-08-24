<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability as Cap;
use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapabilitySet;
use App\Connectors\Infrastructure\BusinessEmail\SecretString;
use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Email\Migadu\MigaduErrorClassifier as Err;
use App\Connectors\Infrastructure\Email\Migadu\MigaduRequestFactory as Req;
use App\Connectors\Infrastructure\Email\Migadu\MigaduResponseMapper as Map;
use App\Connectors\Infrastructure\ProviderResult;
use DateTimeImmutable;

/**
 * INFRA888 · E5 — the first real EmailProviderConnector.
 *
 * THIS IS THE ONLY CLASS IN THE PLATFORM ALLOWED TO KNOW WHICH VENDOR WE USE.
 * Everything it returns is provider-neutral: neutral value objects, neutral
 * error codes, opaque references. A caller cannot tell from any return value
 * which vendor answered, which is the entire white-label promise expressed as
 * a type signature rather than a policy document.
 *
 * E5 IS READ-ONLY. Every mutating method is fully implemented and fully tested
 * against mocked HTTP, and every one of them refuses to execute while the
 * mutation gate is closed — which it is by default and throughout E5. The
 * methods exist so E6 has something proven to switch on; the gate exists so E5
 * cannot provision anything by accident.
 *
 * FIVE OF TWENTY CAPABILITIES ARE UNSUPPORTED HERE. They are refused, never
 * emulated outside the adapter and never quietly approximated. See
 * MigaduCapabilityMap for each verdict and the documentation that produced it.
 */
final class MigaduEmailProviderConnector implements EmailProviderConnector
{
    /**
     * Bounded fan-out for forwarder inventory. The provider nests forwardings
     * under each mailbox, so a complete list costs one call per mailbox. Beyond
     * this the inventory is reported as PARTIAL rather than silently truncated —
     * a reconciliation that believes it saw everything will delete things it
     * simply did not fetch.
     */
    private const MAX_FORWARDER_FANOUT = 50;

    public function __construct(
        private readonly MigaduClient $client,
        private readonly bool $mutationsEnabled = false,
    ) {
    }

    // ═══ IDENTITY ════════════════════════════════════════════════════════════

    public function provider(): string
    {
        // The provider KEY, not a brand string for display. It is matched
        // against infra_providers.provider_key and never rendered to a customer.
        return 'migadu';
    }

    public function capability(): string
    {
        return 'email';
    }

    public function capabilitySet(): EmailProviderCapabilitySet
    {
        return MigaduCapabilityMap::capabilitySet();
    }

    public function supports(string $capability): bool
    {
        return MigaduCapabilityMap::supports($capability);
    }

    // ═══ HEALTH AND GENERIC INFRASTRUCTURE SURFACE ═══════════════════════════

    public function healthCheck(): ProviderResult
    {
        return $this->read('health', function () {
            $response = $this->client->get(Req::domains());

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $domains = Map::rows($response['body'], 'domains');

            return ProviderResult::verified(
                normalizedState: 'healthy',
                providerState: 'reachable',
                data: [
                    'domain_count'   => count($domains),
                    'rate_limit'     => $this->rateLimit($response['headers']),
                    'verified_on'    => MigaduCapabilityMap::VERIFIED_ON,
                ],
                correlationId: $response['correlation_id'],
            );
        });
    }

    /** Generic re-read of one provider resource, used by the observation stack. */
    public function synchronize(string $providerResourceId): ProviderResult
    {
        return $this->read('synchronize', function () use ($providerResourceId) {
            [$domain, $localPart] = Map::splitRef($providerResourceId);

            $response = $this->client->get(Req::mailbox($domain, $localPart));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $mailbox = Map::toMailbox($domain, $response['body']);

            // The VALUE OBJECT, not an array. The engine reads these payloads as
            // typed objects — the fake proves the shape — and handing it an
            // array here would fail at the first property access.
            return ProviderResult::verified(
                normalizedState: $mailbox->suspended === true ? 'suspended' : 'active',
                providerResourceId: $providerResourceId,
                data: ['mailbox' => $mailbox],
                correlationId: $response['correlation_id'],
            );
        });
    }

    /** Read-back confirmation. This is what turns `accepted` into `verified`. */
    public function verify(string $providerResourceId, array $expectation = []): ProviderResult
    {
        return $this->read('verify', function () use ($providerResourceId, $expectation) {
            [$domain, $localPart] = Map::splitRef($providerResourceId);

            $response = $this->client->get(Req::mailbox($domain, $localPart));

            if ($response['status'] === 404) {
                return ProviderResult::failed(
                    errorCode: Err::NOT_FOUND,
                    errorSummary: 'The email service does not have this object.',
                    retryClassification: 'permanent',
                    correlationId: $response['correlation_id'],
                );
            }

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $mailbox = Map::toMailbox($domain, $response['body']);
            $mismatches = [];

            foreach ($expectation as $field => $expected) {
                $actual = match ($field) {
                    'display_name' => $mailbox->displayName,
                    'suspended'    => $mailbox->suspended,
                    'local_part'   => $mailbox->localPart,
                    default        => null,
                };

                if ($actual !== $expected) {
                    $mismatches[] = $field;
                }
            }

            // A drifted object is a real observation, not a failure. The engine
            // decides what to do about it; the adapter only reports honestly.
            if ($mismatches !== []) {
                return ProviderResult::accepted(
                    normalizedState: 'drifted',
                    providerResourceId: $providerResourceId,
                    data: ['mismatched_fields' => $mismatches, 'mailbox' => $mailbox],
                    correlationId: $response['correlation_id'],
                );
            }

            return ProviderResult::verified(
                normalizedState: 'verified',
                providerResourceId: $providerResourceId,
                data: ['mailbox' => $mailbox],
                correlationId: $response['correlation_id'],
            );
        });
    }

    // ═══ DOMAIN ══════════════════════════════════════════════════════════════

    public function getDomainAuthStatus(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->read('domain_status', function () use ($domain) {
            $response = $this->client->get(Req::domainDiagnostics($domain));

            // Documented as "DNS checks or validation failed" — which for a
            // domain still waiting on records is the expected state, not an error.
            if ($response['status'] === 422) {
                return ProviderResult::accepted(
                    normalizedState: 'awaiting_dns',
                    providerResourceId: $domain,
                    data: ['checks' => $this->diagnosticChecks($response['body'])],
                    correlationId: $response['correlation_id'],
                );
            }

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $checks = $this->diagnosticChecks($response['body']);
            $ok = $checks !== [] && ! in_array(false, $checks, true);

            return $ok
                ? ProviderResult::verified(
                    normalizedState: 'verified',
                    providerResourceId: $domain,
                    data: ['checks' => $checks],
                    correlationId: $response['correlation_id'],
                )
                : ProviderResult::accepted(
                    normalizedState: 'awaiting_dns',
                    providerResourceId: $domain,
                    data: ['checks' => $checks],
                    correlationId: $response['correlation_id'],
                );
        });
    }

    public function getDnsRequirements(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->read('dns_requirements', function () use ($domain) {
            $response = $this->client->get(Req::domainRecords($domain));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $records = Map::toDnsRecords($response['body']);

            if ($records === []) {
                return ProviderResult::failed(
                    errorCode: Err::MALFORMED,
                    errorSummary: 'The email service returned no usable DNS requirements.',
                    retryClassification: 'retryable',
                    correlationId: $response['correlation_id'],
                );
            }

            return ProviderResult::verified(
                normalizedState: 'requirements_known',
                providerResourceId: $domain,
                data: ['records' => $records],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function onboardDomain(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::DOMAIN_ONBOARD, function () use ($domain, $context) {
            $response = $this->client->post(Req::domains(), ['name' => $domain]);

            if (! in_array($response['status'], [200, 201], true)) {
                return $this->failure($response, mutating: true);
            }

            // ONBOARDING IS NEVER VERIFIED ON THE FIRST CALL. The domain exists
            // at the provider, but mail will not flow until DNS is published and
            // observed. E2 established this two-phase rule; a "verified" here
            // would tell a customer their email works before it does.
            return ProviderResult::accepted(
                normalizedState: 'awaiting_dns',
                providerResourceId: $domain,
                providerState: 'created',
                data: ['idempotency_key' => $context->idempotencyKey],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function verifyDomain(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::DOMAIN_VERIFY, function () use ($domain) {
            // Activation is a GET in this provider's grammar, but it CHANGES
            // STATE, so it is gated as a mutation regardless of its verb. Verb
            // is not a safe proxy for effect.
            $response = $this->client->get(Req::domain($domain) . '/activate');

            if ($response['status'] === 422) {
                return ProviderResult::accepted(
                    normalizedState: 'awaiting_dns',
                    providerResourceId: $domain,
                    data: ['checks' => $this->diagnosticChecks($response['body'])],
                    correlationId: $response['correlation_id'],
                );
            }

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::verified(
                normalizedState: 'verified',
                providerResourceId: $domain,
                providerState: 'active',
                correlationId: $response['correlation_id'],
            );
        });
    }

    // ═══ MAILBOX ═════════════════════════════════════════════════════════════

    public function createMailbox(
        string $domain,
        MailboxSpec $spec,
        ProviderCallContext $context,
        ?SecretString $password = null
    ): ProviderResult {
        return $this->mutate(Cap::MAILBOX_CREATE, function () use ($domain, $spec, $context, $password) {
            // The single read of the customer's secret, immediately before it is
            // transmitted. After this line it is unreadable.
            $response = $this->client->post(
                Req::mailboxes($domain),
                Req::createMailbox($spec, $password?->reveal())
            );

            // THE PROVIDER RETURNS AN OPAQUE 400 FOR TWO VERY DIFFERENT THINGS.
            //
            // Proven live on 2026-08-06: creating a mailbox that already exists
            // returns exactly {"error":"bad request"} — byte-identical to the
            // response for a malformed payload. String-sniffing cannot tell them
            // apart, so the adapter asks the provider instead: if the object is
            // there, this was a duplicate, and a duplicate create is the desired
            // end state rather than an error. That is what makes a retried
            // create converge instead of failing.
            if ($response['status'] === 400) {
                $existing = $this->client->get(Req::mailbox($domain, $spec->localPart));

                if ($existing['status'] === 200) {
                    return ProviderResult::accepted(
                        normalizedState: 'provisioning',
                        providerResourceId: Map::mailboxRef($domain, $spec->localPart),
                        providerState: 'already_exists',
                        data: [
                            'idempotency_key' => $context->idempotencyKey,
                            'already_existed' => true,
                        ],
                        correlationId: $response['correlation_id'],
                    );
                }

                // Absent, so the request itself was refused. Live evidence says
                // the overwhelmingly likely cause is a missing initial password
                // — see MigaduCapabilityMap::MAILBOX_CREATE. Saying so is worth
                // far more to an operator than "validation failed".
                return ProviderResult::failed(
                    errorCode: Err::VALIDATION_FAILED,
                    errorSummary: 'The email service refused to create the mailbox. It requires an initial '
                        . 'password, which this platform does not generate.',
                    retryClassification: 'permanent',
                    correlationId: $response['correlation_id'],
                );
            }

            if (! in_array($response['status'], [200, 201], true)) {
                return $this->failure($response, mutating: true);
            }

            $ref = Map::mailboxRef($domain, $spec->localPart);

            // ACCEPTED, NOT VERIFIED. The provider said yes; the engine confirms
            // by read-back. This is the rule that makes a timeout survivable.
            //
            // INFRA888 · E7: under invitation mode the read-back will find a
            // mailbox that exists and is not yet usable, so the state it lands
            // in says exactly that.
            return ProviderResult::accepted(
                normalizedState: $spec->usesInvitation() ? 'awaiting_activation' : 'provisioning',
                providerResourceId: $ref,
                providerState: 'created',
                data: [
                    'idempotency_key' => $context->idempotencyKey,
                    // Records THAT a customer-supplied credential was used, never
                    // the credential. LevelUp never generates or retains one.
                    'password_supplied_by_customer' => $password !== null,
                    'invitation_sent'      => $spec->usesInvitation(),
                    'awaiting_owner_action' => $spec->usesInvitation(),
                ],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function updateMailbox(string $mailboxRef, MailboxSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::MAILBOX_UPDATE, function () use ($mailboxRef, $spec) {
            [$domain, $localPart] = Map::splitRef($mailboxRef);

            $response = $this->client->put(Req::mailbox($domain, $localPart), Req::updateMailbox($spec));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'updating',
                providerResourceId: $mailboxRef,
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function suspendMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::MAILBOX_SUSPEND, function () use ($mailboxRef) {
            [$domain, $localPart] = Map::splitRef($mailboxRef);

            // Native, not emulated — see MigaduRequestFactory::activation().
            $response = $this->client->put(Req::mailbox($domain, $localPart), Req::activation(false));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'suspending',
                providerResourceId: $mailboxRef,
                data: ['mechanism' => 'native_is_active'],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function restoreMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::MAILBOX_RESTORE, function () use ($mailboxRef) {
            [$domain, $localPart] = Map::splitRef($mailboxRef);

            $response = $this->client->put(Req::mailbox($domain, $localPart), Req::activation(true));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'restoring',
                providerResourceId: $mailboxRef,
                // No longer lossy: one field goes down and the same field comes
                // back up, so nothing else about the mailbox can be disturbed.
                data: ['mechanism' => 'native_is_active'],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function deleteMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::MAILBOX_DELETE, function () use ($mailboxRef) {
            [$domain, $localPart] = Map::splitRef($mailboxRef);

            $response = $this->client->delete(Req::mailbox($domain, $localPart));

            // Already gone is the desired end state, not a failure. Retrying a
            // delete must converge rather than error.
            if ($response['status'] === 404) {
                return ProviderResult::verified(
                    normalizedState: 'deleted',
                    providerResourceId: $mailboxRef,
                    correlationId: $response['correlation_id'],
                );
            }

            if (! in_array($response['status'], [200, 202, 204], true)) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'deleting',
                providerResourceId: $mailboxRef,
                correlationId: $response['correlation_id'],
            );
        });
    }

    /**
     * INFRA888 · E7.3 — relay a customer-chosen password, once.
     *
     * The secret is revealed at exactly one line, handed straight to the HTTP
     * client, and is unreadable thereafter. It appears in no ProviderResult, no
     * log line and no exception: MigaduClient logs method, route shape and
     * status only, and SecretString refuses to stringify or serialise.
     */
    public function setMailboxPassword(
        string $mailboxRef,
        SecretString $password,
        ProviderCallContext $context
    ): ProviderResult {
        return $this->mutate(Cap::MAILBOX_PASSWORD_SET, function () use ($mailboxRef, $password) {
            [$domain, $localPart] = Map::splitRef($mailboxRef);

            $response = $this->client->put(
                Req::mailbox($domain, $localPart),
                // The one permitted read. After this line the value is gone.
                Req::setPassword($password->reveal())
            );

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: true);
            }

            // ACCEPTED, not verified. The provider took the change; only a
            // read-back may say the mailbox is usable.
            return ProviderResult::accepted(
                normalizedState: 'activating',
                providerResourceId: $mailboxRef,
                data: [
                    // Deliberately no password, no hash, no length, no hint.
                    'password_relayed' => true,
                ],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function requestPasswordReset(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        // UNSUPPORTED, DELIBERATELY. The provider exposes no operation that
        // sends a reset to the mailbox owner; it exposes a write-only password
        // field. Using it would mean this platform generating and transmitting
        // a mailbox secret, which E4 forbids. Refusing is the honest answer.
        return Err::unsupported(
            Cap::MAILBOX_PASSWORD_RESET,
            'Password reset is not available for this mailbox.'
        );
    }

    public function getMailboxStatus(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->read('mailbox_status', function () use ($mailboxRef) {
            [$domain, $localPart] = Map::splitRef($mailboxRef);

            $response = $this->client->get(Req::mailbox($domain, $localPart));

            if ($response['status'] === 404) {
                return ProviderResult::failed(
                    errorCode: Err::NOT_FOUND,
                    errorSummary: 'The email service does not have this mailbox.',
                    retryClassification: 'permanent',
                    correlationId: $response['correlation_id'],
                );
            }

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $mailbox = Map::toMailbox($domain, $response['body']);

            // Three outcomes, not two. A mailbox waiting for its owner is
            // neither active nor suspended, and flattening it into either one
            // misinforms the customer.
            $state = match (true) {
                Map::isAwaitingActivation($response['body']) => 'awaiting_activation',
                $mailbox->suspended === true                 => 'suspended',
                default                                      => 'active',
            };

            return ProviderResult::verified(
                normalizedState: $state,
                providerResourceId: $mailboxRef,
                data: ['mailbox' => $mailbox],
                correlationId: $response['correlation_id'],
            );
        });
    }

    // ═══ ROUTING ═════════════════════════════════════════════════════════════

    public function createAlias(string $domain, AliasSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::ALIAS_CREATE, function () use ($domain, $spec) {
            // REFUSED RATHER THAN RE-ROUTED. This provider's aliases redirect
            // only within the same domain. Quietly turning an external alias
            // into a forwarder would change its semantics, require a parent
            // mailbox, and subject it to recipient confirmation — none of which
            // the caller asked for.
            if (! $this->isSameDomain($spec->targetAddress, $domain)) {
                return Err::unsupported(
                    Cap::ALIAS_CREATE,
                    'An alias can only deliver to another address on the same domain.'
                );
            }

            $response = $this->client->post(Req::aliases($domain), Req::createAlias($spec));

            if (! in_array($response['status'], [200, 201], true)) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'provisioning',
                providerResourceId: Map::mailboxRef($domain, $spec->sourceLocalPart),
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function deleteAlias(string $aliasRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::ALIAS_DELETE, function () use ($aliasRef) {
            [$domain, $localPart] = Map::splitRef($aliasRef);

            $response = $this->client->delete(Req::alias($domain, $localPart));

            if ($response['status'] === 404) {
                return ProviderResult::verified(
                    normalizedState: 'deleted',
                    providerResourceId: $aliasRef,
                    correlationId: $response['correlation_id'],
                );
            }

            if (! in_array($response['status'], [200, 202, 204], true)) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'deleting',
                providerResourceId: $aliasRef,
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function createForwarder(string $domain, ForwarderSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::FORWARDER_CREATE, function () use ($domain, $spec, $context) {
            // THE STRUCTURAL MISMATCH, HANDLED HONESTLY.
            //
            // The contract is domain-scoped: source local part plus destination.
            // This provider nests forwardings under a mailbox, so the source
            // must already BE a mailbox. Creating one to satisfy the call would
            // be a mutation the caller never requested — it would consume
            // storage, appear in inventory, and change what the customer is
            // billed for. So we check, and refuse.
            $parent = $this->client->get(Req::mailbox($domain, $spec->sourceLocalPart));

            if ($parent['status'] === 404) {
                return Err::unsupported(
                    Cap::FORWARDER_CREATE,
                    'Forwarding can only be set up for an address that already has a mailbox.'
                );
            }

            if ($parent['status'] !== 200) {
                return $this->failure($parent, mutating: false);
            }

            $response = $this->client->post(
                Req::forwardings($domain, $spec->sourceLocalPart),
                Req::createForwarding($spec)
            );

            if (! in_array($response['status'], [200, 201], true)) {
                return $this->failure($response, mutating: true);
            }

            $confirmed = Map::forwarderIsConfirmed($response['body']);

            // A forwarding the destination has not confirmed does NOT deliver
            // mail. Reporting it as verified would tell a customer their
            // forwarding works while messages silently go nowhere.
            return $confirmed
                ? ProviderResult::verified(
                    normalizedState: 'active',
                    providerResourceId: Map::mailboxRef($domain, $spec->sourceLocalPart) . '/' . $spec->destinationAddress,
                    correlationId: $response['correlation_id'],
                )
                : ProviderResult::accepted(
                    normalizedState: 'awaiting_confirmation',
                    providerResourceId: Map::mailboxRef($domain, $spec->sourceLocalPart) . '/' . $spec->destinationAddress,
                    providerState: 'unconfirmed',
                    data: [
                        'requires_destination_confirmation' => true,
                        'idempotency_key'                   => $context->idempotencyKey,
                    ],
                    correlationId: $response['correlation_id'],
                );
        });
    }

    public function deleteForwarder(string $forwarderRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::FORWARDER_DELETE, function () use ($forwarderRef) {
            $parts = explode('/', $forwarderRef, 3);

            if (count($parts) !== 3) {
                return ProviderResult::failed(
                    errorCode: Err::NOT_FOUND,
                    errorSummary: 'That forwarding reference is not recognised.',
                    retryClassification: 'permanent',
                );
            }

            [$domain, $mailbox, $address] = $parts;

            $response = $this->client->delete(Req::forwarding($domain, $mailbox, $address));

            if ($response['status'] === 404) {
                return ProviderResult::verified(
                    normalizedState: 'deleted',
                    providerResourceId: $forwarderRef,
                    correlationId: $response['correlation_id'],
                );
            }

            if (! in_array($response['status'], [200, 202, 204], true)) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'deleting',
                providerResourceId: $forwarderRef,
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function configureCatchAll(string $domain, CatchAllSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::CATCHALL_CONFIGURE, function () use ($domain, $spec) {
            $response = $this->client->post(Req::rewrites($domain), Req::createCatchAll($spec));

            if (! in_array($response['status'], [200, 201], true)) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'provisioning',
                providerResourceId: $domain . '/' . Req::CATCHALL_SLUG,
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function clearCatchAll(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate(Cap::CATCHALL_CLEAR, function () use ($domain) {
            // Deletes OUR rewrite by its stable slug. A customer's own pattern
            // rules are not ours to remove, and a wildcard search would find them.
            $response = $this->client->delete(Req::rewrite($domain, Req::CATCHALL_SLUG));

            if ($response['status'] === 404) {
                return ProviderResult::verified(
                    normalizedState: 'disabled',
                    providerResourceId: $domain,
                    correlationId: $response['correlation_id'],
                );
            }

            if (! in_array($response['status'], [200, 202, 204], true)) {
                return $this->failure($response, mutating: true);
            }

            return ProviderResult::accepted(
                normalizedState: 'disabling',
                providerResourceId: $domain,
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function getCatchAll(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->read('catchall_status', function () use ($domain) {
            $response = $this->client->get(Req::rewrites($domain));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $catchAll = Map::toCatchAll($domain, Map::rows($response['body'], 'rewrites'));

            return ProviderResult::verified(
                normalizedState: $catchAll->enabled === true ? 'enabled' : 'disabled',
                providerResourceId: $domain,
                data: ['catch_all' => $catchAll],
                correlationId: $response['correlation_id'],
            );
        });
    }

    // ═══ OBSERVATION ═════════════════════════════════════════════════════════

    public function getUsage(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->read('usage', function () use ($domain) {
            $response = $this->client->get(Req::domainUsage($domain));

            if ($response['status'] !== 200) {
                return $this->failure($response, mutating: false);
            }

            $mailboxes = $this->client->get(Req::mailboxes($domain));
            $localParts = [];

            if ($mailboxes['status'] === 200) {
                foreach (Map::rows($mailboxes['body'], 'mailboxes') as $row) {
                    $localParts[] = (string) ($row['local_part'] ?? '');
                }
            }

            $samples = Map::toUsageSamples($response['body'], array_filter($localParts), new DateTimeImmutable());

            return ProviderResult::verified(
                normalizedState: 'observed',
                providerResourceId: $domain,
                data: [
                    'domain_usage'   => Map::toDomainUsage($response['body']),
                    'samples'        => $samples,
                    // Said plainly so nothing downstream mistakes null for zero.
                    'per_mailbox_storage_available' => false,
                ],
                correlationId: $response['correlation_id'],
            );
        });
    }

    public function getInventory(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->read('inventory', function () use ($domain) {
            $mailboxResponse = $this->client->get(Req::mailboxes($domain));

            if ($mailboxResponse['status'] !== 200) {
                return $this->failure($mailboxResponse, mutating: false);
            }

            $mailboxRows = Map::rows($mailboxResponse['body'], 'mailboxes');
            $mailboxes = array_map(fn (array $row) => Map::toMailbox($domain, $row), $mailboxRows);

            $aliasResponse = $this->client->get(Req::aliases($domain));

            if ($aliasResponse['status'] !== 200) {
                return $this->failure($aliasResponse, mutating: false);
            }

            $aliases = array_map(
                fn (array $row) => Map::toAlias($domain, $row),
                Map::rows($aliasResponse['body'], 'aliases')
            );

            $rewriteResponse = $this->client->get(Req::rewrites($domain));
            $catchAll = $rewriteResponse['status'] === 200
                ? Map::toCatchAll($domain, Map::rows($rewriteResponse['body'], 'rewrites'))
                : \App\Connectors\Infrastructure\BusinessEmail\Values\RemoteCatchAll::unknown();

            // CORRECTED IN E6.
            //
            // E5 fetched forwardings one mailbox at a time, because the published
            // schema showed them only as a nested endpoint. The live mailbox row
            // carries them INLINE, so the whole fan-out — and the truncation risk
            // that came with bounding it — disappears for any response that
            // embeds them. The per-mailbox path is kept for the rows that do not.
            $forwarders = [];
            $needFetch = [];

            foreach ($mailboxRows as $row) {
                $localPart = (string) ($row['local_part'] ?? '');

                if ($localPart === '') {
                    continue;
                }

                if (Map::hasEmbeddedForwardings($row)) {
                    foreach (Map::embeddedForwarders($domain, $row) as $forwarder) {
                        $forwarders[] = $forwarder;
                    }

                    continue;
                }

                $needFetch[] = $localPart;
            }

            // Only whatever was not embedded, and still bounded — an inventory
            // that believes an incomplete list is complete deletes things that
            // were merely not fetched.
            $truncated = count($needFetch) > self::MAX_FORWARDER_FANOUT;

            foreach (array_slice($needFetch, 0, self::MAX_FORWARDER_FANOUT) as $localPart) {
                $forwardingResponse = $this->client->get(Req::forwardings($domain, $localPart));

                if ($forwardingResponse['status'] !== 200) {
                    $truncated = true;
                    continue;
                }

                foreach (Map::rows($forwardingResponse['body'], 'forwardings') as $forwardingRow) {
                    $forwarders[] = Map::toForwarder($domain, $localPart, $forwardingRow);
                }
            }

            $observedAt = new DateTimeImmutable();

            $inventory = $truncated
                ? ProviderInventory::partial(
                    $domain,
                    $observedAt,
                    'Forwarding inventory is incomplete for this domain.',
                    $mailboxes,
                    $aliases,
                    $forwarders,
                    $catchAll,
                )
                : new ProviderInventory(
                    domain: $domain,
                    observedAt: $observedAt,
                    mailboxes: $mailboxes,
                    aliases: $aliases,
                    forwarders: $forwarders,
                    catchAll: $catchAll,
                );

            return ProviderResult::verified(
                normalizedState: $truncated ? 'partial' : 'observed',
                providerResourceId: $domain,
                data: ['inventory' => $inventory],
                correlationId: $mailboxResponse['correlation_id'],
            );
        });
    }

    // ═══ THE GATES ═══════════════════════════════════════════════════════════

    /**
     * Every mutating method goes through here, and during E5 every one of them
     * stops here. This is layered control #5 from the E5 brief: even with a
     * credential installed, a provider connection enabled and a workspace
     * assigned, nothing can be created, changed or destroyed until mutations are
     * separately switched on. One flag must never be enough.
     */
    private function mutate(string $capability, callable $call): ProviderResult
    {
        if (! $this->supports($capability)) {
            return Err::unsupported(
                $capability,
                'That action is not available for this email service.'
            );
        }

        if (! $this->mutationsEnabled) {
            return ProviderResult::failed(
                errorCode: 'provider_mutations_disabled',
                errorSummary: 'Changes to the email service are disabled in this environment.',
                retryClassification: 'permanent',
                normalizedState: 'blocked',
            );
        }

        return $this->guard($call, mutating: true);
    }

    private function read(string $label, callable $call): ProviderResult
    {
        return $this->guard($call, mutating: false);
    }

    /**
     * The single place a provider exception becomes a ProviderResult.
     *
     * Nothing provider-shaped escapes past this point, which is what allows the
     * engine to stay ignorant of who the vendor is.
     */
    private function guard(callable $call, bool $mutating): ProviderResult
    {
        try {
            return $call();
        } catch (MigaduTransportException $e) {
            [$code, $retry, $summary] = Err::classifyTransport($e, $mutating);

            return Err::toResult($code, $retry, $summary, $this->client->lastCorrelationId());
        } catch (\InvalidArgumentException $e) {
            return ProviderResult::failed(
                errorCode: Err::VALIDATION_FAILED,
                errorSummary: 'That request could not be prepared for the email service.',
                retryClassification: 'permanent',
            );
        } catch (\Throwable $e) {
            // Unknown plus mutating is ambiguous, never a safe retry.
            return Err::toResult(
                $mutating ? Err::INDETERMINATE : Err::UNKNOWN,
                $mutating ? 'ambiguous' : 'retryable',
                'The email service request did not complete.',
                $this->client->lastCorrelationId(),
            );
        }
    }

    private function failure(array $response, bool $mutating): ProviderResult
    {
        [$code, $retry, $summary] = Err::classifyStatus($response['status'], $response['body'], $mutating);

        $result = Err::toResult($code, $retry, $summary, $response['correlation_id'] ?? null);

        return $result;
    }

    // ═══ HELPERS ═════════════════════════════════════════════════════════════

    private function isSameDomain(string $address, string $domain): bool
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            return false;
        }

        return strtolower(substr($address, $at + 1)) === strtolower($domain);
    }

    /** @return array<string,bool> */
    private function diagnosticChecks(array $body): array
    {
        $checks = [];

        foreach (['mx', 'spf', 'dkim', 'dmarc', 'verified', 'ownership'] as $key) {
            if (array_key_exists($key, $body)) {
                $checks[$key] = (bool) $body[$key];
            }
        }

        // Some responses nest the detail. One level is worth following; more
        // would be guessing at a schema the provider has not published.
        foreach (['checks', 'diagnostics'] as $wrapper) {
            if (! isset($body[$wrapper]) || ! is_array($body[$wrapper])) {
                continue;
            }

            foreach ($body[$wrapper] as $key => $value) {
                if (is_scalar($value)) {
                    $checks[(string) $key] = (bool) $value;
                }
            }
        }

        return $checks;
    }

    private function rateLimit(array $headers): array
    {
        return [
            'retry_after_seconds' => MigaduClient::retryAfterSeconds($headers),
            // The provider publishes no rate-limit documentation. Saying so is
            // more useful than reporting a limit we inferred from one response.
            'documented'          => false,
        ];
    }
}
