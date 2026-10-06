<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Core\Email888\DeliveryLedger;
use App\Core\Email888\OutboundPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/public/contact — the marketing site's contact form (rebuild U10, 2026-09-07).
 * Same shape as the waitlist: honeypot, real-IP rate limit, lead into the company workspace with a topic,
 * notification through the certified mail pipeline. Enterprise, support, press or other.
 */
class PublicContactController
{
    public const WORKSPACE_ID = 1;
    public const SOURCE = 'levelupgrowth_contact';
    public const NOTIFY_TO = 'hello@levelupgrowth.io';
    public const TOPICS = ['enterprise', 'support', 'press', 'partnership', 'advertising', 'other'];   // ADV-PAGE-1 (2026-10-06): the /advertising/ brief
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 600;

    public function store(Request $request): JsonResponse
    {
        $ip = $this->clientIp($request);
        if (filled($request->input('website_confirm'))) {
            Log::info('public.contact.honeypot', ['ip' => $ip]);
            return response()->json(['ok' => true, 'reference' => 'LUG-C-0000']);
        }
        $key = 'public-contact:' . $ip;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json(['ok' => false, 'message' => 'Too many messages from this connection. Please try again in a few minutes.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $validator = Validator::make($request->all(), [
            'topic'   => ['required', 'string', 'in:' . implode(',', self::TOPICS)],
            'name'    => ['required', 'string', 'min:2', 'max:120'],
            'email'   => ['required', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'consent' => ['accepted'],
            'source_page' => ['nullable', 'string', 'max:300'],
        ], ['consent.accepted' => 'Please confirm we may reply to you by email.']);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);
        $data = $validator->validated();
        $email = mb_strtolower(trim($data['email']));
        $metadata = ['intake_type' => 'contact', 'topic' => $data['topic'], 'message' => $data['message'], 'source_page' => $data['source_page'] ?? null, 'client_ip' => $ip, 'user_agent' => substr((string) $request->userAgent(), 0, 400), 'submitted_at' => now()->toIso8601String()];

        // LEADS-W1 (DEC-0089): in a LEADS-W1 house workspace the enquiry goes through the one capture door, with the house business,
        // so Sarah reads it, drafts a reply within a minute and chases it like any customer's enquiry
        $__house = \App\Engines\CRM\Services\LeadsAssistant::enabled(self::WORKSPACE_ID)
            ? DB::table('businesses')->where('workspace_id', self::WORKSPACE_ID)->whereNull('deleted_at')->where('name', 'LevelUpGrowth')->value('id') : null;
        if ($__house) {
            try {
                $cap = app(\App\Engines\CRM\Services\CrmService::class)->captureLead(self::WORKSPACE_ID, ['business_id' => (int) $__house, 'name' => $data['name'], 'email' => $email, 'company' => $data['company'] ?? null,
                    'source' => self::SOURCE, 'activity' => $data['message'], 'metadata' => $metadata + ['first_message' => $data['message']], 'tags' => ['contact', $data['topic']]]);
                $leadId = (int) $cap['lead']->id;
            } catch (\Throwable $e) {
                Log::error('public.contact.capture_failed', ['error' => $e->getMessage()]);
                return response()->json(['ok' => false, 'message' => 'We could not save your message. Please email ' . self::NOTIFY_TO . '.'], 500);
            }
        } else
        try {
            $existing = DB::table('leads')->where('workspace_id', self::WORKSPACE_ID)->where('email', $email)->whereNull('deleted_at')->first(['id', 'metadata_json']);
            if ($existing) {
                $prior = json_decode((string) $existing->metadata_json, true) ?: [];
                $prior['contacts'][] = $metadata;
                DB::table('leads')->where('id', $existing->id)->update(['metadata_json' => json_encode($prior), 'updated_at' => now()]);
                $leadId = (int) $existing->id;
            } else {
                $leadId = (int) DB::table('leads')->insertGetId(['workspace_id' => self::WORKSPACE_ID, 'name' => $data['name'], 'email' => $email, 'company' => $data['company'] ?? null, 'source' => self::SOURCE, 'status' => 'new', 'metadata_json' => json_encode($metadata), 'tags_json' => json_encode(['contact', $data['topic']]), 'created_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::error('public.contact.store_failed', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'We could not save your message. Please email ' . self::NOTIFY_TO . '.'], 500);
        }
        $reference = 'LUG-C-' . str_pad((string) $leadId, 5, '0', STR_PAD_LEFT);

        try {
            $lines = ["New {$data['topic']} message — levelupgrowth.io", "Reference: {$reference}", str_repeat('-', 46), 'Name:    ' . $data['name'], 'Email:   ' . $email, 'Company: ' . ($data['company'] ?? '—'), 'Page:    ' . ($data['source_page'] ?? '—'), '', $data['message'], '', 'In CRM: workspace ' . self::WORKSPACE_ID . ', source ' . self::SOURCE . '.'];
            Mail::raw(implode("\n", $lines), function ($m) use ($data, $email, $reference) {
                $m->getSymfonyMessage()->getHeaders()->addTextHeader(OutboundPolicy::HDR_PURPOSE, 'intake');
                $m->to(self::NOTIFY_TO)->replyTo($email, $data['name'])->subject("[{$reference}] {$data['topic']} — {$data['name']}");
            });
        } catch (\Throwable $e) {
            Log::warning('public.contact.mail_failed', ['error' => $e->getMessage(), 'lead' => $leadId]);
            app(DeliveryLedger::class)->markLastRecordedFailed($e);
        }

        return response()->json(['ok' => true, 'reference' => $reference]);
    }

    private function clientIp(Request $request): string
    {
        $cf = (string) $request->header('CF-Connecting-IP');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) { return $cf; }
        $xff = (string) $request->header('X-Forwarded-For');
        if ($xff !== '') { $first = trim(explode(',', $xff)[0]); if (filter_var($first, FILTER_VALIDATE_IP)) { return $first; } }
        return (string) $request->ip();
    }
}
