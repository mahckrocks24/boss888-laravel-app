<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * PublicIntakeController — unauthenticated intake for markraymundo.com forms.
 *
 * Deliberately narrow: the ONLY unauthenticated write path into `leads`.
 * Everything is validated, the workspace is pinned server-side, and the
 * client never supplies a workspace_id.
 *
 * Rate limiting is done HERE rather than via the route's `throttle` middleware
 * because requests arrive over a loopback reverse proxy from markraymundo.com's
 * nginx, so the framework sees REMOTE_ADDR 127.0.0.1 for every visitor. Keying
 * the limiter on the framework IP would put every visitor in one shared bucket.
 * We resolve the real address ourselves (see clientIp) and key on that. This
 * keeps the fix local — no change to BOSS888's shared TrustProxies config.
 *
 * Added 2026-07-18 for the markraymundo.com Phase B conversion pass.
 */
class PublicIntakeController
{
    /** Workspace that owns markraymundo.com inbound. Server-side constant. */
    private const WORKSPACE_ID = 4;

    /** Where submission notifications go. */
    private const NOTIFY_TO = 'markfloresraymundo@gmail.com';

    /** Accepted form types → lead source tag. */
    private const TYPES = [
        'challenge' => 'markraymundo_challenge',
        'advisory'  => 'markraymundo_advisory',
    ];

    /** Submissions allowed per client IP per window. */
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 600;

