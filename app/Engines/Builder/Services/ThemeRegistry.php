<?php

namespace App\Engines\Builder\Services;

/**
 * KABAYAN888 G1 (2026-09-03) — theme registry for renderer-path sites.
 *
 * `websites.settings_json.theme` names a theme class. Before this registry the
 * dispatch was a hard-coded `=== 'amg-travel'` check in two places
 * (BuilderRenderer::renderPage and ::renderArticle). Adding a theme now means
 * one line here.
 *
 * Contract every theme honours (duck-typed, no interface so AmgTravelTheme is
 * untouched):
 *   renderBody(array $secs, array $brand, array $website, array $page [, ?callable $fallback]): string
 *   renderArticle(array $article, array $website): string
 * `$fallback($sec)` renders a section with BuilderRenderer's generic arm so a
 * theme only has to own the section types it styles.
 */
final class ThemeRegistry
{
    /** @var array<string, class-string> */
    private const THEMES = [
        'amg-travel'   => AmgTravelTheme::class,
        'kabayan-news' => KabayanNewsTheme::class,
        'mrdigital-enterprise' => MrDigitalEnterpriseTheme::class, // MRDIGITAL888 G1 (2026-09-17)
    ];

    public static function resolve(?string $key): ?object
    {
        $key = strtolower(trim((string) $key));
        if ($key === '' || !isset(self::THEMES[$key])) return null;
        $cls = self::THEMES[$key];
        return class_exists($cls) ? new $cls() : null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }
}
