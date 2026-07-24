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
    public function resolve(int $workspaceId): array
    {
        $studio   = DB::table('studio_brand_kits')->where('workspace_id', $workspaceId)->first();
        $creative = DB::table('creative_brand_identities')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->first();
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();

        // Determine if this is truly a "neutral" workspace (no brand data at all)
        $hasStudioKit   = $studio !== null;
        $hasCreativeKit = $creative !== null;
        $hasWsBrandColor = $workspace && !empty($workspace->brand_color);
        $isNeutral = !$hasStudioKit && !$hasCreativeKit && !$hasWsBrandColor;

        // Brand name: workspace.name FIRST (their business), then studio.brand_name,
        // NEVER a generic "your brand" placeholder
        $brandName = $studio->brand_name
            ?? ($workspace->name ?? 'this business');

        // Colors: prefer studio (richest), then creative, then workspace.brand_color,
        // then neutral grays. White-label: NEVER #5B5BD6 / #6C5CE7 platform defaults.
        $primaryColor    = $studio->primary_color    ?? $creative->primary_color    ?? $workspace->brand_color ?? '#1F2937';
        $secondaryColor  = $studio->secondary_color  ?? $creative->secondary_color  ?? $this->derive_secondary($primaryColor);
        $accentColor     = $studio->accent_color     ?? $creative->accent_color     ?? $this->derive_accent($primaryColor);
        $backgroundColor = $studio->background_color ?? '#FFFFFF';
        $textColor       = $studio->text_color       ?? '#0F172A';

        // Typography: studio fonts > neutral system stack
        $headingFont = $studio->heading_font ?? 'Inter, system-ui, sans-serif';
        $bodyFont    = $studio->body_font    ?? 'Inter, system-ui, sans-serif';

        // Voice/tone: creative > neutral professional
        $voice  = $creative->voice  ?? 'professional';
        $tone   = $creative->tone   ?? 'friendly';
        $audience = $creative->target_audience ?? null;
        $styleNotes = $creative->style_notes  ?? null;

        // Industry: workspace > creative_brand
        $industry = $workspace->industry
            ?? ($creative->industry ?? null);

        // Logo: studio (with dark variant) > creative
        $logoUrl     = $studio->logo_url     ?? $creative->logo_url ?? null;
        $logoDarkUrl = $studio->logo_dark_url ?? null;

        $tagline = $studio->tagline ?? null;

        // /* h2-resolver-extras */ creative extras consumed by AgentBridgeService
        $visualStyle = $creative->visual_style ?? null;
        $colorsJson  = [];
        if (!empty($creative->colors_json)) {
            $decoded = is_string($creative->colors_json) ? json_decode($creative->colors_json, true) : $creative->colors_json;
            if (is_array($decoded)) $colorsJson = $decoded;
        }

        return [
            'workspace_id'      => $workspaceId,
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
                'studio_brand_kit'        => $hasStudioKit,
                'creative_brand_identity' => $hasCreativeKit,
                'workspace_brand_color'   => $hasWsBrandColor,
            ]),
        ];
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
        $kit = $this->resolve($workspaceId);

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
