<?php

namespace App\Core\Lifecycle;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * MAIL-BRAND-1: one mail class for every lifecycle email (welcome, social nudge, trial ending, trial ended …). The
 * purpose header lets Email888 pin sender and stream; lifecycle mail carries one-click unsubscribe headers (the rule
 * Gmail and Yahoo enforce) and a plain-text part.
 */
class LifecycleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $correlationId = '';

    public function __construct(public string $purpose, public string $subjectLine, public array $layout) {}

    public function envelope(): Envelope
    {
        $this->correlationId = $this->correlationId !== '' ? $this->correlationId : (string) \Illuminate\Support\Str::uuid();
        $cid = $this->correlationId; $purpose = $this->purpose; $unsub = (string) ($this->layout['unsubscribe'] ?? ''); $plain = EmailLayout::text($this->layout);
        return new Envelope(
            subject: $this->subjectLine,
            using: [function (\Symfony\Component\Mime\Email $m) use ($cid, $purpose, $unsub, $plain) {
                $h = $m->getHeaders();
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, $purpose);
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_CORRELATION, $cid);
                if ($unsub !== '') {
                    $h->addTextHeader('List-Unsubscribe', '<' . $unsub . '>');
                    $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
                $m->text($plain);
            }],
        );
    }

    public function failed(\Throwable $e): void
    {
        try { app(\App\Core\Email888\DeliveryLedger::class)->markLastRecordedFailed($e, ['correlation_id' => $this->correlationId]); } catch (\Throwable) {}
    }

    public function content(): Content
    {
        return new Content(htmlString: EmailLayout::render($this->layout));
    }
}
