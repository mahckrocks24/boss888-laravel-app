<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * IMAGE-1 (2026-09-03, Owner): "generate an image" is answered deterministically, in ONE reliable turn-pair.
 *
 * Owner, 2026-09-03 (ws2): asked Sarah for a mini image; she said "generating now" but created NO task, then
 * asked for confirmation again ("say the word") — the owner confirmed ~3× for one image and nothing ran.
 * Root cause: image generation had no deterministic handler, so confirmation relied on the ~36%-reliable LLM
 * re-emitting the task, which it dropped. This mirrors DraftPublishing/QueueCancellation: turn 1 states exactly
 * what will be generated and the cost and asks for a yes; turn 2's yes creates AND runs the task deterministically
 * (owner-confirmed, so it runs without a second approval) — no LLM, no drop, no repeat confirmation.
 */
class ImageGeneration
{
    public const TTL_MIN = 15;

    public static function key(int $wsId): string { return 'sarah:imagegen:ws' . $wsId; }

    /**
     * A clear request to generate a NEW standalone image, with a subject. Deliberately narrow:
     * excludes the featured-image / missing-image bulk flows (their own router branches), questions,
     * and publish/cancel. Quoted single-article work and "featured image" stay on their own paths.
     */
    public static function asks(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return false;
        // A DRAWING verb (draw/sketch/paint/illustrate/doodle) implies an image on its own —
        // "draw me a cat" needs no explicit "image/picture" noun. A GENERAL creation verb does.
        $drawVerb = (bool) preg_match('/\b(draw|sketch|paint|illustrate|doodle)\b/', $t);
        // "draw" idioms are NOT image requests: draw up a plan, draw on, draw attention, draw the line,
        // draw a comparison/conclusion, paint the town. Strip the waiver so they need an explicit image noun.
        if ($drawVerb && preg_match('/\b(draw\s+(up|on|from|out|attention|the line|a (comparison|conclusion|distinction|parallel|blank))|paint\s+the\s+town)\b/', $t)) {
            $drawVerb = false;
        }
        $genVerb  = (bool) preg_match('/\b(generate|create|make|design|produce|render|whip up|cook up)\b/', $t)
                 || (bool) preg_match('/\b(i want|i need|i\x27d like|can you (make|create|generate|design|do)|give me|could you (make|create|generate|design))\b/', $t);
        if (! $drawVerb && ! $genVerb) return false;
        // must name an image artefact (waived when a drawing verb is present)
        $noun = (bool) preg_match('/\b(image|images|picture|pictures|photo|photos|photograph|graphic|graphics|visual|visuals|artwork|illustration|drawing|logo|poster|banner|mockup|wallpaper|avatar|icon)\b/', $t);
        if (! $noun && ! $drawVerb) return false;
        // exclude the FEATURED / MISSING / bulk-article image flows (handled elsewhere)
        if (preg_match('/\bfeatured image|\bmissing\b|\ball (my |the )?articles?\b|\bevery article\b|\beach article\b|\bthe ones (that|missing)\b|\bblog post\b/', $t)) return false;
        // exclude questions / lookups
        if (preg_match('/\b(how many|which|list|show me my|where is|where are|do i have|status of|count of)\b/', $t)) return false;
        // exclude publish / cancel (their own handlers)
        if (preg_match('/\bpublish|\bcancel\b|\bstop\b/', $t)) return false;
        // must have a subject: an "of/showing/with/about" clause …
        if (preg_match('/\b(of|showing|with|about|depicting|featuring|for)\b\s+\S+/', $t)) return true;
        // … OR a drawing verb directly followed by a subject ("draw [me] a cat", "paint a sunset") …
        if ($drawVerb && preg_match('/\b(draw|sketch|paint|illustrate|doodle)\b\s+(me\s+|us\s+|a\s+|an\s+|the\s+|some\s+|my\s+)?\S+/', $t)) return true;
        // … OR enough descriptive words to be a real brief. A bare "make me an image" is not actionable.
        return str_word_count($t) >= 6;
    }

    public static function confirms(string $text): bool
    {
        return (bool) preg_match('/^\s*(yes|yeah|yep|yup|ok|okay|sure|go ahead|go for it|do it|confirm(ed)?|please do|generate (it|that)|make (it|that)|proceed|approved?|go)\b/i', trim($text));
    }

    public static function declines(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        // Only a SHORT, rejection-only message is a decline. "No, I mean X" / "No, generate Y" is a CORRECTION, not a decline.
        return (bool) preg_match('/^(no|nope|nah|not now|not yet|no thanks?|no thank you|cancel( that)?|skip( it)?|don\x27?t|do not|stop|forget it|never ?mind|leave it|hold( on)?)\s*[.!]*$/', $t);
    }

    public static function lastKey(int $wsId): string { return 'sarah:lastimg:ws' . $wsId; }

