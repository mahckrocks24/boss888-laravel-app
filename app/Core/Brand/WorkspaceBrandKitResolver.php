<?php

namespace App\Core\Brand;

use Illuminate\Support\Facades\DB;

/**
 * WorkspaceBrandKitResolver — single source of truth for workspace-grounded
 * brand context used by all AI generation surfaces (Email, Studio, Social,
 * Builder, Creative).
 *
 * White-labeling contract:
 *   - NEVER returns platform default colors (no #5B5BD6 LevelUp accent,
 *     no #6C5CE7 fallback). If a workspace has no brand data, returns
 *     neutral grays (#1F2937 text, #F4F4F5 bg) with `is_neutral=true` flag
 *     so callers know to recommend brand setup.
 *   - brand_name fallback is the workspace.name (the merchant's own
 *     business name), NEVER "your brand" or "Acme Co.".
 *   - voice/tone defaults to "professional/friendly" — neutral, industry-
 *     agnostic descriptors that any merchant can override.
 *
 * Data source priority (highest → lowest):
 *   1. studio_brand_kits (richest: palette + fonts + logo)
 *   2. creative_brand_identities (voice + tone + audience)
 *   3. workspaces table (industry + brand_color fallback for older accounts)
 *
 * Caller workflow:
 *   $kit = app(WorkspaceBrandKitResolver::class)->resolve($workspaceId);
 *   // Override individual fields with caller-supplied values:
 *   $kit = $resolver->resolveWithOverrides($workspaceId, $params);
 */
