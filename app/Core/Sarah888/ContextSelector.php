<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 - choose the context this turn actually needs.
 *
 * MEASURED CAUSE (2026-08-10 forensic, three repeats per condition):
 *   OFFLINE-A baseline .................. 100%
 *   + all production state blocks ....... 75%   (-25)
 *   + conversation history only ......... 73%   (-27)
 *   + state AND history ................. 58%   (-42)  <- production measures 58%
 *   guard chain ......................... 0 points, exonerated
 *
 * Every block is harmless alone and destructive in combination. The defect is
 * cumulative context load, not any single module - which is why removing 48,603
 * chars of execution machinery, and separately the conversation horizon, both
 * changed nothing measurable.
 *
 * WHAT THIS IS NOT. It is not a memory system and it holds no facts. Every value
 * it emits comes from the blocks the route already built, or from
 * agent_messages. It decides INCLUSION, never content, so there is no second
 * source of truth to drift.
 *
 * WHAT IT REFUSES TO DO. It never drops MANDATORY_GLOBAL or MANDATORY_SAFETY
 * material, whatever the turn looks like: identity, truth rules, temporal
 * anchor, derived state and absence semantics travel on every turn. Dropping
 * those to save tokens would trade a reasoning defect for a truthfulness one.
 */
class ContextSelector
{
    public const MANDATORY_GLOBAL = 'MANDATORY_GLOBAL';
    public const MANDATORY_SAFETY = 'MANDATORY_SAFETY';
    public const TURN_RELEVANT    = 'TURN_RELEVANT';
    public const DOMAIN_RELEVANT  = 'DOMAIN_RELEVANT';
    public const HISTORY_RELEVANT = 'HISTORY_RELEVANT';
    public const OPTIONAL         = 'OPTIONAL';

    /** Which domain each production block serves. null = every turn. */
    private const BLOCK_DOMAINS = [
        'conciseRule'       => null,            // truth rules - mandatory
        'identityBlock'     => null,            // role - mandatory
        'brandFactsBlock'   => null,            // workspace identity - mandatory
        'sarahFrame'        => null,            // time + derived state + absence - mandatory
        'execFrame'         => null,            // executive material + capability map
        'evidenceBlock'     => null,            // deterministic facts computed for THIS turn
        'activeQueueBlock'  => ['tasks', 'content', 'incident'],
        'taskActivityBlock' => ['tasks', 'content', 'incident'],
        'groundingBlock'    => ['crm', 'seo', 'content'],
    ];

    private const CLASS_OF = [
        'conciseRule'       => self::MANDATORY_SAFETY,
        'identityBlock'     => self::MANDATORY_GLOBAL,
        'brandFactsBlock'   => self::MANDATORY_GLOBAL,
        'sarahFrame'        => self::MANDATORY_SAFETY,
        'execFrame'         => self::TURN_RELEVANT,
        'evidenceBlock'     => self::TURN_RELEVANT,
        'activeQueueBlock'  => self::DOMAIN_RELEVANT,
        'taskActivityBlock' => self::DOMAIN_RELEVANT,
        'groundingBlock'    => self::DOMAIN_RELEVANT,
    ];

    public function __construct(private RouterIntent $intent) {}

    /**
     * @param array<string,string> $blocks the route's already-built context blocks
     * @return array{context:string, manifest:array}
     */
    public function select(int $wsId, string $turn, array $blocks): array
    {
        $domains = $this->intent->domains($turn, $wsId);

        $context = '';
        $manifest = ['domains' => $domains, 'included' => [], 'omitted' => [],
                     'chars_included' => 0, 'chars_omitted' => 0];

        foreach ($blocks as $name => $text) {
            $text = (string) $text;
            if ($text === '') continue;

            $class = self::CLASS_OF[$name] ?? self::OPTIONAL;
            $need  = self::BLOCK_DOMAINS[$name] ?? null;
            $mandatory = in_array($class, [self::MANDATORY_GLOBAL, self::MANDATORY_SAFETY], true)
                      || $class === self::TURN_RELEVANT;

            $relevant = $mandatory || $need === null || array_intersect($need, $domains);

            if ($relevant) {
                $context .= $text;
                $manifest['included'][] = ['block'=>$name, 'class'=>$class, 'chars'=>mb_strlen($text),
                    'reason'=>$mandatory ? 'mandatory' : 'domain: ' . implode('/', array_intersect($need, $domains))];
                $manifest['chars_included'] += mb_strlen($text);
            } else {
                $manifest['omitted'][] = ['block'=>$name, 'class'=>$class, 'chars'=>mb_strlen($text),
                    'reason'=>'no domain overlap (needs ' . implode('/', $need) . ')'];
                $manifest['chars_omitted'] += mb_strlen($text);
            }
        }

        Log::info('[Sarah888] context selection', [
            'ws' => $wsId, 'domains' => $domains,
            'included' => count($manifest['included']), 'omitted' => count($manifest['omitted']),
            'chars_included' => $manifest['chars_included'], 'chars_omitted' => $manifest['chars_omitted'],
        ]);

        return ['context' => $context, 'manifest' => $manifest];
    }

