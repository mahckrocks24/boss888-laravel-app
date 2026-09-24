<?php

namespace App\Core\Business;

use Illuminate\Support\Facades\DB;

/**
 * K1 (2026-09-25) - the canonical PUBLIC identity of one website.
 *
 * Every machine-readable claim about a customer (article publisher, llms.txt,
 * schema entities) must name the customer's own site. Before this class,
 * WriteService::aeoEnrich read seo_settings.site_url - a workspace-wide key set
 * on only 8 workspaces - and fell back to a hardcoded 'https://levelupgrowth.io'.
 * Measured 2026-09-24: of 202 articles carrying JSON-LD, 174 named the platform
 * as publisher, 11 named staging, 8 named 127.0.0.1, 2 named a doubled host.
 * Seven named a real customer domain.
 *
 * The rule this class enforces: identity comes from the WEBSITE ROW, or it is
 * not published at all. There is no fallback host. A site legitimately served
 * at levelupgrowth.io (the platform's own workspace) still resolves correctly,
 * because the value is read from its row rather than substituted for a failure.
 *
 * Host precedence: verified custom domain -> domain -> subdomain.
 */
final class CanonicalSite
{
    /** Hosts that cannot be anyone's public identity, whatever the row says. */
    private const IMPOSSIBLE_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'staging.levelupgrowth.io'];

    /** @var array<int,array|null> request-scoped cache, keyed by website id */
    private array $cache = [];

    /**
     * The public host of one website, or null when the row cannot yield one.
     */
    public static function hostOf(?object $row): ?string
    {
        if (! $row) {
            return null;
        }

        $candidates = [];
        $custom = trim((string) ($row->custom_domain ?? ''));
        if ($custom !== '' && (int) ($row->domain_verified ?? 0) === 1) {
            $candidates[] = $custom;
        }
        $candidates[] = trim((string) ($row->domain ?? ''));
        $candidates[] = trim((string) ($row->subdomain ?? ''));

        foreach ($candidates as $candidate) {
            $host = self::normaliseHost($candidate);
            if ($host !== null) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Reduce a stored value to a bare host, or null when it is not usable.
     * Strips scheme, credentials, port and path; rejects the impossible hosts
     * and the doubled-suffix shape that produced
     * 'b4-bakery-ot3.levelupgrowth.io.levelupgrowth.io' in two stored blobs.
     */
    public static function normaliseHost(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        } else {
            $value = explode('/', $value)[0];
        }

        $value = strtolower(trim($value));
        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }
        $value = explode(':', $value)[0];
        $value = trim($value, ". \t\n\r\0\x0B");

        if ($value === '') {
            return null;
        }
        if (in_array($value, self::IMPOSSIBLE_HOSTS, true)) {
            return null;
        }
        if (! str_contains($value, '.')) {
            return null;
        }
        if (preg_match('/[^a-z0-9.\-]/', $value)) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return null;
        }
        // A registrable suffix repeated back to back is always a concatenation bug.
        if (preg_match('/^(.+?)\.([a-z0-9\-]+\.[a-z]{2,})\.\2$/', $value)) {
            return null;
        }

        return $value;
    }

    /** The canonical identity of one website, or null when it has no usable host. */
    public function forWebsite(int $websiteId): ?array
    {
        if (array_key_exists($websiteId, $this->cache)) {
            return $this->cache[$websiteId];
        }

        $row = DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')
            ->first(['id', 'workspace_id', 'name', 'domain', 'subdomain', 'custom_domain', 'domain_verified', 'status', 'business_id']);
        if (! $row) {
            return $this->cache[$websiteId] = null;
        }

        $host = self::hostOf($row);
        if ($host === null) {
            return $this->cache[$websiteId] = null;
        }

        $business = app(BusinessProfileResolver::class)->forWebsite($websiteId);
        $name = trim((string) ($business->name ?? '')) ?: trim((string) $row->name);

        return $this->cache[$websiteId] = [
            'website_id'   => (int) $row->id,
            'workspace_id' => (int) $row->workspace_id,
            'business_id'  => $business->id ?? null,
            'host'         => $host,
            'url'          => 'https://' . $host,
            'name'         => $name,
            'published'    => $row->status === 'published',
        ];
    }

    /**
     * The identity an article must publish as.
     *
     * articles.website_id is the answer when it is set. When it is not (346 of
     * 627 articles on 2026-09-25) a workspace with exactly one published
     * website is unambiguous and is used. A workspace with several published
     * websites is genuinely ambiguous: this returns no host rather than assert
     * one site's identity on another site's article. The caller then publishes
     * the business name without a url, which is valid schema.org and honest.
     */
    public function forArticle(int $articleId, ?int $workspaceId = null): array
    {
        $article = DB::table('articles')->where('id', $articleId)->first(['id', 'workspace_id', 'website_id']);
        $wsId = (int) ($article->workspace_id ?? $workspaceId ?? 0);

        if ($article && $article->website_id) {
            $identity = $this->forWebsite((int) $article->website_id);
            if ($identity) {
                return $identity + ['ambiguous' => false, 'reason' => 'article.website_id'];
            }

            return $this->unresolved($wsId, 'website_has_no_usable_host');
        }

        $published = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->where('status', 'published')->orderBy('id')->pluck('id')->all();

        if (count($published) === 1) {
            $identity = $this->forWebsite((int) $published[0]);
            if ($identity) {
                return $identity + ['ambiguous' => false, 'reason' => 'workspace_has_one_published_website'];
            }

            return $this->unresolved($wsId, 'website_has_no_usable_host');
        }

        // A workspace with NO website rows at all is a pure connector workspace:
        // its site lives in WordPress and its address is the customer-configured
        // seo_settings.site_url. That is the customer's own evidence, not a
        // platform fallback, so it is accepted — but only here, only when no
        // website row exists to contradict it, and only if it survives
        // normalisation. The store is known to be dirty: 20 of 23 rows carry a
        // doubled '.levelupgrowth.io.levelupgrowth.io' suffix and one is
        // 127.0.0.1:8093. Those are refused, not published.
        if (DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->doesntExist()) {
            $connector = $this->fromConnector($wsId);
            if ($connector) { return $connector; }
        }

        return $this->unresolved(
            $wsId,
            count($published) === 0 ? 'workspace_has_no_published_website' : 'workspace_has_several_published_websites'
        );
    }

    /** The WordPress address a connector workspace configured for itself. */
    private function fromConnector(int $wsId): ?array
    {
        $value = DB::table('seo_settings')->where('workspace_id', $wsId)
            ->where('key', 'site_url')->value('value');
        $host = self::normaliseHost((string) $value);
        if ($host === null) { return null; }

        $profile = app(BusinessProfileResolver::class)->profile($wsId);

        return [
            'website_id'   => null,
            'workspace_id' => $wsId,
            'business_id'  => $profile['business_id'] ?? null,
            'host'         => $host,
            'url'          => 'https://' . $host,
            'name'         => trim((string) ($profile['name'] ?? '')),
            'published'    => true,
            'ambiguous'    => false,
            'reason'       => 'connector_workspace_site_url',
        ];
    }

    /** No host may be published. Carry the best available NAME and say why. */
    private function unresolved(int $wsId, string $reason): array
    {
        $profile = app(BusinessProfileResolver::class)->profile($wsId);
        $name = trim((string) ($profile['name'] ?? ''));

        return [
            'website_id'   => null,
            'workspace_id' => $wsId,
            'business_id'  => $profile['business_id'] ?? null,
            'host'         => null,
            'url'          => null,
            'name'         => $name,
            'published'    => false,
            'ambiguous'    => true,
            'reason'       => $reason,
        ];
    }
}
