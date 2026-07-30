<?php

namespace App\Core\Engineer888\Signals;

/**
 * Application errors since the previous brief.
 *
 * Reads FORWARD from the byte offset recorded by the last snapshot, so the
 * count is genuinely "new since yesterday" rather than a rolling tail. The log
 * is a single 9.7MB file with no rotation, so a tail-window approach would
 * silently miss bursts.
 *
 * Handles truncation: if the file is now smaller than the stored offset it has
 * been rotated or cleared, so we start from zero rather than reading garbage.
 *
 * BASELINE. On the very first run there is no previous offset, so the scan
 * necessarily covers historical log content. That content is NOT new, and
 * reporting it as new was a real defect caught on the first live run — 2,628
 * five-day-old entries presented as having arrived since the last brief. The
 * baseline flag makes the distinction explicit so the rules can word it
 * honestly; novelty detection begins with the second brief.
 */
final class ErrorSignal implements Signal
{
    public function __construct(
        private string $logPath,
        private int $sinceOffset = 0,
        private bool $isBaseline = false,
        private string $productionChannel = 'staging',
    ) {}

    public function key(): string { return 'errors'; }
    public function label(): string { return 'Errors'; }

    public function collect(): array
    {
        if (! is_file($this->logPath)) {
            return ['available' => false, 'reason' => 'log file absent', 'offset' => 0, 'baseline' => $this->isBaseline];
        }

        $size = (int) filesize($this->logPath);
        $from = $this->sinceOffset;
        $rotated = false;

        if ($from > $size) {
            $rotated = true;
            $from = 0;
        }

        // Bound the read. A first run against 9.7MB, or a huge burst, must not
        // consume unbounded memory.
        $maxRead = 4 * 1024 * 1024;
        $truncatedRead = false;
        if ($size - $from > $maxRead) {
            $from = $size - $maxRead;
            $truncatedRead = true;
        }

        $counts = ['ERROR' => 0, 'CRITICAL' => 0, 'EMERGENCY' => 0, 'ALERT' => 0];
        $samples = [];

        // Test runs write into this same file — `testing.ERROR` lines appear
        // alongside `staging.ERROR`. Counting them as production errors made the
        // brief report 58 new production failures that were another session's
        // test suite. Counted separately: the production number stays honest,
        // and the pollution itself becomes visible instead of being swallowed.
        $foreignCounts = [];
        $foreignSamples = [];

        $fh = @fopen($this->logPath, 'rb');
        if ($fh === false) {
            return ['available' => false, 'reason' => 'log unreadable', 'offset' => $size, 'baseline' => $this->isBaseline];
        }
        fseek($fh, $from);
        while (($line = fgets($fh)) !== false) {
            // Monolog writes "[timestamp] <channel>.<LEVEL>: message".
            if (! preg_match('/^\[[^\]]+\]\s+([A-Za-z0-9_.\-]+)\.(ERROR|CRITICAL|EMERGENCY|ALERT):/', $line, $m)) {
                continue;
            }
            $channel = $m[1];
            $level = $m[2];

            if ($channel === $this->productionChannel) {
                $counts[$level]++;
                if (count($samples) < 5) {
                    // Message only — never the stack trace or any payload.
                    $samples[] = trim(mb_substr($line, 0, 200));
                }
            } else {
                $foreignCounts[$channel] = ($foreignCounts[$channel] ?? 0) + 1;
                if (count($foreignSamples) < 2) {
                    $foreignSamples[] = trim(mb_substr($line, 0, 160));
                }
            }
        }
        fclose($fh);

        return [
            'available'         => true,
            'offset'            => $size,
            'baseline'          => $this->isBaseline,
            'bytes_scanned'     => max(0, $size - $from),
            'rotated'           => $rotated,
            'partial_scan'      => $truncatedRead,
            'channel'           => $this->productionChannel,
            'counts'            => $counts,
            'total'             => array_sum($counts),
            'samples'           => $samples,
            'foreign_counts'    => $foreignCounts,
            'foreign_total'     => array_sum($foreignCounts),
            'foreign_samples'   => $foreignSamples,
        ];
    }
}
