<?php

namespace App\Core\Engineer888\Decisions;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use Illuminate\Support\Facades\DB;

/**
 * Turns "show me the Bug Tracker" into a real governed object.
 *
 * ── THE BOUNDARY THIS CLASS IS ──────────────────────────────────────
 *
 * The model may say WHAT IT WANTS SHOWN. It may not say what that is. Every
 * governed field — the card uuid, the action type, the approval id, the
 * fingerprint, the required statement, the repository path — is looked up here,
 * from the projection and the card table, using the access context of the human
 * who is actually in the conversation. The model contributes at most a free-text
 * hint, and a hint that resolves to nothing yields nothing rather than something
 * arbitrary.
 *
 * ── WHY LOGICAL KEYS AND NOT CARD UUIDs ─────────────────────────────
 *
 * Cards rotate. CardIssuanceService revokes and re-mints them every poll on a
 * 30-minute TTL, so the uuid that identifies a decision at 10:00 does not exist
 * at 11:00 — measured on 2026-08-13, the live card table went from 52 rows to 0
 * purely through expiry while the decisions themselves were untouched.
 *
 * A presentation that remembered a card uuid would therefore be a presentation
 * that breaks by itself, silently, some minutes after it was created. That is
 * the stale-card problem this system has already paid for once. So what gets
 * persisted against a message is the LOGICAL DECISION, and the current valid
 * card is resolved fresh every time it is rendered or reopened.
 */
final class DecisionPresentationResolver
{
    public const TYPE_DECISION = 'decision';

    public function __construct(
        private readonly DecisionProjection $projection = new DecisionProjection(),
    ) {}

    /**
     * The references to persist against an assistant turn.
     *
     * Deliberately thin: a type and a logical key. No card, no fingerprint, no
     * statement — those are resolved at render time, because by then they may
     * be different and the reader must see what is true now.
     *
     * @return array<int,array{type:string,logical_key:string}>
     */
    public function referencesFor(
        Engineer888AccessContext $ctx,
        string $intent,
        ?string $hint,
        ?int $projectId
    ): array {
        if ($intent === 'SHOW_DECISION') {
            $item = $this->projection->resolve($ctx, $hint, $projectId);

            return $item === null ? [] : [$this->ref($item)];
        }

        if ($intent === 'SHOW_DECISIONS') {
            return array_map(fn ($i) => $this->ref($i), $this->projection->current($ctx, $projectId));
        }

        return [];
    }

    /**
     * Hydrate persisted references into what the UI may draw, right now.
     *
     * A reference whose work item no longer needs a decision hydrates to
     * nothing and is simply not drawn — the approval was given, the candidate
     * was superseded, the task completed. A closed conversation should not
     * resurrect a decision that has since been made.
     *
     * @param  array<int,array> $refs
     * @return array<int,array>
     */
    public function hydrate(Engineer888AccessContext $ctx, array $refs, ?int $projectId): array
    {
        if ($refs === []) { return []; }

        if (! (new Engineer888Access())->allowsContext($ctx, Cap::REVIEW_CANDIDATE)) {
            return [];
        }

        $live = [];
        foreach ($this->projection->current($ctx, $projectId) as $item) {
            $live[$item['work_key']] = $item;
        }

        $out = [];

        foreach ($refs as $ref) {
            $key = (string) ($ref['logical_key'] ?? '');
            $item = $live[$key] ?? null;

            if ($item === null) { continue; }   // decided, superseded or gone

            $out[] = [
                'type'        => self::TYPE_DECISION,
                'logical_key' => $key,
                'decision'    => $this->renderSafe($ctx, $item),
            ];
        }

        return $out;
    }

    /**
     * What the compact card is allowed to know.
     *
     * Enough to decide whether to open it, and nothing that belongs in Review.
     * The card uuid IS included — the button has to press something — but it is
     * resolved here, now, from the live table, and it is never what the
     * presentation remembered.
     */
    private function renderSafe(Engineer888AccessContext $ctx, array $item): array
    {
        $cards = $this->currentCardsFor($item['candidate_uuid']);

        return [
            'kind'           => $item['kind'] ?? DecisionProjection::KIND_REVIEW,
            'title'          => $item['title'],
            'project'        => $item['project'],
            'file_count'     => $item['file_count'],
            'confidence'     => $item['confidence'],
            'attempts'       => $item['attempts'],
            'earlier'        => count($item['history']),
            'task_uuid'      => $item['task_uuid'],
            'candidate_uuid' => $item['candidate_uuid'],
            'review_card'    => $cards['approve_candidate'] ?? null,
            'reject_card'    => $cards['reject_candidate'] ?? null,
            'stale'          => $cards === [],
        ];
    }

    /**
     * The live cards for a candidate, keyed by action.
     *
     * Read at render time, every time. Expired, consumed and revoked cards are
     * excluded here rather than filtered later, so a button is drawn only when
     * there is something for it to press.
     *
     * @return array<string,string>
     */
    private function currentCardsFor(?string $candidateUuid): array
    {
        if ($candidateUuid === null) { return []; }

        $rows = DB::table('e888_action_cards')
            ->where('candidate_uuid', $candidateUuid)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->get(['uuid', 'action_type']);

        $out = [];

        foreach ($rows as $r) {
            // orderByDesc means the first seen per action is the newest.
            $out[$r->action_type] ??= $r->uuid;
        }

        return $out;
    }

    /** @return array{type:string,logical_key:string} */
    private function ref(array $item): array
    {
        return ['type' => self::TYPE_DECISION, 'logical_key' => $item['work_key']];
    }
}
