<?php

namespace App\Core\Agents;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DIGEST-1 (Owner 2026-09-28: "I see a lot of messages from sarah regarding tasks done by other ai agents, those should be
 * combined in one message not like that").
 *
 * Reports of finished work — a specialist's report relayed by Sarah (SarahVoice) and the orchestrator's completion line —
 * no longer land one bubble (and one push) each. They wait here; once the team goes quiet for a minute (or five minutes
 * have passed since the first one), Sarah posts ONE message in her words that covers them all, with one push.
 * A lone report goes out as it was written. Messages with an image, a card or a user-set reminder never wait.
 * Kill switch: storage/app/digest1.on (absent = every report posts at once, as before).
 */
final class SarahDigest
{
    public const QUIET_SECONDS = 60;
    public const MAX_WAIT_SECONDS = 300;

    public static function on(): bool
    {
        return is_file(storage_path('app/digest1.on'));
    }

    /** Should this message wait for the digest? $relayed = SarahVoice re-voiced a specialist. */
    public static function wants(bool $relayed, array $metadata): bool
    {
        if (! self::on() || ! empty($metadata['digest_flush']) || ! empty($metadata['no_digest'])) return false;
        if (! empty($metadata['attachments']) || ! empty($metadata['card']) || ! empty($metadata['followup'])) return false;
        return $relayed || ! empty($metadata['completion_report']);
    }

    public function hold(int $wsId, string $content, array $metadata): void
    {
        DB::table('sarah_digest_items')->insert([
            'workspace_id' => $wsId, 'content' => mb_substr($content, 0, 65535),
            'metadata_json' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // one waiting job per workspace is enough; it re-arms itself while reports keep arriving
        if (Cache::add('sarah-digest-armed:' . $wsId, 1, now()->addSeconds(self::QUIET_SECONDS + 30))) {
            \App\Jobs\SarahDigestJob::dispatch($wsId)->delay(now()->addSeconds(self::QUIET_SECONDS + 5));
        }
    }

    /** Post what is waiting when the team has gone quiet. Returns seconds to wait again, or 0 when done. */
    public function flush(int $wsId, bool $force = false): int
    {
        $lock = Cache::lock('sarah-digest-flush:' . $wsId, 60);
        if (! $lock->get()) return 20;
        try {
            $rows = DB::table('sarah_digest_items')->where('workspace_id', $wsId)->whereNull('flushed_at')->orderBy('id')->get();
            if ($rows->isEmpty()) return 0;
            $newest = \Carbon\Carbon::parse($rows->last()->created_at); $oldest = \Carbon\Carbon::parse($rows->first()->created_at);
            if (! $force && $newest->diffInSeconds(now()) < self::QUIET_SECONDS && $oldest->diffInSeconds(now()) < self::MAX_WAIT_SECONDS) {
                return max(10, self::QUIET_SECONDS - (int) $newest->diffInSeconds(now()) + 5);
            }
            $ids = $rows->pluck('id')->all();
            DB::table('sarah_digest_items')->whereIn('id', $ids)->update(['flushed_at' => now(), 'updated_at' => now()]);

            $metas = $rows->map(fn ($r) => json_decode((string) $r->metadata_json, true) ?: [])->all();
            if (count($rows) === 1) {
                $id = app(AgentMessageService::class)->postAsAgent($wsId, 'sarah', (string) $rows[0]->content, $metas[0] + ['digest_flush' => true]);
            } else {
                $items = []; $fallback = [];
                foreach ($rows as $i => $r) {
                    $who = (string) ($metas[$i]['relayed_sender'] ?? '');
                    $text = trim((string) preg_replace('/^[A-Z][a-z]+ reports:\s*/u', '', (string) $r->content));
                    $items[] = ['teammate' => $who ?: null, 'report' => mb_substr($text, 0, 1200)];
                    $fallback[] = '• ' . ($who ? $who . ': ' : '') . mb_substr((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/^[A-Z][a-z]+ (finished|checked) the (job|\d+ jobs) I passed along\s*[—-]+\s*(here\'s what\'s done:)?\s*/u', '', $text)), 0, 220);
                }
                $fb = "Here's what the team finished just now:\n\n" . implode("\n", $fallback);
                $text = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($wsId, 'sarah_digest',
                    'Several pieces of work your team finished just now are listed. Write ONE short message that covers all of them: one opening line, then a short bullet list (one bullet per piece of work, "- " at the start of each line; name the teammate only where it helps, keep article and post titles exactly), then — only if one of them waits for the owner (for example a post ready to go out with a Post it button) — one closing line saying what to do. Do not repeat the same thing twice. No greeting beyond the opening line, no internal ids.',
                    ['finished_work' => $items], $fb);
                $meta = ['completion_report' => true, 'digest_flush' => true, 'digest_count' => count($rows), 'notification_type' => 'sarah_digest',
                    'root_task_ids' => array_values(array_unique(array_filter(array_map(fn ($m) => isset($m['root_task_id']) ? (string) $m['root_task_id'] : null, $metas)))),
                    'task_ids' => array_values(array_unique(array_filter(array_map(fn ($m) => isset($m['task_id']) ? (string) $m['task_id'] : null, $metas)))),
                    'relayed_from' => array_values(array_unique(array_filter(array_map(fn ($m) => $m['relayed_from'] ?? null, $metas)))),
                    'action_links' => array_values(array_filter(array_map(fn ($m) => $m['action_link'] ?? null, $metas)))];
                if (($first = collect($metas)->first(fn ($m) => ! empty($m['action_link']))) !== null) $meta['action_link'] = $first['action_link'];
                $id = app(AgentMessageService::class)->postAsAgent($wsId, 'sarah', $text, $meta);
            }
            DB::table('sarah_digest_items')->whereIn('id', $ids)->update(['message_id' => $id ?: null]);
            Log::info('[DIGEST-1] posted', ['ws' => $wsId, 'items' => count($ids), 'message' => $id]);
            return 0;
        } finally {
            $lock->release();
        }
    }

    /** Safety net (every minute): anything left waiting past the window goes out even if a job was lost. */
    public function sweep(): int
    {
        $n = 0;
        $ws = DB::table('sarah_digest_items')->whereNull('flushed_at')->where('created_at', '<=', now()->subSeconds(self::QUIET_SECONDS + 60))->distinct()->pluck('workspace_id');
        foreach ($ws as $w) { try { if ($this->flush((int) $w) === 0) $n++; } catch (\Throwable $e) { Log::warning('[DIGEST-1] sweep failed', ['ws' => $w, 'e' => $e->getMessage()]); } }
        return $n;
    }
}
