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

    public static function asks(string $text, bool $hasImage = false): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 600) return false;
        // RFC-0025 P2: a photo attached with "animate this", "bring it to life", "make it move" is a video request
        if ($hasImage && preg_match('/\b(animate|animated|bring (it|this|them) to life|make (it|this|them|the \w+) move|into a (short )?(video|clip|reel)|(video|clip|reel) (of|from) (this|it|the photo)|(video|clip|reel|reels) (of|from|with|using) (these|my|the|those|all) (\w+ )?(photos|pictures|images|pics|shots))\b/u', $t)
            && ! preg_match('/\b(how many|which|did you|have you|what happened|status of)\b/', $t)) return true;
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
    public static function spec(int $wsId, string $text, array $images = []): array
    {
        $photo = $images[0] ?? null;   // RFC-0025 P2: the owner's photo
        $photos = array_values(array_slice(array_filter($images, fn ($i) => ! empty($i['url'])), 0, 3));   // RFC-0025 P3: up to three, in order
        $t = mb_strtolower($text);
        $duration = (preg_match('/\b(\d{1,2})\s*(-|\s)?(s|sec|secs|second|seconds)\b/', $t, $m) && (int) $m[1] >= 8) || preg_match('/\b(ten|10)[- ]second/', $t) ? 10 : 6;
        $aspect = '9:16';
        if (preg_match('/\b(youtube|website|web site|landscape|horizontal|widescreen|16:9|banner|tv)\b/', $t)) $aspect = '16:9';
        elseif (preg_match('/\b(square|1:1)\b/', $t)) $aspect = '1:1';
        if (preg_match('/\bpinterest\b/', $t)) $aspect = '2:3';   // RFC-0025: Pinterest's own shape
        $platform = preg_match('/\b(instagram|insta|ig|reels?|stories)\b/', $t) ? 'instagram' : (preg_match('/\btiktok\b/', $t) ? 'tiktok' : (preg_match('/\b(facebook|fb)\b/', $t) ? 'facebook'
            : (preg_match('/\bpinterest\b/', $t) ? 'pinterest' : (preg_match('/\b(youtube|shorts)\b/', $t) ? 'youtube' : (preg_match('/\b(website|web site)\b/', $t) ? 'website' : null)))));
        $prompt = trim((string) preg_replace('/^\s*(hey sarah[,!]?\s*|sarah[,!]?\s*)?(please\s+)?(can you|could you|would you|i want|i need|i\'d like|give me|let\'s have|make me|make|create|generate|produce|shoot|film|put together|do)\b[^.]*?\b(videos?|clips?|reels?|tiktoks?|shorts|animation)\b\s*(of|showing|about|with|featuring|for)?\s*/iu', '', $text, 1));
        $prompt = trim((string) preg_replace('/\b(for (our |my )?(instagram|insta|ig|facebook|fb|tiktok|reels?|stories|youtube|website|linkedin|pinterest))\b[.!?]*\s*$/iu', '', $prompt));
        $prompt = trim((string) preg_replace('/\b\d{1,2}\s*-?\s*(s|sec|secs|second|seconds)\b\s*(long)?/iu', '', $prompt));
        // VIDEO-CERT-2: "for Instagram of the kare-kare ..." - where it will run is not what is filmed
        $prompt = trim((string) preg_replace('/^\s*(a\s+)?(short\s+|quick\s+)?(vertical\s+|square\s+|landscape\s+|horizontal\s+)?((for\s+)?(our |my )?(instagram|insta|ig|facebook|fb|tiktok|reels?|stories|youtube|website|linkedin|pinterest)\s+)?(of|showing|about|with|featuring)?\s+/iu', '', ' ' . $prompt));
        if ($photo) {
            $prompt = trim((string) preg_replace('/^\s*(please\s+)?(can you\s+|could you\s+|would you\s+)?(animate|bring\b.*?\bto life|make\b.*?\bmove)\s*(this|the|my|it|them)?\s*(photo|picture|image|pic|shot)?\s*(into a (short )?(video|clip|reel))?\s*[-,:;.]?\s*(with\s+)?/iu', '', $text));
            $prompt = trim((string) preg_replace('/\b(for (our |my )?(instagram|insta|ig|facebook|fb|tiktok|reels?|stories|youtube|website|linkedin|pinterest))\b[.!?]*/iu', '', $prompt), " ,.;:-");
            // RFC-0025 P3: "make a video from these photos", "turn these photos into a reel" are the ask, not the motion
            $prompt = trim((string) preg_replace('/^\s*(please\s+)?(can you\s+|could you\s+)?((make|create|put together|do)\s+(me\s+)?(a|an)?\s*(short\s+|quick\s+)?(video|clip|reel)\s+(from|of|with|using)\s+(these|my|the|those|all)\s+(\w+\s+)?(photos|pictures|images|pics|shots)|turn\s+(these|my|the|those)\s+(\w+\s+)?(photos|pictures|images|pics)\s+into\s+(a|an)\s+(short\s+)?(video|clip|reel))\s*[-,:;.]?\s*(with\s+)?/iu', '', $prompt), " ,.;:-");
            if (preg_match('/^["\x{201C}\x{2018}\']{1}[^"\x{201C}\x{201D}]{1,80}["\x{201D}\x{2019}\']\s+as\s+the\s+(title|headline)\.?$/iu', $prompt)) $prompt = 'gentle, natural motion with a slow push in, ' . $prompt;
            if (mb_strlen($prompt) < 4) $prompt = 'gentle, natural motion with a slow push in';
            if (! preg_match('/\b(youtube|website|web site|landscape|horizontal|widescreen|16:9|banner|tv|square|1:1|vertical|reel|reels|story|stories|tiktok|9:16|pinterest|instagram|insta|ig)\b/', $t)) {   // round A: Instagram = 9:16
                $aspect = self::photoShape((string) ($photo['url'] ?? ''));   // the photo's own orientation
            }
            if (preg_match('/\bpinterest\b/', $t)) $aspect = '2:3';
        } elseif (mb_strlen($prompt) < 4) $prompt = trim($text);
        $biz = null;
        foreach (DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name']) as $b) { if (str_contains($t, mb_strtolower($b->name))) { $biz = (int) $b->id; break; } }
        if (! $biz) {   // VIDEO-CERT-2: the business the owner is talking about (sticky), as every other Sarah path
            try {
                $r = app(\App\Core\Business\BusinessContext::class)->resolve($wsId, $text);
                if (! empty($r['business_id']) && ($r['mode'] ?? '') !== 'portfolio' && empty($r['ask'])) $biz = (int) $r['business_id'];
            } catch (\Throwable) {}
        }
        if (count($photos) > 1) $duration = 10;   // several photos make one 10-second video, priced as one
        if ($biz) {   // "for Smile Studio Dental" names whose video it is, not what is in it
            $bn = (string) DB::table('businesses')->where('id', $biz)->value('name');
            if ($bn !== '') $prompt = trim((string) preg_replace('/\s*\b(for|at|of)\s+' . preg_quote($bn, '/') . '\b/iu', '', $prompt), " ,.;:-");
        }
        $spec = ['prompt' => mb_substr($prompt, 0, 600), 'duration' => $duration, 'aspect_ratio' => $aspect, 'business_id' => $biz, 'cost' => self::costFor($duration), 'platform' => $platform];
        if (count($photos) > 1) {
            $spec['source_images'] = array_map(fn ($i) => (string) $i['url'], $photos);
            $checks = array_map(fn ($i) => self::photoChecks((string) $i['url']), $photos);
            $spec['photo_checks'] = ['people' => (bool) array_filter(array_column($checks, 'people')), 'text' => (bool) array_filter(array_column($checks, 'text')),
                'description' => implode('; ', array_map(fn ($k, $c) => 'photo ' . ($k + 1) . ': ' . ($c['description'] ?? ''), array_keys($checks), $checks))];
        } elseif ($photo) {
            $spec['source_image'] = (string) ($photo['url'] ?? '');
            $spec['source_media_id'] = (int) ($photo['media_id'] ?? 0);
            $spec['photo_checks'] = self::photoChecks($spec['source_image']);
        }
        return $spec;
    }

    /** RFC-0025 P2: the photo's orientation decides the shape when the owner names none. */
    public static function photoShape(string $url): string
    {
        try {
            $rel = ltrim((string) preg_replace('#^.*?/storage/#', '', (string) parse_url($url, PHP_URL_PATH)), '/');
            $size = $rel !== '' ? @getimagesize(\Illuminate\Support\Facades\Storage::disk('public')->path($rel)) : false;
            if ($size && $size[1] > 0) { $r = $size[0] / $size[1]; return $r > 1.15 ? '16:9' : ($r > 0.87 ? '1:1' : '9:16'); }
        } catch (\Throwable) {}
        return '9:16';
    }

    /** RFC-0025 P2 (D6): does the photo show people or writing - said in the offer, never guessed. */
    public static function photoChecks(string $url): array
    {
        if ($url === '') return [];
        try {
            $abs = preg_match('#^https?://#', $url) ? $url : rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
            $v = app(\App\Connectors\RuntimeClient::class)->visionAnalyze('Look at this photo. Return ONLY JSON: {"people": true or false (any person, face or hands visible), "text": true or false (any readable writing, labels, signs or logos), "description": "one plain sentence of what the photo shows"}', '', $abs);
            $raw = (string) ($v['analysis'] ?? '');
            $j = preg_match('/\{.*\}/s', $raw, $m) ? (json_decode($m[0], true) ?: []) : [];
            return ['people' => (bool) ($j['people'] ?? false), 'text' => (bool) ($j['text'] ?? false), 'description' => mb_substr(trim((string) ($j['description'] ?? '')), 0, 300), 'checked' => ! empty($j)];
        } catch (\Throwable) { return ['checked' => false]; }
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
        return ['9:16' => 'vertical (for Reels, Stories and TikTok)', '16:9' => 'landscape (for YouTube and your website)', '1:1' => 'square (for the feed)', '2:3' => 'tall (for Pinterest)'][$aspect] ?? 'vertical';
    }

    /** Turn 1 — exactly what will be made, the cost and the wait; nothing runs yet. */
    public function describe(array $spec): string
    {
        if (! empty($spec['source_images']) && count($spec['source_images']) > 1) {   // RFC-0025 P3: several photos
            $pc = (array) ($spec['photo_checks'] ?? []);
            $n = count($spec['source_images']);
            return "I'll turn your $n photos into one **" . (int) $spec['duration'] . '-second ' . self::shapeWords((string) $spec['aspect_ratio']) . '** video: one moving shot per photo, in the order you sent them, joined with soft crossfades'
                . (mb_strlen((string) $spec['prompt']) > 3 && ! str_starts_with((string) $spec['prompt'], 'gentle, natural motion') ? ' - ' . rtrim((string) $spec['prompt'], '.?! ') : '') . '. '
                . 'Everything in the photos stays as it is; only the motion and the camera are added. '
                . (! empty($pc['text']) ? 'Writing in a photo, like signs or labels, can bend once it moves - any headline goes on afterwards as a clean title. ' : '')
                . (($__q = \App\Core\ImageIntelligence\ImageIntelligenceService::quotedText((string) $spec['prompt'])) ? 'The words "' . $__q[0] . '" go on as a clean title. ' : '')
                . "It costs **" . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . " credits** and takes about three minutes — I'll post it right here when it's ready.\n\n"
                . (! empty($pc['people']) ? 'The photos show people, so reply **yes** only if you have their permission to animate them - or **no**.' : 'Reply **yes** to go ahead, or **no**.');
        }
        if (! empty($spec['source_image'])) {   // RFC-0025 P2: animate the owner's photo
            $pc = (array) ($spec['photo_checks'] ?? []);
            return "I'll animate your photo into a **" . (int) $spec['duration'] . '-second ' . self::shapeWords((string) $spec['aspect_ratio']) . '** video: ' . rtrim((string) $spec['prompt'], '.?! ') . '. '
                . 'Everything in the photo stays as it is; only the motion and the camera are added. '
                . (! empty($pc['text']) ? 'Writing in the photo, like signs or labels, can bend once it moves - any headline goes on afterwards as a clean title. ' : '')
                . (($__q = \App\Core\ImageIntelligence\ImageIntelligenceService::quotedText((string) $spec['prompt'])) ? 'The words "' . $__q[0] . '" go on as a clean title. ' : '')
                . "It costs **" . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . " credits** and takes about two minutes — I'll post it right here when it's ready.\n\n"
                . (! empty($pc['people']) ? 'The photo shows people, so reply **yes** only if you have their permission to animate them - or **no**.' : 'Reply **yes** to go ahead, or **no**.');
        }
        return "I'll make a **" . (int) $spec['duration'] . '-second ' . self::shapeWords((string) $spec['aspect_ratio']) . '** video: ' . rtrim((string) $spec['prompt'], '.?! ') . '. '
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
                    'platform' => $spec['platform'] ?? null,   // RFC-0025: the safe-zone profile
                    'title' => 'Video: ' . mb_substr((string) $spec['prompt'], 0, 80), 'created_via' => 'sarah_video_request', 'user_request' => (string) ($spec['owner_text'] ?? ''),
                    'image_url' => $spec['source_image'] ?? null, 'source_media_id' => ! empty($spec['source_media_id']) ? (int) $spec['source_media_id'] : null,   // RFC-0025 P2
                    'source_images' => ! empty($spec['source_images']) ? array_values($spec['source_images']) : null,   // RFC-0025 P3
                    'photo_description' => $spec['photo_checks']['description'] ?? null,
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
        if (! empty($spec['source_images']) && count($spec['source_images']) > 1) {   // RFC-0025 P3
            return "I'm making your " . count($spec['source_images']) . '-photo, ' . (int) $spec['duration'] . '-second ' . explode(' ', self::shapeWords((string) $spec['aspect_ratio']))[0] . ' video now ('
                . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . " credits). It takes about three minutes; I'll post it here as soon as it's ready.";
        }
        return "I'm making your " . (int) $spec['duration'] . '-second ' . explode(' ', self::shapeWords((string) $spec['aspect_ratio']))[0] . ' video now (' . (int) ($spec['cost'] ?? self::costFor((int) $spec['duration'])) . " credits). It takes about two minutes; I'll post it here as soon as it's ready.";
    }
}
