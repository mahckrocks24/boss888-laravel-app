<?php

namespace Tests\Fakes\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapabilitySet;
use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxUsageSample;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteAlias;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteCatchAll;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteForwarder;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteMailbox;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use RuntimeException;

/**
 * INFRA888 · E2 — DETERMINISTIC FAKE EMAIL PROVIDER.
 *
 * 🔴 THIS IS NOT A PROVIDER AND MUST NEVER BE MISTAKEN FOR ONE.
 *
 * It exists to prove that the Business Email engine is genuinely
 * provider-agnostic — that every capability, lifecycle rule, idempotency
 * guarantee and failure classification holds without any vendor account
 * existing. The masterplan puts this before the first real adapter on purpose:
 * an abstraction proven by construction is worth more than one asserted in a
 * document.
 *
 * FIVE STRUCTURAL SAFEGUARDS AGAINST IT REACHING PRODUCTION
 *
 *  1. It lives under `Tests\Fakes\` — autoloaded only by `autoload-dev`, so it
 *     does not exist in a production autoloader at all.
 *  2. `provider()` returns a value prefixed `fake-`, which cannot collide with
 *     a real provider key and is visible in every persisted row it touches.
 *  3. It refuses to construct when the application environment is production.
 *     Not a log line — a thrown exception.
 *  4. It is never registered in `config/infrastructure.php`. Tests inject it
 *     through `InfrastructureConnectorResolver::fake()`, the existing seam. A
 *     fake reachable from configuration is one environment variable away from
 *     being reachable in production.
 *  5. A guard test asserts 2, 3 and 4 continue to hold.
 *
 * DETERMINISM IS THE WHOLE POINT. No randomness anywhere. Provider references
 * are derived by hash from the idempotency key, so the same request always
 * yields the same identifiers and a test can assert on them.
 *
 * IT REACHES NOTHING. No HTTP, no DNS, no SMTP, no IMAP, no filesystem, no
 * process execution, no queue dispatch. Its entire world is the in-memory
 * arrays below. A guard test asserts this by source inspection.
 */
final class FakeEmailProvider implements EmailProviderConnector
{
    // ── configurable outcomes ────────────────────────────────────────────────

    /** The provider did it AND we read it back. */
    public const OUTCOME_VERIFIED = 'verified';
    /** The provider acknowledged. Nothing is confirmed yet. */
    public const OUTCOME_ACCEPTED = 'accepted';
    /** Transient. Safe to try again. */
    public const OUTCOME_RETRYABLE = 'retryable_failure';
    /** The request itself is wrong. Retrying identically never works. */
    public const OUTCOME_PERMANENT = 'permanent_failure';
    /**
     * The dangerous one. The mutation DID happen provider-side, but the
     * response was lost. Neither success nor failure; only a read-back can say.
     */
    public const OUTCOME_AMBIGUOUS = 'ambiguous';
    /** Ambiguity's most common cause, reported distinctly for operators. */
    public const OUTCOME_TIMEOUT = 'timeout';
    /**
     * The provider claimed success but returned nothing usable — no reference,
     * or a payload that does not match the contract's declared shape.
     */
    public const OUTCOME_MALFORMED = 'malformed';

    public static function outcomes(): array
    {
        return [
            self::OUTCOME_VERIFIED, self::OUTCOME_ACCEPTED, self::OUTCOME_RETRYABLE,
            self::OUTCOME_PERMANENT, self::OUTCOME_AMBIGUOUS, self::OUTCOME_TIMEOUT,
            self::OUTCOME_MALFORMED,
        ];
    }

    // ── recorded activity, for assertions ────────────────────────────────────

    /** @var array<int,array{method:string,args:array,idempotency_key:?string,outcome:string}> */
    public array $calls = [];

    /** @var array<int,string> every idempotency key this fake was handed, in order */
    public array $idempotencyKeys = [];

    /** @var array<string,int> method => times called */
    public array $callCounts = [];

    // ── in-memory provider state ─────────────────────────────────────────────

    /** @var array<string,RemoteMailbox> providerRef => mailbox */
    private array $mailboxes = [];