    public static function lastPrompt(int $wsId): ?string
    {
        try { $v = \Illuminate\Support\Facades\Cache::get(self::lastKey($wsId)); if (is_string($v) && trim($v) !== '') { return $v; } }
        catch (\Throwable $e) { /* fall through */ }
        // Fall back to the most recent generated image in this workspace (ANY path), so a refinement
        // works even when the image was not created through IMAGE-1's own flow (e.g. the LLM/legacy path).
        try {
            $p = \Illuminate\Support\Facades\DB::table('assets')
                ->where('workspace_id', $wsId)->where('type', 'image')->where('status', 'completed')
                ->whereNull('deleted_at')->whereNotNull('prompt')->where('prompt', '!=', '')
                ->orderByDesc('id')->value('prompt');
            return (is_string($p) && trim($p) !== '') ? $p : null;
        } catch (\Throwable $e) { return null; }
    }

    /** A follow-up that changes a just-generated image ("make it hyperrealistic", "a X version of it"). */
    public static function isRefinement(int $wsId, string $text): bool
    {
        if (self::lastPrompt($wsId) === null) { return false; }
        $t = mb_strtolower(trim($text));
        if ($t === '') { return false; }
        // A concrete change request ("hyperrealistic", "more X", "brighter", "instead") — a refinement even
        // if it opens with "make it" (which the confirm-detector would otherwise swallow).
        $hasChange = self::changeFrom($text) !== ''
            || (bool) preg_match('/\b(more|less|brighter|darker|bigger|smaller|change|turn it|instead|but make|but with|without)\b/', $t);
        $refers = (bool) preg_match('/\b(it|that|this one|the image|the picture|the photo|the one|same|version|again|you (sent|made|generated|created))\b/', $t);
        if (! $hasChange && ! $refers) { return false; }
        // A BARE confirm/decline (no change, no reference-with-instruction) is not a refinement.
        if (! $hasChange && (self::confirms($t) || self::declines($t))) { return false; }
        return true;
    }

    /** Build this turn's prompt: a refinement reuses the last image's prompt + the requested change. */
    public static function resolvePrompt(int $wsId, string $text): string
    {
        $last = self::lastPrompt($wsId);
        if ($last !== null && self::isRefinement($wsId, $text)) {
            $mod = self::changeFrom($text);
            if ($mod === '') { $mod = trim((string) preg_replace('/\b(no,?\s*i mean|the image you sent|of it|version|make it)\b/i', ' ', $text)); }
            $mod = trim((string) $mod, " ,.-");
            return $mod === '' ? $last : (rtrim($last, '. ') . '. ' . $mod . '.');
        }
        return self::extractPrompt($text);
    }

    /** The meaningful style/quality change in a refinement, if any (else empty). */
    private static function changeFrom(string $text): string
    {
        $t = mb_strtolower($text);
        $styles = ['hyperrealistic', 'hyper realistic', 'photorealistic', 'photo realistic', 'realistic', 'cinematic', 'cartoon', 'anime', '3d render', '3d', 'watercolour', 'watercolor', 'oil painting', 'pencil sketch', 'sketch', 'minimalist', 'vintage', 'retro', 'neon', 'black and white', 'monochrome', 'pixel art', 'pop art', 'vibrant', 'more colourful', 'more colorful', 'more detailed', 'high detail', 'brighter', 'darker', 'warmer', 'cooler', 'professional'];
        $found = [];
        foreach ($styles as $s) { if (strpos($t, $s) !== false) { $found[] = $s; } }
        // drop any match that is a substring of another (e.g. "realistic" inside "hyperrealistic")
        $found = array_values(array_filter($found, function ($s) use ($found) {
            foreach ($found as $o) { if ($o !== $s && strpos($o, $s) !== false) { return false; } }
            return true;
        }));
        return $found ? implode(', ', array_values(array_unique($found))) : '';
    }

