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
        $n = e($this->name); $s = e($this->siteName); $u = e($this->siteUrl); $p = e($this->plansUrl); $c = e($this->cheapest); $ai = e($this->aiFrom);
        return new Content(htmlString: <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:32px 24px;color:#182420;line-height:1.6">
  <h2 style="margin:0 0 16px">Hi {$n}, your website is live</h2>
  <p style="margin:0 0 14px"><a href="{$u}" style="color:#5B3DF5">{$s}</a> is on the Free plan, so it shows a small LevelUpGrowth ad to your visitors. That is how we keep the Free plan free.</p>
  <p style="margin:0 0 8px"><strong>To remove the ads:</strong></p>
  <ol style="margin:0 0 18px;padding-left:20px">
    <li>Choose a paid plan (from {$c} a month).</li>
    <li>That's it — the ads disappear from your website within a minute.</li>
  </ol>
  <p style="margin:0 0 22px">Plans from {$ai} a month also put Sarah, your AI marketing manager, to work: social posts, articles that help you get found on Google, and campaigns for your business.</p>
  <p style="margin:0 0 26px"><a href="{$p}" style="display:inline-block;background:#5B3DF5;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold">See the plans</a></p>
  <p style="margin:0;color:#6b7280;font-size:13px">LevelUpGrowth</p>
</div>
HTML);
    }
}