    /** @var array<string,bool> refs whose owner has not accepted an invitation */
    private array $awaitingActivation = [];

    /** @var array<string,bool> refs whose password has been relayed */
    private array $passwordSet = [];
    /** @var array<string,RemoteAlias> */
    private array $aliases = [];
    /** @var array<string,RemoteForwarder> */
    private array $forwarders = [];
    /** @var array<string,RemoteCatchAll> domain => catch-all */
    private array $catchAlls = [];
    /** @var array<string,bool> domain => onboarded */
    private array $domains = [];
    /** @var array<string,bool> domain => authentication verified */
    private array $verifiedDomains = [];

    /**
     * Mutations waiting to become visible to reads. Models replication lag: the
     * provider accepted the change and a read-back a moment later still shows
     * the old world. Applied by applyPendingWrites().
     *
     * @var array<int,callable>
     */
    private array $pendingWrites = [];

    // ── configuration ────────────────────────────────────────────────────────

    private string $defaultOutcome = self::OUTCOME_VERIFIED;
    /** @var array<string,string> method => outcome */
    private array $methodOutcomes = [];
    /** @var array<string,array<int,string>> method => queued one-shot outcomes */
    private array $queuedOutcomes = [];
    private bool $staleReadBack = false;
    private bool $partialInventory = false;
    private string $partialInventoryReason = '';
    private EmailProviderCapabilitySet $capabilities;
    /** @var array<string,ProviderResult> idempotency key => the result already returned */
    private array $idempotentResults = [];

    public function __construct(?EmailProviderCapabilitySet $capabilities = null)
    {
        // Safeguard 3. Deliberately an exception, not a warning.
        if (function_exists('app') && app()->environment('production')) {
            throw new RuntimeException(
                'FakeEmailProvider must never be constructed in production. It simulates a mail provider and '
                . 'would report mailboxes as created that do not exist.'
            );
        }

        $this->capabilities = $capabilities ?? EmailProviderCapabilitySet::full();
    }

    public static function make(?EmailProviderCapabilitySet $capabilities = null): self
    {
        return new self($capabilities);
    }

    // ── fluent configuration ─────────────────────────────────────────────────

    public function alwaysVerified(): self
    {
        $this->defaultOutcome = self::OUTCOME_VERIFIED;

        return $this;
    }

    public function alwaysAccepted(): self
    {
        $this->defaultOutcome = self::OUTCOME_ACCEPTED;

        return $this;
    }

    /** Fix the outcome for one method for the rest of this fake's life. */
    public function willReturn(string $method, string $outcome): self
    {
        $this->assertOutcome($outcome);
        $this->methodOutcomes[$method] = $outcome;

        return $this;
    }

    /**
     * Queue a ONE-SHOT outcome. Consumed by the next call to that method, after
     * which the fake reverts to its standing configuration. This is how a test
     * expresses "the first attempt timed out, the retry succeeded".
     */
    public function willReturnOnce(string $method, string $outcome): self
    {
        $this->assertOutcome($outcome);
        $this->queuedOutcomes[$method][] = $outcome;

        return $this;
    }

    /** Reads lag behind writes until applyPendingWrites() is called. */
    public function withStaleReadBack(): self
    {
        $this->staleReadBack = true;

        return $this;
    }

    /** Make every deferred write visible. Models propagation finally completing. */
    public function applyPendingWrites(): self
    {
        foreach ($this->pendingWrites as $write) {
            $write();
        }

        $this->pendingWrites = [];

        return $this;
    }

    /** The provider cannot enumerate exhaustively — see ProviderInventory::$complete. */
    public function withPartialInventory(string $reason = 'enumeration truncated by provider rate limit'): self
    {
        $this->partialInventory = true;
        $this->partialInventoryReason = $reason;

        return $this;
    }

