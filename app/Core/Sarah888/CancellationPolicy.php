<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1Q — cancellation is domain-scoped.
 *
 * Traces to S1P-D02 and implements the architectural ruling: intent → entity
 * resolution → domain ownership → capability classification → approval policy →
 * mutation, with NO mutation before entity and domain are resolved.
 *
 * The defect: "Cancel the hygiene certificate renewal" mutated a CRM lead to
 * status='lost' because a lead's name resembled a commitment's name. Generic
 * cancellation language never means a CRM lifecycle transition. "Cancel Acme"
 * does not mean lead.status = lost.
 *
 * WHY THIS IS NOT A FORBIDDEN-WORD LIST. Adding cancel_* to the refuse-outright
 * registry would treat a domain-resolution defect as a vocabulary problem, and
 * would also break legitimate cancellation — the owner must still be able to
 * cancel a commitment. The rule is not "cancellation is dangerous", it is
 * "cancellation may only touch the domain that owns the referenced entity".
 *
 * Request-scoped and bound through app()->instance(), the same mechanism as
 * SpendContext and ActionAuthority. Unset means no cancellation in flight,
 * which is the correct default for workers and scheduled jobs.
 */
class CancellationPolicy
{
    /** Generic cancellation. Domain-neutral by nature — that is the point. */
    private const CANCEL_INTENT = '/\b(?:cancel|drop|kill|scrap|abandon|bin|call\s+off|shelve)\s+'
        . '(?:the\s+|that\s+|this\s+|our\s+|my\s+)?([^.!?,]{2,70})/i';

    /** "X is dead", "X is off". */
    private const CANCEL_PREDICATE = '/\b(?:the\s+)?([a-z0-9][\w\s\'-]{2,60}?)\s+is\s+(?:dead|cancelled|canceled|off)\b/i';

    /**
     * Language that names a CRM entity AND a CRM lifecycle transition.
     *
     * Only this unlocks a CRM write. Both halves are required: naming the
     * entity type is not enough ("the Acme lead is dead" is still ambiguous
     * about whether the commitment or the record is meant), and naming a
     * status is not enough either.
     */
    private const CRM_INTENT = '/\b(?:mark|move|set|change|flag|push)\b[^.!?]{0,40}'
        . '\b(?:lead|prospect|opportunity|contact|deal)\b[^.!?]{0,30}'
        . '\b(?:lost|won|closed?|disqualif\w+|unqualified)\b'
        . '|\b(?:lead|prospect|opportunity|contact|deal)\b[^.!?]{0,30}'
        . '\b(?:to|as)\s+(?:lost|won|closed?|disqualified)\b'
        . '|\bdisqualif\w+\b[^.!?]{0,30}\b(?:lead|prospect|contact)\b'
        . '|\bclose\s+(?:this|the)\s+(?:crm\s+)?opportunity\b/i';

    private bool $active = false;
    private string $reference = '';
    private string $resolution = 'none';
    private ?string $domain = null;
    private array $matches = [];

    public function __construct(private EntityResolver $resolver) {}

    /**
     * Classify this turn and publish the decision for the rest of the request.
     *
     * @return array{active:bool, reference:string, resolution:string, domain:?string, matches:array}
     */
    public function markTurn(int $wsId, string $userText): array
    {
        $this->reset();

        $ref = $this->extractReference($userText);
        if ($ref !== null) {
            $this->active    = true;
            $this->reference = $ref;

            // Explicit CRM lifecycle language names its own domain — no
            // resolution contest, because the owner has said which world they
            // are operating in.
            if (preg_match(self::CRM_INTENT, $userText)) {
                $this->resolution = 'unique';
                $this->domain     = EntityResolver::DOMAIN_CRM;
            } else {
                $r = $this->resolver->resolve($wsId, $ref);
                $this->resolution = $r['resolution'];
                $this->domain     = $r['domain'];
                $this->matches    = $r['matches'];

                // "Cancel Acme." resolving to exactly one CRM lead is still not
                // permission to change that lead's status. The ruling is
                // explicit: generic cancellation language does NOT mean
                // lead.status = lost, however confidently the name matches. A
                // clean single match makes this MORE dangerous, not less,
                // because it is precisely the case that looks safe to act on.
                if ($this->resolution === 'unique' && $this->domain === EntityResolver::DOMAIN_CRM) {
                    $this->resolution = 'crm_without_intent';
                }
            }

            Log::info('[Sarah888] cancellation turn classified', [
                'ws' => $wsId, 'reference' => $this->reference,
                'resolution' => $this->resolution, 'domain' => $this->domain,
                'domains_matched' => array_keys($this->matches),
            ]);
        }

        try { app()->instance(self::class, $this); } catch (\Throwable $e) { /* non-fatal */ }
        return $this->state();
    }

    /** Pull the thing being cancelled out of the turn. */
    private function extractReference(string $text): ?string
    {
        foreach (preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [] as $clause) {
            if (preg_match(self::CANCEL_INTENT, $clause, $m)) {
                return $this->clean($m[1]);
            }
            if (preg_match(self::CANCEL_PREDICATE, $clause, $m2)) {
                return $this->clean($m2[1]);
            }
        }
        return null;
    }

    /**
     * May a task in this engine be created during this turn?
     *
     * Engine IS domain. A cancellation that resolved to a commitment may not
     * produce a crm task; a cancellation that resolved to nothing, or to more
     * than one domain, may not produce anything at all. Non-mutating engines
     * are untouched — asking a question during a cancellation turn is fine.
     */
    public function permits(string $engine): bool
    {
        if (!$this->active) return true;
        if ($this->resolution !== 'unique') return false;
        if ($this->domain === null) return false;

        // A commitment cancellation is applied by CommitmentStore, not by a
        // task, so it legitimately needs no task at all. Anything that mutates
        // another domain is refused.
        return $engine === $this->domain;
    }

    public function isActive(): bool      { return $this->active; }
    public function resolution(): string  { return $this->resolution; }
    public function domain(): ?string     { return $this->domain; }
    public function reference(): string   { return $this->reference; }
    public function matches(): array      { return $this->matches; }

    public function state(): array
    {
        return ['active' => $this->active, 'reference' => $this->reference,
                'resolution' => $this->resolution, 'domain' => $this->domain,
                'matches' => $this->matches];
    }

    public function reset(): void
    {
        $this->active = false; $this->reference = ''; $this->resolution = 'none';
        $this->domain = null; $this->matches = [];
    }

    private function clean(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_substr(trim($s, " \t\n\r\0\x0B.,;:!?—-"), 0, 120);
    }
}
