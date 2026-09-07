<?php

namespace App\Engines\Resume\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** RESUME888 — "your CV is ready" with a 7-day download link and a 12-month continue link. White-label: the site's name only. */
class ResumeReadyMail extends Mailable
{
    public function __construct(public string $siteName, public string $downloadUrl, public string $continueUrl, public string $lang = 'tl') {}

    public function envelope(): Envelope
    {
        $subject = match ($this->lang) { 'en' => 'Your CV is ready — ' . $this->siteName, 'fil' => 'Handa na ang iyong CV — ' . $this->siteName, default => 'Handa na ang CV mo — ' . $this->siteName };
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->html());
    }

    private function html(): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t = match ($this->lang) {
            'en' => ['hi' => 'Hi kabayan,', 'body' => 'Your CV is ready. Download the PDF below (link valid for 7 days). To edit it later on any phone, use the second link — it stays valid for 12 months and nobody else can use it.', 'dl' => 'Download my CV (PDF)', 'edit' => 'Edit my CV later', 'foot' => 'This CV was built by you with the free CV tool on ' . $this->siteName . '. Your data is kept for 12 months and can be deleted any time from the tool.'],
            'fil' => ['hi' => 'Kumusta kabayan,', 'body' => 'Handa na ang iyong CV. I-download ang PDF sa ibaba (7 araw ang bisa ng link). Para i-edit ito sa ibang pagkakataon sa kahit anong telepono, gamitin ang pangalawang link — 12 buwan ang bisa at ikaw lamang ang makakagamit.', 'dl' => 'I-download ang aking CV (PDF)', 'edit' => 'I-edit ang aking CV', 'foot' => 'Ikaw ang gumawa ng CV na ito gamit ang libreng CV tool ng ' . $this->siteName . '. Itatago ang iyong data nang 12 buwan at maaaring burahin anumang oras.'],
            default => ['hi' => 'Kumusta kabayan,', 'body' => 'Ready na ang CV mo. I-download ang PDF sa baba (7 days valid ang link). Para i-edit ulit sa kahit anong phone, gamitin ang second link — 12 months ang validity at ikaw lang ang makakagamit.', 'dl' => 'I-download ang CV ko (PDF)', 'edit' => 'I-edit ang CV ko later', 'foot' => 'Ikaw ang gumawa ng CV na ito gamit ang libreng CV tool ng ' . $this->siteName . '. Itatago ang data mo nang 12 buwan at pwedeng burahin anytime.'],
        };
        return '<!doctype html><html><body style="margin:0;background:#F4F5F9;font-family:Inter,Segoe UI,Arial,sans-serif;color:#141826"><div style="max-width:560px;margin:0 auto;padding:28px 20px">'
            . '<div style="background:#fff;border-radius:14px;padding:26px;border:1px solid #E5E7EF"><div style="font-family:Georgia,serif;font-weight:700;font-size:22px;color:#0038A8;margin-bottom:14px">' . $e($this->siteName) . '</div>'
            . '<p style="font-size:16px;margin:0 0 10px">' . $e($t['hi']) . '</p><p style="font-size:15px;line-height:1.55;margin:0 0 20px">' . $e($t['body']) . '</p>'
            . '<p style="margin:0 0 12px"><a href="' . $e($this->downloadUrl) . '" style="display:inline-block;background:#0038A8;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600">' . $e($t['dl']) . '</a></p>'
            . '<p style="margin:0 0 6px"><a href="' . $e($this->continueUrl) . '" style="color:#0038A8;font-weight:600">' . $e($t['edit']) . ' →</a></p></div>'
            . '<p style="font-size:12px;color:#6B7280;line-height:1.5;margin:16px 4px 0">' . $e($t['foot']) . '</p></div></body></html>';
    }
}