    public function withCapabilities(EmailProviderCapabilitySet $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    public function withoutCapability(string ...$capabilities): self
    {
        $this->capabilities = $this->capabilities->withOut(...$capabilities);

        return $this;
    }

    // ── seeding provider-side state that we did not create ───────────────────

    /**
     * Put an object at the provider that INFRA888 has no record of. This is how
     * "unexpected mailbox" drift is created: someone made it in the provider's
     * own console.
     */
    public function seedMailbox(string $localPart, ?int $quotaMb = 1024, bool $suspended = false, ?string $ref = null): string
    {
        $ref = $ref ?? 'fake-mbx-' . substr(hash('sha256', $localPart), 0, 16);
        $this->mailboxes[$ref] = new RemoteMailbox($ref, $localPart, null, $quotaMb, $suspended);

        return $ref;
    }

    public function seedAlias(string $sourceLocalPart, string $targetAddress, ?string $ref = null): string
    {
        $ref = $ref ?? 'fake-als-' . substr(hash('sha256', $sourceLocalPart), 0, 16);
        $this->aliases[$ref] = new RemoteAlias($ref, $sourceLocalPart, $targetAddress);

        return $ref;
    }

    public function seedForwarder(string $sourceLocalPart, string $destination, ?string $ref = null): string
    {
        $ref = $ref ?? 'fake-fwd-' . substr(hash('sha256', $sourceLocalPart . $destination), 0, 16);
        $this->forwarders[$ref] = new RemoteForwarder($ref, $sourceLocalPart, $destination);

        return $ref;
    }

    /** Remove an object provider-side without telling INFRA888 — creates "missing" drift. */
    public function dropMailbox(string $ref): self
    {
        unset($this->mailboxes[$ref]);

        return $this;
    }

    public function dropAlias(string $ref): self
    {
        unset($this->aliases[$ref]);

        return $this;
    }

    public function dropForwarder(string $ref): self
    {
        unset($this->forwarders[$ref]);

        return $this;
    }

    /** Change a mailbox provider-side — creates quota or suspension drift. */
    public function mutateMailbox(string $ref, ?int $quotaMb = null, ?bool $suspended = null): self
    {
        $existing = $this->mailboxes[$ref] ?? null;

        if ($existing === null) {
            throw new RuntimeException("FakeEmailProvider: no seeded mailbox '{$ref}' to mutate.");
        }

        $this->mailboxes[$ref] = new RemoteMailbox(
            $ref,
            $existing->localPart,
            $existing->displayName,
            $quotaMb ?? $existing->quotaMb,
            $suspended ?? $existing->suspended,
            $existing->storageUsedMb,
        );

        return $this;
    }

    /** Force two objects to share one provider reference — a provider defect. */
    public function seedDuplicateRef(string $ref, string $localPartA, string $localPartB): self
    {
        $this->mailboxes[$ref] = new RemoteMailbox($ref, $localPartA);
        $this->aliases[$ref] = new RemoteAlias($ref, $localPartB, 'somewhere@example.test');

        return $this;
    }

    // ── InfrastructureConnector ──────────────────────────────────────────────

    public function provider(): string
    {
        // Safeguard 2: visible in every infra_operations and
        // infra_provider_resources row this fake ever touches.
        return 'fake-email';
    }

    public function capability(): string
    {
        return InfraProviderConnection::CAPABILITY_EMAIL;
    }

    public function healthCheck(): ProviderResult
    {
        $this->record('healthCheck', [], null, self::OUTCOME_VERIFIED);

        return ProviderResult::verified('healthy', null, 'fake', ['simulated' => true]);
    }

    public function synchronize(string $providerResourceId): ProviderResult
    {
        $this->record('synchronize', [$providerResourceId], null, self::OUTCOME_VERIFIED);

        $mailbox = $this->visibleMailboxes()[$providerResourceId] ?? null;

        return $mailbox === null
            ? ProviderResult::failed('provider_object_missing', 'That mailbox no longer exists.', 'permanent')
            : ProviderResult::verified('active', $providerResourceId, 'fake', ['mailbox' => $mailbox]);
    }

    public function verify(string $providerResourceId, array $expectation = []): ProviderResult
    {
        $this->record('verify', [$providerResourceId, $expectation], null, self::OUTCOME_VERIFIED);

        $exists = isset($this->visibleMailboxes()[$providerResourceId])
            || isset($this->visibleAliases()[$providerResourceId])
            || isset($this->visibleForwarders()[$providerResourceId]);

        return $exists
            ? ProviderResult::verified('active', $providerResourceId, 'fake')
            : ProviderResult::failed('provider_object_missing', 'That object does not exist at the provider.', 'permanent');
    }

    // ── capability discovery ─────────────────────────────────────────────────

    public function capabilitySet(): EmailProviderCapabilitySet
    {
        return $this->capabilities;
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities->supports($capability);
    }

    // ── domain ───────────────────────────────────────────────────────────────

    public function getDomainAuthStatus(string $domain, ProviderCallContext $context): ProviderResult
    {
        $this->record('getDomainAuthStatus', [$domain], $context->idempotencyKey, self::OUTCOME_VERIFIED);

        $verified = $this->verifiedDomains[strtolower($domain)] ?? false;

        return ProviderResult::verified('active', null, 'fake', [
            'active' => isset($this->domains[strtolower($domain)]),
            'mx'     => $verified,
            'spf'    => $verified,
            'dkim'   => $verified,
            'dmarc'  => false,
        ]);
    }

    public function getDnsRequirements(string $domain, ProviderCallContext $context): ProviderResult
    {
        $this->record('getDnsRequirements', [$domain], $context->idempotencyKey, self::OUTCOME_VERIFIED);

        // Deliberately shaped like a real provider's answer: the VALUES contain
        // provider hostnames, because that is the one thing no abstraction can
        // hide. Everything around them is our vocabulary.
        return ProviderResult::verified('active', null, 'fake', [
            'records' => [
                new MailDnsRecord(MailDnsRecord::PURPOSE_MX, 'MX', '@', 'inbound.fake-mail.test', 10, 3600),
                new MailDnsRecord(MailDnsRecord::PURPOSE_SPF, 'TXT', '@', 'v=spf1 include:spf.fake-mail.test -all', null, 3600),
                new MailDnsRecord(MailDnsRecord::PURPOSE_DKIM, 'TXT', 'sel1._domainkey', 'v=DKIM1; k=rsa; p=FAKEKEY', null, 3600),
                new MailDnsRecord(MailDnsRecord::PURPOSE_DMARC, 'TXT', '_dmarc', 'v=DMARC1; p=none', null, 3600, false),
            ],
        ]);
    }

    public function onboardDomain(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('onboardDomain', [$domain], $context, function () use ($domain) {
            $this->domains[strtolower($domain)] = true;

            return 'fake-dom-' . substr(hash('sha256', strtolower($domain)), 0, 16);
        });
    }

    public function verifyDomain(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('verifyDomain', [$domain], $context, function () use ($domain) {
            $this->verifiedDomains[strtolower($domain)] = true;

            return 'fake-dom-' . substr(hash('sha256', strtolower($domain)), 0, 16);
        });
    }

    // ── mailbox ──────────────────────────────────────────────────────────────

    /**
     * INFRA888 · E7. A mailbox created with an invitation EXISTS but cannot be
     * used until its owner sets a password. The fake models that, because a
     * fake that reports a usable mailbox where the real provider reports an
     * unclaimed one teaches the engine the wrong lesson.
     */
    public function createMailbox(
        string $domain,
        MailboxSpec $spec,
        ProviderCallContext $context,
        ?\App\Connectors\Infrastructure\BusinessEmail\SecretString $password = null
    ): ProviderResult {
        return $this->mutate('createMailbox', [$domain, $spec->toArray()], $context, function () use ($spec, $context, $password) {
            // Read once and discard, exactly as the real adapter does, so a test
            // that tries to replay a secret fails here rather than in production.
            $password?->reveal();
            $ref = 'fake-mbx-' . substr(hash('sha256', $context->idempotencyKey), 0, 16);

            $this->write(function () use ($ref, $spec) {
                $this->mailboxes[$ref] = new RemoteMailbox(
                    $ref, $spec->localPart, $spec->displayName, $spec->quotaMb, false, 0
                );

                if ($spec->usesInvitation()) {
                    $this->awaitingActivation[$ref] = true;
                }
            });

            return $ref;
        });
    }

    /**
     * The owner accepted their invitation. Test-only: there is no API for this,
     * because in reality a person clicks a link in an email.
     */
    public function completeActivation(string $mailboxRef): void
    {
        unset($this->awaitingActivation[$mailboxRef]);
    }

    public function isAwaitingActivation(string $mailboxRef): bool
    {
        return isset($this->awaitingActivation[$mailboxRef]);
    }

    public function updateMailbox(string $mailboxRef, MailboxSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('updateMailbox', [$mailboxRef, $spec->toArray()], $context, function () use ($mailboxRef, $spec) {
            $existing = $this->mailboxes[$mailboxRef] ?? null;

            if ($existing === null) {
                return null;
            }

            $this->write(function () use ($mailboxRef, $existing, $spec) {
                $this->mailboxes[$mailboxRef] = new RemoteMailbox(
                    $mailboxRef,
                    $existing->localPart,
                    $spec->changesDisplayName() ? $spec->displayName : $existing->displayName,
                    $spec->changesQuota() ? $spec->quotaMb : $existing->quotaMb,
                    $existing->suspended,
                    $existing->storageUsedMb,
                );
            });

            return $mailboxRef;
        });
    }

    public function suspendMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->setSuspension('suspendMailbox', $mailboxRef, true, $context);
    }