    /** mini (1cr) / high (4cr) / standard (2cr) from the wording. */
    public static function actionFor(string $text): string
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b(mini|small|quick|thumbnail|tiny|little)\b/', $t)) return 'generate_image_mini';
        if (preg_match('/\b(hi.?res|high.?res|high resolution|large|hd|ultra|detailed|premium)\b/', $t)) return 'generate_image_high';
        return 'generate_image';
    }

    public static function costFor(string $action): int
    {
        return ['generate_image_mini' => 1, 'generate_image' => 2, 'generate_image_high' => 4][$action] ?? 2;
    }

    /** Strip the leading command so the stored prompt is the subject/brief, not "generate an image of …". */
    public static function extractPrompt(string $text): string
    {
        $t = trim($text);
        // remove a leading command phrase up to and including the artefact + optional "of/showing/…"
        $stripped = preg_replace(
            '/^\s*(please\s+)?(can you|could you|i want|i need|i\x27d like|give me|generate|create|make|design|draw|produce|render|whip up|cook up)\b[^.]*?\b(image|images|picture|pictures|photo|photos|photograph|graphic|graphics|visual|visuals|artwork|illustration|drawing|logo|poster|banner|mockup|wallpaper|avatar|icon)s?\b\s*(of|showing|depicting|featuring|about|with|for)?\s*/i',
            '',
            $t,
            1
        );
        $stripped = trim((string) $stripped);
        // if stripping removed everything meaningful, fall back to the whole message
        return (mb_strlen($stripped) >= 3) ? $stripped : $t;
    }

    public function remember(int $wsId, array $spec, string $ownerText): void
    {
        Cache::put(self::key($wsId), [
            'prompt'     => (string) ($spec['prompt'] ?? ''),
            'action'     => (string) ($spec['action'] ?? 'generate_image'),
            'cost'       => (int) ($spec['cost'] ?? 2),
            'asked_at'   => time(),
            'owner_text' => $ownerText,
        ], now()->addMinutes(self::TTL_MIN));
    }

    public function pending(int $wsId): ?array { $v = Cache::get(self::key($wsId)); return is_array($v) ? $v : null; }

    public function forget(int $wsId): void { Cache::forget(self::key($wsId)); }

    /** Turn 1 — state exactly what will be generated and the cost, and ask for a yes. */
    public function describe(array $spec): string
    {
        $prompt = trim((string) ($spec['prompt'] ?? ''));
        $cost   = (int) ($spec['cost'] ?? 2);
        $kind   = ($spec['action'] ?? '') === 'generate_image_mini' ? 'quick '
                : (($spec['action'] ?? '') === 'generate_image_high' ? 'high-detail ' : '');
        $article = $kind === '' ? 'an ' : 'a ';
        $short  = mb_strlen($prompt) > 140 ? mb_substr($prompt, 0, 140) . '…' : $prompt;
        return "I'll create {$article}{$kind}image: \"{$short}\". That's {$cost} credit" . ($cost === 1 ? '' : 's')
            . ". Say **yes** and I'll generate it now, or **no** to skip.";
    }

    /** Turn 2 — the owner's yes creates AND runs the task deterministically (owner-confirmed). */
    public function execute(int $wsId, array $pending, ?int $userId, string $ownerText): array
    {
        $prompt = trim((string) ($pending['prompt'] ?? ''));
        $action = (string) ($pending['action'] ?? 'generate_image');
        $cost   = (int) ($pending['cost'] ?? self::costFor($action));
        if ($prompt === '') return ['success' => false, 'error' => 'no_prompt'];

        // The bare "yes" classifies as a statement; bind the turn as an authorisation so TaskService
        // does not refuse it (UNCOMMISSIONED_TURN) — the owner's yes to a concrete offer IS the commission.
        try {
            app(SpendContext::class)->setTurn([
                'specifies_action' => true, 'authorized' => true, 'classification' => 'authorisation',
                'reason' => 'the owner said yes to Sarah\'s image offer (IMAGE-1)',
            ], $wsId);
        } catch (\Throwable $e) { /* non-fatal */ }

        try {
            $task = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, [
                'engine' => 'creative', 'action' => $action, 'source' => 'agent', 'assigned_agents' => ['studio'],
                'auto_approve' => true, 'requires_approval' => false, 'user_confirmed' => true, 'priority' => 'normal',
                'payload' => [
                    'prompt'       => $prompt,
                    'title'        => 'Image: ' . mb_substr($prompt, 0, 80),
                    // Distinct marker (NOT 'sarah_router') so the Orchestrator posts THIS finished
                    // image back into the chat with a thumbnail — bulk/featured images stay suppressed.
                    'created_via'  => 'sarah_image_request',
                    'user_request' => $ownerText,
                ],
            ]);
            // Remember this prompt so a follow-up ("make it hyperrealistic", "a X version of it") can refine it.
            try { \Illuminate\Support\Facades\Cache::put(self::lastKey($wsId), $prompt, now()->addMinutes(120)); } catch (\Throwable $e) {}
            Log::info('[Sarah888] IMAGE-1 executed', ['ws' => $wsId, 'user' => $userId, 'task' => $task->id, 'action' => $action, 'cost' => $cost]);
            return ['success' => true, 'task_id' => $task->id, 'action' => $action, 'cost' => $cost];
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] IMAGE-1 could not create the image task', ['ws' => $wsId, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function report(array $res): string
    {
        if (! ($res['success'] ?? false)) {
            return "I couldn't start that image — " . ($res['error'] === 'no_prompt'
                ? "I didn't catch what to draw. Tell me what the image should show."
                : "something went wrong on my side and nothing was charged. Try again in a moment.");
        }
        $cost = (int) ($res['cost'] ?? 2);
        return "On it — Studio is creating your image now ({$cost} credit" . ($cost === 1 ? '' : 's')
            . "). It'll take a few seconds; you'll find it under Results as soon as it's ready.";
    }
}
