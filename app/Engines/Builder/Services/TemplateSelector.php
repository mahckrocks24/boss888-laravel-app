<?php

namespace App\Engines\Builder\Services;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TEMPLATE SELECTOR (2026-09-11) — pick the template from the whole business, not from a keyword.
 *
 * Until now Arthur chose a template by scanning the industry string for keywords, longest match
 * first, then a coarse regex, then "consulting". It could not tell a supper club from a consultancy,
 * a boutique hotel with a restaurant from a restaurant, or a children's coding club from a gym, and it
 * never looked at the description, the services, the audience or the style the customer asked for.
 *
 * This reads all of it. The model is handed the full business context and a catalogue of every
 * design that is live on disk, asked to classify the industry FIRST and choose the template SECOND,
 * and made to explain itself. Its answer is only accepted if the slug it names actually exists in the
 * catalogue. If the model is unavailable, the keyword resolver decides exactly as it did before — the
 * floor is yesterday's behaviour, never worse.
 *
 * Every decision is returned with method, confidence, reasoning and the runners-up, so it can be
 * logged, stored on the website and read back by Sarah when a customer asks "why this design?".
 */
final class TemplateSelector
{
    /** Canonical industries — the 33 the rest of the platform understands. */
    public const INDUSTRIES = [
        'aesthetic_clinic', 'architecture', 'automotive', 'barbershop', 'beauty_salon', 'cafe', 'catering',
        'childcare', 'construction', 'consulting', 'dental', 'ecommerce', 'event_venue', 'gym', 'home_services',
        'hotel', 'interior_design', 'it_services', 'marketing_agency', 'medical_clinic', 'news_channel',
        'online_courses', 'pet_services', 'real_estate_agency', 'resort', 'restaurant', 'retail_shop',
        'short_term_rental', 'training_center', 'travel_agency', 'tutoring',
        // 2026-09-24: the two verticals that were collapsing into 'consulting'.
        'legal', 'accounting',
    ];

    /**
     * Slugs that share the dental clinic's markup (EV-0976). They are never offered as candidates: a
     * restaurant choosing "restaurant" would get a dental page. ArthurService::CLONE_OVERRIDE keeps the
     * same list as its safety net.
     */
    private const CLONES = [
        'restaurant', 'catering', 'resort', 'short_term_rental', 'travel_agency',
        'tutoring', 'online_courses', 'retail_shop', 'ecommerce',
    ];

    private const CONFIDENCE_FLOOR = 0.45;

    public function __construct(
        private readonly TemplateService $templates,
        private readonly RuntimeClient $runtime,
    ) {}

    /**
     * @param array $business  What Arthur has collected: business_name, industry, description, services,
     *                         target_market, location, style, colors, tagline, template (explicit pick).
     * @param string|null $keywordSlug  The old resolver's answer, used as the fallback and the tie-breaker.
     * @return array{template:string, industry:string, method:string, confidence:float, reason:string,
     *               alternatives:array<int,string>, keyword_slug:?string}
     */
    public function select(array $business, ?string $keywordSlug = null, bool $includeInactive = false, ?int $workspaceId = null): array
    {
        $catalogue = $this->catalogue($includeInactive, $workspaceId);
        // RETIRE-CLASSIC-1: a keyword answer that names a withheld Classic design keeps its industry, on a new design
        if ($keywordSlug !== null && ! isset($catalogue[$keywordSlug])) { $__r = \App\Engines\Builder\Support\DesignCatalog::forNewSite($keywordSlug, $workspaceId); if (isset($catalogue[$__r])) $keywordSlug = $__r; }
        $keywordSlug = $keywordSlug !== null && isset($catalogue[$keywordSlug]) ? $keywordSlug : null;

        // 1. An explicit choice (the customer picked a design) is honoured as-is when it is live.
        $explicit = strtolower(trim((string) ($business['template'] ?? '')));
        if ($explicit !== '' && isset($catalogue[$explicit])) {
            return $this->decision($explicit, $catalogue[$explicit]['industry'], 'explicit', 1.0,
                'The customer chose this design.', [], $keywordSlug);
        }

        $summary = $this->businessSummary($business);
        if (trim($summary) === '') {
            return $this->fallback($keywordSlug, 'no business context to read');
        }

        // 2. Same business, same answer — do not pay for the model twice on a rebuild.
        $cacheKey = 'arthur:tplsel:' . sha1($summary . '|' . implode(',', array_keys($catalogue)) . '|' . ($includeInactive ? 'all' : 'live'));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($catalogue[$cached['template'] ?? ''])) {
            return $cached + ['method' => 'llm-cached'];
        }

