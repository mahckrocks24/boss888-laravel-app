<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 — WHERE DID THIS PARAMETER COME FROM?
 *
 * THE DEFECT THIS CLOSES. Asked "Can you check where we rank?" — a question containing no
 * keyword at all — Sarah billed a live DataForSEO call for "private chef New Jersey", a
 * keyword carried from an earlier turn, in 2 of 4 measured runs. In the other 2 she asked
 * which keyword. Same question, same code, opposite behaviour. The non-determinism is worse
 * than either choice made consistently: it cannot be relied on and it will not reproduce.
 *
 * The completion check in place at the time scored all of it "complete", because it asked
 * whether a value was PRESENT, not where the value CAME FROM. A parameter invented from
 * stale context passes a presence check while spending the owner's money on a guess.
 *
 * THE RULE. A paid, mutating or externally-visible action may only use a parameter that is
 * one of:
 *
 *   EXPLICIT              the owner said it in this turn
 *   CONVERSATION_RESOLVED they said it earlier in THIS conversation and exactly one
 *                         candidate exists, so the reference is unambiguous
 *   AUTHORITATIVE_UNIQUE  the workspace itself resolves it uniquely — one tracked keyword,
 *                         one draft — so there is nothing to guess between
 *
 * Anything else is INFERRED_UNSAFE or MISSING, and the correct behaviour is to ask. Free
 * local reads are unaffected: guessing wrong about which table to read costs nothing and
 * asking about everything would be insufferable.
 *
 * Ambiguity is decided by COUNTING candidates, not by how confident the sentence sounds.
 * "The same one again" is unambiguous when one candidate exists and unsafe when three do.
 */
final class ParameterProvenance
{
    public const EXPLICIT              = 'EXPLICIT';
    public const CONVERSATION_RESOLVED = 'CONVERSATION_RESOLVED';
    public const AUTHORITATIVE_UNIQUE  = 'AUTHORITATIVE_UNIQUE';
    public const INFERRED_UNSAFE       = 'INFERRED_UNSAFE';
    public const MISSING               = 'MISSING';

    /** Only these may be executed when the action costs money or changes the world. */
    public const SAFE = [self::EXPLICIT, self::CONVERSATION_RESOLVED, self::AUTHORITATIVE_UNIQUE];

    /**
     * @param  array<int,string> $required
     * @return array{provenance: array<string,string>, unsafe: array<int,string>}
     */
    /**
     * P2-U2 (2026-08-30): the owner's own words naming an article beat any id the model carried over.
     * Returns ['article_id' => <id>] when the message contains exactly one article title of the workspace
     * (normalised containment, unique), otherwise []. Deterministic: no ranking, no partial matches.
     */
    public function bindFromOwnerWords(ToolIntent $intent, array $required): array
    {
        $bound = [];
        $msg = $this->norm((string) ($intent->ownerMessage ?? ''));
        if ($msg === '' || !in_array('article_id', $required, true)) return $bound;
        try {
            $rows = DB::table('articles')->where('workspace_id', $intent->workspaceId)
                ->whereNull('deleted_at')->orderByDesc('id')->limit(300)->get(['id', 'title']);
            $hits = [];
            foreach ($rows as $row) {
                $t = $this->norm((string) $row->title);
                if ($t !== '' && mb_strlen($t) >= 8 && str_contains($msg, $t)) $hits[(int) $row->id] = $t;
            }
            // A shorter title that appears ONLY as part of a longer matched title is not a second candidate;
            // if the owner wrote the shorter one on its own as well, both were named → ambiguous → no binding.
            if (count($hits) > 1) {
                foreach ($hits as $id => $t) {
                    foreach ($hits as $id2 => $t2) {
                        if ($id === $id2 || $t === $t2 || !str_contains($t2, $t)) continue;
                        $standalone = substr_count($msg, $t) > substr_count($msg, $t2);
                        if (!$standalone) { unset($hits[$id]); }
                        break;
                    }
                }
            }
            if (count($hits) === 1) $bound['article_id'] = (string) array_key_first($hits);
        } catch (\Throwable $e) { /* never bind on a failed lookup */ }
        return $bound;
    }