class WorkspaceBrandKitResolver
{
    /**
     * Resolve a normalized brand kit for the given workspace.
     *
     * @return array Always returns the full shape (never null fields except
     *               logo_url and tagline which are optional).
     */
    public function resolve(int $workspaceId, ?int $businessId = null): array
    {
        // BRAND-B0 (RFC-0017, 2026-09-27): per-business brand. A workspace holds several businesses
        // (RFC-0011); each business resolves its OWN brand. Rows keyed to the business win. Workspace-level
        // rows (business_id NULL) belong to the default business only, so one business's colours never
        // bleed into another. LevelUpGrowth's own palette is never treated as a customer's brand.
        $biz = $this->businessFor($workspaceId, $businessId);
        $isDefaultBiz = ! $biz || (int) ($biz->is_default ?? 0) === 1;
        $pick = function (string $table) use ($workspaceId, $biz, $isDefaultBiz) {
            $base = function () use ($table, $workspaceId) {
                $q = DB::table($table)->where('workspace_id', $workspaceId);
                if ($table === 'creative_brand_identities') { $q->whereNull('deleted_at'); }
                return $q;
            };
            $row = $biz ? $base()->where('business_id', (int) $biz->id)->first() : null;
            if (! $row && $isDefaultBiz) { $row = $base()->whereNull('business_id')->first(); }
            return $row;
        };
        $studio   = $pick('studio_brand_kits');
        $creative = $pick('creative_brand_identities');
        // A kit that is LevelUpGrowth's untouched default (primary AND secondary are platform colours) is no brand at all.
        if ($workspaceId !== 1 && $studio && self::isPlatformColor($studio->primary_color ?? null) && self::isPlatformColor($studio->secondary_color ?? null)) { $studio = null; }
        // RFC-0011 U2: the workspace row as the given business sees it (identical while the switch is off).
        $workspace = app(\App\Core\Business\BusinessProfileResolver::class)->workspaceRowFor($workspaceId, $businessId) ?? DB::table('workspaces')->where('id', $workspaceId)->first();
        $site = $this->siteTheme($workspaceId, $biz, $isDefaultBiz);

        $c = fn ($v) => $this->realColor($v, $workspaceId);
        $f = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $font = fn ($v) => ($x = $f($v)) !== null && ($workspaceId === 1 || strcasecmp($x, 'Syne') !== 0) ? $x : null; // Syne = LevelUpGrowth's house display font
        $creativeFonts = [];
        if (! empty($creative->fonts_json)) {
            $d = is_string($creative->fonts_json) ? json_decode($creative->fonts_json, true) : (array) $creative->fonts_json;
            if (is_array($d)) { $creativeFonts = $d; }
        }

        $studioPrimary   = $c($studio->primary_color ?? null);
        $creativePrimary = $c($creative->primary_color ?? null);
        $bizColor        = $c($biz->brand_color ?? null);
        $wsColor         = $isDefaultBiz ? $c($workspace->brand_color ?? null) : null;
        $sitePrimary     = $c($site['primary'] ?? null);

        $hasStudioKit    = $studio !== null && ($studioPrimary || $c($studio->secondary_color ?? null));
        $hasCreativeKit  = $creative !== null && ($creativePrimary || $c($creative->secondary_color ?? null) || $f($creative->visual_style ?? null));
        $hasWsBrandColor = (bool) ($bizColor ?: $wsColor);
        $hasSite         = (bool) $sitePrimary;
        $isNeutral = ! $studioPrimary && ! $creativePrimary && ! $bizColor && ! $wsColor && ! $sitePrimary;

        // Brand name: the business's own name, never a generic placeholder.
        $brandName = $f($studio->brand_name ?? null) ?? $f($biz->name ?? null) ?? ($workspace->name ?? 'this business');

        // Colours: studio kit > brand identity > business colour > workspace colour > the business's website theme > neutral.
        $primaryColor    = $studioPrimary ?? $creativePrimary ?? $bizColor ?? $wsColor ?? $sitePrimary ?? '#1F2937';
        $secondaryColor  = $c($studio->secondary_color ?? null) ?? $c($creative->secondary_color ?? null)
            ?? (($primaryColor === $sitePrimary) ? $c($site['secondary'] ?? null) : null) ?? $this->derive_secondary($primaryColor);
        $accentColor     = $c($studio->accent_color ?? null) ?? $c($creative->accent_color ?? null)
            ?? (($primaryColor === $sitePrimary) ? $c($site['accent'] ?? null) : null) ?? $this->derive_accent($primaryColor);
        $backgroundColor = $c($studio->background_color ?? null) ?? $c($site['background'] ?? null) ?? '#FFFFFF';
        $textColor       = $c($studio->text_color ?? null) ?? $c($site['text'] ?? null) ?? '#0F172A';

        // Typography: studio kit > brand identity fonts > website fonts > neutral system stack.
        $headingFont = $font($studio->heading_font ?? null) ?? $font($creativeFonts['heading'] ?? null) ?? $font($site['heading_font'] ?? null) ?? 'Inter, system-ui, sans-serif';
        $bodyFont    = $f($studio->body_font ?? null) ?? $f($creativeFonts['body'] ?? null) ?? $f($site['body_font'] ?? null) ?? 'Inter, system-ui, sans-serif';

        // Voice/tone: the business's own tone is specific and wins; generic defaults last.
        $voice  = $f($creative->voice ?? null) ?? 'professional';
        $tone   = $f($biz->tone ?? null) ?? $f($creative->tone ?? null) ?? 'friendly';
        if ($f($biz->tone ?? null) && strtolower($voice) === 'professional') { $voice = $biz->tone; }
        $audience   = $f($creative->target_audience ?? null) ?? $f($biz->target_audience ?? null);
        $styleNotes = $f($creative->style_notes ?? null);

        $industry = $f($biz->industry ?? null) ?? ($workspace->industry ?? null) ?? ($creative->industry ?? null);

        $logoUrl     = $f($studio->logo_url ?? null) ?? $f($creative->logo_url ?? null) ?? $f($biz->logo_url ?? null)
            ?? ($isDefaultBiz ? $f($workspace->logo_url ?? null) : null) ?? $f($site['logo_url'] ?? null);
        $logoDarkUrl = $f($studio->logo_dark_url ?? null);
        $tagline     = $f($studio->tagline ?? null);

        $visualStyle = $f($creative->visual_style ?? null);
        $colorsJson  = [];
        if (! empty($creative->colors_json)) {
            $decoded = is_string($creative->colors_json) ? json_decode($creative->colors_json, true) : $creative->colors_json;
            if (is_array($decoded)) { $colorsJson = array_values(array_filter(array_map($c, $decoded))); }
        }

        return [
            'workspace_id'      => $workspaceId,
            'business_id'       => $biz ? (int) $biz->id : null,
            'brand_name'        => $brandName,
            'industry'          => $industry,
            'tagline'           => $tagline,
            'logo_url'          => $logoUrl,
            'logo_dark_url'     => $logoDarkUrl,
            'primary_color'     => $primaryColor,
            'secondary_color'   => $secondaryColor,
            'accent_color'      => $accentColor,
            'background_color'  => $backgroundColor,
            'text_color'        => $textColor,
            'heading_font'      => $headingFont,
            'body_font'         => $bodyFont,
            'voice'             => $voice,
            'tone'              => $tone,
            'target_audience'   => $audience,
            'style_notes'       => $styleNotes,
            'visual_style'      => $visualStyle,
            'colors_json'       => $colorsJson,
            'is_neutral'        => $isNeutral,
            'sources_present'   => array_filter([
                'studio_brand_kit'        => (bool) $hasStudioKit,
                'creative_brand_identity' => (bool) $hasCreativeKit,
                'workspace_brand_color'   => $hasWsBrandColor,
                'website_theme'           => $hasSite,
            ]),
        ];
    }

