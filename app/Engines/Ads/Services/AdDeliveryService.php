<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AdDeliveryService — the logic behind the three public ad endpoints.
 *
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER
 * The endpoints themselves are not built yet: `routes/api.php` is an active
 * concurrency hotspot and the go-live is gated on the advertising terms. But
 * the *logic* is the part worth getting right and worth testing, and none of it
 * needs a request object. Putting it here means:
 *   - it is fully unit-testable today, with no HTTP and no routes;
 *   - the eventual controller is a thin shell that validates input and calls
 *     these three methods, so the risky wiring change is small and reviewable.
 *
 * SECURITY POSTURE — these are PUBLIC, UNAUTHENTICATED entry points
 *   - Every billable event must carry a valid, unexpired, unreplayed token.
 *     An event without one is recorded as INVALID (reason `token`), never
 *     accepted and never silently dropped.
 *   - The click redirect only ever sends a visitor to the creative's own stored
 *     `click_url`. The destination never comes from the request, so the
 *     endpoint cannot be used as an open redirect.
 *   - Nothing here trusts a client-reported creative, campaign or website id:
 *     they are read from the signed token's claims.
 */
final class AdDeliveryService
{
    public function __construct(
        private readonly AdDecisionService $decisions,
        private readonly AdTokenService $tokens,
        private readonly AdEventRecorder $events,
        private readonly AdSettingsService $settings,
    ) {
    }

    /**
     * POST /api/ads/decide
     *
     * @param  array<string,mixed>  $payload  website_id, slots[], ctx{}
     * @param  array<string,mixed>  $signals  server-derived: ip, user_agent, country
     * @return array{status: int, body: array<string,mixed>|null}
     */
    public function decide(array $payload, array $signals = []): array
    {
        try {
            $websiteId = (int) ($payload['website_id'] ?? 0);
            $slots     = array_values((array) ($payload['slots'] ?? []));

            if ($websiteId <= 0 || $slots === []) {
                return ['status' => 400, 'body' => ['error' => 'website_id and slots are required']];
            }

            $ctx = (array) ($payload['ctx'] ?? []);

            // Visitor country comes from Cloudflare's CF-IPCountry header on the
            // server, NEVER from the client — a client-supplied country would
            // let anyone claim to be in whichever geo an advertiser paid for.
            $viewportW = isset($ctx['viewport_w']) ? (int) $ctx['viewport_w'] : null;
            $viewportH = isset($ctx['viewport_h']) ? (int) $ctx['viewport_h'] : null;

            $context = [
                'visitor_country' => $this->country($signals['country'] ?? null),
                'device'          => $this->device($ctx['device'] ?? null),
                'language'        => $this->language($ctx['language'] ?? null),
                'page_type'       => $this->pageType($ctx['page_type'] ?? null),
                'ip_hash'         => $this->events->ipHash($signals['ip'] ?? null),
                // Ratio preference is a rendering hint, not a targeting filter —
                // it only separates creatives that are already commercially
                // equivalent, so it can never cost the publisher revenue.
                'preferred_ratio' => AdCreativeValidator::preferredRatio($viewportW, $viewportH),
            ];

            $fills = [];

            foreach (array_slice($slots, 0, 3) as $slotCode) {
                if (! is_string($slotCode) || $slotCode === '') {
                    continue;
                }

                $result = $this->decisions->decide($websiteId, $slotCode, $context);

                if ($result['fill'] === null) {
                    continue;
                }

                $fill  = $result['fill'];
                $token = $this->tokens->issue(
                    (int) $fill['creative_id'],
                    (int) $fill['campaign_id'],
                    $websiteId,
                    (int) $fill['slot_id'],
                );

                // A `request` event is recorded for every FILLED decision, so
                // fill rate and request volume are both measurable.
                $this->events->record(
                    AdEventRecorder::EVENT_REQUEST,
                    $websiteId,
                    (int) ($result['diagnostics']['gate']['workspace_id'] ?? 0),
                    (int) $fill['creative_id'],
                    (int) $fill['campaign_id'],
                    (int) $fill['slot_id'],
                    $signals,
                );

                $fills[] = [
                    'slot'           => $fill['slot'],
                    'type'           => $fill['type'],
                    'asset_url'      => $fill['asset_url'],
                    'html'           => $fill['html'],
                    'alt'            => $fill['alt'],
                    'label'          => $fill['label'],
                    'width'          => $fill['width'],
                    'height'         => $fill['height'],
                    'interstitial'   => $fill['interstitial'] ?? false,
                    'aspect_ratio'   => $fill['aspect_ratio'] ?? null,
                    'media_type'     => $fill['media_type'] ?? 'image',
                    'video_url'      => $fill['video_url'] ?? null,
                    'video_webm_url' => $fill['video_webm_url'] ?? null,
                    'poster_url'     => $fill['poster_url'] ?? null,
                    'duration_ms'    => $fill['duration_ms'] ?? null,
                    'click_url'      => $this->clickUrl($token['token']),
                    'token'          => $token['token'],
                ];
            }

            if ($fills === []) {
                // 204: a legitimate "nothing to show". The tag leaves the slot
                // hidden, so the page is exactly as it would have been.
                return ['status' => 204, 'body' => null];
            }

            return ['status' => 200, 'body' => ['fills' => $fills]];
        } catch (Throwable) {
            // Never surface an error to a tenant's page. No fill is always safe.
            return ['status' => 204, 'body' => null];
        }
    }

