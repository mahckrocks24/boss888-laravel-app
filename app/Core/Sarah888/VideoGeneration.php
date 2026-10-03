<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VIDEO-2 (Owner 2026-09-27: "go, close all gaps, prove it works"). The owner asks Sarah for a video in her chat — on the
 * web or in the companion app — and it is made, the same two-turn way as an image (IMAGE-1):
 *   turn 1  "make a short video of our sourdough for Instagram" → Sarah says exactly what she will make (length, shape,
 *           subject), that it follows the brand, the cost and the wait; nothing runs yet
 *   turn 2  "yes" → the video task is created under that yes (no second approval); the finished clip comes back to the chat
 *           (CreativeService::tellVideoInChat) with a poster and a player; a failure says so and the credits return.
 * Shape: vertical 9:16 unless the owner names a landscape place (YouTube, website) or square. Mentioning Instagram is a
 * shape hint, never a reason to refuse — making the video does not need a connected account.
 */
final class VideoGeneration
{
    public const COST = 8;
    private const TTL_MIN = 30;

    public static function key(int $wsId): string { return 'sarah:videogen:ws' . $wsId; }

    public static function asks(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 600) return false;
        if (! preg_match('/\b(videos?|clips?|reels?|tiktoks?|shorts|animation|animated|motion graphic)\b/', $t)) return false;
        $verb = preg_match('/\b(make|create|generate|produce|shoot|film|render|animate|do|put together|whip up)\b/', $t)
             || preg_match('/\b(i want|i need|i\'d like|give me|can you|could you|would you|let\'s have)\b/', $t);
        if (! $verb) return false;
        // questions and lookups, posting/cancelling an existing one, and editing in Studio are not requests for a new video
        if (preg_match('/\b(how many|which|list|show me my|where is|where are|do i have|status of|did you|have you|what happened)\b/', $t)) return false;
        if (preg_match('/\b(post|publish|upload|share|schedule|cancel|stop|delete)\b[^.?!]{0,20}\b(the|that|this|my) (video|clip|reel)\b/', $t)) return false;
        if (preg_match('/\b(watch|play|send me) (the|that|my) (video|clip)\b/', $t)) return false;
        return true;
    }

    public static function confirms(string $t): bool { return ImageGeneration::confirms($t); }
    public static function declines(string $t): bool { return ImageGeneration::declines($t); }

    /** What will be made, from the owner's words. */
    public static function spec(int $wsId, string $text): array
    {
        $t = mb_strtolower($text);
        $duration = (preg_match('/\b(\d{1,2})\s*(-|\s)?(s|sec|secs|second|seconds)\b/', $t, $m) && (int) $m[1] >= 8) || preg_match('/\b(ten|10)[- ]second/', $t) ? 10 : 6;
        $aspect = '9:16';
        if (preg_match('/\b(youtube|website|web site|landscape|horizontal|widescreen|16:9|banner|tv)\b/', $t)) $aspect = '16:9';
        elseif (preg_match('/\b(square|1:1)\b/', $t)) $aspect = '1:1';
        $prompt = trim((string) preg_replace('/^\s*(hey sarah[,!]?\s*|sarah[,!]?\s*)?(please\s+)?(can you|could you|would you|i want|i need|i\'d like|give me|let\'s have|make me|make|create|generate|produce|shoot|film|put together|do)\b[^.]*?\b(videos?|clips?|reels?|tiktoks?|shorts|animation)\b\s*(of|showing|about|with|featuring|for)?\s*/iu', '', $text, 1));
        $prompt = trim((string) preg_replace('/\b(for (our |my )?(instagram|insta|ig|facebook|fb|tiktok|reels?|stories|youtube|website|linkedin))\b[.!?]*\s*$/iu', '', $prompt));
        $prompt = trim((string) preg_replace('/\b\d{1,2}\s*-?\s*(s|sec|secs|second|seconds)\b\s*(long)?/iu', '', $prompt));
        // VIDEO-CERT-2: "for Instagram of the kare-kare ..." - where it will run is not what is filmed
        $prompt = trim((string) preg_replace('/^\s*(a\s+)?(short\s+|quick\s+)?(vertical\s+|square\s+|landscape\s+|horizontal\s+)?((for\s+)?(our |my )?(instagram|insta|ig|facebook|fb|tiktok|reels?|stories|youtube|website|linkedin)\s+)?(of|showing|about|with|featuring)?\s+/iu', '', ' ' . $prompt));
        if (mb_strlen($prompt) < 4) $prompt = trim($text);
        $biz = null;
        foreach (DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name']) as $b) { if (str_contains($t, mb_strtolower($b->name))) { $biz = (int) $b->id; break; } }
        if (! $biz) {   // VIDEO-CERT-2: the business the owner is talking about (sticky), as every other Sarah path
            try {
                $r = app(\App\Core\Business\BusinessContext::class)->resolve($wsId, $text);
                if (! empty($r['business_id']) && ($r['mode'] ?? '') !== 'portfolio' && empty($r['ask'])) $biz = (int) $r['business_id'];
            } catch (\Throwable) {}
        }
        return ['prompt' => mb_substr($prompt, 0, 600), 'duration' => $duration, 'aspect_ratio' => $aspect, 'business_id' => $biz, 'cost' => self::costFor($duration)];
    }

    /** VIDEO-CERT-2: the price Sarah says is the price the kernel charges (28 for 6 s, 52 for 10 s today) - never a constant. */
    public static function costFor(int $duration): int
    {
        try { return (int) app(\App\Core\EngineKernel\CapabilityMapService::class)->creditCostFor('generate_video', ['duration' => $duration]); }
        catch (\Throwable) { return $duration === 10 ? 52 : 28; }
    }

    public function remember(int $wsId, array $spec, string $ownerText): void
    {
        Cache::put(self::key($wsId), $spec + ['owner_text' => $ownerText, 'asked_at' => time()], now()->addMinutes(self::TTL_MIN));
    }

    public function pending(int $wsId): ?array { $v = Cache::get(self::key($wsId)); return is_array($v) ? $v : null; }

    public function forget(int $wsId): void { Cache::forget(self::key($wsId)); }

    public static function shapeWords(string $aspect): string
    {
        return ['9:16' => 'vertical (for Reels, Stories and TikTok)', '16:9' => 'landscape (for YouTube and your website)', '1:1' => 'square (for the feed)'][$aspect] ?? 'vertical';
    }

    /** Turn 1 — exactly what will be made, the cost and the wait; nothing runs yet. */
    public function describe(array $spec): string
    {
        return "I'll make a **" . (int) $spec['duration'] . '-second ' . self::shapeWords((string) $spec['aspect_ratio']) . '** video: ' . rtrim((string) $spec['prompt'], '. ') . '. '
            . (($__q = \App\Core\ImageIntelligence\ImageIntelligenceService::quotedText((string) $spec['prompt'])) ? 'The words "' . $__q[0] . '" go on as a clean title. ' : '')   // VIDEO-CERT-2
            . "It follows your brand and design styles. It costs **" . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . " credits** and takes about two minutes — I'll post it right here when it's ready.\n\n"
            . 'Reply **yes** to go ahead, or **no**.';
    }

    /** Turn 2 — the owner's yes is the commission: the video task runs under it. */
    public function execute(int $wsId, array $spec, ?int $userId): array
    {
        try {
            app(SpendContext::class)->setTurn(['specifies_action' => true, 'authorized' => true, 'classification' => 'authorisation',
                'reason' => 'the owner said yes to Sarah\'s video offer (VIDEO-2)'], $wsId);
        } catch (\Throwable $e) {}
        try {
            $task = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, [
                'engine' => 'creative', 'action' => 'generate_video', 'source' => 'agent', 'assigned_agents' => ['studio'],
                'auto_approve' => true, 'requires_approval' => false, 'user_confirmed' => true, 'priority' => 'normal',
                'payload' => array_filter([
                    'prompt' => $spec['prompt'], 'duration' => (int) $spec['duration'], 'aspect_ratio' => (string) $spec['aspect_ratio'], 'business_id' => $spec['business_id'] ?? null,
                    'title' => 'Video: ' . mb_substr((string) $spec['prompt'], 0, 80), 'created_via' => 'sarah_video_request', 'user_request' => (string) ($spec['owner_text'] ?? ''),
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
            Log::info('[VIDEO-2] executed', ['ws' => $wsId, 'task' => $task->id, 'aspect' => $spec['aspect_ratio'], 'duration' => $spec['duration']]);
            return ['success' => true, 'task_id' => $task->id];
        } catch (\Throwable $e) {
            Log::warning('[VIDEO-2] could not create the video task', ['ws' => $wsId, 'e' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function report(array $res, array $spec): string
    {
        if (! ($res['success'] ?? false)) {
            return preg_match('/credit/i', (string) ($res['error'] ?? ''))
                ? "I couldn't start the video — there aren't enough credits for it (it needs " . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . '). Nothing was charged.'
                : "I couldn't start the video just now — nothing was charged. Try again in a moment.";
        }
        return "I'm making your " . (int) $spec['duration'] . '-second ' . explode(' ', self::shapeWords((string) $spec['aspect_ratio']))[0] . ' video now (' . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . " credits). It takes about two minutes; I'll post it here as soon as it's ready.";
    }
}
