<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1B slice 1B.3 — apply extracted intents to the commitment store.
 *
 * Sits between CommitmentExtractor (what did the CEO say?) and CommitmentStore
 * (what is the executive record?). It exists so the chat route needs one call
 * and so the resolution rules — which are the dangerous part — live in one
 * testable place.
 *
 * RESOLUTION REFUSES TO GUESS. "Cancel the audiobook" must cancel the audiobook
 * or nothing. Cancelling the wrong commitment because a title half-matched is
 * far worse than cancelling none: the CEO would not see it happen, and the
 * record would be silently wrong. So a reference resolves only on a clear
 * token-overlap win, and an unresolved reference is logged and left alone.
 */
class CommitmentSync
{
    /** Minimum share of the reference's words that must appear in the title. */
    private const MATCH_THRESHOLD = 0.6;

    /** How far ahead of the runner-up the winner must be to count as unambiguous. */
    private const MARGIN = 0.15;

    /**
     * How alike two titles must be, in BOTH directions, to be the same
     * commitment restated rather than two related ones.
     *
     * Deliberately far above MATCH_THRESHOLD. A reference only has to identify
     * a commitment; a duplicate has to BE it. "wholesale deck" and "wholesale
     * target" identify each other well enough to resolve a cancellation and are
     * emphatically not the same obligation.
     */
    private const DUPLICATE_THRESHOLD = 0.85;

    /**
     * The commitment most recently created or referred to in this turn.
     *
     * "Nora owns that." is how an executive actually assigns work, and the
     * extractor can only report that the target was the word "that". Without a
     * referent the sync layer recorded a live commitment literally titled
     * "that", owned by Nora — so the Phase 1H answer to "what's the wholesale
     * target and who owns it?" said the owner was the CEO themselves. The
     * antecedent is nearly always the thing named immediately before, which is
     * knowable here and nowhere else.
     */
    private ?int $lastTouchedId = null;

    public function __construct(
        private CommitmentExtractor $extractor,
        private CommitmentStore $store,
    ) {}