    /**
     * POST /api/ads/event — the measurement beacon.
     *
     * @return array{status: int, body: array<string,mixed>}
     */
    public function event(array $payload, array $signals = []): array
    {
        try {
            $event = (string) ($payload['event'] ?? '');
            $token = (string) ($payload['token'] ?? '');

            // `request` is server-recorded at decision time and must never be
            // accepted from a client; everything else the tag can legitimately
            // report, including the video quartiles and the dismissal.
            $acceptable = array_values(array_diff(
                AdEventRecorder::EVENTS,
                [AdEventRecorder::EVENT_REQUEST]
            ));

            if (! in_array($event, $acceptable, true)) {
                return ['status' => 400, 'body' => ['error' => 'unsupported event']];
            }

            $verified = $this->tokens->verify($token, $event);

            // Identifiers come from the TOKEN, never from the request body.
            $claims     = $verified['claims'] ?? [];
            $websiteId  = (int) ($claims['w'] ?? 0);
            $creativeId = (int) ($claims['cr'] ?? 0);
            $campaignId = (int) ($claims['ca'] ?? 0);
            $slotId     = (int) ($claims['s'] ?? 0);

            if (! $verified['valid']) {
                // Attribution depends on whether we can BELIEVE the token.
                //
                //  - Signature good but expired/replayed → the claims are ours,
                //    so this is a real client we can truthfully attribute. It is
                //    recorded as invalid, which is what explains a gap between
                //    decisions and impressions in an advertiser dispute.
                //
                //  - Signature forged or malformed → the claims are attacker-
                //    supplied. Recording them would let anyone inject events
                //    against ANY website id and pollute the invalid-traffic
                //    report we rely on as evidence. Rejected without a row;
                //    this belongs in rate limiting and application logs, not in
                //    advertiser-facing statistics.
                if ($verified['claims'] !== null && $websiteId > 0) {
                    $workspaceId = (int) (DB::table('websites')->where('id', $websiteId)->value('workspace_id') ?? 0);

                    $this->events->record(
                        $event, $websiteId, $workspaceId, $creativeId ?: null, $campaignId ?: null,
                        $slotId ?: null, $signals, AdInvalidTrafficFilter::REASON_TOKEN
                    );
                }

                return ['status' => 202, 'body' => [
                    'accepted' => false,
                    'invalid'  => true,
                    'reason'   => $verified['reason'],
                ]];
            }

            $workspaceId = (int) (DB::table('websites')->where('id', $websiteId)->value('workspace_id') ?? 0);

            $signals['dwell_ms'] = $payload['dwell_ms'] ?? null;
            $signals['js']       = $payload['js'] ?? true;

            $result = $this->events->record(
                $event, $websiteId, $workspaceId,
                $creativeId ?: null, $campaignId ?: null, $slotId ?: null,
                $signals
            );

            return ['status' => 202, 'body' => [
                'accepted' => $result['recorded'],
                'invalid'  => $result['invalid'],
            ]];
        } catch (Throwable) {
            return ['status' => 202, 'body' => ['accepted' => false]];
        }
    }

