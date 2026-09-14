<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MEDIA CATALOGUER (2026-09-12, EV-1006 — Owner: "add proper tags and descriptions on them with use cases …
 * and all future ai generated images from all users").
 *
 * Every image in the library — platform stock, a customer's AI-generated picture, an upload, a blog featured
 * image — carries the same four tag axes and a description of what is in it:
 *
 *   use:<slot>      which template/product slot it serves      hero, gallery, listing, room, blog, avatar, …
 *   subject:<what>  what is physically in the frame            interior, exterior, food, vehicle, people, …
 *   shape:<aspect>  wide | square | portrait                   a square image in a 16:7 hero is cropped to ruin
 *   flag:<defect>   garbled-text, has-faces, illustration, …   plus quality:review
 *
 * Every axis is PREFIXED, so the builder's existing lookups — tags LIKE '%"<tag>%' and LIKE '%"<tag>"%' — cannot
 * match them. Adding a tag here can never change which photographs a customer's site retrieves.
 *
 * The description is DERIVED from the generation prompt, the storage path and the pixel dimensions, and is marked
 * desc:derived. A human- or vision-checked description is marked desc:verified and is never overwritten by a
 * derived one. visionVerify() upgrades a derived description using the Runtime's vision model when it is
 * available (as at 2026-09-12 the OpenAI account reports credit_balance_exhausted — see RISK-0166).
 */
class MediaCataloguer
{
    /** Style noise to strip off the front of a generation prompt. */
    private const LEAD_NOISE = '/^(a |an |the )?(photo-?realistic|photorealistic|professional|high[- ]quality|ultra[- ]realistic|cinematic|hyper[- ]realistic|premium|modern|stunning|beautiful)\s+/i';

    /** Constraint clauses the model was told to obey — captured as facts, not repeated as description. */
    private const CONSTRAINTS = '/,?\s*(and\s+)?no\s+(text|logos?|people|faces?|animals?|readable\s+text|words|signage|brand(ing)?|humans?)\b\.?/i';

    /** prompt/path keyword → subject: tag. First match per subject wins; an image can carry several. */
    /** A trailing * means prefix ("architectur*" matches architectural); everything else matches a whole word. */
    private const SUBJECT = [
        'interior'     => ['interior*', 'indoor*', 'inside', 'room', 'rooms', 'lobby', 'reception', 'showroom', 'salon', 'clinic', 'office', 'lounge', 'hall', 'counter', 'kitchen', 'bedroom', 'bathroom', 'waiting'],
        'exterior'     => ['exterior*', 'facade', 'outdoor*', 'outside', 'street', 'courtyard', 'garden', 'rooftop', 'terrace', 'playground', 'forecourt'],
        'architecture' => ['building*', 'tower*', 'facade', 'architectur*', 'bridge', 'construction', 'skyscraper*', 'campus'],
        'cityscape'    => ['skyline*', 'downtown', 'cityscape', 'urban', 'marina', 'district'],
        'landscape'    => ['mountain*', 'savanna*', 'desert', 'forest', 'countryside', 'valley', 'meadow', 'hillside', 'island*'],
        'water'        => ['ocean', 'sea', 'beach', 'pool', 'marina', 'waterfront', 'lagoon', 'coast*'],
        'food'         => ['dish', 'dishes', 'food', 'meal', 'plated', 'cuisine', 'pastr*', 'croissant*', 'canape*', 'canapés', 'dessert*', 'buffet', 'menu', 'toast', 'bakery', 'appetis*', 'appetiz*', 'cake*'],
        'drink'        => ['coffee', 'espresso', 'latte', 'cocktail*', 'wine', 'barista', 'bar'],
        'vehicle'      => ['car', 'cars', 'vehicle*', 'sedan', 'coupe', 'suv', 'convertible', 'truck', 'van', 'motorcycle'],
        'people'       => ['people', 'person', 'portrait*', 'avatar', 'staff', 'chef', 'barista', 'hands', 'student*', 'headshot'],
        'product'      => ['product*', 'flatlay', 'packshot', 'packaging', 'bottle*', 'jar', 'watch', 'merchandise'],
        'equipment'    => ['equipment', 'machine*', 'monitor*', 'server*', 'camera*', 'laptop', 'tools', 'instrument*', 'rack*'],
        'animals'      => ['dog', 'dogs', 'cat', 'cats', 'pet', 'pets', 'puppy', 'kitten', 'animal*'],
        'document'     => ['certificate*', 'diploma', 'notebook*', 'whiteboard', 'newspaper', 'book', 'books', 'paperwork'],
        'aerial'       => ['aerial', 'drone', 'overhead'],
    ];

    /** filename pattern → use: tag. Ordered; first hit wins. */
    private const USE_BY_FILE = [
        'listing'   => '/^listing[_-]?\d*/i',
        'area'      => '/^area[_-]?\d*/i',
        'room'      => '/^room[_-]?\d*/i',
        'vehicle'   => '/^vehicle[_-]?\d*/i',
        'project'   => '/^project[_-]?\d*/i',
        'portfolio' => '/^portfolio[_-]?\d*/i',
        'facility'  => '/^facility[_-]?\d*/i',
        'blog'      => '/^blog[_-]?\d*/i',
        'gallery'   => '/^gallery/i',
        'about'     => '/^about/i',
        'story'     => '/^story/i',
        'hero'      => '/^hero/i',
    ];

    /** Everything this class writes, so a re-run replaces rather than accumulates. */
    public const MANAGED_PREFIXES = ['use:', 'subject:', 'shape:', 'flag:', 'quality:', 'desc:', 'origin:'];

    // ─────────────────────────────────────────────────────────── description

    /** A readable sentence describing the image, from its prompt; null when there is nothing to work from. */
    public static function describeFromPrompt(?string $prompt): ?string
    {
        $p = trim((string) $prompt);
        if (mb_strlen($p) < 20) return null;

        $noPeople = (bool) preg_match('/no\s+(people|faces?|humans?)/i', $p);
        $noText   = (bool) preg_match('/no\s+(text|logos?|words|signage|readable)/i', $p);

        $p = preg_replace(self::CONSTRAINTS, '', $p) ?? $p;
        $p = preg_replace(self::LEAD_NOISE, '', trim($p)) ?? $p;
        $p = trim(preg_replace('/\s+/', ' ', $p), " \t\n\r\0\x0B,.;:-—");
        if ($p === '') return null;

        // a prompt written as an instruction ("Generate a hero image for a page about: X") keeps only its subject
        if (preg_match('/(?:image|photo|picture)\s+for[^:]*:\s*(.+)$/is', $p, $m)) $p = trim($m[1]);

        $s = mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
        if ($noPeople) $s .= ', no people';
        if ($noText && !$noPeople) $s .= ', no text';
        return rtrim($s, '.,; ') . '.';
    }

    // ──────────────────────────────────────────────────────────────── tags

    /** @return string[] the managed tags for one media row (object or array). */
    public static function tagsFor($row): array
    {
        $r = (object) (array) $row;
        $url = (string) ($r->url ?? '');
        $path = (string) ($r->path ?? '');
        $file = basename(parse_url($url, PHP_URL_PATH) ?: $path ?: '');
        // EV-1006: strip the model's constraint clauses FIRST — matching "no people" as subject:people is
        // the same defect EV-1004 removed 34 of. Then match on word boundaries so "pet" is not found in "carpet".
        $clean = preg_replace(self::CONSTRAINTS, ' ', (string) ($r->prompt ?? '')) ?? '';
        $hay = mb_strtolower(' ' . $clean . ' ' . $path . ' ' . $url . ' ');
        $tags = [];

        // use: — storage location first (it is authoritative), then the filename, then the category
        $use = null;
        foreach ([
            'avatar' => '/builder-avatars/', 'hero' => '/builder-heroes/', 'blog' => '/blog/',
        ] as $u => $needle) if (str_contains($url . $path, $needle)) { $use = $u; break; }
        if ($use === null) foreach (self::USE_BY_FILE as $u => $re) if (preg_match($re, $file)) { $use = $u; break; }
        if ($use === null) {
            $cat = mb_strtolower((string) ($r->category ?? ''));
            $use = match (true) {
                $cat === 'hero' => 'hero',
                $cat === 'avatar', $cat === 'portrait' => 'avatar',
                $cat === 'blog' => 'blog',
                $cat === 'gallery', $cat === 'template_image' => 'gallery',
                (string) ($r->source ?? '') === 'seo_featured_image' => 'blog',
                default => 'content',
            };
        }
        $tags[] = 'use:' . $use;

        // subject: — what is in the frame
        foreach (self::SUBJECT as $subject => $needles) {
            foreach ($needles as $needle) {
                $prefix = str_ends_with($needle, '*');
                $re = '/\b' . preg_quote(rtrim($needle, '*'), '/') . ($prefix ? '' : '\b') . '/u';
                if (preg_match($re, $hay)) { $tags[] = 'subject:' . $subject; break; }
            }
        }

        // shape: — decides whether it can take a wide slot without being cropped to ruin
        $w = (int) ($r->width ?? 0); $h = (int) ($r->height ?? 0);
        if ($w > 0 && $h > 0) {
            $ratio = $w / $h;
            $tags[] = 'shape:' . ($ratio > 1.2 ? 'wide' : ($ratio < 0.85 ? 'portrait' : 'square'));
        }

        // origin: — house stock, a customer's own image, or a platform asset
        $tags[] = 'origin:' . ((int) ($r->is_platform_asset ?? 0) === 1 ? 'platform' : (($r->workspace_id ?? null) ? 'workspace' : 'unattributed'));

        return array_values(array_unique($tags));
    }

    // ───────────────────────────────────────────────────────────── applying

    /**
     * Catalogue one row. Returns ['tags'=>[], 'description'=>string|null, 'verified'=>bool].
     * An existing desc:verified description is never replaced by a derived one.
     */
    public static function catalogue(int $mediaId, bool $force = false): array
    {
        $row = DB::table('media')->where('id', $mediaId)->first();
        if (!$row) return ['tags' => [], 'description' => null, 'verified' => false];

        $old = json_decode((string) $row->tags, true) ?: [];
        $meta = json_decode((string) $row->metadata_json, true);
        if (!is_array($meta)) $meta = [];

        $wasVerified = in_array('desc:verified', $old, true);
        $keep = array_values(array_filter($old, function ($t) {
            foreach (self::MANAGED_PREFIXES as $p) if (str_starts_with((string) $t, $p)) return false;
            return true;
        }));
        // flags are findings about the image, not derived state — carry them through
        $carry = array_values(array_filter($old, fn ($t) => str_starts_with((string) $t, 'flag:') || $t === 'quality:review'));

        $desc = $meta['description'] ?? null;
        $verified = $wasVerified && is_string($desc) && $desc !== '';
        if (!$verified || $force) {
            $derived = self::describeFromPrompt($row->prompt);
            if ($derived !== null && (!is_string($desc) || $desc === '' || !$verified)) { $desc = $derived; $verified = false; }
        }

        $tags = array_merge($keep, self::tagsFor($row), $carry);
        if (is_string($desc) && $desc !== '') $tags[] = $verified ? 'desc:verified' : 'desc:derived';
        $tags = array_values(array_unique($tags));

        if (is_string($desc) && $desc !== '') $meta['description'] = $desc;
        $meta['catalogued_at'] = now()->toDateString();

        DB::table('media')->where('id', $mediaId)->update([
            'tags' => json_encode($tags, JSON_UNESCAPED_SLASHES),
            'metadata_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
        return ['tags' => $tags, 'description' => $desc, 'verified' => $verified];
    }

    /** Called from MediaService on every new row — never allowed to break the caller. */
    public static function catalogueQuietly(int $mediaId): void
    {
        try { self::catalogue($mediaId); }
        catch (\Throwable $e) { Log::warning('[MediaCataloguer] failed for media ' . $mediaId . ': ' . $e->getMessage()); }
    }

    /**
     * Upgrade a derived description to a verified one using the Runtime's vision model, and add flags for the
     * things only an eye can see: words rendered into the picture, visible faces, non-photographic style.
     * Returns false (and changes nothing) when vision is unavailable.
     */
    public static function visionVerify(int $mediaId): bool
    {
        $row = DB::table('media')->where('id', $mediaId)->first();
        if (!$row || !$row->url) return false;
        $url = str_starts_with($row->url, 'http') ? $row->url : rtrim((string) config('app.url'), '/') . $row->url;

        $r = app(\App\Connectors\RuntimeClient::class)->visionAnalyze(
            'Reply as JSON only: {"description":"a media-library caption of one or two factual sentences: who or what is in the frame (for people: apparent age range, gender presentation, hair, attire — never a name), the setting, the light and the mood; no speculation",'
            . '"subjects":["interior|exterior|food|drink|vehicle|people|product|equipment|landscape|cityscape|architecture|water|animals|document|aerial"],'
            . '"has_readable_text":true|false,"has_visible_faces":true|false,"is_photographic":true|false}',
            '', $url
        );
        if (!($r['success'] ?? false)) return false;

        $a = $r['analysis'] ?? '';
        $j = json_decode(preg_replace('/^```(?:json)?|```$/m', '', trim($a)) ?: '', true);
        if (!is_array($j) || empty($j['description'])) return false;

        $meta = json_decode((string) $row->metadata_json, true); if (!is_array($meta)) $meta = [];
        $meta['description'] = trim((string) $j['description']);
        $meta['vision_model'] = $r['model'] ?? null;
        $meta['catalogued_at'] = now()->toDateString();

        $tags = json_decode((string) $row->tags, true) ?: [];
        $tags = array_values(array_filter($tags, fn ($t) => !str_starts_with((string) $t, 'desc:') && !str_starts_with((string) $t, 'subject:')));
        foreach ((array) ($j['subjects'] ?? []) as $s) { $s = preg_replace('/[^a-z]/', '', mb_strtolower((string) $s)); if ($s) $tags[] = 'subject:' . $s; }
        $flags = [];
        if (!empty($j['has_readable_text']))  $flags[] = 'flag:has-text';
        if (!empty($j['has_visible_faces']))  $flags[] = 'flag:has-faces';
        if (isset($j['is_photographic']) && !$j['is_photographic']) $flags[] = 'flag:illustration';
        $tags = array_merge($tags, $flags);
        if ($flags) $tags[] = 'quality:review';
        $tags[] = 'desc:verified';

        DB::table('media')->where('id', $mediaId)->update([
            'tags' => json_encode(array_values(array_unique($tags)), JSON_UNESCAPED_SLASHES),
            'metadata_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
        return true;
    }
}
