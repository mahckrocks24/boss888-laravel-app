<?php

namespace App\Core\Experience888;

use Illuminate\Support\Facades\DB;

/**
 * EXPERIENCE888 — owner feedback classification.
 *
 * The owner's corrections are the highest-value learning signal on the platform,
 * and the easiest to get catastrophically wrong. Turning every irritated remark
 * into permanent policy produces an assistant that becomes less useful the more
 * you talk to it.
 *
 * So feedback is TYPED, and the type decides durability:
 *
 *   FACT_CORRECTION      "that number is wrong"        - corrects a fact, not a rule
 *   PREFERENCE           "I prefer short captions"     - durable, can be superseded
 *   POLICY               "never publish without me"    - durable and strong
 *   ONE_OFF_INSTRUCTION  "for this campaign, long"     - explicitly NOT durable
 *   STRATEGIC_DECISION   "we're dropping that market"  - durable
 *   OUTCOME_FEEDBACK     "that worked well"            - evidence, not a rule
 *
 * Explicit beats inferred. A newer explicit durable statement supersedes an
 * older one on the same subject. A one-off never supersedes a standing rule.
 */
final class OwnerFeedbackClassifier
{
    public const FACT_CORRECTION     = 'FACT_CORRECTION';
    public const PREFERENCE          = 'PREFERENCE';
    public const POLICY              = 'POLICY';
    public const ONE_OFF_INSTRUCTION = 'ONE_OFF_INSTRUCTION';
    public const STRATEGIC_DECISION  = 'STRATEGIC_DECISION';
    public const OUTCOME_FEEDBACK    = 'OUTCOME_FEEDBACK';

    public const TYPES = [
        self::FACT_CORRECTION, self::PREFERENCE, self::POLICY,
        self::ONE_OFF_INSTRUCTION, self::STRATEGIC_DECISION, self::OUTCOME_FEEDBACK,
    ];

    /** Types that may persist and influence later turns. */
    public const DURABLE_TYPES = [self::PREFERENCE, self::POLICY, self::STRATEGIC_DECISION];

    /**
     * Deterministic classification from the owner's own words.
     *
     * Scope-limiting language ("for this one", "just this time") is checked
     * FIRST: it is what separates a one-off from a standing rule, and getting
     * that order wrong is how "long-form is fine for this campaign" becomes a
     * permanent reversal of the standing preference.
     */
    public function classify(string $text): array
    {
        $t = mb_strtolower(trim($text));

        $oneOff = (bool) preg_match(
            '/\b(for (this|that) (one|campaign|post|article|time)|just (this|for now)|this time only|on this occasion|as a one[- ]off|exception)\b/', $t);

        $standing = (bool) preg_match(
            '/\b(from now on|going forward|always|never|standing (rule|instruction)|as a rule|by default|change the (standing )?rule|new rule|permanently)\b/', $t);

        $prefWords   = preg_match('/\b(i prefer|prefer|i like|i want|i\'d rather|keep them|make them|use )\b/', $t);
        $policyWords = preg_match('/\b(never|always|must|do not|don\'t) \b/', $t);
        // Corrections are phrased many ways ("that's wrong", "that number is
        // wrong", "it should be 15, not 50"). Matching only the first form let a
        // factual correction fall through and be stored as outcome feedback.
        $factWords   = preg_match(
            '/\b(is wrong|are wrong|that\'?s wrong|that is wrong|incorrect|not right|wrong (number|figure|count)'
          . '|actually it|no,? it|should be|got that wrong|mistake)\b/', $t)
          || preg_match('/\b\d[\d,]*\s+not\s+\d/', $t);   // "15 not 50"
        $outcomeWords= preg_match('/\b(that worked|didn\'t work|did not work|went well|was a failure|worked well|no effect)\b/', $t);
        $strategic   = preg_match('/\b(we\'re (dropping|stopping|pausing)|we are (dropping|stopping|pausing)|shift focus|new direction|stop investing)\b/', $t);

        // Order matters. Each branch documents why it outranks the ones below.
        if ($factWords) {
            $type = self::FACT_CORRECTION;      // corrects a value; never becomes a rule
        } elseif ($outcomeWords && !$standing) {
            $type = self::OUTCOME_FEEDBACK;     // evidence about a result, not an instruction
        } elseif ($strategic) {
            $type = self::STRATEGIC_DECISION;
        } elseif ($oneOff && !$standing) {
            $type = self::ONE_OFF_INSTRUCTION;  // scope-limited: must not become durable
        } elseif ($standing && $policyWords) {
            $type = self::POLICY;
        } elseif ($standing || $prefWords) {
            $type = self::PREFERENCE;
        } else {
            $type = self::OUTCOME_FEEDBACK;
        }

        // Explicitness: did the owner state it, or are we inferring it?
        $explicit = $standing || $oneOff || $prefWords || $policyWords || $factWords || $strategic;

        $strength = match (true) {
            $type === self::POLICY               => 'STRONG',
            $type === self::STRATEGIC_DECISION   => 'STRONG',
            $type === self::PREFERENCE && $standing => 'STRONG',
            $type === self::PREFERENCE           => 'MEDIUM',
            $type === self::ONE_OFF_INSTRUCTION  => 'WEAK',
            default                              => 'MEDIUM',
        };

        return [
            'feedback_type' => $type,
            'durable'       => in_array($type, self::DURABLE_TYPES, true) && !$oneOff,
            'explicitness'  => $explicit ? 'explicit' : 'inferred',
            'strength'      => $strength,
            'scope_limited' => $oneOff,
            'standing'      => $standing,
        ];
    }

