<?php

namespace App\Core\Business;

use App\Models\Business;
use App\Models\BusinessFact;
use Illuminate\Support\Facades\DB;

/**
 * K8 (2026-09-25) — which fact reached which page, and is it still current?
 *
 * The composer already knows exactly what it published, because it decided.
 * This walks a business's published websites and pages, asks the composer for
 * each page's graph, and records one row per (fact, page, property) with a hash
 * of the value that went out.
 *
 * That makes two questions answerable that could not be asked before:
 *
 *   "Where does this phone number appear?"  -> usagesOf()
 *   "What is now stale?"                    -> stale()
 *
 * Nothing here runs on a page render. A render that also wrote usage rows would
 * cost a write per fact per page per request; the scan is explicit instead, so
 * the hot path stays read-only.
 */
final class FactUsageScanner
{
    /** schema.org property => the identity column that produced it. */
    private const IDENTITY_PROPERTIES = [
        'telephone' => 'phone',
        'email' => 'email',
        'address' => 'address_json',
        'sameAs' => 'sameas_json',
        'foundingDate' => 'founding_date',
        'areaServed' => 'area_served_json',
        'openingHoursSpecification' => 'opening_hours_json',
    ];

    /** schema.org property => the claim kind that produced it. */
    private const CLAIM_PROPERTIES = [
        'award' => 'award',
        'hasCredential' => 'certification',
        'memberOf' => 'membership',
        'review' => 'testimonial',
    ];

    public function __construct(
        private readonly SchemaComposer $composer,
        private readonly CanonicalSite $canonical,
    ) {
    }

    /**
     * Rebuild the usage record for one business. Returns the number of rows written.
     * Idempotent: the business's rows are replaced, not appended to.
     */
    public function scanBusiness(int $businessId): int
    {
        $business = Business::find($businessId);
        if (! $business) {
            return 0;
        }

        $websites = DB::table('websites')->where('business_id', $businessId)
            ->whereNull('deleted_at')->where('status', 'published')
            ->pluck('id')->all();

        $rows = [];
        foreach ($websites as $websiteId) {
            foreach ($this->pageUrlsFor((int) $websiteId) as $pageUrl) {
                foreach ($this->usagesOnPage($business, (int) $websiteId, $pageUrl) as $row) {
                    $rows[] = $row;
                }
            }
        }

        DB::transaction(function () use ($businessId, $rows) {
            DB::table('fact_usages')->where('business_id', $businessId)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('fact_usages')->insert($chunk);
            }
        });

        return count($rows);
    }

    /** Every page of one business that carries a given fact. */
    public function usagesOf(int $businessId, string $factType, string $factKey): array
    {
        return DB::table('fact_usages')
            ->where('business_id', $businessId)
            ->where('fact_type', $factType)
            ->where('fact_key', $factKey)
            ->orderBy('page_url')
            ->get(['website_id', 'page_url', 'property', 'node_id', 'value_hash', 'scanned_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Rows whose recorded value no longer matches the fact's current value.
     *
     * This is the whole point: a phone number edited in Settings makes every
     * page that published the old one stale, and says so by name.
     */
    public function stale(int $businessId): array
    {
        $business = Business::find($businessId);
        if (! $business) {
            return [];
        }

        $current = [];
        foreach (self::IDENTITY_PROPERTIES as $property => $field) {
            $current['identity:' . $field] = $this->hash($business->{$field} ?? null);
        }
        foreach (BusinessFact::publishableFor($businessId) as $fact) {
            $current['claim:' . $fact->id] = $this->hash([$fact->label, $fact->value]);
        }

        $stale = [];
        foreach (DB::table('fact_usages')->where('business_id', $businessId)->get() as $row) {
            $key = $row->fact_type . ':' . $row->fact_key;
            $now = $current[$key] ?? null;

            if ($now === null) {
                $stale[] = (array) $row + ['reason' => 'the fact is no longer published at all'];
            } elseif ($now !== $row->value_hash) {
                $stale[] = (array) $row + ['reason' => 'the fact has changed since this page published it'];
            }
        }

        return $stale;
    }

    // ---- internals -------------------------------------------------------

    /** The public URLs of one website: its home plus its published pages. */
    private function pageUrlsFor(int $websiteId): array
    {
        $identity = $this->canonical->forWebsite($websiteId);
        if (! $identity || empty($identity['url'])) {
            return [];
        }

        $base = rtrim((string) $identity['url'], '/');
        $urls = [$base . '/'];

        foreach (DB::table('pages')->where('website_id', $websiteId)->where('status', 'published')
            ->orderBy('slug')->limit(100)->pluck('slug') as $slug) {
            $slug = ltrim((string) $slug, '/');
            if ($slug !== '' && $slug !== 'home' && $slug !== 'index') {
                $urls[] = $base . '/' . $slug;
            }
        }

        return $urls;
    }

    /** One row per fact the composer actually published on this page. */
    private function usagesOnPage(Business $business, int $websiteId, string $pageUrl): array
    {
        $graph = $this->composer->forWebsite($websiteId, ['url' => $pageUrl]);
        if (! $graph) {
            return [];
        }

        $organization = null;
        foreach ($graph['@graph'] ?? [] as $node) {
            if (isset($node['@id']) && str_ends_with((string) $node['@id'], '#organization')) {
                $organization = $node;
                break;
            }
        }
        if (! $organization) {
            return [];
        }

        $nodeId = (string) ($organization['@id'] ?? '');
        $now = now();
        $rows = [];

        foreach (self::IDENTITY_PROPERTIES as $property => $field) {
            if (! array_key_exists($property, $organization)) {
                continue;
            }
            $rows[] = [
                'business_id' => (int) $business->id, 'website_id' => $websiteId, 'page_url' => $pageUrl,
                'fact_type' => 'identity', 'fact_key' => $field, 'property' => $property,
                'node_id' => $nodeId, 'value_hash' => $this->hash($business->{$field} ?? null),
                'scanned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $claimsByKind = [];
        foreach (BusinessFact::publishableFor((int) $business->id) as $fact) {
            $claimsByKind[(string) $fact->kind][] = $fact;
        }

        foreach (self::CLAIM_PROPERTIES as $property => $kind) {
            if (! array_key_exists($property, $organization)) {
                continue;
            }
            foreach ($claimsByKind[$kind] ?? [] as $fact) {
                $rows[] = [
                    'business_id' => (int) $business->id, 'website_id' => $websiteId, 'page_url' => $pageUrl,
                    'fact_type' => 'claim', 'fact_key' => (string) $fact->id, 'property' => $property,
                    'node_id' => $nodeId, 'value_hash' => $this->hash([$fact->label, $fact->value]),
                    'scanned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    private function hash(mixed $value): string
    {
        return sha1(is_scalar($value) || $value === null
            ? (string) $value
            : json_encode($value, JSON_UNESCAPED_SLASHES));
    }
}
