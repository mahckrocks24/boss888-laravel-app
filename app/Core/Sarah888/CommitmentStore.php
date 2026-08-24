<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1B — Executive Commitment Store.
 *
 * The single writer for sarah_commitments. Every lifecycle transition goes
 * through here so the supersession chain, cancellation semantics and workspace
 * isolation cannot be bypassed by an ad-hoc DB::table() call somewhere else.
 *
 * Traces to F1-D01: ~70 commitments were dictated across 163 turns and not one
 * was persisted. Sarah could hold a count in the conversation window and lose
 * the entire executive record the moment the window rolled.
 */
class CommitmentStore
{
    /** Terminal statuses — a commitment here is no longer live. */
    public const TERMINAL = ['completed', 'verified', 'cancelled', 'superseded', 'expired'];

    /**
     * Statuses that count as an open executive obligation.
     *
     * 'proposed' is deliberately NOT here. A low-confidence extraction is a
     * suggestion awaiting confirmation, not something the CEO has committed to.
     * Counting it as live meant idle speculation — "maybe we could cancel the
     * cookbook", "what if Nora owns the launch instead" — silently inflated the
     * answer to "how many live commitments do I have?", which is precisely the
     * phantom-commitment failure Phase 1B exists to prevent.
     */
    public const LIVE = ['confirmed', 'active', 'blocked', 'at_risk'];

    /** Extracted but unconfirmed. Surfaced for confirmation, never counted as fact. */
    public const PENDING = ['proposed'];

    /** Below this, an extraction is proposed rather than recorded as fact. */
    public const CONFIRM_THRESHOLD = 0.75;

    public function record(int $wsId, array $data): int
    {
        $confidence = (float) ($data['confidence'] ?? 1.0);
        $status = $data['status'] ?? ($confidence >= self::CONFIRM_THRESHOLD ? 'active' : 'proposed');

        $id = DB::table('sarah_commitments')->insertGetId([
            'workspace_id'       => $wsId,
            'conversation_id'    => $data['conversation_id'] ?? null,
            'source_message_id'  => $data['source_message_id'] ?? null,
            'execution_id'       => $data['execution_id'] ?? null,
            'actor'              => $data['actor'] ?? 'user',
            'title'              => mb_substr((string) $data['title'], 0, 255),
            'description'        => $data['description'] ?? null,
            'objective'          => $data['objective'] ?? null,
            'owner'              => $data['owner'] ?? null,
            'accountable'        => $data['accountable'] ?? null,
            'deadline'           => $data['deadline'] ?? null,
            'deadline_text'      => $data['deadline_text'] ?? null,
            'priority'           => $data['priority'] ?? 'medium',
            'status'             => $status,
            'confidence'         => $confidence,
            'extraction_method'  => $data['extraction_method'] ?? 'explicit',
            'requires_approval'  => (bool) ($data['requires_approval'] ?? false),
            'cost_estimate_credits' => $data['cost_estimate_credits'] ?? null,
            'dependencies_json'  => isset($data['dependencies']) ? json_encode($data['dependencies']) : null,
            'confirmed_at'       => $status === 'active' ? now() : null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        Log::info('[Sarah888] commitment recorded', [
            'ws' => $wsId, 'id' => $id, 'status' => $status,
            'source_message_id' => $data['source_message_id'] ?? null,
        ]);
        return (int) $id;
    }

    /**
     * Replace a commitment with a new version, preserving the chain.
     *
     * A rename, a moved deadline or a reassignment is NOT an edit — the F1 test
     * renamed a project twice and moved a deadline, and a mutable row would have
     * destroyed the history that makes "what did I call it before?" answerable.
     * The old row is kept and marked superseded so the chain stays walkable.
     */
    public function supersede(int $wsId, int $oldId, array $newData): ?int
    {
        $old = $this->find($wsId, $oldId);
        if (!$old) return null;
        if (in_array($old->status, ['cancelled', 'superseded'], true)) {
            // Superseding an already-dead commitment would fork the chain.
            Log::warning('[Sarah888] refused to supersede a terminal commitment', [
                'ws' => $wsId, 'id' => $oldId, 'status' => $old->status,
            ]);
            return null;
        }

        $merged = array_merge([
            'title'             => $old->title,
            'description'       => $old->description,
            'owner'             => $old->owner,
            'accountable'       => $old->accountable,
            'deadline'          => $old->deadline,
            'deadline_text'     => $old->deadline_text,
            'priority'          => $old->priority,
            'objective'         => $old->objective,
            'conversation_id'   => $old->conversation_id,
            'status'            => 'active',
        ], array_filter($newData, static fn ($v) => $v !== null));

        $newId = $this->record($wsId, $merged);
        DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $newId)
            ->update(['supersedes_id' => $oldId, 'updated_at' => now()]);
        DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $oldId)
            ->update(['status' => 'superseded', 'superseded_by_id' => $newId, 'updated_at' => now()]);

        return $newId;
    }

