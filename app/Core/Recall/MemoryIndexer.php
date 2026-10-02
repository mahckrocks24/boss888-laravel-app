<?php

namespace App\Core\Recall;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P4: writes the recall index. Idempotent on `ref`; cheap enough to run after every Sarah turn (OwnerModelJob) and
 * hourly for everything else (memory:index). Nothing leaves the database: no model, no vendor.
 */
class MemoryIndexer
{
    public const MAX_TEXT = 700;

    /** Index one workspace: owner and Sarah messages, journal, ledger outcomes, confirmed facts. Returns counts. */
    public function indexWorkspace(int $wsId, ?\DateTimeInterface $since = null): array
    {
        $n = ['messages' => 0, 'journal' => 0, 'outcomes' => 0, 'facts' => 0];
        try {
            $q = DB::table('agent_messages')->where('workspace_id', $wsId)->whereIn('role', ['user', 'agent'])
                ->where(fn ($w) => $w->where('role', 'user')->orWhere('agent_slug', 'sarah'))->orderBy('id');
            if ($since) $q->where('created_at', '>=', $since);
            $q->chunk(300, function ($rows) use (&$n, $wsId) { foreach ($rows as $m) { if ($this->indexMessage($m)) $n['messages']++; } });

            $q = DB::table('business_journal')->where('workspace_id', $wsId)->orderBy('id');
            if ($since) $q->where('created_at', '>=', $since);
            foreach ($q->get() as $j) {
                if ($this->put($wsId, $j->business_id ? (int) $j->business_id : null, 'journal', 'journal:' . $j->id, 'owner', ucfirst((string) $j->kind) . ': ' . $j->text, $j->created_at)) $n['journal']++;
            }

            $q = DB::table('outcome_ledger')->where('workspace_id', $wsId)->orderBy('id');
            if ($since) $q->where('updated_at', '>=', $since);
            foreach ($q->get() as $r) {
                $p = json_decode((string) $r->planned_json, true) ?: []; $v = json_decode((string) $r->verdict_json, true) ?: [];
                $txt = ucfirst((string) $r->kind) . ': ' . trim((string) ($p['what'] ?? str_replace('_', ' ', (string) ($p['action'] ?? $r->kind)))) . ' - ' . $r->status
                    . ($r->verdict !== 'none' ? ', ' . $r->verdict . (! empty($v['reason']) ? ' (' . $v['reason'] . ')' : '') : '')
                    . (! empty($r->lesson) ? '. Lesson: ' . $r->lesson : '');
                if ($this->put($wsId, $r->business_id ? (int) $r->business_id : null, 'outcome', 'ledger:' . $r->id, 'system', $txt, $r->delivered_at ?: $r->created_at, true)) $n['outcomes']++;
            }

            foreach (DB::table('owner_model_facts')->where('workspace_id', $wsId)->where('status', 'confirmed')->get() as $f) {
                if ($this->put($wsId, $f->business_id ? (int) $f->business_id : null, 'fact', 'fact:' . $f->id, $f->source === 'stated' ? 'owner' : 'system', ucfirst((string) $f->group) . ': ' . $f->value, $f->last_confirmed_at ?: $f->first_seen_at ?: $f->created_at, true)) $n['facts']++;
            }
            // facts that were dismissed or erased leave the index
            DB::table('memory_chunks')->where('workspace_id', $wsId)->where('source', 'fact')
                ->whereNotIn('ref', DB::table('owner_model_facts')->where('workspace_id', $wsId)->where('status', 'confirmed')->selectRaw("concat('fact:', id)"))->delete();
        } catch (\Throwable $e) { Log::warning('[RECALL] index failed: ' . $e->getMessage(), ['ws' => $wsId]); }
        return $n;
    }

    /** One chat message. Skips acks, cards without words, confirmations and very short lines. */
    public function indexMessage(object $m): bool
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $m->content));
        if (mb_strlen($text) < 12) return false;
        $meta = json_decode((string) ($m->metadata_json ?? ''), true) ?: [];
        if (($meta['phase'] ?? '') === 'ack') return false;
        // system notices in Sarah's voice (credit heads-ups, welcome, lifecycle, memory echoes) are not moments worth recalling
        if ($m->role !== 'user' && (! empty($meta['notification_type']) || ! empty($meta['lifecycle']) || preg_match('/^(Heads up: \d+ credits|👋|Welcome aboard|Draft ready — the preview)/u', $text))) return false;
        if ($m->role === 'user' && preg_match('/^\s*(yes|no|ok|okay|sure|go|go ahead|approve|approved|thanks|thank you|done|cancel|stop|\d+([ ,and]+\d+)*)\s*[.!]?\s*$/iu', $text)) return false;
        $biz = isset($meta['business_id']) ? (int) $meta['business_id'] : (isset($meta['card']['business_id']) ? (int) $meta['card']['business_id'] : null);
        return $this->put((int) $m->workspace_id, $biz ?: null, $m->role === 'user' ? 'owner_message' : 'sarah_message', 'msg:' . $m->id, $m->role === 'user' ? 'owner' : 'sarah', $text, $m->created_at);
    }

    /** Every workspace with a Sarah conversation. */
    public function indexAll(?\DateTimeInterface $since = null, ?int $only = null): array
    {
        $tot = ['workspaces' => 0, 'messages' => 0, 'journal' => 0, 'outcomes' => 0, 'facts' => 0];
        $ws = $only ? collect([$only]) : DB::table('agent_messages')->where('role', 'user')->when($since, fn ($q) => $q->where('created_at', '>=', $since))->distinct()->pluck('workspace_id');
        foreach ($ws as $id) { $n = $this->indexWorkspace((int) $id, $since); $tot['workspaces']++; foreach ($n as $k => $v) $tot[$k] += $v; }
        return $tot;
    }

    private function put(int $wsId, ?int $bizId, string $source, string $ref, string $saidBy, string $text, $saidAt, bool $upsert = false): bool
    {
        $text = mb_substr(trim($text), 0, self::MAX_TEXT);
        if ($text === '') return false;
        $row = ['workspace_id' => $wsId, 'business_id' => $bizId, 'source' => $source, 'ref' => $ref, 'said_by' => $saidBy, 'text' => $text, 'said_at' => $saidAt ?: now(), 'created_at' => now()];
        if ($upsert) { DB::table('memory_chunks')->upsert([$row], ['ref'], ['text', 'business_id', 'said_at']); return true; }
        return (bool) DB::table('memory_chunks')->insertOrIgnore($row);
    }
}
