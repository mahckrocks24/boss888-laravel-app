<?php

namespace App\Core\Growth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ADS-NOTICE-1 (Owner 2026-09-28: "the Free ones under our subdomain should get email notification of how to remove ads on
 * their website"). Platform mail from LevelUpGrowth (billing purpose), sent once per website when ads start showing on it.
 */
class RemoveAdsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const PURPOSE = 'billing';
    public string $correlationId = '';

    public function __construct(public string $name, public string $siteName, public string $siteUrl, public string $plansUrl, public string $cheapest, public string $aiFrom = '$49') {}

    public function envelope(): Envelope
    {
        $this->correlationId = $this->correlationId !== '' ? $this->correlationId : (string) \Illuminate\Support\Str::uuid();
        $cid = $this->correlationId;
        return new Envelope(
            subject: 'How to remove the ads from ' . $this->siteName,
            using: [function (\Symfony\Component\Mime\Email $m) use ($cid) {
                $h = $m->getHeaders();
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, self::PURPOSE);
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_CORRELATION, $cid);
            }],
        );
    }

    public function failed(\Throwable $e): void
    {
        try { app(\App\Core\Email888\DeliveryLedger::class)->markLastRecordedFailed($e, ['correlation_id' => $this->correlationId]); } catch (\Throwable) {}
    }

    public function content(): Content
    {
        // MAIL-BRAND-1: the one branded design
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return new Content(htmlString: \App\Core\Lifecycle\EmailLayout::render([
            'preheader'  => 'Your website is live on the Free plan. Here is how to remove the ads.',
            'hero' => 'ended', 'eyebrow' => 'Free plan',
            'heading'    => 'Your website is live',
            'greeting'   => 'Hi ' . $e($this->name) . ',',
            'paragraphs' => ['<a href="' . $e($this->siteUrl) . '" style="color:#6C5CE7">' . $e($this->siteName) . '</a> is on the Free plan, so it shows a small LevelUpGrowth ad to your visitors. That is how the Free plan stays free.'],
            'list'       => ['Choose a paid plan, from ' . $e($this->cheapest) . ' a month, and the ads leave your website within a minute.', 'Plans from ' . $e($this->aiFrom) . ' a month also put Sarah and the AI team to work: social posts, articles that help you get found on Google, and campaigns.'],
            'button'     => ['See the plans', $this->plansUrl],
            'signoff'    => 'team',
            'reason'     => 'You received this email because your website runs on the LevelUpGrowth Free plan.',
        ]));    }
}
