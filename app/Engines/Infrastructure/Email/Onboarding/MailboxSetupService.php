<?php

namespace App\Engines\Infrastructure\Email\Onboarding;

use App\Connectors\Infrastructure\BusinessEmail\SecretString;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * INFRA888 · E7.3 — LevelUp's own mailbox onboarding, end to end.
 *
 * THE WHOLE POINT: the provider never speaks to the customer. LevelUp issues
 * the link, sends the branded email, hosts the page, takes the password, and
 * relays it once. The customer never learns which vendor stores their mail.
 *
 * THE PASSWORD PATH, IN FULL
 *   request body → SecretString → connector → HTTP → provider
 *
 * That is the entire journey. It is never assigned to a model, never placed in
 * an array that gets persisted, never dispatched to a queue, never written to
 * an operation record, and never logged. SecretString makes most of those
 * impossible rather than merely forbidden, and the tests prove the rest.
 */
final class MailboxSetupService
{
    /** Raw token: 32 bytes of CSPRNG, hex-encoded. */
    private const TOKEN_BYTES = 32;

    /**
     * Issue a setup link for a mailbox, superseding any live one.
     *
     * Returns the RAW token exactly once, for the caller to put in an email and
     * then forget. It is never returned again and never stored.
     *
     * @return array{token:string,record:MailboxSetupToken}
     */
    public function issue(
        EmailMailbox $mailbox,
        string $recipientEmail,
        string $purpose = MailboxSetupToken::PURPOSE_SETUP,
        ?int $issuedByUserId = null
    ): array {
        if (! in_array($purpose, MailboxSetupToken::purposes(), true)) {
            throw new RuntimeException('Unknown setup token purpose.');
        }

        $raw = bin2hex(random_bytes(self::TOKEN_BYTES));

        // Tokens are workspace-scoped, and INFRA888 fails closed without an
        // explicit context rather than quietly querying across tenants.
        $record = WorkspaceContext::run((int) $mailbox->workspace_id, fn () => DB::transaction(
            function () use ($mailbox, $recipientEmail, $purpose, $issuedByUserId, $raw) {
            // One live token per mailbox and purpose. The database enforces it
            // too — this is the courteous path, the unique index is the honest
            // one when two requests race.
            MailboxSetupToken::query()
                ->where('email_mailbox_id', $mailbox->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->update([
                    'superseded_at'     => now(),
                    'superseded_reason' => 'replaced_by_new_token',
                ]);

                return MailboxSetupToken::create([
                    'workspace_id'      => $mailbox->workspace_id,
                    'email_domain_id'   => $mailbox->email_domain_id,
                    'email_mailbox_id'  => $mailbox->id,
                    'token_hash'        => MailboxSetupToken::hash($raw),
                    'purpose'           => $purpose,
                    'recipient_email'   => $recipientEmail,
                    'expires_at'        => now()->addHours(MailboxSetupToken::TTL_HOURS),
                    'issued_by_user_id' => $issuedByUserId,
                    'send_count'        => 0,
                ]);
            }
        ));

        return ['token' => $raw, 'record' => $record];
    }

    /**
     * Resolve a raw token to its record, without leaking why it failed.
     *
     * Lookup is by hash, so an attacker with the database still cannot forge a
     * link, and a timing difference between "no such token" and "expired token"
     * tells them nothing they could enumerate.
     */
    public function resolve(#[\SensitiveParameter] string $rawToken, string $purpose = MailboxSetupToken::PURPOSE_SETUP): ?MailboxSetupToken
    {
        $rawToken = trim($rawToken);

        // Shape check first: a token is always 64 hex characters, so anything
        // else never reaches the database at all.
        if (! preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }

        // withoutGlobalScopes: the customer following this link is NOT
        // authenticated and has no workspace context. The token itself carries
        // the tenancy, which is exactly why it is bound to one.
        return MailboxSetupToken::withoutGlobalScopes()
            ->where('token_hash', MailboxSetupToken::hash($rawToken))
            ->where('purpose', $purpose)
            ->first();
    }

    /**
     * The mailbox a usable token points at, or null.
     *
     * Returns null for every failure mode: unknown token, expired, consumed,
     * superseded, mailbox gone, or mailbox in a state where setting a password
     * makes no sense. The caller shows one customer-safe message for all of
     * them — distinguishing them for a stranger is an enumeration oracle.
     */
    public function mailboxFor(MailboxSetupToken $token): ?EmailMailbox
    {
        if (! $token->isUsable()) {
            return null;
        }

        $mailbox = WorkspaceContext::run(
            (int) $token->workspace_id,
            fn () => EmailMailbox::find($token->email_mailbox_id)
        );

        if ($mailbox === null) {
            return null;
        }

        // A deleted or failed mailbox cannot be activated. A token that outlives
        // its mailbox is dead, not dangerous.
        if (in_array($mailbox->lifecycle_state, [
            EmailMailboxState::DELETED,
            EmailMailboxState::DELETING,
        ], true)) {
            return null;
        }

        return $mailbox;
    }

    /**
     * Relay the customer's chosen password to the provider — the only place in
     * the platform where a mailbox password is handled at all.
     *
     * SYNCHRONOUS BY DESIGN. Queueing it would mean serialising the password
     * into Redis and the failed_jobs table, which is precisely what the custody
     * rule forbids. If the provider is unreachable, the customer is told to try
     * again and the password is discarded — we do not hold it for a retry.
     *
     * @return array{ok:bool,state:string,reason:?string}
     */
    public function relay(
        MailboxSetupToken $token,
        EmailMailbox $mailbox,
        SecretString $password,
        ?int $actorUserId = null
    ): array {
        $connector = EmailProviderRegistry::connector();

        // INFRA888 · E7.4 — JUST IN TIME.
        //
        // The provider mailbox does not exist yet. It is created HERE, with the
        // password the customer has just chosen, because E7.3 proved that
        // creating with a password works and changing one afterwards does not
        // reliably take effect.
        //
        // A provider binding that already exists means this reservation has
        // been fulfilled, and creating again would produce a duplicate mailbox.
        if ($this->providerReferenceFor($mailbox) !== null) {
            return ['ok' => false, 'state' => 'already_provisioned', 'reason' => 'already_exists'];
        }

        $context = new ProviderCallContext(
            workspaceId: (int) $mailbox->workspace_id,
            ownerType: 'email_mailbox',
            ownerId: (int) $mailbox->id,
            // Stable per token: the same logical intent, so a duplicate submit
            // is the same operation rather than a second one.
            idempotencyKey: 'mbx-setup-' . $token->id,
            actorUserId: $actorUserId,
        );

        $domainName = (string) DB::table('email_domains')
            ->where('id', $mailbox->email_domain_id)
            ->value('domain');

        if ($domainName === '') {
            return ['ok' => false, 'state' => 'unavailable', 'reason' => 'no_domain'];
        }

        // The single read of the secret happens inside the connector, one line
        // before it is transmitted. Nothing here ever holds the plaintext.
        $result = $connector->createMailbox(
            $domainName,
            new MailboxSpec(
                localPart: (string) $mailbox->local_part,
                displayName: $mailbox->display_name !== null ? (string) $mailbox->display_name : null,
            ),
            $context,
            $password
        );

        $ref = $result->providerResourceId ?? ($domainName . '/' . $mailbox->local_part);

        if (! $result->success) {
            // AMBIGUITY IS NOT RESOLVED BY KEEPING THE PASSWORD.
            //
            // If the provider's answer was inconclusive the change may or may
            // not have landed. We discard the password regardless, leave the
            // token live so the customer can try again, and let an operator see
            // a mailbox that needs looking at. Retaining the secret "just in
            // case" would trade a rare inconvenience for a permanent custody
            // liability.
            Log::info('infra.email.setup_relay_failed', [
                'mailbox_id'    => $mailbox->id,
                'workspace_id'  => $mailbox->workspace_id,
                'error_code'    => $result->errorCode,
                'normalized'    => $result->normalizedState,
                // No password. No token. No address.
            ]);

            // AMBIGUOUS CREATE IS NEVER RETRIED, AND THE PASSWORD IS ALREADY
            // GONE. The mailbox may or may not exist at the provider, so the
            // only honest next step is a read-back — never a second create with
            // a password we would have had to keep in order to resend.
            if ($result->normalizedState === 'needs_reconciliation') {
                $this->markForReconciliation($mailbox);

                return ['ok' => false, 'state' => 'needs_reconciliation', 'reason' => 'inconclusive'];
            }

            return [
                'ok'     => false,
                'state'  => $result->normalizedState,
                'reason' => 'provider_refused',
            ];
        }

        // The binding is what stops a second submission creating a second
        // mailbox — it is checked at the top of this method and written here,
        // inside the same request that created the object.
        DB::table('infra_provider_resources')->updateOrInsert(
            ['owner_type' => 'email_mailbox', 'owner_id' => $mailbox->id],
            [
                'workspace_id'           => $mailbox->workspace_id,
                'provider'               => (string) EmailProviderRegistry::activeProviderKey(),
                'provider_resource_type' => 'mailbox',
                'provider_resource_id'   => $ref,
                'normalized_state'       => 'provisioning',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]
        );

        // Consumed the moment the provider accepts, so a replayed submit cannot
        // create a second mailbox.
        WorkspaceContext::run(
            (int) $token->workspace_id,
            fn () => $token->forceFill(['consumed_at' => now()])->save()
        );

        // RECORD WHAT WE JUST DID, BEFORE ASKING WHETHER IT WORKED.
        // The provider now holds a real mailbox. A row still reading
        // 'requested' is not a cautious record, it is a false one - and it was
        // the reason confirmActivation() below silently matched nothing.
        WorkspaceContext::run((int) $mailbox->workspace_id, function () use ($mailbox) {
            $fresh = EmailMailbox::find($mailbox->id);

            if ($fresh !== null && $fresh->lifecycle_state === EmailMailboxState::REQUESTED) {
                $fresh->transitionTo(EmailMailboxState::PROVISIONING);
                $fresh->forceFill([
                    // The customer's chosen password is now live at the provider.
                    // We store WHEN, never WHAT.
                    'password_set_at'  => now(),
                    'state_changed_at' => now(),
                ])->save();
            }
        });

        // ACTIVE ONLY ON EVIDENCE. The provider accepting the change is not the
        // same as the mailbox working, so we read it back.
        $confirmed = $this->confirmActivation($connector, $mailbox, $context, $ref);

        // Re-read. $mailbox is the instance we were handed at the top of the
        // request and no longer reflects the transitions above; reporting its
        // stale lifecycle_state is how a wrong answer reaches the customer.
        $persistedState = WorkspaceContext::run(
            (int) $mailbox->workspace_id,
            fn () => EmailMailbox::find($mailbox->id)?->lifecycle_state
        ) ?? $mailbox->lifecycle_state;

        return [
            'ok'     => true,
            'state'  => $persistedState,
            'reason' => $confirmed ? null : 'awaiting_confirmation',
        ];
    }

    /**
     * An inconclusive create leaves a reservation nobody can safely act on, so
     * it is handed to an operator rather than guessed at.
     */
    private function markForReconciliation(EmailMailbox $mailbox): void
    {
        WorkspaceContext::run((int) $mailbox->workspace_id, function () use ($mailbox) {
            $fresh = EmailMailbox::find($mailbox->id);

            if ($fresh !== null && $fresh->lifecycle_state !== EmailMailboxState::RECONCILING) {
                $fresh->transitionTo(EmailMailboxState::RECONCILING);
                $fresh->save();
            }
        });
    }

    /**
     * The opaque provider reference for a mailbox.
     *
     * E1 deliberately keeps provider identity OUT of the mailbox row and binds
     * it in infra_provider_resources instead, so that no business table carries
     * a vendor concept. This reads that binding.
     */
    private function providerReferenceFor(EmailMailbox $mailbox): ?string
    {
        $ref = DB::table('infra_provider_resources')
            ->where('owner_type', 'email_mailbox')
            ->where('owner_id', $mailbox->id)
            ->orderByDesc('id')
            ->value('provider_resource_id');

        $ref = is_string($ref) ? trim($ref) : '';

        return $ref === '' ? null : $ref;
    }

    /**
     * Read the mailbox back and move it to ACTIVE only if the provider says it
     * is. E7's lifecycle already requires evidence for this edge.
     */
    private function confirmActivation($connector, EmailMailbox $mailbox, ProviderCallContext $context, ?string $ref = null): bool
    {
        $ref = $ref ?? $this->providerReferenceFor($mailbox);

        if ($ref === null) {
            return false;
        }

        $status = $connector->getMailboxStatus($ref, $context);

        if (! $status->success || $status->normalizedState !== 'active') {
            return false;
        }

        // RETURNS WHAT THE DATABASE SAYS, NOT WHAT THE PROVIDER SAID.
        //
        // The previous version returned true whenever the provider reported
        // active, even if the transition below matched nothing and saved
        // nothing. That is how a customer came to be told "active: true" while
        // the row still read "requested". A confirmation that cannot fail is
        // not a confirmation.
        $persisted = false;

        WorkspaceContext::run((int) $mailbox->workspace_id, function () use ($mailbox, &$persisted) {
            $fresh = EmailMailbox::find($mailbox->id);

            if ($fresh === null) {
                return;
            }

            if (in_array($fresh->lifecycle_state, [
                EmailMailboxState::PROVISIONING,
                EmailMailboxState::AWAITING_ACTIVATION,
                EmailMailboxState::RECONCILING,
            ], true)) {
                $fresh->transitionTo(EmailMailboxState::ACTIVE);
                $fresh->forceFill(['state_changed_at' => now()])->save();
            }

            $persisted = EmailMailbox::find($mailbox->id)?->lifecycle_state === EmailMailboxState::ACTIVE;
        });

        return $persisted;
    }

    /**
     * Resend: a NEW token and a NEW email. The mailbox password is untouched,
     * and the provider is not asked to send anything.
     *
     * @return array{token:string,record:MailboxSetupToken}
     */
    public function resend(EmailMailbox $mailbox, string $recipientEmail, ?int $actorUserId = null): array
    {
        return $this->issue($mailbox, $recipientEmail, MailboxSetupToken::PURPOSE_SETUP, $actorUserId);
    }

    /** Invalidate every live token for a mailbox — used when it is deleted. */
    public function revokeAllFor(EmailMailbox $mailbox, string $reason = 'mailbox_removed'): int
    {
        return MailboxSetupToken::withoutGlobalScopes()
            ->where('email_mailbox_id', $mailbox->id)
            ->whereNull('consumed_at')
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now(), 'superseded_reason' => $reason]);
    }

    /** The customer-facing URL. LevelUp's own host, always. */
    public function setupUrl(string $rawToken): string
    {
        return rtrim((string) config('app.url'), '/') . '/business-email/setup/' . $rawToken;
    }
}
