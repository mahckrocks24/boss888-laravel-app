<?php

namespace App\Core\OwnerModel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P1 (2026-10-02). The ONE writer of what Sarah knows about an owner, per business.
 *
 * Three lanes feed it: stated (the owner's words, through OwnerModelExtractor), observed (domain events, through
 * observe()) and outcomes (P2). Every fact carries source, confidence, evidence, dates and a lifetime; a stated
 * instruction is confirmed at once and echoed back, an inferred fact is proposed and shown until the owner confirms,
 * corrects or dismisses it, an observation decays (DEC-0073 D5: 90 days). The owner can see everything in plain words
 * and erase it.
 */
final class OwnerModelService
{
    public const GROUPS = ['identity', 'goals', 'preferences', 'interests', 'behaviour', 'wants', 'relationship'];
    public const OBSERVATION_DAYS = 90;   // DEC-0073 D5

    /** Write or refresh a fact. Returns ['id'=>..., 'changed'=>bool, 'status'=>...]. */
    public function upsert(int $wsId, ?int $bizId, string $group, string $key, string $value, string $source = 'stated', float $confidence = 0.8, ?string $evidence = null, ?string $status = null, ?int $ttlDays = null): array
    {
        $group = in_array($group, self::GROUPS, true) ? $group : 'interests';
        $key = mb_substr(preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($key))), 0, 80);
        $value = trim(mb_substr(preg_replace('/\s+/', ' ', $value), 0, 600));
        if ($key === '' || $value === '') return ['id' => null, 'changed' => false, 'status' => null];
        $status = $status ?: ($source === 'stated' || $source === 'computed' ? 'confirmed' : 'proposed');
        if ($ttlDays === null && in_array($source, ['observed', 'inferred'], true)) $ttlDays = self::OBSERVATION_DAYS;
        $now = now();
        $q = DB::table('owner_model_facts')->where('workspace_id', $wsId)->where('key', $key);
        $q = $bizId ? $q->where('business_id', $bizId) : $q->whereNull('business_id');
        $row = $q->first();
        $expires = $ttlDays ? $now->copy()->addDays($ttlDays) : null;
        if ($row) {
            $same = trim((string) $row->value) === $value && $row->status !== 'erased' && $row->status !== 'dismissed';
            DB::table('owner_model_facts')->where('id', $row->id)->update([
                'group' => $group, 'value' => $value, 'source' => $source, 'confidence' => max(0, min(1, $confidence)), 'evidence_ref' => $evidence ?: $row->evidence_ref,
                'status' => $same ? ($row->status === 'proposed' && $status === 'confirmed' ? 'confirmed' : $row->status) : $status,
                'last_confirmed_at' => ($status === 'confirmed') ? $now : $row->last_confirmed_at, 'expires_at' => $expires, 'updated_at' => $now,
            ]);
            $this->event($wsId, 'fact_written', ['key' => $key, 'changed' => ! $same, 'source' => $source]);
            if (! $same) RuntimeMemorySync::queue($wsId);   // RFC-0023 P6
            return ['id' => (int) $row->id, 'changed' => ! $same, 'status' => $same ? $row->status : $status];
        }
        $id = DB::table('owner_model_facts')->insertGetId([
            'workspace_id' => $wsId, 'business_id' => $bizId, 'group' => $group, 'key' => $key, 'value' => $value, 'source' => $source,
            'confidence' => max(0, min(1, $confidence)), 'evidence_ref' => $evidence, 'status' => $status, 'first_seen_at' => $now,
            'last_confirmed_at' => $status === 'confirmed' ? $now : null, 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->event($wsId, 'fact_written', ['key' => $key, 'changed' => true, 'source' => $source, 'new' => true]);
        RuntimeMemorySync::queue($wsId);   // RFC-0023 P6
        return ['id' => $id, 'changed' => true, 'status' => $status];
    }

    public function confirm(int $wsId, array $ids): int
    {
        $n = DB::table('owner_model_facts')->where('workspace_id', $wsId)->whereIn('id', $ids)->whereIn('status', ['proposed', 'confirmed'])
            ->update(['status' => 'confirmed', 'last_confirmed_at' => now(), 'expires_at' => null, 'updated_at' => now()]);
        if ($n) { $this->event($wsId, 'fact_confirmed', ['ids' => $ids]); RuntimeMemorySync::queue($wsId); }   // RFC-0023 P6
        return $n;
    }

    public function dismiss(int $wsId, array $ids): int
    {
        $n = DB::table('owner_model_facts')->where('workspace_id', $wsId)->whereIn('id', $ids)->update(['status' => 'dismissed', 'updated_at' => now()]);
        if ($n) { $this->event($wsId, 'fact_dismissed', ['ids' => $ids]); RuntimeMemorySync::queue($wsId); }   // RFC-0023 P6
        return $n;
    }

    /** The owner's right to erase: every fact, every observed event, the journal of their words, their standing corrections. */
    public function erase(int $wsId): array
    {
        $out = ['facts' => 0, 'events' => 0, 'journal' => 0, 'feedback' => 0];
        $out['facts'] = DB::table('owner_model_facts')->where('workspace_id', $wsId)->whereNotIn('status', ['erased'])->update(['status' => 'erased', 'value' => '[erased by the owner]', 'updated_at' => now()]);
        $out['events'] = DB::table('owner_model_events')->where('workspace_id', $wsId)->delete();
        try { $out['journal'] = DB::table('business_journal')->where('workspace_id', $wsId)->delete(); } catch (\Throwable) {}
        try { $out['feedback'] = DB::table('experience_owner_feedback')->where('workspace_id', $wsId)->delete(); } catch (\Throwable) {}
        $this->event($wsId, 'erased', $out);
        RuntimeMemorySync::queue($wsId, true);   // RFC-0023 P6 / D5: the runtime forgets in the same breath
        return $out;
    }

    /** Live facts for a business (workspace-level facts included), newest confirmed first; expired ones drop out. */
    public function facts(int $wsId, ?int $bizId = null, ?array $groups = null): array
    {
        $q = DB::table('owner_model_facts')->where('workspace_id', $wsId)->whereIn('status', ['confirmed', 'proposed'])
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if ($bizId) $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id')); else $q->whereNull('business_id');
        if ($groups) $q->whereIn('group', $groups);
        return $q->orderByRaw("FIELD(status,'confirmed','proposed')")->orderByDesc('last_confirmed_at')->orderByDesc('updated_at')->get()->all();
    }

    /** The observed lane: a domain event that shapes behaviour facts. */
    public function observe(int $wsId, ?int $bizId, string $event, ?string $subject = null, array $payload = []): void
    {
        try {
            DB::table('owner_model_events')->insert(['workspace_id' => $wsId, 'business_id' => $bizId, 'event' => mb_substr($event, 0, 48), 'subject' => $subject ? mb_substr($subject, 0, 120) : null,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, 'created_at' => now()]);
            // a rejection with a reason is a stated preference in disguise
            if ($event === 'approval_rejected' && trim((string) ($payload['reason'] ?? '')) !== '') {
                $this->upsert($wsId, $bizId, 'preferences', 'rejection_' . substr(md5((string) $payload['reason']), 0, 8), 'Rejected ' . ($payload['action'] ?? 'a proposal') . ': ' . $payload['reason'], 'observed', 0.7, $subject ? 'event:approval_rejected:' . $subject : null, 'proposed', 60);
            }
        } catch (\Throwable $e) { Log::info('[OWNER-MODEL] observe skipped: ' . $e->getMessage()); }
    }

    /** Behaviour, computed from what the owner does (never asked, never stored as opinion). */
    public function behaviour(int $wsId): array
    {
        $out = [];
        try {
            $since = now()->subDays(30);
            $rows = DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('created_at', '>=', $since)->orderBy('id')->get(['role', 'created_at']);
            $gaps = []; $hours = []; $lastAgent = null; $userTurns = 0;
            foreach ($rows as $r) {
                if ($r->role === 'agent') { $lastAgent = strtotime($r->created_at); continue; }
                $userTurns++; $hours[(int) date('G', strtotime($r->created_at))] = ($hours[(int) date('G', strtotime($r->created_at))] ?? 0) + 1;
                if ($lastAgent) { $g = strtotime($r->created_at) - $lastAgent; if ($g > 0 && $g < 86400 * 3) $gaps[] = $g; $lastAgent = null; }
            }
            if ($gaps) { sort($gaps); $med = $gaps[intdiv(count($gaps), 2)]; $out['typical reply time'] = $med < 3600 ? round($med / 60) . ' min' : round($med / 3600, 1) . ' h'; }
            if ($hours) { arsort($hours); $top = array_slice(array_keys($hours), 0, 3); sort($top); $out['active hours (UTC)'] = implode(', ', array_map(fn ($h) => $h . ':00', $top)); }
            $out['messages in 30 days'] = (string) $userTurns;
            $asked = (int) DB::table('owner_checkins')->where('workspace_id', $wsId)->count(); $ans = (int) DB::table('owner_checkins')->where('workspace_id', $wsId)->where('status', 'answered')->count();
            if ($asked) $out['check-ins answered'] = "$ans of $asked";
            $ev = DB::table('owner_model_events')->where('workspace_id', $wsId)->where('created_at', '>=', $since)->selectRaw('event, COUNT(*) n')->groupBy('event')->pluck('n', 'event');
            if (($ev['approval_approved'] ?? 0) + ($ev['approval_rejected'] ?? 0) > 0) $out['approvals (30 d)'] = ($ev['approval_approved'] ?? 0) . ' approved, ' . ($ev['approval_rejected'] ?? 0) . ' rejected';
        } catch (\Throwable $e) {}
        return $out;
    }

    /** "What do you remember about me" - the model in plain words, grouped, with dates. */
    public function summary(int $wsId, ?int $bizId = null): string
    {
        $facts = $this->facts($wsId, $bizId);
        $by = [];
        foreach ($facts as $f) $by[$f->group][] = $f;
        $titles = ['identity' => 'Who you are and your vision', 'goals' => 'Your goals', 'preferences' => 'Your preferences', 'interests' => 'What you care about', 'wants' => 'What you asked for', 'relationship' => 'How we are doing'];
        $lines = [];
        foreach ($titles as $g => $title) {
            if (empty($by[$g])) continue;
            $lines[] = "**{$title}**";
            foreach (array_slice($by[$g], 0, 12) as $f) {
                $when = substr((string) ($f->last_confirmed_at ?: $f->first_seen_at ?: $f->created_at), 0, 10);
                $lines[] = '- ' . self::secondPerson($f->value) . ' (' . ($f->status === 'proposed' ? 'my guess, ' : '') . ($f->source === 'stated' ? 'you told me ' : ($f->source === 'observed' ? 'from what you did, ' : '')) . $when . ')';
            }
        }
        $beh = $this->behaviour($wsId);
        if ($beh) { $lines[] = '**What I notice**'; foreach ($beh as $k => $v) $lines[] = "- {$k}: {$v}"; }
        if (! $lines) return "I don't hold anything personal about you yet beyond your business details. Tell me your goals or how you like things done and I'll keep it.";
        return implode("\n", $lines) . "\n\nSay **forget me** and I'll erase all of it. Say **no** after any line you want gone.";
    }

    /** Owner-facing lines speak to the owner, not about them: "The owner never wants emojis" -> "You never want emojis". */
    public static function secondPerson(string $v): string
    {
        $v = preg_replace(["/^The owner's business is /u", "/^The owner's /u", "/^The owner /u", "/^The business owner /u"], ['Your business is ', 'Your ', 'You ', 'You '], $v);
        $v = preg_replace(['/\btheir\b/u', '/\bthemselves\b/u', '/\bthem\b/u'], ['your', 'yourself', 'you'], $v);
        $irregular = ['is' => 'are', 'has' => 'have', 'does' => 'do', 'was' => 'were', 'goes' => 'go', 'wishes' => 'wish', 'focuses' => 'focus', 'dislikes' => 'dislike'];
        return preg_replace_callback('/^You( never| always| also| only| usually| rarely)? ([a-z]+)\b/u', function ($m) use ($irregular) {
            $verb = $m[2];
            if (isset($irregular[$verb])) $verb = $irregular[$verb];
            elseif (preg_match('/^(want|need|love|like|hate|prefer|run|cook|serve|sign|use|post|speak|talk|care|plan|aim|sell|own|work|live|enjoy|avoid|offer|open|close|charge|deliver|teach|write|make|keep|start|think|believe|value|expect|see|host|cater|operate|lead|manage|target|describe|call|consider|tell|ask|expect|intend|hope)s$/u', $verb)) $verb = substr($verb, 0, -1);
            return 'You' . $m[1] . ' ' . $verb;
        }, $v);
    }
    public function event(int $wsId, string $kind, array $meta = [], int $size = 0, bool $truncated = false): void
    {
        try { DB::table('memory_events')->insert(['workspace_id' => $wsId, 'kind' => $kind, 'size' => $size, 'truncated' => $truncated, 'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now()]); } catch (\Throwable) {}
    }
}