    /**
     * Never throws. A failure here must not cost the user their reply — the
     * chat path is the customer-facing surface and the commitment record is a
     * derived artefact of it, not the other way round.
     */
    public function syncFromMessage(int $wsId, string $text, array $corr): array
    {
        $applied = ['recorded' => 0, 'cancelled' => 0, 'renamed' => 0, 'reassigned' => 0,
                    'rescheduled' => 0, 'proposed' => 0, 'merged' => 0, 'unresolved' => 0];
        $this->lastTouchedId = null;

        try {
            $items = $this->extractor->extract($text);
            if (!$items) return $applied;

            foreach ($items as $item) {
                $conf = (float) ($item['confidence'] ?? 0);
                $base = [
                    'conversation_id'   => $corr['conversation_id'] ?? null,
                    'source_message_id' => $corr['user_message_id'] ?? null,
                    'execution_id'      => $corr['execution_id'] ?? null,
                    'confidence'        => $conf,
                    'extraction_method' => 'explicit',
                ];

                // A question is never a commitment, not even a pending one.
                // "When must the ChefListed migration finish?" was being stored
                // as a proposed commitment titled "the ChefListed migration
                // finish?" — pure noise that accumulates every time the owner
                // asks about their own record.
                if (($item['reason'] ?? '') === 'interrogative') {
                    continue;
                }

                // Below the threshold nothing is applied to existing state — a
                // low-confidence read must never cancel or rename something.
                if ($conf < CommitmentStore::CONFIRM_THRESHOLD) {
                    $this->store->record($wsId, $base + [
                        'title'  => $item['title'] ?? ($item['target'] ?? 'unclear commitment'),
                        'status' => 'proposed',
                        'description' => 'Low-confidence extraction — confirm before acting. Source: '
                                       . ($item['source_text'] ?? ''),
                    ]);
                    $applied['proposed']++;
                    continue;
                }

                switch ($item['type']) {
                    case 'commitment':
                        // A restatement enriches the existing record instead of
                        // forking it. See CommitmentStore::enrich().
                        $existing = $this->findEquivalent($wsId, $item['title']);
                        if ($existing) {
                            $this->store->enrich($wsId, (int) $existing->id, [
                                'deadline'      => $item['deadline'] ?? null,
                                'deadline_text' => $item['deadline_text'] ?? null,
                                'description'   => $item['source_text'] ?? null,
                            ]);
                            $this->lastTouchedId = (int) $existing->id;
                            $applied['merged']++;
                            break;
                        }
                        $this->lastTouchedId = $this->store->record($wsId, $base + [
                            'title'         => $item['title'],
                            'deadline'      => $item['deadline'] ?? null,
                            'deadline_text' => $item['deadline_text'] ?? null,
                            'description'   => $item['source_text'] ?? null,
                        ]);
                        $applied['recorded']++;
                        break;

                    case 'ownership':
                        $target = $this->resolveRef($wsId, $item, $corr);
                        if ($target) {
                            $this->store->reassign($wsId, (int) $target->id, $item['owner']);
                            $this->lastTouchedId = (int) $target->id;
                            $applied['reassigned']++;
                        } elseif (!empty($item['target_is_pronoun'])) {
                            // "Nora owns that" with nothing to point at. A row
                            // titled "that" is unusable, so the statement is
                            // dropped and logged rather than half-recorded.
                            $applied['unresolved']++;
                            Log::info('[Sarah888] ownership pronoun had no antecedent', [
                                'ws' => $wsId, 'owner' => $item['owner'] ?? null,
                                'source_message_id' => $corr['user_message_id'] ?? null,
                            ]);
                        } else {
                            // No existing commitment to reassign: the ownership
                            // statement IS the commitment. Recording it is
                            // correct — the CEO named work and an owner.
                            $this->lastTouchedId = $this->store->record($wsId, $base + [
                                'title'         => $item['target'] ?: ('Work owned by ' . $item['owner']),
                                'owner'         => $item['owner'],
                                'deadline'      => $item['deadline'] ?? null,
                                'deadline_text' => $item['deadline_text'] ?? null,
                                'description'   => $item['source_text'] ?? null,
                            ]);
                            $applied['recorded']++;
                        }
                        break;

                    case 'cancellation':
                        $target = $this->resolveRef($wsId, $item, $corr);
                        if ($target) {
                            $this->store->cancel($wsId, (int) $target->id, 'cancelled in conversation');
                            $this->lastTouchedId = (int) $target->id;
                            $applied['cancelled']++;
                            break;
                        }
                        // Nothing matched. The cancellation is logged and the
                        // record is left alone.
                        //
                        // Phase 1I briefly recorded these as inert 'cancelled'
                        // rows so that "which two things did I cancel?" could be
                        // answered even for untracked work. That was the wrong
                        // fix and it is deliberately not here: it manufactured
                        // commitments out of vague references — a row titled
                        // "thing we talked about earlier" — and it let a phrase
                        // naming work in another workspace create a row in this
                        // one. The Phase 1H failure it was aimed at had a real
                        // cause, the enumeration splitter, which is fixed above;
                        // once the items exist, these cancellations resolve to
                        // them normally.
                        //
                        // KNOWN LIMITATION (S1I-N01): cancelling something that
                        // was never tracked leaves no durable trace beyond this
                        // log line. Refusing to guess is worth that.
                        $applied['unresolved']++;
                        Log::info('[Sarah888] cancellation referenced nothing tracked', [
                            'ws' => $wsId, 'ref' => $item['target'] ?? '',
                            'pronoun' => !empty($item['target_is_pronoun']),
                            'source_message_id' => $corr['user_message_id'] ?? null,
                        ]);
                        break;

                    case 'rename':
                        $target = $item['target'] ? $this->resolveRef($wsId, $item, $corr) : null;
                        if ($target && !empty($item['new_title'])) {
                            $new = $this->store->supersede($wsId, (int) $target->id, ['title' => $item['new_title']]);
                            if ($new) $this->lastTouchedId = (int) $new;
                            $applied['renamed']++;
                            break;
                        }
                        // "Rename the winter market residency." names the thing
                        // but not the new name — that arrives in the next
                        // clause as "It's Project Kiln now". Holding the
                        // resolved target as the antecedent is the whole point
                        // of this branch: without it the pronoun in the next
                        // clause falls back to whatever was recorded most
                        // recently, which in Phase 1L was an unrelated
                        // commitment. Nothing is mutated here.
                        if ($target && empty($item['new_title'])) {
                            $this->lastTouchedId = (int) $target->id;
                            Log::info('[Sarah888] rename intent held, awaiting the new name', [
                                'ws' => $wsId, 'commitment_id' => $target->id,
                            ]);
                            break;
                        }
                        $applied['unresolved']++;
                        break;

                    case 'deadline_change':
                        $target = $this->resolveRef($wsId, $item, $corr);
                        if ($target) {
                            $this->store->reschedule($wsId, (int) $target->id,
                                $item['deadline'] ?? null, $item['deadline_text'] ?? null);
                            $this->lastTouchedId = (int) $target->id;
                            $applied['rescheduled']++;
                        } else {
                            $applied['unresolved']++;
                        }
                        break;
                }
            }

            if (array_sum($applied) > 0) {
                Log::info('[Sarah888] commitments synced from chat', $applied + [
                    'ws' => $wsId, 'source_message_id' => $corr['user_message_id'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // Deliberately swallowed. Losing a commitment is recoverable —
            // the CEO can restate it. Losing the reply is not.
            Log::error('[Sarah888] commitment sync failed', [
                'ws' => $wsId, 'error' => $e->getMessage(),
                'source_message_id' => $corr['user_message_id'] ?? null,
            ]);
        }
        return $applied;
    }

    /**
     * Resolve an intent's target, following a pronoun to its antecedent.
     *
     * The antecedent is whatever this turn last touched; failing that, the most
     * recent live commitment in this conversation. Both are bounded and
     * workspace-scoped — a pronoun never reaches across conversations, because
     * "cancel that" said on Tuesday must not kill something named on Monday.
     */
    private function resolveRef(int $wsId, array $item, array $corr): ?object
    {
        if (empty($item['target_is_pronoun'])) {
            return $this->resolve($wsId, (string) ($item['target'] ?? ''));
        }

        if ($this->lastTouchedId !== null) {
            $row = $this->store->find($wsId, $this->lastTouchedId);
            if ($row && in_array($row->status, CommitmentStore::LIVE, true)) return $row;
        }

        $conversationId = $corr['conversation_id'] ?? null;
        if (!$conversationId) return null;

        return DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)
            ->where('conversation_id', $conversationId)
            ->whereIn('status', CommitmentStore::LIVE)
            ->orderByDesc('id')
            ->first(['id', 'title', 'owner', 'status', 'deadline', 'deadline_text', 'description', 'objective']);
    }

    /**
     * The same commitment already on record, or null.
     *
     * Symmetric containment: every content word of each title must appear in
     * the other. "site rebuild" and "site rebuild must finish by 28 February"
     * are the same obligation stated twice; "wholesale deck" and "wholesale
     * target" are not.
     */
    private function findEquivalent(int $wsId, string $title): ?object
    {
        $t = $this->tokens($title);
        if (!$t) return null;

        $rows = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)
            ->whereIn('status', CommitmentStore::LIVE)
            ->get(['id', 'title', 'owner', 'status', 'deadline', 'deadline_text', 'description', 'objective']);

        foreach ($rows as $row) {
            $r = $this->tokens((string) $row->title);
            if (!$r) continue;
            if ($this->numbersDiffer($title, (string) $row->title)) continue;
            $shared = count(array_intersect($t, $r));
            if ($shared / count($t) >= self::DUPLICATE_THRESHOLD
                && $shared / count($r) >= self::DUPLICATE_THRESHOLD) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Find the live commitment a phrase refers to, or null.
     *
     * Token overlap rather than LIKE: "the audiobook" must match "Audiobook"
     * and "audiobook edition", but "the print quote" must not match "the
     * quote from the printer for the second run" more strongly than the real
     * "Print quote" row. Requires both a threshold and a margin over the
     * runner-up, so an ambiguous reference resolves to nothing.
     */
    public function resolve(int $wsId, string $ref): ?object
    {
        $refTokens = $this->tokens($ref);
        if (!$refTokens) return null;

        $rows = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)
            ->whereIn('status', CommitmentStore::LIVE)
            ->get(['id', 'title', 'owner', 'status']);
        if ($rows->isEmpty()) return null;

        // Numeric discriminators are decisive, not just another token.
        // "workstream number 1" and "workstream number 2" share two of three
        // tokens, which cleared the 0.6 threshold and made every new numbered
        // commitment resolve to the previous one — 45 distinct items collapsed
        // into 1. Executives enumerate work exactly this way ("article 3",
        // "phase 2", "batch 12"), so the number IS the identity.
        $refNums = $this->numbers($ref);

        $scored = [];
        foreach ($rows as $row) {
            $t = $this->tokens((string) $row->title);
            if (!$t) continue;
            $rowNums = $this->numbers((string) $row->title);
            // If both sides carry numbers and none coincide, they are different
            // things however similar the words are.
            if ($refNums && $rowNums && !array_intersect($refNums, $rowNums)) continue;
            $hit = count(array_intersect($refTokens, $t));
            $scored[] = ['row' => $row, 'score' => $hit / count($refTokens)];
        }
        if (!$scored) return null;

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = $scored[0];
        if ($best['score'] < self::MATCH_THRESHOLD) return null;

        $runnerUp = $scored[1]['score'] ?? 0.0;
        if ($best['score'] - $runnerUp < self::MARGIN && $runnerUp >= self::MATCH_THRESHOLD) {
            // Two plausible targets. Refuse rather than pick one.
            Log::info('[Sarah888] ambiguous commitment reference — refused to guess', [
                'ws' => $wsId, 'ref' => $ref,
                'candidates' => array_map(fn ($s) => $s['row']->title, array_slice($scored, 0, 3)),
            ]);
            return null;
        }
        return $best['row'];
    }

    /** Two phrases carrying different numbers are different things. */
    private function numbersDiffer(string $a, string $b): bool
    {
        $na = $this->numbers($a); $nb = $this->numbers($b);
        return $na && $nb && !array_intersect($na, $nb);
    }

    /** Standalone numbers in a phrase — the discriminator for enumerated work. */
    private function numbers(string $s): array
    {
        preg_match_all('/\b\d{1,6}\b/', $s, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    /** Lowercased content words, stopwords dropped. */
    private function tokens(string $s): array
    {
        $stop = ['the','a','an','of','for','to','and','our','my','that','this','it','is','on','in','all'];
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($s)) ?: [];
        $words = array_filter($words, static fn ($w) => $w !== '' && mb_strlen($w) > 1 && !in_array($w, $stop, true));
        return array_values(array_unique($words));
    }
}
