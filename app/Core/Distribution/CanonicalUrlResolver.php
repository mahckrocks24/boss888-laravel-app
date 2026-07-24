<?php

namespace App\Core\Distribution;

use Illuminate\Support\Facades\DB;

/**
 * W5 (2026-07-22) — the authoritative public URL for a published article.
 *
 * Reuses the SAME precedence the published-site middleware and the sitemap
 * canonicaliser already use (websites.custom_domain when verified, else
 * subdomain.levelupgrowth.io) rather than concatenating strings ad hoc, so a
 * share link can never disagree with the canonical tag the crawler sees.
 *
 * Refuses to produce a link for anything that is not a public article route:
 * no staging host, no admin/app path, no preview/draft route, no bare IP.
 */
class CanonicalUrlResolver
{
    /** Hosts that must never appear in a public share. */
    private const FORBIDDEN_HOST_PARTS = ['staging.', 'localhost', '127.0.0.1', '.local', '.test'];

    /** Path prefixes that are internal surfaces, never public article routes. */
    private const FORBIDDEN_PATH_PARTS = ['/app/', '/admin', '/api/', '/preview', '/draft', '/_'];

    public const BLOG_PREFIX = '/blog/';

    /**
     * @return array{ok:bool, url?:string, host?:string, source?:string, reason?:string}
     */
    public function resolve(int $wsId, object $article): array
    {
        $slug = trim((string) ($article->slug ?? ''));
        if ($slug === '') {
            return ['ok' => false, 'reason' => 'ARTICLE_HAS_NO_SLUG'];
        }

        $host = $this->resolveHost($wsId);
        if ($host === null) {
            return ['ok' => false, 'reason' => 'NO_PUBLIC_DOMAIN_FOR_WORKSPACE'];
        }

        $url = 'https://' . $host . self::BLOG_PREFIX . ltrim($slug, '/');

        $check = $this->validatePublicUrl($url);
        if (!$check['ok']) return $check;

        return [
            'ok'     => true,
            'url'    => $url,
            'host'   => $host,
            'source' => $this->lastSource,
        ];
    }

    private ?string $lastSource = null;

    /**
     * Precedence: verified custom domain > subdomain > none.
     * Mirrors PublishedSiteMiddleware's canonical host selection.
     */
    private function resolveHost(int $wsId): ?string
    {
        $site = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->first(['custom_domain', 'domain_verified', 'subdomain']);

        if (!$site) { $this->lastSource = null; return null; }

        if (!empty($site->custom_domain) && (int) ($site->domain_verified ?? 0) === 1) {
            $this->lastSource = 'custom_domain';
            return strtolower(trim((string) $site->custom_domain, " /\t\n\r"));
        }

        if (!empty($site->subdomain)) {
            $this->lastSource = 'subdomain';
            $sub = strtolower(trim((string) $site->subdomain, " /\t\n\r"));
            // The column is inconsistent in live data: some rows hold a bare
            // label ('chef-red'), others a full host ('platform.levelupgrowth.io').
            // Appending blindly produced 'platform.levelupgrowth.io.levelupgrowth.io'.
            return str_contains($sub, '.') ? $sub : $sub . '.levelupgrowth.io';
        }

        $this->lastSource = null;
        return null;
    }

    /**
     * A share link must be a well-formed https URL on a public host, pointing at
     * a public article route. Anything else is refused outright — we would
     * rather fail the share than publish a link to an admin surface.
     */
    public function validatePublicUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'reason' => 'MALFORMED_URL'];
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return ['ok' => false, 'reason' => 'URL_NOT_HTTPS'];
        }

        $host = strtolower($parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'reason' => 'URL_IS_BARE_IP'];
        }
        foreach (self::FORBIDDEN_HOST_PARTS as $bad) {
            if (str_contains($host, $bad)) {
                return ['ok' => false, 'reason' => 'URL_IS_NON_PUBLIC_HOST'];
            }
        }
        if (!str_contains($host, '.')) {
            return ['ok' => false, 'reason' => 'URL_HOST_NOT_FQDN'];
        }

        $path = strtolower($parts['path'] ?? '/');
        foreach (self::FORBIDDEN_PATH_PARTS as $bad) {
            if (str_contains($path, $bad)) {
                return ['ok' => false, 'reason' => 'URL_IS_INTERNAL_ROUTE'];
            }
        }
        if (!str_starts_with($path, self::BLOG_PREFIX) || strlen($path) <= strlen(self::BLOG_PREFIX)) {
            return ['ok' => false, 'reason' => 'URL_NOT_ARTICLE_ROUTE'];
        }

        return ['ok' => true];
    }
}