    /**
     * History for this turn: a small recent working set PLUS older messages that
     * are actually about the subject.
     *
     * Measured: 20 recent messages cost 27 points of reasoning on their own. The
     * fix is not a smaller fixed window - that would simply lose long-range
     * recall, which is the F1 amnesia defect - it is that relevance must be able
     * to beat recency.
     *
     * Scoring is lexical and deterministic on purpose. Retrieval has to be
     * explainable in the manifest, and a model call to rank history would put a
     * second opinion between Sarah and her own record.
     *
     * AUTHORITY IS PRESERVED. Each retained message keeps its role, so a prior
     * assistant reply is never presented as evidence of what happened - only as
     * something Sarah said. That distinction is what stops an old hallucination
     * becoming authoritative by being in the transcript.
     */
    public function selectHistory(int $wsId, ?string $conversationId, string $turn,
                                  int $recent = 6, int $retrieved = 6): array
    {
        $q = DB::table('agent_messages')->where('workspace_id', $wsId);
        $all = $q->orderByDesc('id')->limit(400)->get(['id','role','sender','content','created_at']);

        $recentSet = $all->take($recent);
        $recentIds = $recentSet->pluck('id')->all();

        // distinctive terms from the turn; short and common words carry no signal
        $stop = ['the','and','for','что','with','from','this','that','what','when','which','would',
                 'about','into','they','them','have','has','was','were','are','you','your','our',
                 'how','why','does','did','can','could','should','will','a','an','of','to','in','is','it'];
        $terms = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($turn), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn($w) => mb_strlen($w) > 3 && !in_array($w, $stop, true)));

        $scored = [];
        foreach ($all as $m) {
            if (in_array($m->id, $recentIds, true)) continue;
            $hay = mb_strtolower((string) $m->content);
            $score = 0;
            foreach ($terms as $t) if (str_contains($hay, $t)) $score++;
            // a user message is primary evidence; an assistant reply is only what she said
            if ($m->role !== 'agent') $score += 1;
            if ($score > 0) $scored[] = ['m' => $m, 'score' => $score];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['m']->id <=> $a['m']->id);
        $picked = array_slice($scored, 0, $retrieved);

        $lines = [];
        foreach ($recentSet->reverse() as $m) {
            $lines[] = ($m->role === 'agent' ? '[SARAH, prior reply] ' : '[OWNER] ')
                     . mb_substr(preg_replace('/\s+/', ' ', (string) $m->content), 0, 600);
        }
        foreach (array_reverse($picked) as $p) {
            $m = $p['m'];
            $lines[] = ($m->role === 'agent' ? '[SARAH, earlier reply] ' : '[OWNER, earlier] ')
                     . mb_substr(preg_replace('/\s+/', ' ', (string) $m->content), 0, 600);
        }

        $total = (int) DB::table('agent_messages')->where('workspace_id', $wsId)->count();
        $shown = count($lines);

        return [
            'text' => $lines ? implode("\n", $lines) : '',
            'manifest' => [
                'total_messages'   => $total,
                'recent_included'  => $recentSet->count(),
                'retrieved_included' => count($picked),
                'omitted'          => max(0, $total - $shown),
                'retrieval_terms'  => array_slice($terms, 0, 8),
                'retrieved_scores' => array_map(fn($p) => $p['score'], $picked),
            ],
        ];
    }
}
