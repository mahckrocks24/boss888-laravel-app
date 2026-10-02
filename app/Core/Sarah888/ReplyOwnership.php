<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REPORT-0071 P1-3 (2026-10-02): one authoritative answer per owner message.
 *
 * Sarah's turn owns the answer to an owner message. Background subsystems that work on the same message (the image study,
 * brand intake, campaign ideas, ...) may post AFTER her only when her turn declared that follow-up - "a card with my design
 * reading follows" - and only once per kind, however many workers finish at the same time. A subsystem whose follow-up was
 * not declared keeps its result as state (library, record) and posts nothing, so the owner never gets a second, competing
 * answer to one question.
 *
 *   declare(ws, msg, kind)        the turn that answers `msg` announces a follow-up of `kind`
 *   mayFollowUp(ws, msg, kind)    a subsystem asks before posting; true at most once per (msg, kind), and only if declared
 *                                 or if Sarah's turn has not answered at all (nothing to compete with)
 */
final class ReplyOwnership
{
    private const TTL_HOURS = 24;

    public static function declare(int $wsId, int $userMessageId, string $kind): void
    {
        if ($userMessageId <= 0) return;
        Cache::put(self::key('decl', $wsId, $userMessageId, $kind), 1, now()->addHours(self::TTL_HOURS));
    }

    public static function declared(int $wsId, int $userMessageId, string $kind): bool
    {
        return (bool) Cache::get(self::key('decl', $wsId, $userMessageId, $kind));
    }

    /** Sarah's final reply to this owner message exists (stamped with user_message_id by the chat route). */
    public static function answered(int $wsId, int $userMessageId): bool
    {
        return DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('id', '>', $userMessageId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.user_message_id')) = ?", [(string) $userMessageId])
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.phase')), '') = 'final'")->exists();
    }

    public static function mayFollowUp(int $wsId, int $userMessageId, string $kind): bool
    {
        if ($userMessageId <= 0) return true;   // not tied to an owner message: not a competing answer
        $allowed = self::declared($wsId, $userMessageId, $kind) || ! self::answered($wsId, $userMessageId);
        if (! $allowed) { Log::info('[REPLY-OWNER] follow-up withheld: not declared by the answering turn', ['ws' => $wsId, 'msg' => $userMessageId, 'kind' => $kind]); return false; }
        // once per (message, kind): Cache::add is atomic, so concurrent workers race for one slot
        if (! Cache::add(self::key('post', $wsId, $userMessageId, $kind), 1, now()->addHours(self::TTL_HOURS))) { Log::info('[REPLY-OWNER] duplicate follow-up dropped', ['ws' => $wsId, 'msg' => $userMessageId, 'kind' => $kind]); return false; }
        return true;
    }

    private static function key(string $p, int $ws, int $msg, string $kind): string { return "reply-own:{$p}:{$ws}:{$msg}:{$kind}"; }
}