        // 3. Ask the model, with the catalogue in front of it.
        $system = $this->systemPrompt($catalogue);
        $prompt = "BUSINESS\n{$summary}\n\n"
            . "Classify the industry, then choose the single best template slug from the catalogue. "
            . "Respond with JSON only.";

        $res = $this->runtime->chatJson($system, $prompt, ['agent' => 'arthur', 'task' => 'template_select'], 500);
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : null;
        if (! ($res['success'] ?? false) || $parsed === null) {
            Log::warning('[Arthur] template selector: model unavailable, using keyword resolver', [
                'error' => $res['error'] ?? null, 'keyword_slug' => $keywordSlug,
            ]);
            return $this->fallback($keywordSlug, 'model unavailable');
        }

        $slug = strtolower(trim((string) ($parsed['template'] ?? '')));
        $industry = strtolower(trim((string) ($parsed['industry'] ?? '')));
        $conf = (float) ($parsed['confidence'] ?? 0);
        $reason = trim((string) ($parsed['reason'] ?? ''));
        $alts = array_values(array_filter(array_map(
            fn($a) => strtolower(trim((string) $a)), (array) ($parsed['alternatives'] ?? [])
        ), fn($a) => $a !== '' && isset($catalogue[$a])));

        // 4. Guardrails. A slug that is not in the catalogue is not a choice, it is a hallucination.
        if ($slug === '' || ! isset($catalogue[$slug])) {
            Log::warning('[Arthur] template selector: model named a slug not in the catalogue', [
                'named' => $slug, 'industry' => $industry, 'keyword_slug' => $keywordSlug,
            ]);
            // If it at least classified the industry sensibly, take the catalogue's design for it.
            $byIndustry = $this->defaultForIndustry($industry, $catalogue);
            if ($byIndustry !== null) {
                return $this->decision($byIndustry, $industry, 'llm-industry', min($conf, 0.6),
                    $reason ?: 'Industry classified by the model; design taken from the catalogue.', $alts, $keywordSlug);
            }
            return $this->fallback($keywordSlug, 'model named an unknown template');
        }
        if (! in_array($industry, self::INDUSTRIES, true)) {
            $industry = $catalogue[$slug]['industry'];
        }

        // 5. A weak answer that contradicts a confident keyword hit defers to the keyword.
        if ($conf < self::CONFIDENCE_FLOOR && $keywordSlug !== null && $keywordSlug !== $slug) {
            Log::info('[Arthur] template selector: low confidence, keyword resolver wins', [
                'model' => $slug, 'confidence' => $conf, 'keyword_slug' => $keywordSlug,
            ]);
            return $this->fallback($keywordSlug, "model confidence {$conf} below floor; model preferred {$slug}");
        }