    /**
     * Cancel. Deliberately NOT a delete: "which two things did I cancel?" is a
     * question Sarah must be able to answer, and a deleted row cannot answer it.
     */
    public function cancel(int $wsId, int $id, ?string $reason = null): bool
    {
        $c = $this->find($wsId, $id);
        if (!$c || in_array($c->status, ['cancelled', 'superseded'], true)) return false;
        DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $id)->update([
            'status' => 'cancelled', 'cancellation_reason' => $reason,
            'cancelled_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    /** Reassign ownership through the chain so the previous owner is recoverable. */
    public function reassign(int $wsId, int $id, string $newOwner): ?int
    {
        return $this->supersede($wsId, $id, ['owner' => $newOwner]);
    }

    /** Move a deadline through the chain — the old date stays recoverable. */
    public function reschedule(int $wsId, int $id, ?string $deadline, ?string $deadlineText = null): ?int
    {
        return $this->supersede($wsId, $id, ['deadline' => $deadline, 'deadline_text' => $deadlineText]);
    }

    /**
     * A completion claim does NOT close a commitment (F1-D08: Sarah said
     * "I've checked the task list; the audiobook has been removed" about a thing
     * that never existed). Completion is recorded; verification is separate and
     * requires evidence.
     */
    public function claimComplete(int $wsId, int $id): bool
    {
        $c = $this->find($wsId, $id);
        if (!$c || in_array($c->status, self::TERMINAL, true)) return false;
        DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $id)->update([
            'status' => 'completed', 'verification_state' => 'unverified',
            'completed_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    public function verify(int $wsId, int $id, string $evidence): bool
    {
        $c = $this->find($wsId, $id);
        if (!$c || $c->status !== 'completed') return false;
        DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $id)->update([
            'status' => 'verified', 'verification_state' => 'verified',
            'verification_evidence' => mb_substr($evidence, 0, 2000), 'updated_at' => now(),
        ]);
        return true;
    }

    /** Overdue live commitments become risks rather than sitting silently late. */
    public function sweepAtRisk(int $wsId): int
    {
        return DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'confirmed'])
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', now()->toDateString())
            ->update(['status' => 'at_risk', 'updated_at' => now()]);
    }

    public function find(int $wsId, int $id): ?object
    {
        return DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $id)->first();
    }