    /**
     * Is this message feedback at all?
     *
     * The live chat path calls this before storing anything. classify() always
     * returns a type, so without this gate every ordinary question ("what should
     * I do this week?") would be filed as OUTCOME_FEEDBACK and the store would
     * fill with noise that later reads as evidence.
     *
     * A question is only feedback when it also carries a correction or
     * instruction marker - "why did you use a long caption? keep them short"
     * is both.
     */
    public function isFeedback(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return false;

        $markers = '/\b(i prefer|prefer|i want|i like|i\'d rather|from now on|going forward|always|never'
                 . '|stop|don\'t|do not|instead|that\'s wrong|that is wrong|is wrong|incorrect|not right'
                 . '|should be|change the|new rule|standing rule|for this (one|campaign|post|article|time)'
                 . '|just this|one[- ]off|that worked|didn\'t work|did not work|worked well|use )\b/';
        if (!preg_match($markers, $t)) return false;

        // A bare interrogative with no instruction is a question, not feedback.
        $isQuestion = str_ends_with(trim($t), '?')
            || (bool) preg_match('/^(what|how|when|who|where|which|should|can|could|is|are|do|does|did|will)\b/', $t);
        $hasInstruction = (bool) preg_match(
            '/\b(i prefer|prefer|i want|from now on|going forward|always|never|stop|don\'t|do not'
          . '|instead|use |keep |make |change the|is wrong|that\'s wrong|incorrect|should be)\b/', $t);

        return $hasInstruction || !$isQuestion;
    }

    /**
     * Deterministic subject, so two statements about captions supersede each
     * other instead of accumulating as unrelated preferences.
     */
    public function deriveSubject(string $text): string
    {
        $t = mb_strtolower($text);
        foreach ([
            'caption_length'   => '/\bcaption/',
            'article_length'   => '/\b(article|blog|post) (length|size)|long[- ]form|word count/',
            'tone_of_voice'    => '/\b(tone|voice|style|formal|casual)\b/',
            'publishing_policy'=> '/\b(publish|publishing|go live)\b/',
            'approval_policy'  => '/\b(approv|sign[- ]?off|permission)\b/',
            'image_style'      => '/\b(image|photo|visual|thumbnail)\b/',
            'email_policy'     => '/\b(email|newsletter|campaign send)\b/',
            'keyword_strategy' => '/\b(keyword|seo|ranking)\b/',
            'lead_handling'    => '/\b(lead|crm|enquir)/',
        ] as $subject => $re) if (preg_match($re, $t)) return $subject;

        return 'general';
    }

    /**
     * Record classified feedback, superseding an earlier durable statement on
     * the same subject only when this one is itself durable and explicit.
     */
    public function record(int $wsId, string $subject, string $text, array $a = []): int
    {
        if ($wsId <= 0) throw new \InvalidArgumentException('Experience888: workspace_id is required');

        $c = $this->classify($text);
        $type = $a['feedback_type'] ?? $c['feedback_type'];
        if (!in_array($type, self::TYPES, true))
            throw new \InvalidArgumentException("Experience888: unknown feedback_type {$type}");

        $durable = $a['durable'] ?? $c['durable'];

        $supersedes = null;
        if ($durable && $c['explicitness'] === 'explicit') {
            // Only a durable statement can retire an earlier durable one. A
            // one-off explicitly cannot, which is the whole point of the type.
            $prev = DB::table('experience_owner_feedback')
                ->where('workspace_id', $wsId)
                ->where('subject', $subject)
                ->where('durable', true)
                ->whereNull('superseded_at')
                ->orderByDesc('id')->first();
            if ($prev) {
                $supersedes = (int) $prev->id;
                DB::table('experience_owner_feedback')->where('id', $prev->id)
                    ->update(['superseded_at' => now(), 'updated_at' => now()]);
            }
        }

        return (int) DB::table('experience_owner_feedback')->insertGetId([
            'workspace_id'      => $wsId,
            'source_message_id' => $a['source_message_id'] ?? null,
            'conversation_id'   => $a['conversation_id'] ?? null,
            'feedback_type'     => $type,
            'subject'           => $subject,
            'value'             => $a['value'] ?? $text,
            'explicitness'      => $c['explicitness'],
            'strength'          => $c['strength'],
            'durable'           => $durable,
            'supersedes_id'     => $supersedes,
            'quote'             => mb_substr($text, 0, 500),
            'created_at'        => now(), 'updated_at' => now(),
        ]);
    }

    /** The standing preference on a subject, or null when none is in force. */
    public function current(int $wsId, string $subject): ?object
    {
        return DB::table('experience_owner_feedback')
            ->where('workspace_id', $wsId)
            ->where('subject', $subject)
            ->where('durable', true)
            ->whereNull('superseded_at')
            ->orderByDesc('id')->first();
    }

    /** All standing preferences for a workspace, for the Sarah read path. */
    public function standing(int $wsId, int $limit = 10): array
    {
        return DB::table('experience_owner_feedback')
            ->where('workspace_id', $wsId)
            ->where('durable', true)
            ->whereNull('superseded_at')
            ->orderByRaw("FIELD(strength,'STRONG','MEDIUM','WEAK')")
            ->orderByDesc('id')->limit($limit)->get()->all();
    }
}
