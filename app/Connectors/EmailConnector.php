<?php

namespace App\Connectors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailConnector extends BaseConnector
{
    private string $driver;
    private string $postmarkToken;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->driver = config('connectors.email.driver', 'smtp');
        $this->postmarkToken = config('connectors.email.postmark_token', '');
        $this->fromEmail = config('connectors.email.from_email', 'noreply@levelupgrowth.io');
        $this->fromName = config('connectors.email.from_name', 'LevelUp Growth');
    }

    public function supportedActions(): array
    {
        return ['send_email', 'send_campaign'];
    }

    public function validationRules(string $action): array
    {
        return match ($action) {
            'send_email' => [
                'to' => 'required|email',
                'subject' => 'required|string|max:500',
                'body' => 'required|string',
                'html' => 'nullable|boolean',
                'reply_to' => 'nullable|email',
                'cc' => 'nullable|array',
                'cc.*' => 'email',
                'bcc' => 'nullable|array',
                'bcc.*' => 'email',
            ],
            'send_campaign' => [
                'recipients' => 'required|array|min:1',
                'recipients.*.email' => 'required|email',
                'recipients.*.name' => 'nullable|string',
                'subject' => 'required|string|max:500',
                'body' => 'required|string',
                'html' => 'nullable|boolean',
                'batch_size' => 'nullable|integer|min:1|max:100',
            ],
            default => [],
        };
    }

    public function execute(string $action, array $params): array
    {
        $validated = $this->validate($action, $params);

        try {
            return match ($action) {
                'send_email' => $this->sendEmail($validated),
                'send_campaign' => $this->sendCampaign($validated),
                default => $this->failure("Unknown action: {$action}"),
            };
        } catch (\Throwable $e) {
            Log::error("EmailConnector::{$action} failed", ['error' => $e->getMessage()]);
            return $this->failure("Email action failed: {$e->getMessage()}");
        }
    }

    public function healthCheck(): bool
    {
        if ($this->driver === 'postmark') {
            try {
                $response = Http::withToken($this->postmarkToken)
                    ->get('https://api.postmarkapp.com/server');
                return $response->successful();
            } catch (\Throwable) {
                return false;
            }
        }

        // SMTP — just check config exists
        return ! empty(config('mail.mailers.smtp.host'));
    }

    // ── Verification ─────────────────────────────────────────────────────

    public function verifyResult(string $action, array $params, array $result): array
    {
        if (! ($result['success'] ?? false)) {
            return ['verified' => false, 'message' => 'Execution reported failure', 'data' => []];
        }

        $data = $result['data'] ?? [];

        if ($action === 'send_email') {
            // Postmark returns MessageID; SMTP returns empty on success
            if ($this->driver === 'postmark' && empty($data['message_id'])) {
                return ['verified' => false, 'message' => 'Postmark did not return MessageID — send not confirmed', 'data' => $data];
            }
            // SMTP: accepted (no delivery confirmation available)
            return ['verified' => true, 'message' => 'Email accepted by provider', 'data' => $data];
        }

        if ($action === 'send_campaign') {
            $sent = $data['sent'] ?? 0;
            $failed = $data['failed'] ?? 0;
            if ($sent === 0 && $failed > 0) {
                return ['verified' => false, 'message' => 'Campaign failed: zero sends', 'data' => $data];
            }
            return ['verified' => true, 'message' => "Campaign verified: {$sent} sent", 'data' => $data];
        }

        return ['verified' => true, 'message' => 'Result accepted', 'data' => $data];
    }

    // ── Private Action Methods ───────────────────────────────────────────

    private function sendEmail(array $params): array
    {
        if ($this->driver === 'postmark') {
            return $this->sendViaPostmark($params);
        }

        return $this->sendViaSmtp($params);
    }

    private function sendCampaign(array $params): array
    {
        $recipients = $params['recipients'];
        $batchSize = $params['batch_size'] ?? 50;
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach (array_chunk($recipients, $batchSize) as $batch) {
            foreach ($batch as $recipient) {
                $result = $this->sendEmail([
                    'to' => $recipient['email'],
                    'subject' => $params['subject'],
                    'body' => $params['body'],
                    'html' => $params['html'] ?? false,
                ]);

                if ($result['success']) {
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = $recipient['email'] . ': ' . $result['message'];
                }
            }
        }

        if ($failed === 0) {
            return $this->success(['sent' => $sent], "Campaign sent to {$sent} recipients");
        }

        return $this->success([
            'sent' => $sent,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 10),
        ], "Campaign partially sent: {$sent} succeeded, {$failed} failed");
    }

    private function sendViaPostmark(array $params): array
    {
        $payload = [
            'From' => "{$this->fromName} <{$this->fromEmail}>",
            'To' => $params['to'],
            'Subject' => $params['subject'],
            'ReplyTo' => $params['reply_to'] ?? null,
        ];

        if (! empty($params['html']) && $params['html']) {
            $payload['HtmlBody'] = $params['body'];
        } else {
            $payload['TextBody'] = $params['body'];
        }

        if (! empty($params['cc'])) {
            $payload['Cc'] = implode(',', $params['cc']);
        }
        if (! empty($params['bcc'])) {
            $payload['Bcc'] = implode(',', $params['bcc']);
        }

        $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $this->postmarkToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(10)
            ->retry(2, 500)
            ->post('https://api.postmarkapp.com/email', $payload);

        if ($response->failed()) {
            return $this->failure('Postmark send failed: ' . $response->body());
        }

        return $this->success([
            'message_id' => $response->json('MessageID'),
            'submitted_at' => $response->json('SubmittedAt'),
        ], 'Email sent via Postmark');
    }

    private function sendViaSmtp(array $params): array
    {
        $isHtml = ! empty($params['html']) && $params['html'];

        Mail::raw($isHtml ? '' : $params['body'], function ($message) use ($params, $isHtml) {
            $message->to($params['to'])
                ->subject($params['subject'])
                ->from($this->fromEmail, $this->fromName);

            if ($isHtml) {
                $message->html($params['body']);
            }

            if (! empty($params['reply_to'])) {
                $message->replyTo($params['reply_to']);
            }
            if (! empty($params['cc'])) {
                $message->cc($params['cc']);
            }
            if (! empty($params['bcc'])) {
                $message->bcc($params['bcc']);
            }
        });

        return $this->success([], 'Email sent via SMTP');
    }
}
