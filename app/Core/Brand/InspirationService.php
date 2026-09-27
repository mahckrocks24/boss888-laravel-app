<?php

namespace App\Core\Brand;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VISION-INSPIRE-1 (Owner 2026-09-27): "when a user sends sarah an image inspiration, she should be able to generate
 * prompt based on the design concept and learn and remembers it for future".
 *
 * study()    — Sarah reads an inspiration image like a senior designer (layout, composition, palette and how it is used,
 *              lighting, grade, typography, graphic elements, effects, why it works), writes a reusable scene prompt
 *              from it, saves it to the business's inspiration library and tells the owner in her own words.
 * relevant() — later image requests borrow the concept: the one the owner just asked for ("Make one like this"), the
 *              pinned ones ("Always use this look"), and saved ones that match the request. The reference's content,
 *              text, logos and colours are never copied: the concept is adapted to the brand.
 */
final class InspirationService
{
    private const INSPIRE = '/\b(inspir\w*|like this|love this|loved this|we like|i like|style|look|vibe|mood|aesthetic|reference|similar|something like|design like|same feel|in this style)\b/i';
    private const ASSET = '/\b(post|use|upload|add|put|share|publish)\s+(this|these|the|my|our)\s+(photo|image|picture|pic)s?\b|\bas (the|our) (photo|image|picture)\b/i';

    public function __construct(private BrandProfileService $profiles) {}