        $out = $this->decision($slug, $industry, 'llm', $conf, $reason, $alts, $keywordSlug);
        Cache::put($cacheKey, $out, now()->addHours(6));
        return $out;
    }

    // ── catalogue ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Every live design, keyed by slug, with enough description for a model to choose between them.
     * Clone slugs are excluded (see CLONES). Cached briefly: reading 90 manifests per build is waste.
     *
     * @return array<string, array{slug:string,industry:string,name:string,brief:string,variant:bool}>
     */
    public function catalogue(bool $includeInactive = false, ?int $workspaceId = null): array
    {
        // includeInactive is for evaluation only: it never reaches a customer build.
        // DESIGN-PICKER-80: the key follows activation (designs-activated.stamp) and the QA override (v3picker.on).
        $qa = \App\Engines\Builder\Support\DesignCatalog::previewFor($workspaceId);
        return Cache::remember('arthur:tplsel:catalogue:' . ($includeInactive ? 'all' : 'live') . ($qa ? ':qa' : '') . ':' . \App\Engines\Builder\Support\DesignCatalog::stamp(), now()->addMinutes(10), function () use ($includeInactive, $workspaceId) {
            $out = [];
            foreach ($this->templates->listTemplates($includeInactive, $workspaceId) as $t) {
                $slug = (string) ($t['id'] ?? '');
                if ($slug === '' || in_array($slug, self::CLONES, true)) { continue; }
                $manifest = $this->templates->getManifest($slug) ?: [];
                $industry = strtolower((string) ($manifest['industry'] ?? $slug));
                if (! in_array($industry, self::INDUSTRIES, true)) { $industry = $slug; }
                $out[$slug] = [
                    'slug'     => $slug,
                    'industry' => $industry,
                    'name'     => (string) ($manifest['name'] ?? $slug),
                    'brief'    => $this->brief($slug, $manifest),
                    'variant'  => isset($manifest['design']['generator']),
                    'kind'     => (string) ($t['kind'] ?? 'classic'),
                    'style'    => $t['style'] ?? null,
                    'layout'   => $t['layout'] ?? null,
                ];
            }
            ksort($out);
            return $out;
        });
    }

    /** One line a model can weigh: what the design is, and what it is for. */
    private function brief(string $slug, array $manifest): string
    {
        $parts = [];
        $desc = trim((string) ($manifest['description'] ?? ''));
        if ($desc !== '') { $parts[] = $desc; }
        $d = $manifest['design'] ?? null;
        if (\App\Engines\Builder\Support\DesignCatalog::isV3($slug, $manifest)) {
            $st = \App\Engines\Builder\Support\DesignCatalog::STYLES[$manifest['style']] ?? null; $ly = \App\Engines\Builder\Support\DesignCatalog::LAYOUTS[$manifest['layout']] ?? null;
            $parts[] = 'Style ' . ($st[0] ?? $manifest['style']) . ' (' . ($st[1] ?? '') . '); layout ' . ($ly[0] ?? $manifest['layout']) . ' (' . ($ly[1] ?? '') . ').';
            return implode(' ', $parts);
        }
        if (is_array($d)) {
            $a = $d['archetypes'] ?? [];
            $parts[] = sprintf('Design: %s palette, %s type, %s hero, %s services layout, %s gallery.',
                $d['palette'] ?? '?', $d['type'] ?? '?', $a['hero'] ?? '?', $a['offers'] ?? '?', $a['gallery'] ?? '?');
        } else {
            $cat = trim((string) ($manifest['category'] ?? ''));
            $parts[] = 'Original ' . ($cat !== '' ? $cat . ' ' : '') . 'template.';
        }
        return implode(' ', $parts);
    }

    private function systemPrompt(array $catalogue): string
    {
        // DESIGN-PICKER-80: an industry can carry 80 new-system designs ({industry}_{style}_{layout}); listing each would
        // drown the prompt, so they are named once per industry as a grid of styles x layouts, with one glossary.
        $lines = []; $grid = [];
        foreach ($catalogue as $c) {
            if (($c['kind'] ?? '') === 'v3' && $c['style'] && $c['layout']) { $grid[$c['industry']]['s'][$c['style']] = 1; $grid[$c['industry']]['l'][$c['layout']] = 1; continue; }
            $lines[] = "- {$c['slug']} [industry: {$c['industry']}] — {$c['brief']}";
        }
        foreach ($grid as $ind => $g) {
            $lines[] = "- {$ind}_<style>_<layout> [industry: {$ind}] — the NEW design system for this industry: styles " . implode(', ', array_keys($g['s'])) . '; layouts ' . implode(', ', array_keys($g['l'])) . '. Build the slug from one style and one layout.';
        }
        if ($grid !== []) {
            $lines[] = '';
            $lines[] = 'NEW-SYSTEM STYLES (the look; match it to the brand\'s mood, colours and audience):';
            foreach (\App\Engines\Builder\Support\DesignCatalog::STYLES as $k => $s) { $lines[] = "  {$k}: {$s[1]}"; }
            $lines[] = 'NEW-SYSTEM LAYOUTS (the structure; match it to how the business wins customers):';
            foreach (\App\Engines\Builder\Support\DesignCatalog::LAYOUTS as $k => $l) { $lines[] = "  {$k}: {$l[1]}"; }
            $lines[] = 'When the classified industry has new-system designs, choose one of them (prefer it over an older design of the same industry).';
        }
        $industries = implode(', ', self::INDUSTRIES);
        return "You choose the website template for a small business. You are careful and you explain yourself.\n\n"
            . "STEP 1 — classify the business into exactly ONE industry from this list:\n{$industries}\n"
            . "Read the whole description: what they sell, to whom, how (bookings, menu, listings, courses, products, "
            . "advice), and the setting. The industry string the customer typed is a hint, not the answer — "
            . "\"consulting\" is the wrong answer for anything that has a menu, a chair, a room, a class or a product.\n\n"
            . "STEP 2 — choose the best template from the catalogue below. Prefer a template whose industry matches "
            . "your classification. If several match, choose by the customer's stated style, audience and tone. "
            . "If none matches, choose the template whose STRUCTURE fits best (a booking-led service, a menu-led "
            . "venue, a listing-led catalogue, a portfolio, an advisory firm).\n\n"
            . "CATALOGUE\n" . implode("\n", $lines) . "\n\n"
            . "Respond with JSON only, in this exact shape:\n"
            . "{\"industry\": \"<one of the list>\", \"template\": \"<a slug from the catalogue>\", "
            . "\"confidence\": <0..1>, \"reason\": \"<one or two sentences>\", \"alternatives\": [\"<slug>\", \"<slug>\"]}";
    }

    private function businessSummary(array $b): string
    {
        $line = function (string $label, $v): ?string {
            if (is_array($v)) { $v = implode(', ', array_filter(array_map('strval', $v))); }
            $v = trim((string) $v);
            return $v === '' ? null : "{$label}: {$v}";
        };
        $colors = $b['colors'] ?? null;
        if (is_array($colors)) { $colors = implode(', ', array_filter(array_map('strval', $colors))); }
        return implode("\n", array_filter([
            $line('Name', $b['business_name'] ?? null),
            $line('Industry (as typed)', $b['industry'] ?? null),
            $line('Tagline', $b['tagline'] ?? null),
            $line('Description', $b['description'] ?? null),
            $line('Services', $b['services'] ?? null),
            $line('Audience', $b['target_market'] ?? null),
            $line('Location', $b['location'] ?? null),
            $line('Style asked for', $b['style'] ?? ($b['design_style'] ?? null)),
            $line('Colours', $colors),
        ]));
    }

    private function defaultForIndustry(string $industry, array $catalogue): ?string
    {
        if ($industry === '') { return null; }
        // Prefer the canonical directory when it is live and not a clone; otherwise the first variant.
        if (isset($catalogue[$industry])) { return $industry; }
        foreach ($catalogue as $slug => $c) {
            if ($c['industry'] === $industry) { return $slug; }
        }
        return null;
    }

    private function fallback(?string $keywordSlug, string $why): array
    {
        $slug = $keywordSlug ?? 'consulting';
        $catalogue = $this->catalogue();
        $industry = $catalogue[$slug]['industry'] ?? $slug;
        return $this->decision($slug, $industry, 'keyword', 0.0, "Keyword resolver used: {$why}.", [], $keywordSlug);
    }

    private function decision(string $slug, string $industry, string $method, float $conf, string $reason, array $alts, ?string $kw): array
    {
        return [
            'template'     => $slug,
            'industry'     => $industry,
            'method'       => $method,
            'confidence'   => round(max(0.0, min(1.0, $conf)), 2),
            'reason'       => $reason,
            'alternatives' => array_values(array_slice($alts, 0, 3)),
            'keyword_slug' => $kw,
        ];
    }
}
