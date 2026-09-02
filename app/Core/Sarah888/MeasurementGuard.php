<?php

namespace App\Core\Sarah888;

use App\Engines\SEO\Services\GaClient;
use App\Engines\SEO\Services\GscClient;
use Illuminate\Support\Facades\Log;

/**
 * TR-1 (REPORT-0027): DeepSeek can invent search/analytics numbers — "your organic traffic is up 23% this
 * month and you gained 1,240 sessions" — and CompletionGuard's STATE_REPORT whitelist lets numeric report
 * sentences through unchecked. This guard closes the one case that is fabrication BY DEFINITION: a specific
 * number tied to a Search-Console/Analytics metric when NEITHER source is connected, so there is no possible
 * origin for the figure. It deliberately does NOT touch a workspace that has a source connected (those numbers
 * may be real), and fails OPEN on any client error, so it never strips a legitimate report.
 *
 * Scope is limited to GSC/GA-sourced metrics (traffic, sessions, clicks, impressions, CTR, position, rank,
 * bounce, engagement). Task/article/lead counts are grounded elsewhere and are not touched here.
 */
class MeasurementGuard
{
    /** GSC/GA-sourced performance nouns. A specific number for these needs Search Console or Analytics. */
    private const METRIC = '(?:organic\s+)?(?:traffic|visitors?|sessions?|pageviews?|page\s+views?|clicks?|impressions?|click-?throughs?(?:\s+rate)?|ctr|bounce(?:\s+rate)?|(?:average\s+)?(?:search\s+)?positions?|rankings?|ranked|serps?|engagement(?:\s+rate)?|dwell\s+time)';

    public function sanitize(string $reply, int $wsId): array
    {
        $out = ['reply' => $reply, 'stripped' => []];
        if (trim($reply) === '' || $wsId <= 0) {
            return $out;
        }

        // Only act when there is NO possible data source. Any client error fails OPEN (never over-strip).
        try {
            $connected = app(GscClient::class)->isConnected($wsId) || app(GaClient::class)->isConnected($wsId);
        } catch (\Throwable $e) {
            return $out;
        }
        if ($connected) {
            return $out;
        }

        $metric  = '/\b' . self::METRIC . '\b/i';
        $numbered = [
            '/\b\d[\d,\.]*\s*%/',
            '/\b(?:up|down|increased?|decreased?|rose|fell|grew|dropped|gained|lost|climbed|jumped|doubled|tripled)\b[^.!?\n]{0,20}\d/i',
            '/\b\d[\d,\.]*\s+' . self::METRIC . '/i',
            '/\b' . self::METRIC . '\b[^.!?\n]{0,30}\d[\d,\.]*/i',
        ];

        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $stripped = [];
        foreach ($sentences as $s) {
            $isClaim = false;
            if (preg_match($metric, $s)) {
                foreach ($numbered as $rx) {
                    if (preg_match($rx, $s)) { $isClaim = true; break; }
                }
            }
            if ($isClaim) {
                $stripped[] = trim($s);
            } else {
                $kept[] = trim($s);
            }
        }

        if (empty($stripped)) {
            return $out;
        }

        $kept = array_values(array_filter($kept, fn ($s) => preg_match('/[a-z0-9]/i', $s) && mb_strlen($s) > 2));
        $clean = trim(implode(' ', $kept));
        $clean = preg_replace('/\s{2,}/', ' ', (string) $clean);
        $clean = trim(preg_replace('/\s+([.,!?])/', '$1', (string) $clean));

        $honest = "I don't have live traffic or ranking numbers yet — Search Console and Analytics aren't "
                . "connected, so I can't give real figures. Connect them in Insights and I'll report the actuals.";
        $clean = $clean === '' ? $honest : rtrim($clean, " \t") . ' ' . $honest;

        Log::warning('[Measurement] stripped an ungrounded performance metric (no GSC/GA connected)', [
            'workspace_id' => $wsId,
            'stripped'     => array_slice($stripped, 0, 3),
        ]);

        return ['reply' => $clean, 'stripped' => $stripped];
    }
}
