<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GoalIntentService — b17 (2026-07-24)
 *
 * WHY THIS EXISTS
 * ───────────────
 * Sarah had no planner. ToolSelectorService is a cost/quality RANKER: for each
 * engine identified from the goal it emits EVERY registered tool_blueprint row
 * scoring above 0.40, in usage_count order. It receives the goal string
 * (getCandidatesForEngine($engine, $goal, $analysis)) and never reads it, and
 * params come from a hardcoded match() that pastes the raw goal in as the
 * article topic.
 *
 * So "Publish 2 of my draft articles every 5 minutes" produced:
 *     create_article, write_article, aeo_enrich, improve_draft,
 *     generate_meta, generate_headlines, generate_outline
 * — the full write-engine tool list — and wrote a NEW article titled
 * "Publish 2 of my draft articles every 5 minutes". It never looked at the
 * customer's existing drafts and never published anything. Any goal hitting
 * the write engine produced the same seven tasks regardless of what was asked.
 *
 * WHAT THIS DOES
 * ──────────────
 * Sits in front of the ranker and handles goals whose OPERATION is explicit
 * and whose OBJECTS are real rows we can resolve. For those it emits a precise,
 * entity-bound sequence — real article IDs, not a goal string in a topic field.
 *
 * It deliberately does NOT try to be a general planner. When the goal is
 * exploratory ("grow my traffic", "improve SEO") there is no single correct
 * action set and the ranker's breadth is the right behaviour, so we return
 * null and nothing changes. This is additive: no recognized intent means the
 * exact pre-existing code path runs.
 *
 * SELF-LIMITING BY DESIGN
 * ───────────────────────
 * Task count comes from how many entities actually qualify — 9 drafts means 9
 * publish tasks, no more. PlanSchedulerService then spreads those across the
 * requested cadence, so "2 every 5 minutes" with 9 drafts naturally becomes 5
 * slots and stops. We never invent work to fill a schedule.
 */
class GoalIntentService
{
    /** Hard ceiling on entity-bound tasks from one goal. */
    public const MAX_TASKS = 60;

    /**
     * Operation verbs. Matched with word boundaries against the lowercased
     * goal. Order matters: the first operation that matches wins, so more
     * specific phrasings are listed before generic ones.
     */
    private const OPERATIONS = [
        'publish' => [
            'publish', 'go live', 'make live', 'put live', 'send live', 'release',
            // "push my drafts live", "push them live" — words between the two.
            'push\s+[\w\s]{0,20}?live',
        ],
        // No 'unpublish' entry: there is no registered unpublish_article action
        // in CapabilityMapService, so emitting one would only fail the
        // capability check. Add the capability first, then the verb here.
    ];

    /** Object nouns → the entity type we resolve. */
    private const OBJECTS = [
        'article' => [
            'article', 'articles', 'draft', 'drafts', 'blog', 'blogs',
            'blog post', 'blog posts', 'post', 'posts', 'content piece',
        ],
    ];

