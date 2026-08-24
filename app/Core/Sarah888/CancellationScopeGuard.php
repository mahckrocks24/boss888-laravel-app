<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1Q — the ambiguity rule, stated to the owner.
 *
 * CancellationPolicy stops the mutation. This makes the reply match what
 * actually happened, because a blocked mutation with a reply that still says
 * "Proceeding now!" is a fabricated completion claim — the defect Phase 1D
 * exists to prevent, reintroduced through the back door.
 *
 * The three cases come straight from the architectural ruling:
 *
 *   AMBIGUOUS — name what was found in each domain and ask which one.
 *   NONE      — say nothing was cancelled, and do not manufacture an entity.
 *   UNIQUE    — leave the reply alone; the normal approval policy applies.
 */
class CancellationScopeGuard
{
    /** Claims that something was cancelled or is being cancelled now. */
    private const CLAIM = '/\b(?:proceeding now|i\'?ll (?:queue|update|mark|cancel|proceed)|i\s+will\s+(?:queue|update|mark|cancel)'
        . '|i\'?ve (?:cancelled|canceled|updated|marked)|has been (?:cancelled|canceled|updated|marked)'
        . '|marking\s+it|updating\s+the|cancel(?:ling|ing)\s+(?:it|the))\b/i';

    public function __construct(private CancellationPolicy $policyIgnored) {}

    /**
     * @return array{reply:string, corrected:bool, resolution:?string}
     */
    public function validate(string $reply, int $wsId): array
    {
        $out = ['reply' => $reply, 'corrected' => false, 'resolution' => null];

        // Resolved at call time, never captured: the policy instance is
        // published per-request by the route. Constructor-injecting it would
        // hold a stale empty object, which is the mistake that made the 1E
        // spend gate and the first 1J guard inert.
        $policy = app(CancellationPolicy::class);
        if (!$policy->isActive()) return $out;

        $out['resolution'] = $policy->resolution();
        if ($policy->resolution() === 'unique') return $out;

        $text = $this->stripClaims($reply);

        if ($policy->resolution() === 'crm_without_intent') {
            $names = [];
            foreach ($policy->matches()[EntityResolver::DOMAIN_CRM] ?? [] as $row) $names[] = '"' . $row['label'] . '"';
            $text = trim($text . ' The only thing I can find matching "' . $policy->reference() . '" is a CRM record'
                  . ($names ? ' (' . implode(', ', array_slice($names, 0, 2)) . ')' : '')
                  . ', and cancelling something in conversation is not the same as changing a CRM record\'s status — '
                  . 'so I haven\'t changed anything. If you want the record marked lost, say so explicitly and I\'ll do that.');
        } elseif ($policy->resolution() === 'ambiguous') {
            $text = trim($text . ' ' . $this->ambiguityQuestion($policy));
        } else {
            $text = trim($text . ' I don\'t have a tracked item matching "' . $policy->reference()
                  . '", so I haven\'t cancelled anything.');
        }

        $out['reply']     = trim(preg_replace('/[ \t]+/', ' ', $text));
        $out['corrected'] = true;

        Log::warning('[Sarah888] cancellation could not be resolved to one entity — nothing mutated', [
            'ws' => $wsId, 'reference' => $policy->reference(),
            'resolution' => $policy->resolution(), 'domains' => array_keys($policy->matches()),
        ]);
        return $out;
    }

    /** Name what was found, per domain, and ask which one is meant. */
    private function ambiguityQuestion(CancellationPolicy $policy): string
    {
        $labels = [
            EntityResolver::DOMAIN_COMMITMENT => 'a commitment',
            EntityResolver::DOMAIN_CRM        => 'a CRM record',
            EntityResolver::DOMAIN_TASK       => 'a task',
        ];
        $parts = [];
        foreach ($policy->matches() as $domain => $rows) {
            // Two CRM records can carry the SAME name, and listing it twice
            // reads as a malfunction: 'a CRM record named "X" and "X"'.
            $names = array_values(array_unique(array_map(static fn ($r) => '"' . $r['label'] . '"', $rows)));
            $count = count($rows);
            $names = array_slice($names, 0, 2);
            $part  = ($labels[$domain] ?? $domain) . ' named ' . implode(' and ', $names);
            if ($count > count($names)) $part .= ' (' . $count . ' records share that name)';
            $parts[] = $part;
        }
        if (!$parts) {
            return 'I could not narrow "' . $policy->reference() . '" to a single item, so I haven\'t cancelled anything. Which one did you mean?';
        }
        return 'I have ' . implode(' and ', $parts)
             . '. I haven\'t cancelled anything yet — which one did you mean?';
    }

    /**
     * Remove any claim that the cancellation happened or is happening.
     *
     * Nothing was mutated, so "Proceeding now!" would be false. Sentences that
     * merely discuss the request are left alone.
     */
    private function stripClaims(string $reply): string
    {
        $kept = [];
        foreach (preg_split('/(?<=[.!?])\s+/', $reply) ?: [] as $sentence) {
            $s = trim($sentence);
            if ($s === '') continue;
            if (preg_match(self::CLAIM, $s)) continue;
            // The solicitation belonged to the action that is no longer
            // happening. Live verification opened a reply with a bare "Shall I
            // go ahead with this action?" after everything around it had been
            // removed, which invites the owner to approve a thing that was
            // just refused.
            if (preg_match('/^(?:shall\s+i\s+(?:proceed|go\s+ahead)|would\s+you\s+like\s+me\s+to\s+proceed'
                . '|want\s+me\s+to\s+(?:proceed|go\s+ahead)|do\s+you\s+want\s+me\s+to\s+proceed)'
                . '[^.!?]{0,40}[.?!]?$/i', $s)) continue;
            $kept[] = $s;
        }
        $text = implode(' ', $kept);
        // The route appends this before the guard chain runs; with the task
        // refused it is no longer true.
        return trim(preg_replace('/\n*✅ Queued [^\n]*\n?/u', '', $text));
    }
}