    public function classify(ToolIntent $intent, array $required): array
    {
        $msg     = $this->norm((string) ($intent->ownerMessage ?? ''));
        $history = $this->norm($this->conversationText($intent));
        $bound   = $this->bindFromOwnerWords($intent, $required);

        $prov = [];
        foreach ($required as $p) {
            if (isset($bound[$p])) { $prov[$p] = self::EXPLICIT; continue; }   // P2-U2: named by the owner
            $v = $intent->parameters[$p] ?? null;
            if ($v === null || $v === '' || !is_scalar($v)) { $prov[$p] = self::MISSING; continue; }

            $vn = trim($this->norm((string) $v));
            if ($vn === '') { $prov[$p] = self::MISSING; continue; }

            if ($msg !== '' && str_contains($msg, $vn)) { $prov[$p] = self::EXPLICIT; continue; }

            // Said earlier in THIS conversation — safe only when nothing competes with it.
            if ($history !== '' && str_contains($history, $vn)
                && $this->conversationCandidates($intent, $p) <= 1) {
                $prov[$p] = self::CONVERSATION_RESOLVED;
                continue;
            }

            if ($this->authoritativelyUnique($intent, $p, (string) $v)) {
                $prov[$p] = self::AUTHORITATIVE_UNIQUE;
                continue;
            }

            $prov[$p] = self::INFERRED_UNSAFE;
        }

        $unsafe = [];
        foreach ($prov as $p => $c) {
            if (!in_array($c, self::SAFE, true)) $unsafe[] = $p;
        }
        return ['provenance' => $prov, 'unsafe' => $unsafe];
    }

    /** A short, owner-facing sentence naming what is actually needed. */
    public function question(string $capabilityId, array $prov): string
    {
        $missing = [];
        foreach ($prov as $p => $c) {
            if (in_array($c, self::SAFE, true)) continue;
            $missing[] = str_replace('_', ' ', $p);
        }
        $list = implode(' and ', array_filter([
            implode(', ', array_slice($missing, 0, max(0, count($missing) - 1))),
            end($missing) ?: null,
        ]));
        return "I need you to tell me the {$list} before I run that — I'd rather ask than "
             . "guess and spend credits on the wrong thing.";
    }

    /**
     * How many DISTINCT plausible values for this parameter appear in the conversation?
     * More than one means the reference is ambiguous, whatever the model concluded.
     */
    private function conversationCandidates(ToolIntent $intent, string $param): int
    {
        $text = $this->conversationText($intent);
        if ($text === '') return 0;

        // Quoted strings are how a keyword is actually named in these conversations.
        if (preg_match_all('/["\x{201C}\x{201D}\']([^"\x{201C}\x{201D}\']{3,60})["\x{201C}\x{201D}\']/u',
            $text, $m)) {
            $distinct = array_unique(array_map(fn ($s) => strtolower(trim($s)), $m[1]));
            if (count($distinct) > 0) return count($distinct);
        }
        if (str_ends_with($param, '_id')) {
            preg_match_all('/\b\d{1,8}\b/', $text, $n);
            return count(array_unique($n[0]));
        }
        return 1;
    }

    /**
     * Does the workspace resolve this parameter uniquely on its own?
     *
     * Deliberately narrow: only cases where "there is exactly one" is a fact of the
     * workspace, not an inference about intent. Anything not listed returns false, so a new
     * parameter is unsafe until someone decides what unique means for it.
     */
    private function authoritativelyUnique(ToolIntent $intent, string $param, string $value): bool
    {
        $ws = $intent->workspaceId;
        try {
            if ($param === 'keyword') {
                // One goal, one target keyword: nothing to choose between.
                $targets = [];
                foreach (DB::table('workspace_goals')->where('workspace_id', $ws)
                    ->whereNull('deleted_at')->pluck('target_json') as $j) {
                    foreach ((array) (json_decode((string) $j, true)['keywords'] ?? []) as $k) {
                        $targets[strtolower(trim((string) $k))] = true;
                    }
                }
                return count($targets) === 1 && isset($targets[strtolower(trim($value))]);
            }
            if ($param === 'article_id') {
                $drafts = DB::table('articles')->where('workspace_id', $ws)
                    ->whereNull('deleted_at')->where('status', 'draft')->pluck('id');
                return $drafts->count() === 1 && (string) $drafts->first() === trim($value);
            }
            if ($param === 'lead_id') {
                $leads = DB::table('leads')->where('workspace_id', $ws)
                    ->whereNull('deleted_at')->pluck('id');
                return $leads->count() === 1 && (string) $leads->first() === trim($value);
            }
        } catch (\Throwable $e) {
            return false;   // never let a failed lookup authorise spending
        }
        return false;
    }

    private function conversationText(ToolIntent $intent): string
    {
        $conv = $intent->conversationId;
        if ($conv === null || $conv === '') return '';
        try {
            return (string) DB::table('agent_messages')
                ->where('workspace_id', $intent->workspaceId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.conversation_id')) = ?", [$conv])
                ->orderByDesc('id')->limit(20)->pluck('content')->implode(' ');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s)) ?? ''));
    }
}
