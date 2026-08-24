<?php

namespace App\Core\Email888;

use App\Core\Email888\Contracts\EmailResult;
use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * EMAIL888 - the single entry point for outbound email.
 *
 * Producers hand over a SendEmailCommand and receive an EmailResult. They never
 * see Mail::, a transport, a message stream or a provider token.
 *
 * IDEMPOTENCY IS CLAIMED BEFORE THE SEND, NOT CHECKED BEFORE IT. A
 * read-then-send loses under concurrency, and losing means a customer gets two
 * copies. The ledger row is inserted first and the database's unique index
 * decides the winner; the loser returns the winner's identifiers and sends
 * nothing.
 */
class EmailDispatcher
{
    public function __construct(private readonly DeliveryLedger $ledger)
    {
    }

    public function send(SendEmailCommand $command): EmailResult
    {
        $provider      = OutboundPolicy::provider();
        $correlationId = $command->correlationId ?? (string) Str::uuid();
        $policy        = OutboundPolicy::resolve($command->purpose);

        // A registered purpose always resolves; the constructor already refused
        // anything unregistered.
        $streamClass = $command->streamClass ?? ($policy['stream_class'] ?? 'transactional');

        // ---- claim ------------------------------------------------------
        $claim = $this->claim($command, $correlationId, $streamClass, $policy);

        if ($claim instanceof EmailResult) {
            return $claim;   // duplicate
        }

        // ---- send -------------------------------------------------------
        try {
            Mail::send(
                $this->bodySpec($command),
                $command->templateData,
                fn (Message $m) => $this->compose($m, $command, $correlationId, $claim->id)
            );
        } catch (Throwable $e) {
            $verdict = FailureClassifier::classify($e);

            $this->ledger->markFailed(
                (int) $claim->id,
                $verdict['category'],
                $verdict['retryable'],
                ['exception' => class_basename($e), 'message' => substr($e->getMessage(), 0, 400)],
                $verdict['state'],
            );

            return EmailResult::refused(
                $provider,
                (int) $claim->id,
                $verdict['category'],
                $verdict['retryable'],
                $correlationId,
            );
        } finally {
            DeliveryLedger::forgetLastRecorded();
        }

        // The listener wrote acceptance and the provider id onto the same row.
        $claim->refresh();

        return EmailResult::accepted(
            $provider,
            $claim->provider_message_id,
            (int) $claim->id,
            $correlationId,
        );
    }

    /**
     * Insert the ledger row up front. Returns the row, or an EmailResult when
     * this command duplicates one already sent.
     */
    private function claim(
        SendEmailCommand $command,
        string $correlationId,
        string $streamClass,
        ?array $policy,
    ): EmailDelivery|EmailResult {
        $attrs = [
            'correlation_id'    => $correlationId,
            'idempotency_key'   => $command->idempotencyKey,
            'workspace_id'      => $command->workspaceId,
            'user_id'           => $command->actorId,
            'purpose'           => $command->purpose,
            'stream_class'      => $streamClass,
            'provider'          => OutboundPolicy::provider(),
            'provider_stream'   => config('email888.streams')[$streamClass] ?? null,
            'recipient_address' => $command->primaryRecipient(),
            'subject'           => $command->subject !== null ? substr($command->subject, 0, 255) : null,
            'sender_address'    => $this->senderFor($command, $policy)['address'] ?? null,
            'state'             => DeliveryState::QUEUED->value,
            'queued_at'         => now(),
            'metadata'          => $command->metadata ?: null,
        ];

        try {
            return EmailDelivery::create($attrs);
        } catch (QueryException $e) {
            if ($command->idempotencyKey === null) {
                throw $e;
            }

            $existing = EmailDelivery::where('idempotency_key', $command->idempotencyKey)->first();

            if (! $existing) {
                throw $e;   // a different constraint failed; do not swallow it
            }

            return EmailResult::duplicate(
                OutboundPolicy::provider(),
                $existing->provider_message_id,
                (int) $existing->id,
                (string) $existing->correlation_id,
            );
        }
    }

    /** @return array{address:string,name:string}|array{} */
    private function senderFor(SendEmailCommand $command, ?array $policy): array
    {
        if ($command->senderIdentity !== null) {
            $s = config('email888.senders')[$command->senderIdentity] ?? null;

            return $s ? ['address' => (string) $s['address'], 'name' => (string) ($s['name'] ?? '')] : [];
        }

        return $policy['sender'] ?? [];
    }

    /** Blade view name, or a raw-text spec Laravel understands. */
    private function bodySpec(SendEmailCommand $command): array|string
    {
        if ($command->template !== null) {
            // Laravel's parseView reads ['html' => view, 'raw' => literal text],
            // so a template can carry a plain-text alternative alongside it.
            return $command->text !== null
                ? ['html' => $command->template, 'raw' => $command->text]
                : $command->template;
        }

        $spec = [];

        // Laravel's parseView() treats 'html' and 'text' as VIEW NAMES. Only an
        // Htmlable is rendered as content, and only the 'raw' key is taken as a
        // literal plain-text body. Passing our HTML string under 'text' would
        // send Laravel looking for a view by that name.
        if ($command->html !== null) {
            $spec['html'] = new \Illuminate\Support\HtmlString($command->html);
        }
        if ($command->text !== null) {
            $spec['raw'] = $command->text;
        }

        return $spec;
    }

    private function compose(Message $m, SendEmailCommand $command, string $correlationId, int $claimId): void
    {
        $m->to($command->recipients);

        if ($command->cc !== []) {
            $m->cc($command->cc);
        }
        if ($command->bcc !== []) {
            $m->bcc($command->bcc);
        }
        if ($command->subject !== null) {
            $m->subject($command->subject);
        }

        foreach ($command->attachments as $a) {
            if (! empty($a['path'])) {
                $m->attach($a['path'], array_filter([
                    'as'   => $a['name'] ?? null,
                    'mime' => $a['mime'] ?? null,
                ]));
            } elseif (isset($a['data'])) {
                $m->attachData($a['data'], (string) ($a['name'] ?? 'attachment'), array_filter([
                    'mime' => $a['mime'] ?? null,
                ]));
            }
        }

        // Routing metadata for the listener. Every one of these is stripped
        // before the message leaves the building.
        $h = $m->getSymfonyMessage()->getHeaders();
        $h->addTextHeader(OutboundPolicy::HDR_PURPOSE, $command->purpose);
        $h->addTextHeader(OutboundPolicy::HDR_CORRELATION, $correlationId);
        $h->addTextHeader(OutboundPolicy::HDR_CLAIM, (string) $claimId);

        if ($command->workspaceId !== null) {
            $h->addTextHeader(OutboundPolicy::HDR_WORKSPACE, (string) $command->workspaceId);
        }
        if ($command->actorId !== null) {
            $h->addTextHeader(OutboundPolicy::HDR_USER, (string) $command->actorId);
        }
        if ($command->senderIdentity !== null) {
            $h->addTextHeader(OutboundPolicy::HDR_SENDER, $command->senderIdentity);
        }
        if ($command->replyTo !== null) {
            $h->addTextHeader(OutboundPolicy::HDR_REPLY_TO, $command->replyTo);
        }
        if ($command->streamClass !== null) {
            $h->addTextHeader(OutboundPolicy::HDR_STREAM, $command->streamClass);
        }
    }
}
