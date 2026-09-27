<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1: tell search engines about new or changed URLs, and ask Google whether a URL is indexed.
 *   IndexNow   Bing, Yandex, Seznam, Naver (and the engines that read their index) — instant, keyed per host. The key file
 *              is served by PublishedSiteMiddleware at https://{host}/{key}.txt.
 *   Google     reads the sitemap advertised in robots.txt (it retired sitemap pings in 2023). With Search Console
 *              connected, Sarah checks each new URL through the URL Inspection API (read-only scope) 7 days later.
 */
final class SearchNotifier
{
    /** @param string[] $urls @return array{ok:bool, http:int|null, sent:int} */
    public function indexNow(object $w, array $urls): array
    {
        $host = SearchSites::host($w);
        $urls = array_values(array_unique(array_filter($urls, fn ($u) => is_string($u) && str_starts_with($u, 'https://' . $host))));
        if (! $host || ! $urls) return ['ok' => false, 'http' => null, 'sent' => 0];
        if (SearchSites::isWp($w)) return ['ok' => false, 'http' => null, 'sent' => 0];   // the key file is served only on LevelUp-built hosts
        try {
            $key = \App\Core\Tenancy\WebsiteScope::seo((int) $w->workspace_id, (int) $w->id, 'indexnow_key');
            if (! $key) { $key = bin2hex(random_bytes(16)); \App\Core\Tenancy\WebsiteScope::putSeo((int) $w->workspace_id, (int) $w->id, 'indexnow_key', $key); }
            $sent = 0; $last = null;
            foreach (array_chunk($urls, 500) as $chunk) {
                $r = Http::timeout(10)->withHeaders(['Content-Type' => 'application/json; charset=utf-8'])
                    ->post('https://api.indexnow.org/IndexNow', ['host' => $host, 'key' => $key, 'keyLocation' => 'https://' . $host . '/' . $key . '.txt', 'urlList' => $chunk]);
                $last = $r->status(); if ($r->successful()) $sent += count($chunk);
            }
            Log::info('[PAGE-ONE-1] IndexNow', ['website' => $w->id, 'host' => $host, 'urls' => count($urls), 'http' => $last]);
            return ['ok' => $sent > 0, 'http' => $last, 'sent' => $sent];
        } catch (\Throwable $e) {
            Log::info('[PAGE-ONE-1] IndexNow failed', ['website' => $w->id, 'e' => $e->getMessage()]);
            return ['ok' => false, 'http' => null, 'sent' => 0];
        }
    }

    /** The Search Console property that covers this host, if the workspace connected one. */
    public function gscProperty(object $w): ?string
    {
        $host = SearchSites::host($w);
        if (! $host) return null;
        $bare = preg_replace('/^www\./', '', $host);
        foreach (DB::table('gsc_connections')->where('workspace_id', $w->workspace_id)->where('connected', 1)->whereNotNull('site_url')->get(['site_url', 'website_id']) as $c) {
            $s = strtolower((string) $c->site_url);
            if ($s === 'sc-domain:' . $bare || str_contains($s, '://' . $host) || str_contains($s, '://www.' . $bare) || str_contains($s, '://' . $bare)) return (string) $c->site_url;
        }
        return null;
    }

    /** Google's view of one URL. @return array{indexed:bool|null, state:string}|null  null = cannot ask */
    public function inspect(object $w, string $url): ?array
    {
        $prop = $this->gscProperty($w);
        if (! $prop) return null;
        try {
            $token = app(\App\Engines\SEO\Services\GscClient::class)->getAccessToken((int) $w->workspace_id);
            $r = Http::withToken($token)->timeout(20)->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', ['inspectionUrl' => $url, 'siteUrl' => $prop]);
            if (! $r->successful()) { Log::info('[PAGE-ONE-1] inspect failed', ['url' => $url, 'http' => $r->status(), 'e' => mb_substr($r->body(), 0, 200)]); return null; }
            $res = $r->json('inspectionResult.indexStatusResult') ?: [];
            $verdict = (string) ($res['verdict'] ?? '');
            $state = (string) ($res['coverageState'] ?? $verdict);
            return ['indexed' => $verdict === 'PASS' ? true : ($verdict === '' ? null : false), 'state' => mb_substr($state, 0, 120)];
        } catch (\Throwable $e) {
            Log::info('[PAGE-ONE-1] inspect error', ['url' => $url, 'e' => $e->getMessage()]);
            return null;
        }
    }
}
