<?php

namespace App\Engines\Ads\Services;

use App\Engines\TrafficDefense\Services\TrafficDefenseService;
use Throwable;

/**
 * AdInvalidTrafficFilter — general invalid traffic (GIVT) detection.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THIS IS A HARD GATE BEFORE ANY PAID FLIGHT MAY BE BILLED.
 * Selling unfiltered impressions is how an ad business loses its first three
 * advertisers permanently. Every classification here is RECORDED, never
 * silently dropped: `ad_events.is_invalid` + `invalid_reason`. Being able to
 * show an advertiser exactly what was filtered and why is worth more than the
 * impressions it removes.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * LAYERS
 *   1. Explicit AI/SEO crawler fingerprints (see note on duplication below)
 *   2. Generic bot heuristics + the workspace's own custom rules, via the
 *      existing TrafficDefenseService — which already does exactly this and is
 *      wired to `traffic_logs` (300k+ rows)
 *   3. Datacenter / hosting-provider ranges
 *   4. Structural signals: absent UA, no-JS, implausible interaction timing
 *
 * ON THE DUPLICATED CRAWLER LIST
 * `AeoTrafficLogger::CRAWLERS` holds the same fingerprints but is `private`, so
 * it cannot be reused without changing that class — which is on the published
 * request path and out of scope for this phase. The list below is therefore a
 * deliberate copy. IF YOU ADD A CRAWLER, ADD IT IN BOTH PLACES. The right fix
 * is to extract a shared `CrawlerFingerprints` support class; it is logged as
 * technical debt rather than done here, because touching AeoTrafficLogger means
 * touching a live request path.
 */
final class AdInvalidTrafficFilter
{
    public const REASON_CRAWLER    = 'crawler';
    public const REASON_DATACENTER = 'datacenter';
    public const REASON_NO_UA      = 'no_ua';
    public const REASON_NO_JS      = 'no_js';
    public const REASON_TIMING     = 'timing';
    public const REASON_RULE       = 'rule';
    public const REASON_TOKEN      = 'token';

    /** Mirrors AeoTrafficLogger::CRAWLERS — keep both in step. */
    private const CRAWLERS = [
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'anthropic-ai',
        'Claude-Web', 'PerplexityBot', 'Perplexity-User', 'Google-Extended',
        'Bytespider', 'Amazonbot', 'CCBot', 'cohere-ai', 'YouBot', 'PhindBot',
        'Diffbot', 'FacebookBot', 'Applebot-Extended', 'Bingbot',
        'Googlebot', 'Slurp', 'DuckDuckBot', 'Baiduspider', 'YandexBot',
        'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot',
    ];

    /** Generic non-browser signatures. */
    private const NON_BROWSER = [
        'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client',
        'java/', 'okhttp', 'axios/', 'node-fetch', 'headlesschrome', 'phantomjs',
        'scrapy', 'httpclient', 'libwww-perl', 'postman',
    ];

    /**
     * Known datacenter / cloud CIDR prefixes. Deliberately a short, high-signal
     * list rather than a full ASN database: a false positive here discards a
     * real impression we could have billed for, so precision beats recall.
     */
    private const DATACENTER_PREFIXES = [
        '3.', '13.', '15.', '18.', '34.', '35.', '52.', '54.',   // AWS / GCP
        '104.196.', '130.211.', '146.148.',                       // GCP
        '40.', '20.', '13.64.', '52.224.',                        // Azure
        '159.65.', '167.71.', '134.209.', '138.68.', '165.227.',  // DigitalOcean
        '128.199.', '178.62.', '188.166.',
        '45.32.', '45.63.', '108.61.',                            // Vultr
        '5.161.', '65.21.', '95.216.', '116.202.', '135.181.',    // Hetzner
    ];

    public function __construct(
        private readonly TrafficDefenseService $defense,
    ) {
    }

    /**
     * Classify a request.
     *
     * @param  array{user_agent?: ?string, ip?: ?string, referrer?: ?string, country?: ?string, js?: ?bool, dwell_ms?: ?int}  $signals
     * @return array{invalid: bool, reason: string|null, detail: string|null}
     */
    public function classify(array $signals, int $workspaceId = 0): array
    {
        $ua = trim((string) ($signals['user_agent'] ?? ''));
        $ip = trim((string) ($signals['ip'] ?? ''));

        // 1. No user agent at all — no real browser omits it.
        if ($ua === '') {
            return $this->invalid(self::REASON_NO_UA, 'empty user agent');
        }

        // 2. Explicit crawler fingerprints.
        foreach (self::CRAWLERS as $crawler) {
            if (stripos($ua, $crawler) !== false) {
                return $this->invalid(self::REASON_CRAWLER, $crawler);
            }
        }

        // 3. Non-browser clients.
        foreach (self::NON_BROWSER as $agent) {
            if (stripos($ua, $agent) !== false) {
                return $this->invalid(self::REASON_CRAWLER, $agent);
            }
        }

        // 4. Explicitly reported absence of JS. The tag only fires when JS ran,
        //    so this is a positive assertion from a caller that knows better.
        if (array_key_exists('js', $signals) && $signals['js'] === false) {
            return $this->invalid(self::REASON_NO_JS, 'client reported no javascript');
        }

        // 5. Implausible interaction timing — a click cannot precede the
        //    impression that produced it, nor land within human reaction time.
        if (isset($signals['dwell_ms']) && is_numeric($signals['dwell_ms'])) {
            $dwell = (int) $signals['dwell_ms'];

            if ($dwell < 0) {
                return $this->invalid(self::REASON_TIMING, 'negative dwell');
            }

            if ($dwell > 0 && $dwell < 50) {
                return $this->invalid(self::REASON_TIMING, "dwell {$dwell}ms below human reaction time");
            }
        }

        // 6. Datacenter ranges — real readers do not browse from a server farm.
        if ($ip !== '') {
            foreach (self::DATACENTER_PREFIXES as $prefix) {
                if (str_starts_with($ip, $prefix)) {
                    return $this->invalid(self::REASON_DATACENTER, $prefix);
                }
            }
        }

        // 7. Workspace custom rules + generic heuristics, reusing the existing
        //    engine rather than duplicating it.
        if ($workspaceId > 0) {
            try {
                $result = $this->defense->evaluateTraffic($workspaceId, [
                    'ip'         => $ip,
                    'user_agent' => $ua,
                    'referrer'   => (string) ($signals['referrer'] ?? ''),
                    'country'    => (string) ($signals['country'] ?? ''),
                ]);

                if (($result['action'] ?? 'allowed') === 'blocked') {
                    return $this->invalid(
                        self::REASON_RULE,
                        implode(',', array_slice((array) ($result['flags'] ?? []), 0, 3))
                    );
                }
            } catch (Throwable) {
                // Traffic defence being unavailable must not invalidate real
                // traffic — the explicit layers above have already run.
            }
        }

        return ['invalid' => false, 'reason' => null, 'detail' => null];
    }

    /** @return array{invalid: true, reason: string, detail: string} */
    private function invalid(string $reason, string $detail): array
    {
        return ['invalid' => true, 'reason' => $reason, 'detail' => $detail];
    }
}