    /**
     * GET /api/ads/click/{token} — verify, record, then redirect.
     *
     * @return array{status: int, url: string|null, reason: string|null}
     */
    public function click(string $token, array $signals = []): array
    {
        try {
            $verified = $this->tokens->verify($token, AdEventRecorder::EVENT_CLICK);

            if (! $verified['valid']) {
                return ['status' => 400, 'url' => null, 'reason' => $verified['reason']];
            }

            $claims     = $verified['claims'];
            $creativeId = (int) ($claims['cr'] ?? 0);
            $websiteId  = (int) ($claims['w'] ?? 0);

            // The destination comes from the CREATIVE, never from the request.
            // This is what stops the endpoint being an open redirect.
            $creative = DB::table('ad_creatives')->where('id', $creativeId)->first(['click_url', 'review_state']);

            if (! $creative || $creative->review_state !== 'approved') {
                return ['status' => 404, 'url' => null, 'reason' => 'creative_unavailable'];
            }

            $workspaceId = (int) (DB::table('websites')->where('id', $websiteId)->value('workspace_id') ?? 0);

            $this->events->record(
                AdEventRecorder::EVENT_CLICK, $websiteId, $workspaceId,
                $creativeId, (int) ($claims['ca'] ?? 0), (int) ($claims['s'] ?? 0),
                $signals
            );

            return ['status' => 302, 'url' => $creative->click_url, 'reason' => null];
        } catch (Throwable) {
            return ['status' => 400, 'url' => null, 'reason' => 'click_error'];
        }
    }

    /**
     * GET /ads.js — decide whether to serve a real tag at all.
     *
     * Returns valid, harmless JavaScript on EVERY path, so a tenant page never
     * sees a script error regardless of plan, settings or state.
     *
     * @return array{js: string, served: bool, reason: string|null}
     */
    public function tag(int $websiteId, AdTagAssetService $assets, AdGateService $gate): array
    {
        try {
            if ($websiteId <= 0) {
                return ['js' => $assets->disabled('missing site'), 'served' => false, 'reason' => 'missing_site'];
            }

            if (! $this->settings->bool(AdSettings::MASTER_ENABLED)) {
                return ['js' => $assets->disabled('platform off'), 'served' => false, 'reason' => 'master_off'];
            }

            $decision = $gate->evaluate($websiteId);

            if (! $decision['allowed']) {
                return [
                    'js'     => $assets->disabled('not eligible'),
                    'served' => false,
                    'reason' => $decision['reason'],
                ];
            }

            return ['js' => $assets->build($websiteId), 'served' => true, 'reason' => null];
        } catch (Throwable) {
            return ['js' => $assets->disabled('unavailable'), 'served' => false, 'reason' => 'tag_error'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function clickUrl(string $token): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $origin = str_contains($host, 'levelupgrowth.io')
            ? 'https://' . $host
            : 'https://staging.levelupgrowth.io';

        return $origin . '/api/ads/click/' . rawurlencode($token);
    }

    private function country(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === 2 ? strtoupper($value) : null;
    }

    private function device(mixed $value): ?string
    {
        return in_array($value, ['mobile', 'tablet', 'desktop'], true) ? $value : null;
    }

    private function language(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z]{2}$/i', $value) ? strtolower($value) : null;
    }

    private function pageType(mixed $value): ?string
    {
        return in_array($value, ['home', 'blog_post', 'services', 'contact', 'listing'], true) ? $value : null;
    }
}