    /** LevelUpGrowth's own palette. Never a customer's brand (only the house workspace 1 may use it). */
    public const PLATFORM_COLORS = ['#6c5ce7', '#00e5a8', '#f4f7fb', '#5b5bd6'];

    public static function isPlatformColor(?string $hex): bool
    {
        return is_string($hex) && in_array(strtolower(trim($hex)), self::PLATFORM_COLORS, true);
    }

    private function realColor($v, int $workspaceId): ?string
    {
        if (! is_string($v)) { return null; }
        $v = trim($v);
        if (! preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v)) { return null; }
        if ($workspaceId !== 1 && self::isPlatformColor($v)) { return null; }
        return $v;
    }

    /** The business (given, else the workspace's default business), or null when the workspace has none. */
    private function businessFor(int $workspaceId, ?int $businessId): ?object
    {
        try {
            $q = DB::table('businesses')->where('workspace_id', $workspaceId)->whereNull('deleted_at');
            $b = $businessId ? (clone $q)->where('id', $businessId)->first() : null;
            return $b ?: (clone $q)->where('is_default', 1)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Colours, fonts and logo the business's own website already uses (template variables). */
    private function siteTheme(int $workspaceId, ?object $biz, bool $isDefaultBiz): array
    {
        try {
            $q = DB::table('websites')->where('workspace_id', $workspaceId)->whereNull('deleted_at');
            $site = $biz ? (clone $q)->where('business_id', (int) $biz->id)->orderByDesc('published_at')->first(['template_variables']) : null;
            if (! $site && $isDefaultBiz && (clone $q)->count() === 1) { $site = (clone $q)->first(['template_variables']); }
            if (! $site) { return []; }
            $tv = json_decode((string) $site->template_variables, true) ?: [];
            $hex = function ($v) { return is_string($v) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($v)) ? trim($v) : null; };
            $logo = $tv['logo_url'] ?? null;
            return array_filter([
                'primary'      => $hex($tv['primary_color'] ?? null) ?? $hex($tv['brand'] ?? null),
                'secondary'    => $hex($tv['secondary_color'] ?? null) ?? $hex($tv['brand-2'] ?? null),
                'accent'       => $hex($tv['accent_color'] ?? null),
                'background'   => $hex($tv['bg_color'] ?? null),
                'text'         => $hex($tv['text_color'] ?? null),
                'heading_font' => is_string($tv['font_display'] ?? null) ? trim($tv['font_display']) : null,
                'body_font'    => is_string($tv['font_body'] ?? null) ? trim($tv['font_body']) : null,
                'logo_url'     => is_string($logo) && preg_match('#^(https?://|/)#', $logo) ? $logo : null,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * Resolve, then overlay caller-supplied overrides. Used by AI endpoints
     * where an admin/agent may pass explicit values that should win.
     *
     * Override keys (in $overrides) that are non-empty win over resolved
     * values. Empty strings and nulls do NOT override.
     */
    public function resolveWithOverrides(int $workspaceId, array $overrides): array
    {
        $kit = $this->resolve($workspaceId, (int) ($overrides['business_id'] ?? 0) ?: null); // BRAND-B0: per business

        // Map overrides keys → kit keys, accepting common aliases
        $aliases = [
            'brand_color'      => 'primary_color',
            'primary_color'    => 'primary_color',
            'secondary_color'  => 'secondary_color',
            'accent_color'     => 'accent_color',
            'brand_name'       => 'brand_name',
            'voice'            => 'voice',
            'tone'             => 'tone',
            'industry'         => 'industry',
            'audience'         => 'target_audience',
            'target_audience'  => 'target_audience',
            'logo_url'         => 'logo_url',
            'tagline'          => 'tagline',
            'heading_font'     => 'heading_font',
            'body_font'        => 'body_font',
        ];

        foreach ($aliases as $inKey => $kitKey) {
            if (!array_key_exists($inKey, $overrides)) continue;
            $val = $overrides[$inKey];
            if ($val === '' || $val === null) continue; // empty does not override
            $kit[$kitKey] = $val;
        }

        // If overrides supplied a primary_color but no derived secondary/accent,
        // re-derive them so the overlay stays internally consistent.
        if (!empty($overrides['brand_color']) || !empty($overrides['primary_color'])) {
            if (empty($overrides['secondary_color']) && !isset($kit['_explicit_secondary'])) {
                $kit['secondary_color'] = $this->derive_secondary($kit['primary_color']);
            }
            if (empty($overrides['accent_color']) && !isset($kit['_explicit_accent'])) {
                $kit['accent_color'] = $this->derive_accent($kit['primary_color']);
            }
        }

        return $kit;
    }

    /**
     * Build a structured brand-context block suitable for injection into
     * an LLM system prompt. Returns a multi-line string the AI can read.
     */
    public function toPromptBlock(array $kit): string
    {
        $lines = [];
        $lines[] = "Brand: {$kit['brand_name']}";
        if (!empty($kit['industry']))         $lines[] = "Industry: {$kit['industry']}";
        if (!empty($kit['target_audience']))  $lines[] = "Audience: {$kit['target_audience']}";
        $lines[] = "Voice: {$kit['voice']}";
        $lines[] = "Tone: {$kit['tone']}";
        $lines[] = "Primary color: {$kit['primary_color']} (use for headlines, CTAs, hero accents)";
        $lines[] = "Secondary color: {$kit['secondary_color']} (use for supporting elements)";
        $lines[] = "Accent color: {$kit['accent_color']} (use sparingly for highlights)";
        $lines[] = "Background: {$kit['background_color']}";
        $lines[] = "Text color: {$kit['text_color']}";
        $lines[] = "Heading font: {$kit['heading_font']}";
        $lines[] = "Body font: {$kit['body_font']}";
        if (!empty($kit['tagline']))    $lines[] = "Tagline: {$kit['tagline']}";
        if (!empty($kit['style_notes'])) $lines[] = "Style notes: {$kit['style_notes']}";

        $intro = $kit['is_neutral']
            ? "NOTE: this workspace has not set up a brand kit. Use the neutral palette below and write copy that is genuinely brand-agnostic. Do NOT invent a fake personality."
            : "Ground every color, copy, and design choice in this brand context. Never substitute platform defaults.";

        return $intro . "\n\n" . implode("\n", $lines);
    }

    // ─── Color derivation (no platform defaults) ─────────────────────────

    private function derive_secondary(string $primary): string
    {
        // Default secondary: 30% lighter version of primary (or fallback gray)
        $rgb = $this->hexToRgb($primary);
        if (!$rgb) return '#94A3B8'; // neutral slate
        $mix = fn(int $c) => (int) round($c * 0.70 + 255 * 0.30);
        return sprintf('#%02X%02X%02X', $mix($rgb[0]), $mix($rgb[1]), $mix($rgb[2]));
    }

    private function derive_accent(string $primary): string
    {
        // Default accent: 20% darker version of primary (or fallback gray)
        $rgb = $this->hexToRgb($primary);
        if (!$rgb) return '#475569';
        $mul = fn(int $c) => (int) round($c * 0.80);
        return sprintf('#%02X%02X%02X', $mul($rgb[0]), $mul($rgb[1]), $mul($rgb[2]));
    }

    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) return null;
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
