<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH-EYES-1 (RISK-0209, 2026-09-30). "that is ugly" -> "I can't see the render myself, so anything I say about it is a
 * guess" - and she guessed the wrong image. When the owner's message is short and points at something ("that", "it", "the
 * banner", "ugly", "better"), the last image Sarah showed in this thread is put in front of the model: which one, when, and
 * what it actually looks like (a cached vision read of the file). She then talks about the right image, with eyes.
 */
final class LastImageContext
{
    private const WINDOW_HOURS = 12;

    /** The context block for the system prompt, or '' when the message is not about an image she showed. */
    public static function block(int $wsId, string $text): string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 240) return '';
        $points = (bool) preg_match('/\b(that|this|it|these|those|the (image|images|banner|picture|photo|design|render|graphic|post|visual|one)|ugly|nice|beautiful|better|worse|love|hate|looks?|redo|again|change|fix|text|font|colou?rs?|faces?|family|garden|background)\b/u', $t);
        if (! $points) return '';
        $last = self::lastShown($wsId);
        if (! $last) return '';
        $desc = self::describe($last['url']);
        $when = $last['at'] instanceof \DateTimeInterface ? $last['at']->format('H:i') : (string) $last['at'];
        $b = "THE IMAGE THE OWNER MEANS: the last image you showed in this chat (at {$when} UTC) is {$last['url']}"
           . ($last['title'] ? " - \"{$last['title']}\"" : '') . ".\n";
        $b .= $desc !== '' ? "What it actually looks like: {$desc}\n" : "You could not re-read the file this time; describe it from your own brief, not as a guess about a different image.\n";
        $b .= "When the owner says that / it / this one, they mean THIS image. Never say you cannot see it, and never answer about a different image.";
        return $b;
    }

    /** @return array{url:string,at:mixed,title:?string}|null */
    public static function lastShown(int $wsId, int $hours = self::WINDOW_HOURS): ?array
    {
        try {
            $rows = DB::table('agent_messages')->where('workspace_id', $wsId)->where('role', 'agent')
                ->where('created_at', '>=', now()->subHours($hours))->orderByDesc('id')->limit(60)->get(['id', 'content', 'metadata_json', 'created_at']);
            foreach ($rows as $r) {
                $m = json_decode((string) $r->metadata_json, true) ?: [];
                // 1) an image attachment on the message
                foreach ((array) ($m['attachments'] ?? []) as $a) {
                    if (is_array($a) && ($a['kind'] ?? '') === 'image' && ! empty($a['url'])) {
                        return ['url' => (string) $a['url'], 'at' => \Carbon\Carbon::parse($r->created_at), 'title' => null];
                    }
                }
                // 2) a post preview she announced (campaign_post_ready / draft ready) - the post's own media
                $nt = (string) ($m['notification_type'] ?? '');
                if ($nt === 'campaign_post_ready' || str_contains((string) $r->content, 'Draft ready')) {
                    $p = DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at')
                        ->where('created_at', '<=', \Carbon\Carbon::parse($r->created_at)->addMinutes(2))->orderByDesc('id')->first(['content', 'media_json']);
                    if ($p) {
                        $media = json_decode((string) $p->media_json, true) ?: [];
                        $u = is_array($media) && $media ? (is_array($media[0]) ? ($media[0]['url'] ?? null) : $media[0]) : null;
                        if ($u) return ['url' => (string) $u, 'at' => \Carbon\Carbon::parse($r->created_at), 'title' => mb_substr((string) $p->content, 0, 80)];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('[SARAH-EYES-1] lastShown failed', ['ws' => $wsId, 'e' => $e->getMessage()]);
        }
        return null;
    }

    /** A plain description of the image, cached per URL for a day. */
    public static function describe(string $url): string
    {
        $key = 'sarah:imgdesc:' . md5($url);
        $c = Cache::get($key);
        if (is_string($c)) return $c;
        $desc = '';
        try {
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if ($runtime->isConfigured()) {
                $r = $runtime->visionAnalyze(
                    'Describe this marketing image plainly in 3-4 sentences for the person who commissioned it: the subject, the setting, the style, '
                    . 'any text on it (quote it exactly and say where it sits), and anything that looks off (text over faces, clutter, blur, odd anatomy, hard-to-read type). No praise.',
                    '', $url);
                $txt = $r['analysis'] ?? $r['text'] ?? $r['result'] ?? $r['content'] ?? $r['description'] ?? '';
                if (is_array($txt)) $txt = json_encode($txt, JSON_UNESCAPED_UNICODE);
                $desc = trim((string) $txt);
                if ($desc === '' && ! empty($r['data'])) $desc = trim(is_string($r['data']) ? $r['data'] : json_encode($r['data'], JSON_UNESCAPED_UNICODE));
                $desc = mb_substr($desc, 0, 900);
            }
        } catch (\Throwable $e) {
            Log::info('[SARAH-EYES-1] describe failed', ['url' => $url, 'e' => $e->getMessage()]);
        }
        Cache::put($key, $desc, now()->addHours(24));
        return $desc;
    }
}
