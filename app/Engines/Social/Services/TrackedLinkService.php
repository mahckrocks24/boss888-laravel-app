<?php

namespace App\Engines\Social\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SOCIAL-LEADS-1 (RFC-0016 P1). A tracked link from social to the business's own website:
 *   https://<business domain>/go/<code>  →  302 to the target (default: the home page's #contact section) with UTM tags,
 * counting each click and dropping a first-party `lug_ref` cookie so a later contact-form submission is tied back to the
 * comment (and its CRM lead) that sent the visitor. No tokens, no third-party tracker.
 */
class TrackedLinkService
{
    /** The business's public site for a workspace (a business's own site first). */
    public function siteFor(int $wsId, ?int $businessId = null): ?object
    {
        $q = DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at');
        if ($businessId) {
            $own = (clone $q)->where('business_id', $businessId)->orderByRaw('custom_domain IS NULL')->orderBy('id')->first();
            if ($own) return $own;
        }
        return $q->orderByRaw('custom_domain IS NULL')->orderBy('id')->first();
    }

    public function baseUrl(object $site): ?string
    {
        $host = ($site->custom_domain && ! empty($site->domain_verified)) ? $site->custom_domain : $site->subdomain;
        return $host ? 'https://' . preg_replace('#^https?://#', '', rtrim((string) $host, '/')) : null;
    }

    /**
     * Create a link. Returns ['code' => …, 'url' => the short link to put in the reply] or null when the business has no site.
     * $anchor '#contact' sends the visitor to the contact section.
     */
    public function create(int $wsId, ?int $businessId, string $sourceType, ?int $sourceId, string $campaign, string $anchor = '#contact'): ?array
    {
        $site = $this->siteFor($wsId, $businessId);
        $base = $site ? $this->baseUrl($site) : null;
        if (! $base) return null;
        $utm = http_build_query(['utm_source' => 'facebook', 'utm_medium' => str_replace('facebook_', '', $sourceType), 'utm_campaign' => mb_substr($campaign, 0, 60)]);
        do { $code = Str::lower(Str::random(8)); } while (DB::table('tracked_links')->where('code', $code)->exists());
        DB::table('tracked_links')->insert([
            'workspace_id' => $wsId, 'website_id' => (int) $site->id, 'code' => $code, 'target_url' => $base . '/?' . $utm . $anchor,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['code' => $code, 'url' => $base . '/go/' . $code];
    }

    /** Resolve a click on a site host. Returns the target, or null when the code is not this site's. */
    public function click(string $code, ?int $websiteId, ?string $ip, ?string $ua): ?string
    {
        $l = DB::table('tracked_links')->where('code', $code)->first();
        if (! $l || ($websiteId && (int) $l->website_id !== (int) $websiteId)) return null;
        DB::table('tracked_links')->where('id', $l->id)->update(['clicks' => DB::raw('clicks + 1'), 'last_click_at' => now(), 'updated_at' => now()]);
        DB::table('tracked_link_clicks')->insert(['tracked_link_id' => $l->id, 'ip_hash' => $ip ? hash('sha256', $ip . '|' . config('app.key')) : null,
            'user_agent' => $ua ? mb_substr($ua, 0, 255) : null, 'clicked_at' => now()]);
        return (string) $l->target_url;
    }

    public function find(string $code): ?object
    {
        return preg_match('/^[a-z0-9]{6,16}$/', $code) ? DB::table('tracked_links')->where('code', $code)->first() : null;
    }
}
