<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1K — commitment-state guard.
 *
 * Traces to S1I-N03, observed on the live HTTP path immediately after Phase 1I
 * closed the extraction defects. Asked "how many items are in the winter
 * tasting programme, and list them?", Sarah answered:
 *
 *   "The winter tasting program currently includes 5 items: door list, cellar
 *    audit, corkage policy, chef briefing, and the guest feedback card."
 *
 * The guest feedback card had been cancelled two turns earlier, and she had
 * said so correctly when asked directly. Four items were live, not five. The
 * executive record was right; the answer drawn from it was not.
 *
 * WHY A GUARD AS WELL AS THE RENDER MARKER
 * CommitmentStore now marks every cancelled line [CANCELLED] rather than
 * relying on a block header, which is the structural half of the fix. That
 * makes the mistake harder, not impossible — the same reply had a correct
 * cancellation answer one turn later, so the information was plainly reaching
 * her. Presenting dead work as live is the kind of error an owner acts on, so
 * it gets a deterministic check that does not depend on the model reading a
 * marker.
 *
 * IT CORRECTS, IT DOES NOT REWRITE
 * Editing a cancelled item out of the middle of a prose sentence would mangle
 * counts, conjunctions and list punctuation. The guard appends one factual
 * sentence naming what is dead. It deliberately does NOT restate a live count:
 * the owner's question is usually scoped to one project while the store is
 * scoped to the workspace, and answering a narrower question with a wider
 * number would replace one wrong number with another.
 */
class CommitmentStateGuard
{
    /**
     * Titles shorter than this are not matched at all.
     *
     * A cancelled commitment called "deck" or "rota" would otherwise fire on
     * any incidental use of the word. Precision matters more than coverage
     * here: a spurious "that is cancelled" correction contradicts a correct
     * answer, which is worse than the miss it prevents.
     */
    private const MIN_TITLE = 6;

    /** Language that shows the reply already knows the item is dead. */
    private const CANCELLED_CONTEXT = '/\b(?:cancel\w*|canceled|cancelled|dead|dropp?ed|drop|scrapp?ed|'
        . 'abandoned|killed|shelved|no longer|not (?:live|active|going ahead)|off the (?:list|table)|'
        . 'removed from)\b/i';

    public function __construct(private CommitmentStore $store) {}

    /**
     * @return array{reply:string, corrected:bool, items:array<string>}
     */
    public function validate(string $reply, int $wsId): array
    {
        $out = ['reply' => $reply, 'corrected' => false, 'items' => []];
        if (trim($reply) === '') return $out;

        try {
            $cancelled = $this->store->cancelled($wsId);
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] CommitmentStateGuard could not read cancellations', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return $out;
        }
        if (!$cancelled) return $out;

        $misstated = [];
        foreach ($cancelled as $c) {
            $title = trim((string) $c->title);
            if (mb_strlen($title) < self::MIN_TITLE) continue;
            if (!$this->mentions($reply, $title)) continue;
            if ($this->knowsItIsDead($reply, $title)) continue;
            if (!in_array($title, $misstated, true)) $misstated[] = $title;
        }
        if (!$misstated) return $out;

        $out['items'] = $misstated;
        $out['corrected'] = true;
        $out['reply'] = rtrim($reply) . "\n\n" . $this->correction($misstated);

        Log::info('[Sarah888] CommitmentStateGuard corrected cancelled work presented as live', [
            'ws' => $wsId, 'items' => $misstated,
        ]);
        return $out;
    }

    /** Whole-phrase, case-insensitive, on word boundaries. */
    private function mentions(string $reply, string $title): bool
    {
        return (bool) preg_match('/\b' . preg_quote($title, '/') . '\b/i', $reply);
    }

    /**
     * Does the sentence carrying this title already say it is cancelled?
     *
     * Scoped to the sentence, not the whole reply: a reply can correctly report
     * one cancellation and then wrongly list a different dead item as live, and
     * checking the whole text would let the second error hide behind the first.
     */
    private function knowsItIsDead(string $reply, string $title): bool
    {
        foreach (preg_split('/(?<=[.!?])\s+|\n+/', $reply) ?: [] as $sentence) {
            if (!$this->mentions($sentence, $title)) continue;
            if (!preg_match(self::CANCELLED_CONTEXT, $sentence)) return false;
        }
        return true;
    }

    private function correction(array $items): string
    {
        $one  = count($items) === 1;
        $list = $one
            ? $items[0]
            : implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[count($items) - 1];

        return 'Correction: ' . $list . ($one ? ' is' : ' are') . ' cancelled — '
             . ($one ? 'it is' : 'they are') . ' not live work and should not be counted among live items.';
    }
}
