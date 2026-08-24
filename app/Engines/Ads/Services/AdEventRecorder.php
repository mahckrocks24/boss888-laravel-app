<?php

namespace App\Engines\Ads\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AdEventRecorder — write one measurement event.
 *
 * PRIVACY BOUNDARY (this is what keeps clause 9 of the advertising terms true)
 * `ip_hash` is salted with APP_KEY **and the calendar date**, so the same
 * visitor produces a DIFFERENT hash tomorrow. Rows therefore cannot be joined
 * across days to reconstruct a person's browsing history. That single design
 * choice is the line between aggregate measurement and behavioural profiling —
 * do not "optimise" the date out of the salt.
 *
 * INVALID TRAFFIC IS RECORDED, NOT DROPPED
 * A filtered event still gets a row, flagged `is_invalid` with a reason. That is
 * what makes the invalid-traffic report possible, and that report is what
 * survives an advertiser dispute.
 *
 * NEVER THROWS. Measurement runs on a public endpoint; a failed write is logged
 * and swallowed rather than turned into an error on a tenant's page.
 */
final class AdEventRecorder
{
    public const EVENT_REQUEST    = 'request';
    public const EVENT_IMPRESSION = 'impression';
    public const EVENT_VIEWABLE   = 'viewable';
    public const EVENT_CLICK      = 'click';

    // ── Interstitial / video ────────────────────────────────────────────
    public const EVENT_VIDEO_START    = 'video_start';
    public const EVENT_VIDEO_Q1       = 'video_q1';
    public const EVENT_VIDEO_Q2       = 'video_q2';
    public const EVENT_VIDEO_Q3       = 'video_q3';
    public const EVENT_VIDEO_COMPLETE = 'video_complete';
    public const EVENT_DISMISS        = 'dismiss';

    /** Every event this recorder will accept. */
    public const EVENTS = [
        self::EVENT_REQUEST, self::EVENT_IMPRESSION, self::EVENT_VIEWABLE, self::EVENT_CLICK,
        self::EVENT_VIDEO_START, self::EVENT_VIDEO_Q1, self::EVENT_VIDEO_Q2,
        self::EVENT_VIDEO_Q3, self::EVENT_VIDEO_COMPLETE, self::EVENT_DISMISS,
    ];

    /** Events that money can depend on, and which therefore require a valid token. */
    public const BILLABLE_EVENTS = [
        self::EVENT_IMPRESSION, self::EVENT_VIEWABLE, self::EVENT_CLICK, self::EVENT_VIDEO_COMPLETE,
    ];

    public function __construct(
        private readonly AdInvalidTrafficFilter $filter,
    ) {
    }

    /**
     * @param  array<string,mixed>  $signals  user_agent, ip, referrer, country, device, js, dwell_ms
     * @return array{recorded: bool, invalid: bool, reason: string|null}
     */
    public function record(
        string $event,
        int $websiteId,
        int $workspaceId,
        ?int $creativeId = null,
        ?int $campaignId = null,
        ?int $slotId = null,
        array $signals = [],
        ?string $forcedInvalidReason = null,
    ): array {
        try {
            if (! in_array($event, self::EVENTS, true)) {
                return ['recorded' => false, 'invalid' => true, 'reason' => 'unknown_event'];
            }

            // A caller may already know the event is invalid (e.g. a token that
            // failed verification). That reason wins — it is more specific than
            // anything the heuristic filter would conclude.
            if ($forcedInvalidReason !== null) {
                $verdict = ['invalid' => true, 'reason' => $forcedInvalidReason, 'detail' => null];
            } else {
                $verdict = $this->filter->classify($signals, $workspaceId);
            }

            DB::table('ad_events')->insert([
                'event'           => $event,
                'creative_id'     => $creativeId,
                'campaign_id'     => $campaignId,
                'website_id'      => $websiteId,
                'workspace_id'    => $workspaceId,
                'slot_id'         => $slotId,
                'visitor_country' => $this->country($signals),
                'device'          => $this->device($signals),
                'ip_hash'         => $this->ipHash($signals['ip'] ?? null),
                'ua_hash'         => $this->uaHash($signals['user_agent'] ?? null),
                'is_invalid'      => $verdict['invalid'],
                'invalid_reason'  => $verdict['reason'],
                'occurred_at'     => now(),
            ]);

            // Frequency capping counts only VALID impressions. Capping on
            // invalid traffic would let a bot exhaust a real visitor's cap.
            if (! $verdict['invalid'] && $event === self::EVENT_IMPRESSION && $campaignId !== null) {
                $this->bumpFrequency($campaignId, $signals['ip'] ?? null);
            }

            return [
                'recorded' => true,
                'invalid'  => (bool) $verdict['invalid'],
                'reason'   => $verdict['reason'],
            ];
        } catch (Throwable $e) {
            Log::warning('ADS888 event write failed', [
                'event'      => $event,
                'website_id' => $websiteId,
                'error'      => $e->getMessage(),
            ]);

            return ['recorded' => false, 'invalid' => false, 'reason' => 'write_failed'];
        }
    }

    /**
     * Salted, DATE-ROTATING hash. The date in the salt is deliberate — see the
     * privacy note in the class docblock.
     */
    public function ipHash(?string $ip): ?string
    {
        if (! is_string($ip) || trim($ip) === '') {
            return null;
        }

        return hash('sha256', $ip . '|' . config('app.key') . '|' . now()->toDateString());
    }

    private function uaHash(?string $ua): ?string
    {
        if (! is_string($ua) || trim($ua) === '') {
            return null;
        }

        return hash('sha256', $ua . '|' . config('app.key'));
    }

    private function country(array $signals): ?string
    {
        $country = $signals['country'] ?? null;

        if (! is_string($country) || strlen($country) !== 2) {
            return null;
        }

        return strtoupper($country);
    }

    private function device(array $signals): ?string
    {
        $device = $signals['device'] ?? null;

        return in_array($device, ['mobile', 'tablet', 'desktop'], true) ? $device : null;
    }

    private function bumpFrequency(int $campaignId, ?string $ip): void
    {
        $hash = $this->ipHash($ip);

        if ($hash === null) {
            return;
        }

        try {
            $affected = DB::table('ad_frequency')
                ->where('ip_hash', $hash)
                ->where('campaign_id', $campaignId)
                ->whereDate('bucket_date', now()->toDateString())
                ->increment('count');

            if ($affected === 0) {
                DB::table('ad_frequency')->insert([
                    'ip_hash'     => $hash,
                    'campaign_id' => $campaignId,
                    'bucket_date' => now()->toDateString(),
                    'count'       => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        } catch (Throwable) {
            // A duplicate-key race just means another request counted it first.
        }
    }
}