    /** @return array{saved:int, rest:array} rest = attachments that are not inspiration (logos, guideline pages, assets) */
    public function study(int $wsId, string $text, array $atts, ?int $messageId = null): array
    {
        $images = array_values(array_filter($atts, fn ($a) => ($a['kind'] ?? '') === 'image' && ! empty($a['url'])));
        if (! $images) return ['saved' => 0, 'rest' => $atts];
        $inspireWords = preg_match(self::INSPIRE, $text) === 1;
        if (preg_match(self::ASSET, $text) && ! $inspireWords) return ['saved' => 0, 'rest' => $atts];

        $runtime = app(\App\Connectors\RuntimeClient::class);
        $base = rtrim((string) config('app.url'), '/');
        $biz = $this->businessFor($wsId, $text);
        $rest = array_values(array_filter($atts, fn ($a) => ($a['kind'] ?? '') !== 'image'));
        $saved = [];
        foreach (array_slice($images, 0, 3) as $img) {
            $url = preg_match('#^https?://#', (string) $img['url']) ? (string) $img['url'] : $base . '/' . ltrim((string) $img['url'], '/');
            $a = $this->read($runtime, $url);
            $kind = strtolower((string) ($a['kind'] ?? 'other'));
            if (in_array($kind, ['logo', 'brand_guideline_page'], true)) { $rest[] = $img; continue; }            // brand material: the summary flow
            if ($kind === 'photo' && ! $inspireWords && trim($text) !== '') { $rest[] = $img; continue; }        // a photo to use, not a concept
            if (! $a || empty($a['prompt'])) { $rest[] = $img; continue; }
            if (! str_contains((string) $a['prompt'], '{subject}')) $a['prompt'] = '{subject} — ' . $a['prompt'];   // the slot the business's own subject fills
            $dirs = [];
            foreach ((array) ($a['direction_fit'] ?? []) as $d) { if (preg_match('/\bD(10|[1-9])\b/', (string) $d, $m)) $dirs[] = 'D' . $m[1]; }
            $dirs = DesignDirections::clean($dirs, 2);
            $title = mb_substr(trim((string) ($a['title'] ?? '')) ?: 'Saved inspiration', 0, 120);
            $id = DB::table('design_inspirations')->insertGetId([
                'workspace_id' => $wsId, 'business_id' => $biz->id ?? null, 'media_id' => $img['media_id'] ?? null, 'message_id' => $messageId,
                'image_url' => mb_substr($url, 0, 1024), 'title' => $title, 'analysis_json' => json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prompt' => mb_substr((string) $a['prompt'], 0, 4000), 'directions_json' => json_encode($dirs), 'owner_note' => mb_substr(trim($text), 0, 500) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved[] = ['id' => $id, 'a' => $a, 'title' => $title, 'url' => $url, 'dirs' => $dirs];
        }
        foreach ($saved as $s) $this->announce($wsId, $biz, $s);
        return ['saved' => count($saved), 'rest' => $rest];
    }

    /** Sarah's design reading of one image (JSON), or null. */
    private function read($runtime, string $url): ?array
    {
        $prompt = 'You are a senior art director. Study this image as a design reference and return ONLY JSON:'
            . '{"kind":"design_example|photo|logo|brand_guideline_page|screenshot|other",'
            . '"title":"a short name for this look, 3-5 words, e.g. Dark editorial fade",'
            . '"archetype":"closest of: statement over scene, editorial carousel, quote portrait, cause and impact, service deck, occasion greeting, launch mockup, recommendation, endorsed project, landscape announcement, product hero, offer banner, lifestyle photo, other",'
            . '"layout":"where the subject, the text zone and any logo sit",'
            . '"composition":"framing, camera angle, depth, negative space",'
            . '"subject":"what is shown",'
            . '"palette":[{"hex":"#RRGGBB","use":"background|text|accent|highlight"}],'
            . '"lighting":"","photo_grade":"","mood":"",'
            . '"typography":{"headline":"style, weight and family feel","body":"","case":"upper|sentence|mixed","accent":"how colour or weight emphasises words"},'
            . '"graphic_elements":["rules, shapes, icons, badges, frames"],'
            . '"effects":["gradient fades, glow, shadow, grain, blur, duotone"],'
            . '"what_makes_it_work":["three short reasons a designer would give"],'
            . '"direction_fit":["the 2 closest of: D1 editorial luxury, D2 clean minimal, D3 bold colour block, D4 warm organic, D5 authentic photo-first, D6 soft cinematic, D7 neon night, D8 layered collage, D9 playful retro, D10 technical blueprint"],'
            . '"prompt":"a reusable image-generation prompt for the BACKGROUND scene only, 50-90 words. It MUST contain the literal token {subject} wherever the main subject goes and must NOT name what this image shows (the business will put its own product, place or people there). Describe framing and camera, where {subject} sits, lighting, photo grade, mood, colour treatment (as roles: dark ground, one warm accent, etc., not these exact colours) and exactly where negative space is left for text. Example shape: \"{subject} standing in the right third, low angle, golden-hour side light, deep shadowed left half fading to near-black for a headline, rich contrast, calm premium mood\". No text, no letters, no logos."}';
        try {
            $v = $runtime->visionAnalyze($prompt, '', $url);
            if (! ($v['success'] ?? false)) return null;
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', (string) $v['analysis']);
            if (! preg_match('/\{.*\}/s', (string) $raw, $m)) return null;
            $j = json_decode($m[0], true);
            return is_array($j) ? $j : null;
        } catch (\Throwable $e) {
            Log::info('[VISION-INSPIRE-1] read failed', ['e' => $e->getMessage()]);
            return null;
        }
    }

    private function businessFor(int $wsId, string $text): ?object
    {
        $t = mb_strtolower($text);
        foreach ($this->profiles->businesses($wsId) as $b) { if ($t !== '' && str_contains($t, mb_strtolower($b->name))) return $b; }
        return $this->profiles->business($wsId, null);
    }

    private function announce(int $wsId, ?object $biz, array $s): void
    {
        $a = $s['a'];
        $name = $biz->name ?? 'your business';
        $facts = ['business' => $name, 'look' => $s['title'], 'layout' => $a['layout'] ?? null, 'mood' => $a['mood'] ?? null, 'why_it_works' => array_slice((array) ($a['what_makes_it_work'] ?? []), 0, 3),
            'closest_styles' => array_map(fn ($d) => DesignDirections::ALL[$d]['name'], $s['dirs'])];
        $fallback = 'I studied it: "' . $s['title'] . '". I saved the look to ' . $name . '\'s inspiration library, so future banners can borrow the concept in your own colours. Tap "Make one like this" to try it now.';
        $words = app(BrandIntakeService::class)->sarahWords($wsId, 'inspiration_saved',
            "Write Sarah's short chat message (2-3 sentences): she studied the owner's inspiration image like a designer; name the look and say in plain words what makes it work (from FACTS); say she saved the look to the business's inspiration library and will borrow the concept (not copy it) in their own brand colours for future banners, and that 'Make one like this' tries it now. Never mention prompts, codes or how you work internally. Warm, specific, no emojis.",
            $facts, $fallback);
        $palette = array_values(array_filter(array_map(fn ($p) => is_array($p) && preg_match('/^#[0-9a-f]{6}$/i', (string) ($p['hex'] ?? '')) ? strtoupper($p['hex']) : null, (array) ($a['palette'] ?? []))));
        $card = ['type' => 'inspiration', 'id' => (int) $s['id'], 'business_id' => $biz->id ?? null, 'business_name' => $name, 'title' => $s['title'], 'image_url' => $s['url'],
            'traits' => array_values(array_filter([$a['archetype'] ?? null, $a['mood'] ?? null, $a['photo_grade'] ?? null])),
            'why' => array_slice(array_values(array_filter(array_map('strval', (array) ($a['what_makes_it_work'] ?? [])))), 0, 3),
            'effects' => array_slice(array_values(array_filter(array_map('strval', (array) ($a['effects'] ?? [])))), 0, 4),
            'palette' => array_slice($palette, 0, 6),   // SECRET-1: the prompt Sarah wrote stays server-side, never in the card
            'directions' => array_map(fn ($d) => DesignDirections::ALL[$d]['name'], $s['dirs'])];
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($wsId, 'sarah', $words, ['card' => $card, 'notification_type' => 'inspiration_saved']);
    }

    /** Inspirations that should shape THIS request: the one just asked for, pinned ones, then the best keyword matches. */
    public function relevant(int $wsId, ?int $businessId, string $request, int $limit = 2): array
    {
        $q = DB::table('design_inspirations')->where('workspace_id', $wsId)->where('status', 'active');
        if ($businessId) $q->where(fn ($w) => $w->where('business_id', $businessId)->orWhereNull('business_id'));
        $rows = $q->orderByDesc('pinned')->orderByDesc('id')->limit(40)->get();
        if ($rows->isEmpty()) return [];
        $focus = (int) Cache::get('brand:insp:focus:' . $wsId, 0);
        $words = array_filter(preg_split('/\W+/u', mb_strtolower($request)), fn ($w) => mb_strlen($w) > 3);
        $scored = [];
        foreach ($rows as $r) {
            $s = $r->id === $focus ? 100 : ($r->pinned ? 50 : 0);
            $hay = mb_strtolower($r->title . ' ' . $r->owner_note . ' ' . $r->analysis_json);
            foreach ($words as $w) { if (str_contains($hay, $w)) $s += 3; }
            if ($s > 0) $scored[] = [$s, $r];
        }
        usort($scored, fn ($x, $y) => $y[0] <=> $x[0]);
        return array_map(fn ($x) => $x[1], array_slice($scored, 0, $limit));
    }

    /** Art direction text for the image reasoning, built from saved readings. */
    public static function block(array $rows): string
    {
        $parts = [];
        foreach ($rows as $r) {
            $a = json_decode((string) $r->analysis_json, true) ?: [];
            $ty = is_array($a['typography'] ?? null) ? implode('; ', array_filter(array_map('strval', $a['typography']))) : '';
            $parts[] = 'Owner\'s saved inspiration "' . $r->title . '": layout ' . ($a['layout'] ?? '-') . '; composition ' . ($a['composition'] ?? '-') . '; lighting ' . ($a['lighting'] ?? '-')
                . '; grade ' . ($a['photo_grade'] ?? '-') . '; mood ' . ($a['mood'] ?? '-') . ($ty !== '' ? '; typography ' . $ty : '')
                . (! empty($a['effects']) ? '; effects ' . implode(', ', array_map('strval', (array) $a['effects'])) : '') . '. Scene prompt it inspired: ' . $r->prompt;
        }
        return $parts ? implode("\n", $parts) . "\nAdapt the concept to this request's subject and the brand's colours; never copy the reference's content, text, people or logos." : '';
    }

    public function markUsed(array $rows): void
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        if ($ids) DB::table('design_inspirations')->whereIn('id', $ids)->update(['uses' => DB::raw('uses + 1'), 'last_used_at' => now()]);
    }
}
