<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;

/**
 * AdCreativeValidator — enforce the creative spec at review time.
 *
 * WHY REJECT RATHER THAN FIX
 * Every rule here refuses a non-conforming asset instead of silently correcting
 * it. Cropping an advertiser's creative to fit, or trimming a 20-second video to
 * 15, changes the message they paid to run and they will find out from a
 * screenshot, not from us. Refusing is recoverable in one email; silently
 * altering is not.
 *
 * Enforced:
 *   - aspect ratio is one the slot accepts (±1% for rounding)
 *   - minimum short edge, so a 200px asset is never upscaled into a modal
 *   - video: hard duration cap, poster required, file size ceiling
 */
final class AdCreativeValidator
{
    /** Ratio tolerance — 1080x1081 is a rounding artefact, not a different ratio. */
    private const RATIO_TOLERANCE = 0.01;

    /** Minimum short edge for an interstitial creative. */
    public const MIN_SHORT_EDGE = 600;

    /** Operator-configurable; the constant is the shipped default and fallback. */
    private function minShortEdge(): int
    {
        return max(1, $this->settings->int(AdSettings::CREATIVE_MIN_SHORT_EDGE));
    }

    /** Supported ratio labels → their decimal value (w/h). */
    public const RATIOS = [
        '1:1' => 1.0,
        '3:4' => 0.75,
        '4:5' => 0.8,
    ];

    public function __construct(
        private readonly AdSettingsService $settings,
    ) {
    }

    /**
     * @param  array<string,mixed>  $creative  media_type, width, height, duration_ms,
     *                                         poster_url, video_url, asset_url, file_bytes
     * @param  array<int,string>|null  $allowedRatios  from the slot; null = any known ratio
     * @return array{valid: bool, errors: array<int,string>, ratio: string|null}
     */
    public function validate(array $creative, ?array $allowedRatios = null): array
    {
        $errors = [];

        $width  = (int) ($creative['width'] ?? 0);
        $height = (int) ($creative['height'] ?? 0);
        $ratio  = null;

        // ── Dimensions and ratio ────────────────────────────────────────
        if ($width <= 0 || $height <= 0) {
            $errors[] = 'width and height are required';
        } else {
            $ratio = $this->detectRatio($width, $height);

            if ($ratio === null) {
                $errors[] = sprintf(
                    '%dx%d is not a supported aspect ratio (accepted: %s)',
                    $width, $height, implode(', ', array_keys(self::RATIOS))
                );
            } elseif ($allowedRatios !== null && ! in_array($ratio, $allowedRatios, true)) {
                $errors[] = sprintf(
                    'ratio %s is not accepted by this slot (accepts: %s)',
                    $ratio, implode(', ', $allowedRatios)
                );
            }

            if (min($width, $height) < $this->minShortEdge()) {
                $errors[] = sprintf(
                    'short edge %dpx is below the %dpx minimum — it would be upscaled and look soft',
                    min($width, $height), $this->minShortEdge()
                );
            }
        }

        // ── Video-specific ──────────────────────────────────────────────
        if (($creative['media_type'] ?? 'image') === 'video') {
            $maxSeconds = max(1, $this->settings->int(AdSettings::MODAL_MAX_VIDEO_SECONDS));
            $duration   = (int) ($creative['duration_ms'] ?? 0);

            if ($duration <= 0) {
                $errors[] = 'duration_ms is required for a video creative';
            } elseif ($duration > $maxSeconds * 1000) {
                $errors[] = sprintf(
                    'video is %.1fs — the hard cap is %ds. Re-cut it; we will not trim it for you.',
                    $duration / 1000, $maxSeconds
                );
            }

            if (empty($creative['poster_url'])) {
                $errors[] = 'poster_url is required for video — it is shown while loading and if playback fails';
            }

            if (empty($creative['video_url'])) {
                $errors[] = 'video_url is required for a video creative';
            }

            $maxBytes = max(1, $this->settings->int(AdSettings::MODAL_MAX_VIDEO_BYTES));
            $bytes    = (int) ($creative['file_bytes'] ?? 0);

            if ($bytes > $maxBytes) {
                $errors[] = sprintf(
                    'video is %.1f MB — the ceiling is %.1f MB. Every completed view pays that in bandwidth.',
                    $bytes / 1048576, $maxBytes / 1048576
                );
            }
        } elseif (empty($creative['asset_url']) && empty($creative['html'])) {
            $errors[] = 'an image creative needs asset_url (or html)';
        }

        return [
            'valid'  => $errors === [],
            'errors' => $errors,
            'ratio'  => $ratio,
        ];
    }

    /** Nearest supported ratio within tolerance, or null. */
    public function detectRatio(int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $actual = $width / $height;

        foreach (self::RATIOS as $label => $value) {
            if (abs($actual - $value) <= self::RATIO_TOLERANCE * $value) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Which ratio suits this viewport best?
     *
     * Portrait viewports (most phone sessions) prefer 3:4; landscape and desktop
     * prefer 1:1. A portrait creative constrained to 82vh on a landscape phone
     * renders around 320x240 — legible for one strong image, not for copy — so
     * the preference matters.
     */
    public static function preferredRatio(?int $viewportW, ?int $viewportH): string
    {
        if ($viewportW === null || $viewportH === null || $viewportW <= 0 || $viewportH <= 0) {
            return '1:1';
        }

        return $viewportH > $viewportW ? '3:4' : '1:1';
    }
}
