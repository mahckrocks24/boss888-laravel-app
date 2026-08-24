<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — ONE STATUS TRAILER PER TURN.
 *
 * THE DEFECT THIS CLOSES (owner-beta ws 2, 2026-08-13, turn 008).
 * Four independent writers each appended their own account of the same turn to
 * the end of the same reply, and the owner read all four at once:
 *
 *   "I'll proceed with this task now.
 *    To be exact, in this workspace today 1 item is waiting...
 *    I've held 1 item for your approval...
 *    I haven't started anything yet."
 *
 * Three of those sentences describe ONE pending item, and the last contradicts
 * the first. T001 did the same to a greeting. None of the four was wrong on its
 * own; they were wrong together, because no component knew what the others had
 * already said.
 *
 * The writers were:
 *   1. ChatActionProposal::disclosure()  — agents-01.php:3383, and again from
 *      ConfirmationClaimGuard and AuthorizationBinder, so up to three times
 *   2. SpendPolicy::disclosure()         — agents-01.php:3366, no dedup check
 *   3. ConfirmationClaimGuard            — appends the same proposal disclosure
 *   4. VerbalAuthorityGuard              — appends its correction
 *
 * WHY A COMPOSER RATHER THAN MORE DEDUP CHECKS.
 * Two of the four already carried str_contains() checks against the reply, and
 * they still stacked: a substring check only catches the exact wording the
 * author happened to think of, and each new writer has to remember to check for
 * every earlier one — n-squared agreements that nobody can keep correct. So the
 * components keep COMPUTING state and stop EMITTING it. Exactly one component
 * decides what the owner sees, which is this one.
 *
 * WHAT IT MUST NEVER DO.
 * Suppress the cost of something new. An approval given without knowing the
 * price is not informed consent, so a proposal the owner has not been shown is
 * always disclosed. Only an UNCHANGED restatement of an already-disclosed set is
 * dropped — which is the repetition the owner actually complained about.
 *
 * Request-scoped, and bound the same way SpendContext and CorrelationContext are:
 * an unbound concrete class resolves to a new instance on every app() call, so
 * without app()->instance() each note() would land in a different object. Bound
 * here rather than in AppServiceProvider, which another session has uncommitted
 * work in.
 */
final class TurnStatusEmitter
{
    /** Strongest first. Exactly one of these reaches the owner. */
    public const PROPOSAL  = 'proposal';    // work parked awaiting approval, with cost
    public const HELD      = 'held';        // spend gate held something this turn
    public const AUTHORITY = 'authority';   // a guard's correction about what has/hasn't run

    private const ORDER = [self::PROPOSAL, self::HELD, self::AUTHORITY];

    /** @var array<string, array{text:string, sig:string, fresh:bool}> */
    private array $notes = [];

    private bool $emitted = false;

    /**
     * Record what this component WOULD have said. It may or may not be what the
     * owner sees — that is the whole point.
     *
     * @param string $sig   identity of the underlying state (e.g. proposal ids).
     *                      Two turns with the same signature said the same thing.
     * @param bool   $fresh true when this state is NEW this turn and must be
     *                      disclosed even if it looks like a repeat.
     */
    public function note(string $kind, string $text, string $sig = '', bool $fresh = false): void
    {
        $text = rtrim($text);
        if (trim($text) === '') return;

        $this->notes[$kind] = ['text' => $text, 'sig' => $sig, 'fresh' => $fresh];
        try { app()->instance(self::class, $this); } catch (\Throwable $e) { /* non-fatal */ }
    }

    /** Did anything record status this turn? */
    public function has(string $kind): bool
    {
        return isset($this->notes[$kind]);
    }

    /** Start a fresh turn. Request scope makes this a no-op in production. */
    public function reset(): void
    {
        $this->notes = [];
        $this->emitted = false;
    }

    /**
     * Append at most one status statement to the reply.
     *
     * Called ONCE, at the end of the guard chain, when every component has had
     * its say and the authoritative state has settled.
     */
    public function emit(int $wsId, ?string $conversationId, string $reply): string
    {
        if ($this->emitted) return $reply;      // idempotent: a second call adds nothing
        $this->emitted = true;

        $pick = null; $kind = null;
        foreach (self::ORDER as $k) {
            if (isset($this->notes[$k])) { $pick = $this->notes[$k]; $kind = $k; break; }
        }
        if ($pick === null) return $reply;

        // Already said in Sarah's own words — a trailer would restate it. A
        // FRESH proposal is exempt: the reply may well say "that needs your
        // approval" without ever naming the price, and an approval given
        // without knowing the cost is not informed consent.
        if (!$pick['fresh'] && $this->replyAlreadyStates($reply, $kind)) {
            $this->remember($conversationId, $kind, $pick['sig']);
            return $reply;
        }

        // An unchanged restatement of state the owner has already been shown is
        // the repetition they complained about. Anything FRESH is always shown.
        if (!$pick['fresh'] && $pick['sig'] !== ''
            && $this->lastSignature($conversationId) === $kind . ':' . $pick['sig']) {
            Log::debug('[Sarah888] TurnStatusEmitter suppressed an unchanged trailer', [
                'ws' => $wsId, 'conversation_id' => $conversationId, 'kind' => $kind]);
            return $reply;
        }

        $this->remember($conversationId, $kind, $pick['sig']);

        if (count($this->notes) > 1) {
            Log::info('[Sarah888] TurnStatusEmitter collapsed competing trailers', [
                'ws' => $wsId, 'conversation_id' => $conversationId,
                'emitted' => $kind, 'suppressed' => array_values(array_diff(array_keys($this->notes), [$kind])),
            ]);
        }

        $sep = str_starts_with($pick['text'], "\n") ? '' : "\n\n";
        return rtrim($reply) . $sep . ltrim($pick['text'], "\n");
    }

    /**
     * Has the reply already told the owner this? Checked against the phrasings
     * Sarah and the guards actually use, so a reply that answered properly is
     * not given a trailer repeating itself.
     */
    private function replyAlreadyStates(string $reply, string $kind): bool
    {
        $n = mb_strtolower($reply);

        $approvalSaid = str_contains($n, 'awaiting your approval')
            || str_contains($n, 'nothing has been created')
            || str_contains($n, 'needs your approval')
            || str_contains($n, 'waiting on your approval')
            || str_contains($n, 'needs your go-ahead')
            || str_contains($n, 'held for your approval');

        return match ($kind) {
            self::PROPOSAL, self::HELD => $approvalSaid,
            self::AUTHORITY => $approvalSaid
                || str_contains($n, "haven't started")
                || str_contains($n, 'nothing has actually run')
                || str_contains($n, 'nothing went live'),
            default => false,
        };
    }

    private function cacheKey(?string $conversationId): ?string
    {
        return $conversationId === null || $conversationId === ''
            ? null : 'sarah888:turnstatus:' . sha1($conversationId);
    }

    private function lastSignature(?string $conversationId): ?string
    {
        $key = $this->cacheKey($conversationId);
        if ($key === null) return null;
        try { return Cache::get($key); } catch (\Throwable $e) { return null; }
    }

    private function remember(?string $conversationId, string $kind, string $sig): void
    {
        $key = $this->cacheKey($conversationId);
        if ($key === null || $sig === '') return;
        try { Cache::put($key, $kind . ':' . $sig, now()->addHours(6)); }
        catch (\Throwable $e) { /* suppression is a nicety, never a correctness gate */ }
    }
}