    public function store(Request $request): JsonResponse
    {
        $ip = $this->clientIp($request);

        // Honeypot. Real browsers leave it empty; bots fill every field they find.
        // Return a normal success shape so scrapers get no signal they were caught.
        if (filled($request->input('website_confirm'))) {
            Log::info('public.intake.honeypot', ['ip' => $ip]);

            return response()->json(['ok' => true, 'reference' => 'MR-0000'], 200);
        }

        $key = 'public-intake:' . $ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Too many submissions from this connection. Please try again shortly, or email '
                             . self::NOTIFY_TO . ' directly.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $validator = Validator::make($request->all(), [
            'type'         => ['required', 'string', 'in:challenge,advisory'],
            'name'         => ['required', 'string', 'min:2', 'max:120'],
            'email'        => ['required', 'email:rfc', 'max:190'],
            'company'      => ['nullable', 'string', 'max:150'],
            'role'         => ['nullable', 'string', 'max:120'],
            'website'      => ['nullable', 'string', 'max:200'],
            'phone'        => ['nullable', 'string', 'max:60'],
            'team_size'    => ['nullable', 'string', 'max:40'],
            'timeline'     => ['nullable', 'string', 'max:60'],
            'message'      => ['required', 'string', 'min:20', 'max:4000'],
            'consent'      => ['accepted'],
            // context — captured for attribution, never trusted for control flow
            'source_page'  => ['nullable', 'string', 'max:300'],
            'referrer'     => ['nullable', 'string', 'max:500'],
            'utm_source'   => ['nullable', 'string', 'max:120'],
            'utm_medium'   => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:150'],
            'utm_term'     => ['nullable', 'string', 'max:150'],
            'utm_content'  => ['nullable', 'string', 'max:150'],
        ], [
            'message.min'      => 'Please give us a little more detail — a sentence or two at minimum.',
            'consent.accepted' => 'Please confirm you are happy to be contacted about this enquiry.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'     => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Only count attempts that got past validation, so a visitor fixing a
        // typo three times is not penalised as if they were flooding us.
        RateLimiter::hit($key, self::DECAY_SECONDS);

        $data = $validator->validated();
        $type = $data['type'];

        $metadata = [
            'intake_type'  => $type,
            'source_page'  => $data['source_page'] ?? null,
            'referrer'     => $data['referrer'] ?? null,
            'utm'          => array_filter([
                'source'   => $data['utm_source'] ?? null,
                'medium'   => $data['utm_medium'] ?? null,
                'campaign' => $data['utm_campaign'] ?? null,
                'term'     => $data['utm_term'] ?? null,
                'content'  => $data['utm_content'] ?? null,
            ]),
            'answers'      => array_filter([
                'role'      => $data['role'] ?? null,
                'team_size' => $data['team_size'] ?? null,
                'timeline'  => $data['timeline'] ?? null,
                'message'   => $data['message'],
            ]),
            'client_ip'    => $ip,
            'user_agent'   => substr((string) $request->userAgent(), 0, 400),
            'submitted_at' => now()->toIso8601String(),
        ];

        try {
            $leadId = DB::table('leads')->insertGetId([
                'workspace_id'  => self::WORKSPACE_ID,
                'name'          => $data['name'],
                'email'         => $data['email'],
                'phone'         => $data['phone'] ?? null,
                'company'       => $data['company'] ?? null,
                'website'       => $data['website'] ?? null,
                'source'        => self::TYPES[$type],
                'status'        => 'new',
                'score'         => 0,
                'deal_value'    => 0,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'tags_json'     => json_encode(['markraymundo', $type], JSON_UNESCAPED_SLASHES),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('public.intake.persist_failed', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'We could not record that submission. Please email ' . self::NOTIFY_TO . ' directly.',
            ], 500);
        }

        $reference = 'MR-' . str_pad((string) $leadId, 4, '0', STR_PAD_LEFT);

        // Notification is best-effort. A mail failure must never lose a captured
        // lead — the row is already committed and recoverable from the CRM.
        try {
            $this->notify($type, $reference, $data, $metadata);
        } catch (\Throwable $e) {
            Log::error('public.intake.notify_failed', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
        }

        Log::info('public.intake.received', [
            'lead_id'   => $leadId,
            'type'      => $type,
            'reference' => $reference,
            'ip'        => $ip,
        ]);

        return response()->json([
            'ok'        => true,
            'reference' => $reference,
        ], 201);
    }

    /**
     * Resolve the real visitor address.
     *
     * X-Real-IP is only trusted when the request actually arrived over our
     * loopback proxy (REMOTE_ADDR 127.0.0.1), because that proxy overwrites the
     * header with the true socket address. Requests reaching Laravel by any
     * other route fall back to the framework's own resolution, so a client
     * cannot spoof its address by inventing the header.
     */
    private function clientIp(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR', '');

        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $forwarded = trim((string) $request->headers->get('X-Real-IP', ''));

            if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }

        return (string) ($request->ip() ?? 'unknown');
    }

    private function notify(string $type, string $reference, array $data, array $metadata): void
    {
        $label = $type === 'challenge' ? 'Challenge application' : 'Advisory enquiry';

        $lines = [
            "New {$label} — markraymundo.com",
            "Reference: {$reference}",
            str_repeat('-', 46),
            'Name:     ' . $data['name'],
            'Email:    ' . $data['email'],
            'Company:  ' . ($data['company'] ?? '—'),
            'Role:     ' . ($data['role'] ?? '—'),
            'Website:  ' . ($data['website'] ?? '—'),
            'Phone:    ' . ($data['phone'] ?? '—'),
            'Team:     ' . ($data['team_size'] ?? '—'),
            'Timeline: ' . ($data['timeline'] ?? '—'),
            '',
            'Message:',
            $data['message'],
            '',
            str_repeat('-', 46),
            'Page:     ' . ($metadata['source_page'] ?? '—'),
            'Referrer: ' . ($metadata['referrer'] ?? '—'),
            'UTM:      ' . (empty($metadata['utm']) ? '—' : json_encode($metadata['utm'])),
            'IP:       ' . $metadata['client_ip'],
            '',
            'In CRM: workspace ' . self::WORKSPACE_ID . ', source ' . self::TYPES[$type] . '.',
        ];

        Mail::raw(implode("\n", $lines), function ($m) use ($label, $reference, $data) {
            // EM-3. The registry gives this purpose no reply-to precisely so the
            // ->replyTo below survives: answers go to the person who enquired.
            $m->getSymfonyMessage()->getHeaders()
                ->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, 'intake');

            $m->to(self::NOTIFY_TO)
              ->replyTo($data['email'], $data['name'])
              ->subject("[{$reference}] {$label} — {$data['name']}");
        });
    }
}