    public function restoreMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->setSuspension('restoreMailbox', $mailboxRef, false, $context);
    }

    public function deleteMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('deleteMailbox', [$mailboxRef], $context, function () use ($mailboxRef) {
            if (! isset($this->mailboxes[$mailboxRef])) {
                return null;
            }

            $this->write(function () use ($mailboxRef) {
                unset($this->mailboxes[$mailboxRef]);
            });

            return $mailboxRef;
        });
    }

    /**
     * INFRA888 · E7.3. The fake accepts a relayed password and records only
     * THAT one was set — never the value. It asserts the secret is single-use
     * by revealing it exactly once, so a test that tries to replay a password
     * fails here rather than in production.
     */
    public function setMailboxPassword(
        string $mailboxRef,
        \App\Connectors\Infrastructure\BusinessEmail\SecretString $password,
        ProviderCallContext $context
    ): ProviderResult {
        return $this->mutate('setMailboxPassword', [$mailboxRef], $context, function () use ($mailboxRef, $password) {
            if (! isset($this->mailboxes[$mailboxRef])) {
                return null;
            }

            // Read once and discard. Nothing about the value is retained.
            $password->reveal();

            $this->write(function () use ($mailboxRef) {
                $this->passwordSet[$mailboxRef] = true;
                unset($this->awaitingActivation[$mailboxRef]);
            });

            return $mailboxRef;
        });
    }

    /** Test assertion helper: was a password ever relayed for this mailbox? */
    public function passwordWasSet(string $mailboxRef): bool
    {
        return isset($this->passwordSet[$mailboxRef]);
    }

    public function requestPasswordReset(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('requestPasswordReset', [$mailboxRef], $context, function () use ($mailboxRef) {
            // Note what is NOT returned: no password, no temporary password, no
            // reset token, no reset URL. The contract forbids it because this
            // payload is persisted to infra_operations.
            return isset($this->mailboxes[$mailboxRef]) ? $mailboxRef : null;
        });
    }

    public function getMailboxStatus(string $mailboxRef, ProviderCallContext $context): ProviderResult
    {
        $this->record('getMailboxStatus', [$mailboxRef], $context->idempotencyKey, self::OUTCOME_VERIFIED);

        $mailbox = $this->visibleMailboxes()[$mailboxRef] ?? null;

        if ($mailbox === null) {
            return ProviderResult::failed(
                'provider_object_missing',
                'That mailbox does not exist at the provider.',
                'permanent'
            );
        }

        // Three outcomes, not two — matching what the real provider returns.
        $state = match (true) {
            isset($this->awaitingActivation[$mailboxRef]) => 'awaiting_activation',
            $mailbox->suspended === true                  => 'suspended',
            default                                       => 'active',
        };

        return ProviderResult::verified(
            $state,
            $mailboxRef,
            'fake',
            ['mailbox' => $mailbox]
        );
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function createAlias(string $domain, AliasSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('createAlias', [$domain, $spec->toArray()], $context, function () use ($spec, $context) {
            $ref = 'fake-als-' . substr(hash('sha256', $context->idempotencyKey), 0, 16);

            $this->write(function () use ($ref, $spec) {
                $this->aliases[$ref] = new RemoteAlias($ref, $spec->sourceLocalPart, $spec->targetAddress);
            });

            return $ref;
        });
    }

    public function deleteAlias(string $aliasRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('deleteAlias', [$aliasRef], $context, function () use ($aliasRef) {
            if (! isset($this->aliases[$aliasRef])) {
                return null;
            }

            $this->write(function () use ($aliasRef) {
                unset($this->aliases[$aliasRef]);
            });

            return $aliasRef;
        });
    }

    public function createForwarder(string $domain, ForwarderSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('createForwarder', [$domain, $spec->toArray()], $context, function () use ($spec, $context) {
            $ref = 'fake-fwd-' . substr(hash('sha256', $context->idempotencyKey), 0, 16);

            $this->write(function () use ($ref, $spec) {
                $this->forwarders[$ref] = new RemoteForwarder(
                    $ref, $spec->sourceLocalPart, $spec->destinationAddress
                );
            });

            return $ref;
        });
    }

    public function deleteForwarder(string $forwarderRef, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('deleteForwarder', [$forwarderRef], $context, function () use ($forwarderRef) {
            if (! isset($this->forwarders[$forwarderRef])) {
                return null;
            }

            $this->write(function () use ($forwarderRef) {
                unset($this->forwarders[$forwarderRef]);
            });

            return $forwarderRef;
        });
    }

    public function configureCatchAll(string $domain, CatchAllSpec $spec, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('configureCatchAll', [$domain, $spec->toArray()], $context, function () use ($domain, $spec) {
            $ref = 'fake-cat-' . substr(hash('sha256', strtolower($domain)), 0, 16);

            $this->write(function () use ($domain, $spec, $ref) {
                $this->catchAlls[strtolower($domain)] = new RemoteCatchAll(true, $spec->targetAddress, $ref);
            });

            return $ref;
        });
    }

    public function clearCatchAll(string $domain, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate('clearCatchAll', [$domain], $context, function () use ($domain) {
            $this->write(function () use ($domain) {
                $this->catchAlls[strtolower($domain)] = RemoteCatchAll::disabled();
            });

            return 'fake-cat-' . substr(hash('sha256', strtolower($domain)), 0, 16);
        });
    }

    public function getCatchAll(string $domain, ProviderCallContext $context): ProviderResult
    {
        $this->record('getCatchAll', [$domain], $context->idempotencyKey, self::OUTCOME_VERIFIED);

        if (! $this->supports(EmailProviderCapability::CATCHALL_CONFIGURE)) {
            // A provider that cannot do catch-all cannot report on it either,
            // and `unknown` is the honest answer rather than `disabled`.
            return ProviderResult::verified('active', null, 'fake', ['catchall' => RemoteCatchAll::unknown()]);
        }

        return ProviderResult::verified('active', null, 'fake', [
            'catchall' => $this->visibleCatchAlls()[strtolower($domain)] ?? RemoteCatchAll::disabled(),
        ]);
    }

    // ── observation ──────────────────────────────────────────────────────────

    public function getUsage(string $domain, ProviderCallContext $context): ProviderResult
    {
        $this->record('getUsage', [$domain], $context->idempotencyKey, self::OUTCOME_VERIFIED);

        $samples = [];

        foreach ($this->visibleMailboxes() as $mailbox) {
            $samples[] = new MailboxUsageSample(
                localPart: $mailbox->localPart,
                observedAt: now(),
                storageUsedMb: $mailbox->storageUsedMb,
                storageQuotaMb: $mailbox->quotaMb,
                // Deliberately null: this fake models a provider that does not
                // report message counters, so the engine's "null is not zero"
                // rule is exercised rather than assumed.
                messagesSent: null,
                messagesReceived: null,
                lastLoginAt: $this->supports(EmailProviderCapability::LAST_LOGIN_READ) ? now() : null,
            );
        }

        return ProviderResult::verified('active', null, 'fake', ['samples' => $samples]);
    }

    public function getInventory(string $domain, ProviderCallContext $context): ProviderResult
    {
        $outcome = $this->outcomeFor('getInventory');
        $this->record('getInventory', [$domain], $context->idempotencyKey, $outcome);

        // Reads fail too. A reconciliation pass against an unreachable provider
        // must be able to happen in a test, or the "never conclude from a
        // failed read" rule is never exercised.
        if (in_array($outcome, [self::OUTCOME_PERMANENT, self::OUTCOME_RETRYABLE], true)) {
            return ProviderResult::failed(
                'provider_unavailable',
                'The service could not be reached.',
                $outcome === self::OUTCOME_RETRYABLE ? 'retryable' : 'permanent',
            );
        }

        if (! $this->supports(EmailProviderCapability::INVENTORY_LIST)) {
            return ProviderResult::failed(
                'capability_not_supported',
                'This service cannot list what exists.',
                'permanent'
            );
        }

        $mailboxes = array_values($this->visibleMailboxes());
        $aliases = array_values($this->visibleAliases());
        $forwarders = array_values($this->visibleForwarders());
        $catchAll = $this->visibleCatchAlls()[strtolower($domain)] ?? RemoteCatchAll::disabled();

        $inventory = $this->partialInventory
            ? ProviderInventory::partial($domain, now(), $this->partialInventoryReason, $mailboxes, $aliases, $forwarders, $catchAll)
            : new ProviderInventory($domain, now(), $mailboxes, $aliases, $forwarders, $catchAll);

        return ProviderResult::verified('active', null, 'fake', ['inventory' => $inventory]);
    }

    // ── assertion helpers ────────────────────────────────────────────────────

    public function callCount(string $method): int
    {
        return $this->callCounts[$method] ?? 0;
    }

    public function wasCalled(string $method): bool
    {
        return $this->callCount($method) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['method'] === $method));
    }

    /** Every method invoked, in order. Proves the engine's call sequence. */
    public function methodSequence(): array
    {
        return array_map(fn (array $c) => $c['method'], $this->calls);
    }

    public function providerMailboxCount(): int
    {
        return count($this->visibleMailboxes());
    }

    public function hasMailboxRef(string $ref): bool
    {
        return isset($this->visibleMailboxes()[$ref]);
    }

    public function reset(): self
    {
        $this->calls = [];
        $this->idempotencyKeys = [];
        $this->callCounts = [];
        $this->idempotentResults = [];

        return $this;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * The shared mutation path: idempotency replay, outcome selection, and the
     * one behaviour that matters most — an AMBIGUOUS result still applies the
     * change, because that is what makes ambiguity dangerous. A fake that
     * skipped the write on ambiguity would let the engine's blind-retry bug
     * pass its tests.
     */
    private function mutate(string $method, array $args, ProviderCallContext $context, callable $apply): ProviderResult
    {
        $outcome = $this->outcomeFor($method);
        $this->record($method, $args, $context->idempotencyKey, $outcome);

        // A well-behaved provider returns the same answer to the same key and
        // does not act twice. The engine should normally prevent a second call
        // ever arriving; this makes the fake safe if it does not.
        $replayKey = $method . ':' . $context->idempotencyKey;

        if (isset($this->idempotentResults[$replayKey])) {
            return $this->idempotentResults[$replayKey];
        }

        $correlation = 'fake-corr-' . substr(hash('sha256', $context->idempotencyKey), 0, 12);

        $result = match ($outcome) {
            self::OUTCOME_RETRYABLE => ProviderResult::failed(
                'provider_unavailable',
                'The service is temporarily unavailable. Nothing has been changed.',
                'retryable',
                'failed',
                $correlation,
            ),

            self::OUTCOME_PERMANENT => ProviderResult::failed(
                'provider_rejected',
                'The service rejected this request.',
                'permanent',
                'failed',
                $correlation,
            ),

            // The mutation HAPPENS, then the answer is lost.
            self::OUTCOME_AMBIGUOUS, self::OUTCOME_TIMEOUT => $this->ambiguous($apply, $outcome, $correlation),

            // Claims success, returns nothing usable.
            self::OUTCOME_MALFORMED => $this->malformed($apply, $correlation),

            self::OUTCOME_ACCEPTED => $this->applied($apply, $correlation, verified: false),

            default => $this->applied($apply, $correlation, verified: true),
        };

        $this->idempotentResults[$replayKey] = $result;

        return $result;
    }

    private function applied(callable $apply, string $correlation, bool $verified): ProviderResult
    {
        $ref = $apply();

        if ($ref === null) {
            return ProviderResult::failed(
                'provider_object_missing',
                'That item does not exist at the service.',
                'permanent',
                'failed',
                $correlation,
            );
        }

        return $verified
            ? ProviderResult::verified('active', $ref, 'fake', [], $correlation)
            : ProviderResult::accepted('provisioning', $ref, 'fake', [], $correlation);
    }

    private function ambiguous(callable $apply, string $outcome, string $correlation): ProviderResult
    {
        // Applied on purpose. The provider did the work; only the answer was lost.
        $apply();

        return ProviderResult::failed(
            errorCode: $outcome === self::OUTCOME_TIMEOUT ? 'provider_timeout' : 'provider_indeterminate',
            errorSummary: 'We could not confirm whether this completed. It is being checked.',
            // NOT retryable. Re-issuing a mutation whose outcome is unknown is
            // how a mailbox gets created twice.
            retryClassification: 'manual',
            normalizedState: 'ambiguous',
            correlationId: $correlation,
        );
    }

    private function malformed(callable $apply, string $correlation): ProviderResult
    {
        $apply();

        // Success with no usable reference. The engine must treat this as a
        // failure to bind rather than storing an empty provider reference.
        return ProviderResult::accepted('provisioning', null, 'fake', ['unexpected' => true], $correlation);
    }

    private function setSuspension(string $method, string $mailboxRef, bool $suspended, ProviderCallContext $context): ProviderResult
    {
        return $this->mutate($method, [$mailboxRef, $suspended], $context, function () use ($mailboxRef, $suspended) {
            $existing = $this->mailboxes[$mailboxRef] ?? null;

            if ($existing === null) {
                return null;
            }

            $this->write(function () use ($mailboxRef, $existing, $suspended) {
                $this->mailboxes[$mailboxRef] = new RemoteMailbox(
                    $mailboxRef, $existing->localPart, $existing->displayName,
                    $existing->quotaMb, $suspended, $existing->storageUsedMb,
                );
            });

            return $mailboxRef;
        });
    }

    /** Apply now, or defer when the fake is simulating replication lag. */
    private function write(callable $write): void
    {
        if ($this->staleReadBack) {
            $this->pendingWrites[] = $write;

            return;
        }

        $write();
    }

    private function outcomeFor(string $method): string
    {
        if (! empty($this->queuedOutcomes[$method])) {
            return array_shift($this->queuedOutcomes[$method]);
        }

        return $this->methodOutcomes[$method] ?? $this->defaultOutcome;
    }

    private function record(string $method, array $args, ?string $idempotencyKey, string $outcome): void
    {
        $this->calls[] = [
            'method'          => $method,
            'args'            => $args,
            'idempotency_key' => $idempotencyKey,
            'outcome'         => $outcome,
        ];

        $this->callCounts[$method] = ($this->callCounts[$method] ?? 0) + 1;

        if ($idempotencyKey !== null) {
            $this->idempotencyKeys[] = $idempotencyKey;
        }
    }

    /** @return array<string,RemoteMailbox> */
    private function visibleMailboxes(): array
    {
        return $this->mailboxes;
    }

    /** @return array<string,RemoteAlias> */
    private function visibleAliases(): array
    {
        return $this->aliases;
    }

    /** @return array<string,RemoteForwarder> */
    private function visibleForwarders(): array
    {
        return $this->forwarders;
    }

    /** @return array<string,RemoteCatchAll> */
    private function visibleCatchAlls(): array
    {
        return $this->catchAlls;
    }

    private function assertOutcome(string $outcome): void
    {
        if (! in_array($outcome, self::outcomes(), true)) {
            throw new RuntimeException("FakeEmailProvider: unknown outcome '{$outcome}'.");
        }
    }
}