    /**
     * Resolve a goal into an entity-bound action sequence.
     *
     * Returns a ToolSelectorService-compatible trace, or null when no confident
     * intent is found (caller then falls back to the ranker).
     */
    public function resolve(int $wsId, string $goal, array $analysis = []): ?array
    {
        $text = mb_strtolower(trim($goal));
        if ($text === '') return null;

        $operation = $this->detectOperation($text);
        if ($operation === null) return null;

        $object = $this->detectObject($text);
        if ($object === null) return null;

        $handler = match ("{$operation}:{$object}") {
            'publish:article' => fn () => $this->publishArticles($wsId, $text),
            default           => null,
        };
        if ($handler === null) return null;

        try {
            return $handler();
        } catch (\Throwable $e) {
            // Never let intent resolution break planning — fall back to ranker.
            Log::warning('[GoalIntent] resolution failed, falling back to ranker', [
                'ws_id' => $wsId, 'goal' => $goal, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Intent handlers
    // ─────────────────────────────────────────────────────────────

    /**
     * "publish my drafts" / "publish 2 draft articles every 5 minutes"
     *
     * Emits one publish_article task per REAL draft, most-complete first so the
     * best content goes live earliest. publish_article is approval_mode=protected
     * in CapabilityMapService — this only selects it; PublishGateService and plan
     * gating still decide whether it may run.
     */
    private function publishArticles(int $wsId, string $text): ?array
    {
        $fromStatus = 'draft';

        $rows = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', $fromStatus)
            // Only content worth putting in front of customers.
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderByRaw('CASE WHEN featured_image_url IS NULL OR featured_image_url = "" THEN 1 ELSE 0 END')
            ->orderBy('created_at')
            ->limit(self::MAX_TASKS)
            ->get(['id', 'title', 'featured_image_url']);

        if ($rows->isEmpty()) {
            // Nothing to act on — let the caller decide how to explain it.
            return [
                'sequence'    => [],
                'total_cost'  => 0,
                'confidence'  => 1.0,
                'rejected'    => [],
                'intent'      => [
                    'operation' => 'publish',
                    'object'    => 'article',
                    'resolved'  => 0,
                    'reason'    => "No {$fromStatus} articles with content were found in this workspace.",
                ],
            ];
        }

        // An explicit total ("publish 5 articles") caps the set. A cadence count
        // ("2 every 5 minutes") does NOT — that is per-slot and belongs to
        // ScheduleSpecParser, which spreads whatever we emit across the slots.
        $explicitTotal = $this->explicitTotal($text);
        if ($explicitTotal !== null) {
            $rows = $rows->take($explicitTotal);
        }

        $sequence = [];
        foreach ($rows as $a) {
            $title = trim((string) $a->title) !== '' ? $a->title : "Article #{$a->id}";
            $sequence[] = [
                'engine'        => 'write',
                'action'        => 'publish_article',
                'agent'         => 'priya',
                'params'        => ['article_id' => (int) $a->id, 'title' => $title],
                'depends_on'    => [],
                'score'         => 1.0,
                'scores'        => ['intent_match' => 1.0],
                'justification' => sprintf(
                    'Publish "%s" — resolved from the workspace\'s %s articles by explicit user intent.',
                    mb_substr($title, 0, 80),
                    $fromStatus
                ),
                'cost'          => 0,
            ];
        }

        $missingImages = $rows->filter(fn ($a) => empty($a->featured_image_url))->count();

        return [
            'sequence'   => $sequence,
            'total_cost' => 0,
            'confidence' => 1.0,
            'rejected'   => [],
            'intent'     => array_filter([
                'operation'      => 'publish',
                'object'         => 'article',
                'resolved'       => count($sequence),
                'target_status'  => 'published',
                'explicit_total' => $explicitTotal,
                'note'           => $missingImages > 0
                    ? "{$missingImages} of these have no featured image — they were ordered last."
                    : null,
            ], fn ($v) => $v !== null),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Parsing helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Verbs that mean "make new content". If one of these LEADS the sentence,
     * the goal is a creation goal and any later "publish" is subordinate:
     *
     *   "Write a blog post about how to publish a cookbook"
     *   "create a draft article and publish it later"
     *
     * Both previously resolved to "publish every draft in the workspace" —
     * 42 irreversible public publishes from a request to write one article.
     * Publishing is public and has no undo, so ambiguity must lose.
     */
    private const CREATION_VERBS = [
        'write', 'create', 'draft', 'generate', 'produce', 'compose',
        'make me', 'add a', 'add an', 'come up with',
    ];

    private function detectOperation(string $text): ?string
    {
        foreach (self::OPERATIONS as $op => $patterns) {
            $opPos = $this->firstMatchPos($text, $patterns);
            if ($opPos === null) continue;

            // A creation verb earlier in the sentence governs the goal.
            $createPos = $this->firstMatchPos($text, self::CREATION_VERBS);
            if ($createPos !== null && $createPos < $opPos) {
                return null;
            }
            return $op;
        }
        return null;
    }

    /** Offset of the earliest match among $patterns, or null. */
    private function firstMatchPos(string $text, array $patterns): ?int
    {
        $best = null;
        foreach ($patterns as $p) {
            // Entries may be plain phrases or small regex fragments (\s+, {0,20}).
            $frag = preg_match('/[\\\\{}+*?\[\]]/', $p) ? $p : preg_quote($p, '/');
            if (preg_match('/\b' . $frag . '/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                $pos = (int) $m[0][1];
                if ($best === null || $pos < $best) $best = $pos;
            }
        }
        return $best;
    }

    private function detectObject(string $text): ?string
    {
        foreach (self::OBJECTS as $obj => $nouns) {
            foreach ($nouns as $n) {
                if (preg_match('/\b' . preg_quote($n, '/') . '\b/u', $text)) {
                    return $obj;
                }
            }
        }
        return null;
    }

    /**
     * A TOTAL count, not a cadence rate.
     *
     * "publish 5 articles"            → 5
     * "publish 2 articles every 5 min"→ null (2 is a per-slot rate)
     * "publish all my drafts"         → null (no cap)
     */
    private function explicitTotal(string $text): ?int
    {
        if (preg_match('/\ball\b/u', $text)) return null;

        // A number followed (anywhere later) by "every"/"per"/"each" is a rate.
        $isCadence = (bool) preg_match('/\b\d+\b[^.]*?\b(every|per|each)\b/u', $text);
        if ($isCadence) return null;

        if (preg_match('/\b(\d+)\b/u', $text, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= self::MAX_TASKS) return $n;
        }
        return null;
    }
}
