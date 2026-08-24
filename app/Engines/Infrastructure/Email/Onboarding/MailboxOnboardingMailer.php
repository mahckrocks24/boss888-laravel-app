<?php

namespace App\Engines\Infrastructure\Email\Onboarding;

use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\EmailDispatcher;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * INFRA888 · E8 — the LevelUp-branded onboarding email.
 *
 * This message exists because the provider's own invitation names the vendor in
 * its sender, subject, link and footer. Everything a customer sees here is
 * LevelUp Growth.
 *
 * IT CARRIES NO PASSWORD. It carries a single-use link to a LevelUp page where
 * the customer chooses their own — which is also the moment the provider
 * mailbox is created.
 *
 * EMAIL888 (2026-08-12). This used to call Mail::send() and pick its own sender
 * from config('business_email.onboarding.from_address'). That env var was never
 * set, so every onboarding email went out from a default address nobody had
 * chosen, on whatever message stream Postmark happened to apply, with no ledger
 * row and no MessageID recorded anywhere.
 *
 * It now declares a PURPOSE and lets the registry decide identity and routing.
 * The delivery is recorded before the provider is called, so a message can no
 * longer be accepted and then lost without trace.
 */
final class MailboxOnboardingMailer
{
    public const SUBJECT = 'Set up your Business Email account';

    /** The registered purpose. Sender, reply-to and stream all derive from it. */
    public const PURPOSE = 'mailbox_onboarding';

    public function __construct(
        private readonly MailboxSetupService $setup,
        private readonly EmailDispatcher $dispatcher,
    ) {
    }

    /**
     * Send the setup link. Returns whether the provider ACCEPTED it — which is
     * acceptance, not proof of delivery, and is reported as such. The delivery
     * ledger row carries what actually happened.
     */
    public function send(EmailMailbox $mailbox, MailboxSetupToken $token, string $rawToken): bool
    {
        $address   = $this->addressOf($mailbox);
        $recipient = (string) $token->recipient_email;

        try {
            $result = $this->dispatcher->send(new SendEmailCommand(
                purpose:    self::PURPOSE,
                recipients: [$recipient],
                subject:    self::SUBJECT,
                template:   'emails.business-email-setup',
                // Plain-text alternative. Same information, no markup: a
                // text-mode client and a spam filter both need to see that this
                // is a real account-setup message and not an empty HTML shell.
                text: "Set up your Business Email account\n\n"
                    . "Your new address: {$address}\n\n"
                    . "Choose your password here (the link works once, and expires in "
                    . MailboxSetupToken::TTL_HOURS . " hours):\n"
                    . $this->setup->setupUrl($rawToken) . "\n\n"
                    . "If you did not expect this, you can ignore it - nothing is created "
                    . "until you choose a password.\n\n"
                    . "LevelUp Growth",
                templateData: [
                    'address'      => $address,
                    'setupUrl'     => $this->setup->setupUrl($rawToken),
                    'expiresHours' => MailboxSetupToken::TTL_HOURS,
                ],
                correlationId: (string) Str::uuid(),
                workspaceId:   (int) $mailbox->workspace_id,
                // Keyed on the SEND, not the token: a resend is a new message and
                // must not be deduplicated against the first one, while a retry
                // of the same send must be.
                idempotencyKey: 'mbx-onboard-' . $token->id . '-' . (int) $token->send_count,
                metadata: [
                    'mailbox_id'      => (int) $mailbox->id,
                    'setup_token_id'  => (int) $token->id,
                    'email_domain_id' => (int) $mailbox->email_domain_id,
                ],
            ));
        } catch (Throwable $e) {
            // The exception may name the transport. It must never name the
            // recipient's token, and there is no password to leak here at all.
            Log::warning('infra.email.setup_mail_failed', [
                'mailbox_id'   => $mailbox->id,
                'workspace_id' => $mailbox->workspace_id,
                'error'        => get_class($e),
            ]);

            return false;
        }

        if (! $result->accepted) {
            Log::warning('infra.email.setup_mail_refused', [
                'mailbox_id'         => $mailbox->id,
                'workspace_id'       => $mailbox->workspace_id,
                'delivery_record_id' => $result->deliveryRecordId,
                'failure_category'   => $result->failureCategory,
                'retryable'          => $result->retryable,
            ]);

            return false;
        }

        // Send accounting lives on the token, so an operator can see how many
        // times a customer was contacted without ever seeing the link itself.
        // The ledger row id is kept beside it: that is the thread from "we tried"
        // to "here is what the provider did about it".
        $token->forceFill([
            'send_count'   => (int) $token->send_count + 1,
            'last_sent_at' => now(),
        ])->saveQuietly();

        Log::info('infra.email.setup_mail_accepted', [
            'mailbox_id'          => $mailbox->id,
            'workspace_id'        => $mailbox->workspace_id,
            'delivery_record_id'  => $result->deliveryRecordId,
            'provider_message_id' => $result->providerMessageId,
            'correlation_id'      => $result->correlationId,
        ]);

        return true;
    }

    private function addressOf(EmailMailbox $mailbox): string
    {
        $domain = DB::table('email_domains')->where('id', $mailbox->email_domain_id)->value('domain');

        return trim((string) $mailbox->local_part) . '@' . trim((string) $domain);
    }
}
