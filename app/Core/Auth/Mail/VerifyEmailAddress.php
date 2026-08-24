<?php

namespace App\Core\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * MISSION-018 WS-2 (2026-08-24). The verification email a new account
 * receives at registration. Before this existed, POST /api/auth/register
 * returned a usable token with no verification step anywhere in the journey
 * (REPORT-0012 §1b) — unverifiable accounts and bot signups at public launch.
 *
 * Queued (redis) so registration latency never waits on Postmark.
 */
class VerifyEmailAddress extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your email — Level Up Growth',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:32px 24px;color:#182420">
  <h2 style="margin:0 0 16px">Welcome to Level Up Growth, {$this->recipientName}!</h2>
  <p style="line-height:1.6;margin:0 0 20px">One quick step before Sarah and the team get to work:
  confirm this is your email address.</p>
  <p style="margin:0 0 28px">
    <a href="{$this->verificationUrl}"
       style="background:#0B6E5C;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;display:inline-block;font-weight:bold">
      Confirm my email
    </a>
  </p>
  <p style="font-size:13px;color:#5A6963;line-height:1.6;margin:0">
    This link is valid for 72 hours. If the button does not work, copy this address into your browser:<br>
    <span style="word-break:break-all">{$this->verificationUrl}</span><br><br>
    If you did not create a Level Up Growth account, you can ignore this email.
  </p>
</div>
HTML,
        );
    }
}