    /** Live commitments only. Cancelled and superseded rows never come back. */
    public function live(int $wsId): array
    {
        return DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)
            ->whereIn('status', self::LIVE)
            ->orderByRaw("FIELD(priority,'critical','high','medium','low')")
            ->orderByRaw('deadline IS NULL, deadline')
            ->get()->all();
    }

    public function cancelled(int $wsId): array
    {
        // Most recent first. Certification Pass A asked "which two things did I
        // kill off?" and Sarah named two cancellations from the seeded history
        // rather than the two from the conversation — both were in the prompt,
        // and she took the ones printed first. What the owner just cancelled is
        // what they are asking about.
        return DB::table('sarah_commitments')->where('workspace_id', $wsId)
            ->where('status', 'cancelled')
            ->orderByDesc('cancelled_at')->orderByDesc('id')->get()->all();
    }

    /**
     * Fill in fields that were previously unknown on an existing commitment,
     * without forking the supersession chain.
     *
     * Restating a commitment is not a revision of it. An executive repeats
     * themselves constantly — the Phase 1H run said "the site rebuild must
     * finish by 28 February" in one turn and referred to the same obligation
     * again later, and every restatement inserted a fresh row. The store ended
     * up holding six pairs of exact duplicates, which inflated the live count
     * the owner was asking about and burned prompt budget rendering the same
     * item twice.
     *
     * supersede() is the wrong tool for this: it exists to record that
     * something CHANGED, and a duplicate carries no change to record. This only
     * ever writes into a null column — a restatement may add a deadline or an
     * owner that was not known before, but it may never overwrite one, because
     * "30 April" arriving after "15 May" is a restatement of stale information,
     * not a reschedule. Contradictions must go through reschedule()/reassign()
     * so they leave a trail.
     */
    public function enrich(int $wsId, int $id, array $data): bool
    {
        $row = $this->find($wsId, $id);
        if (!$row) return false;

        $patch = [];
        foreach (['owner', 'deadline', 'deadline_text', 'description', 'objective'] as $f) {
            $incoming = $data[$f] ?? null;
            if ($incoming !== null && $incoming !== '' && ($row->$f ?? null) === null) {
                $patch[$f] = $incoming;
            }
        }
        if (!$patch) return false;

        $patch['updated_at'] = now();
        DB::table('sarah_commitments')->where('workspace_id', $wsId)->where('id', $id)->update($patch);
        Log::info('[Sarah888] commitment enriched from restatement', [
            'ws' => $wsId, 'id' => $id, 'fields' => array_keys($patch),
        ]);
        return true;
    }

    /** Walk a supersession chain back to its origin — "what was it called before?" */
    public function history(int $wsId, int $id): array
    {
        $chain = [];
        $cur = $this->find($wsId, $id);
        $guard = 0;
        while ($cur && $guard++ < 50) {
            array_unshift($chain, $cur);
            $cur = $cur->supersedes_id ? $this->find($wsId, (int) $cur->supersedes_id) : null;
        }
        return $chain;
    }

    /** Follow a chain forward to whatever is current. */
    public function current(int $wsId, int $id): ?object
    {
        $cur = $this->find($wsId, $id);
        $guard = 0;
        while ($cur && $cur->superseded_by_id && $guard++ < 50) {
            $next = $this->find($wsId, (int) $cur->superseded_by_id);
            if (!$next) break;
            $cur = $next;
        }
        return $cur;
    }

    /**
     * Slice 1B.4 — render the executive record for Sarah's system prompt.
     *
     * This is the half of F1-D01 that persistence alone does not fix. The store
     * can hold a perfect record and Sarah will still answer from the last 20
     * chat messages unless the record is placed in front of her. In the F1 test
     * she denied the cookbook had ever been discussed while every fact about it
     * sat two turns outside her window.
     *
     * Deliberately compact: this block is prepended to every Sarah turn, so its
     * token cost is paid on every message. Live commitments carry owner and
     * deadline because those are what she contradicted herself about; cancelled
     * items are listed by name only because the single question that matters is
     * "which ones did I cancel"; supersession chains are rendered only where a
     * rename happened, because "what was it called before?" was asked and lost.
     */
    public function renderForPrompt(int $wsId, int $maxLive = 60, ?string $turnText = null): string
    {
        $live = $this->live($wsId);

        // ── CERT-A-D01 — RECENCY DECIDES WHAT IS VISIBLE ────────────────────
        // Certification Pass A reached 100 live commitments against a cap of
        // 40, and live() orders by priority then deadline — so WHICH 40 survived
        // was effectively arbitrary. The 21 commitments seeded before the
        // conversation crowded out the 89 the conversation itself created, and
        // Sarah answered from the seed: asked which two things the owner had
        // killed, she named two cancellations from months earlier. Recall
        // measured 30%.
        //
        // An executive asking about something has almost always just discussed
        // it, so the visible set is ordered by when each commitment was last
        // touched. Urgency still matters, so anything with a deadline inside
        // the next 30 days is pulled to the front regardless of age — a
        // forgotten deadline is exactly what this record exists to prevent.
        // ── RELEVANCE-AWARE COMPACTION ──────────────────────────────────────
        // Recency alone is not relevance. At 101 live commitments the frame
        // rendered 9,957 characters against a 10,000 budget — effectively
        // saturated — so what survives has to be chosen rather than whatever
        // happened to be touched last. Injecting 101 full records to answer a
        // question about three is what exhausted the budget in the first place.
        //
        // Tiers are the explicit priority order, most important first:
        //   1 overdue        2 due soon        3 recently changed
        //   4 referenced in the current turn   5 has an owner   6 the rest
        //
        // A commitment the owner just NAMED is additionally force-kept, above
        // and beyond its tier. Tier ordering decides what is worth space in
        // general; being asked about something directly is not a general case,
        // and answering "I have no record of that" about a commitment that is
        // in the record — merely crowded out of this turn's view — is the exact
        // failure this record exists to prevent.
        $todayStr  = now()->toDateString();
        $soonStr   = now()->addDays(30)->toDateString();
        $recentCut = now()->subHours(24)->toDateTimeString();
        $tokens    = $this->turnTokens($turnText);

        $named = [];
        foreach ($live as $c) {
            if ($tokens && $this->mentionedInTurn((string) $c->title, $tokens)) $named[(int) $c->id] = true;
        }

        $tier = function ($c) use ($todayStr, $soonStr, $recentCut, $named): int {
            $d = $c->deadline ?? null;
            if ($d && $d < $todayStr)  return 1;
            if ($d && $d <= $soonStr)  return 2;
            if ((string) ($c->updated_at ?? '') >= $recentCut) return 3;
            if (isset($named[(int) $c->id])) return 4;
            if (!empty($c->owner)) return 5;
            return 6;
        };

        $tierOf = [];
        foreach ($live as $c) $tierOf[(int) $c->id] = $tier($c);

        usort($live, static function ($a, $b) use ($tier) {
            $ta = $tier($a); $tb = $tier($b);
            if ($ta !== $tb) return $ta <=> $tb;
            return strcmp((string) ($b->updated_at ?? ''), (string) ($a->updated_at ?? ''));
        });

        // Force anything the owner just named to the front, whatever its tier.
        if ($named) {
            usort($live, static function ($a, $b) use ($named) {
                $an = isset($named[(int) $a->id]) ? 0 : 1;
                $bn = isset($named[(int) $b->id]) ? 0 : 1;
                return $an <=> $bn;
            });
        }
        $cancelled = $this->cancelled($wsId);
        $pending   = DB::table('sarah_commitments')->where('workspace_id', $wsId)
            ->whereIn('status', self::PENDING)->orderByDesc('id')->limit(8)->get()->all();

        if (!$live && !$cancelled && !$pending) return '';

        $out = "EXECUTIVE COMMITMENT RECORD (durable, workspace-scoped, and NOT limited to the recent conversation.\n"
             . "This is the source of truth for what the owner has committed to. Trust it over your recollection of chat.\n"
             . "If the owner asks about something that is NOT in this record, say you have no record of it — do NOT assert that it never happened.)\n";

        // DETAIL IS RATIONED BY RELEVANCE, NOT BY POSITION.
        // Rendering 60 full records cost 4,542 characters and pushed the
        // delegation block out of the frame entirely. On this workspace only
        // two of those 60 were overdue or due soon; the other 58 were filler
        // occupying space that other sections needed. Full detail now goes to
        // the commitments that earned it — overdue, due soon, just changed, or
        // named in this turn — and everything else is still listed by title, so
        // it stays retrievable without paying for owner, priority and deadline
        // fields nobody asked for.
        // Tier 3 is "changed in the last 24 hours", which on an active
        // workspace is nearly everything — 60 of 101 here — so tiering alone
        // did not ration anything and the block stayed at 4,542 characters.
        // The ceiling below is what actually enforces the budget. Anything the
        // owner NAMED is exempt from it: a named commitment must never be
        // demoted to a bare title, because a direct question is precisely when
        // the deadline and owner fields are the answer.
        $detailCap = min($maxLive, self::DETAIL_CAP);
        $detailed = [];
        $summary  = [];
        foreach ($live as $c) {
            $t = $tierOf[(int) $c->id] ?? 6;
            $isNamed = isset($named[(int) $c->id]);
            if ($isNamed || (($t <= 4) && count($detailed) < $detailCap)) {
                $detailed[] = $c;
            } else {
                $summary[] = $c;
            }
        }
        // Never render a bare skeleton: if nothing scored as relevant, show the
        // most recently touched few so the block is not just a list of names.
        if (!$detailed) {
            $detailed = array_slice($summary, 0, min(12, $maxLive));
            $summary  = array_slice($summary, count($detailed));
        }

        $shown = $detailed;
        $this->lastRenderStats = [
            'live_total'   => count($live),
            'live_detailed'=> count($shown),
            'live_summary' => count($summary),
            'live_omitted' => 0,
            'named_in_turn'=> count($named),
        ];
        $out .= "\nLIVE COMMITMENTS (" . count($live) . "):\n";
        if (!$shown) $out .= "  (none)\n";
        foreach ($shown as $c) {
            $bits = [];
            if (!empty($c->owner))    $bits[] = 'owner: ' . $c->owner;
            if (!empty($c->deadline)) $bits[] = 'due: ' . $c->deadline;
            elseif (!empty($c->deadline_text)) $bits[] = 'due: ' . $c->deadline_text;
            if (($c->priority ?? 'medium') !== 'medium') $bits[] = 'priority: ' . $c->priority;
            if ($c->status !== 'active') $bits[] = 'status: ' . $c->status;
            $out .= '  - [#' . $c->id . '] ' . $c->title . ($bits ? ' | ' . implode(' | ', $bits) : '') . "\n";
        }
        if ($summary) {
            // The overflow is listed by NAME rather than dropped. A bare "and
            // 60 more" told Sarah a number and left her unable to answer about
            // any of them; a title costs about thirty characters and keeps the
            // commitment retrievable, which is the whole purpose of the record.
            $rest = $summary;
            $out .= "\n  ALSO LIVE (" . count($rest) . ", listed by name only — ask me for detail on any of these):\n";
            foreach (array_slice($rest, 0, 80) as $c) {
                $out .= '    · ' . mb_substr((string) $c->title, 0, 70) . "\n";
            }
            if (count($rest) > 80) {
                $out .= '    … and ' . (count($rest) - 80)
                      . " beyond that which I cannot see right now — say so rather than implying this is the whole list.\n";
            }
        }

        if ($cancelled) {
            // Every line carries its own [CANCELLED] marker rather than relying
            // on the block header. Asked to list a project's items, Sarah read
            // straight past the header and returned a cancelled item inside the
            // live list — five items where four were live. A header describes a
            // region; a per-line marker travels with the line even when the
            // model lifts entries out of their block.
            $out .= "\nCANCELLED (" . count($cancelled) . ") — these are dead. Never count them, "
                  . "list them as live, or present them as in progress:\n";
            foreach (array_slice($cancelled, 0, 20) as $c) $out .= '  - [CANCELLED] ' . $c->title . "\n";
        }

        // Revision history. Title changes AND owner changes both matter: the F1
        // test asked "what was it called before?" and "who had copy editing
        // before that person?", and Sarah answered the second one with two
        // mutually contradictory names two turns apart (F1-D10). Rendering only
        // title changes would leave that question unanswerable, because a
        // reassignment keeps the title identical.
        $renamed = [];
        $reowned = [];
        foreach ($live as $c) {
            if (empty($c->supersedes_id)) continue;
            $chain = $this->history($wsId, (int) $c->id);

            $titles = array_values(array_unique(array_map(static fn ($r) => $r->title, $chain)));
            if (count($titles) > 1) $renamed[] = '  - ' . implode(' → ', $titles) . ' (now #' . $c->id . ')';

            $owners = [];
            foreach ($chain as $r) {
                $o = $r->owner ?: '(unassigned)';
                if (!$owners || end($owners) !== $o) $owners[] = $o;
            }
            if (count($owners) > 1) {
                $reowned[] = '  - ' . $c->title . ': ' . implode(' → ', $owners)
                           . '  (current owner: ' . (end($owners)) . ')';
            }
        }
        if ($renamed) {
            $out .= "\nRENAME HISTORY (the owner may refer to an EARLIER name):\n" . implode("\n", $renamed) . "\n";
        }
        if ($reowned) {
            $out .= "\nOWNERSHIP HISTORY (who held this BEFORE the current owner):\n" . implode("\n", $reowned) . "\n";
        }

        if ($pending) {
            $out .= "\nAWAITING CONFIRMATION (" . count($pending) . ") — extracted but NOT confirmed. Do not treat as agreed:\n";
            foreach ($pending as $c) $out .= '  - ' . $c->title . "\n";
        }

        return $out . "\n";
    }

    /** Stats from the most recent renderForPrompt() call, for frame profiling. */
    /**
     * How many commitments get full detail (owner, deadline, priority) in one
     * frame. Everything beyond it is still listed by title. Chosen so the
     * commitment block leaves room for the horizon, ledger and delegation
     * blocks rather than consuming the budget alone.
     */
    public const DETAIL_CAP = 24;

    public array $lastRenderStats = [];

    /** Content words from the turn, long enough to be worth matching on. */
    private function turnTokens(?string $turnText): array
    {
        if (!$turnText) return [];
        $stop = ['the','and','for','that','this','with','what','when','who','how','are','was',
                 'you','your','our','have','has','had','about','from','they','them','there',
                 'been','were','will','would','can','could','should','did','does','any','all'];
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($turnText)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) $out[$w] = true;
        }
        return array_keys($out);
    }

    /** Two shared content words is a reference; one is a coincidence. */
    private function mentionedInTurn(string $title, array $tokens): bool
    {
        $t = mb_strtolower($title);
        $hits = 0;
        foreach ($tokens as $tok) {
            if (str_contains($t, $tok)) { $hits++; if ($hits >= 2) return true; }
        }
        // A single long, distinctive word still counts.
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) >= 7 && str_contains($t, $tok)) return true;
        }
        return false;
    }

    public function counts(int $wsId): array
    {
        $rows = DB::table('sarah_commitments')->where('workspace_id', $wsId)
            ->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all();
        $live = 0;
        foreach (self::LIVE as $s) $live += (int) ($rows[$s] ?? 0);
        $pending = 0;
        foreach (self::PENDING as $s) $pending += (int) ($rows[$s] ?? 0);
        return [
            'live'       => $live,
            'pending'    => $pending,
            'cancelled'  => (int) ($rows['cancelled'] ?? 0),
            'superseded' => (int) ($rows['superseded'] ?? 0),
            'completed'  => (int) ($rows['completed'] ?? 0),
            'verified'   => (int) ($rows['verified'] ?? 0),
            'by_status'  => $rows,
        ];
    }
}
