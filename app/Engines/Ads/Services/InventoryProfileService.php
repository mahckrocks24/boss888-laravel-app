<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdIndustryTaxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * InventoryProfileService — computes and stores the sellable description of a
 * website, so ad decisioning never has to reason about free text at request time.
 *
 * ORDER OF OPERATIONS
 *   1. Load the website and its workspace.
 *   2. Classify industry (IndustryClassifier cascade).
 *   3. Parse business location (LocationParser).
 *   4. Derive contextual interests (InterestDeriver) — needs the industry.
 *   5. Score inventory quality.
 *   6. Upsert `ad_inventory_profiles`, stamping provenance and a staleness date.
 *
 * INVARIANTS THIS CLASS UPHOLDS
 *   - A profile with source='explicit' is NEVER overwritten by automation.
 *     An admin override outranks every derived signal, permanently, unless the
 *     caller explicitly forces a re-profile.
 *   - Unresolved inventory is stored as `unknown` with confidence 0.00. It is
 *     never guessed into an industry to make the numbers look better; it shows
 *     up in the admin unclassified queue instead.
 *   - Writes are idempotent. Re-running profiling produces the same row.
 *
 * READ-ONLY WITH RESPECT TO EVERYTHING ELSE
 * This service reads `websites`, `workspaces`, `pages`, `seo_keywords`,
 * `workspace_memory` and `articles`. It writes exactly one table:
 * `ad_inventory_profiles`. It touches no template, no renderer, and no request
 * path — which is precisely why P0a can ship without regression risk.
 */
final class InventoryProfileService
{
    /** Re-profile a site this many days after its last profiling run. */
    public const STALE_AFTER_DAYS = 30;

    public function __construct(
        private readonly IndustryClassifier $industry,
        private readonly LocationParser $location,
        private readonly InterestDeriver $interests,
    ) {
    }

    /**
     * Profile a single website and persist the result.
     *
     * @param  bool  $force  re-derive even when an admin override exists
     * @return array<string,mixed>|null  the stored profile, or null when the
     *                                   website does not exist
     */
    public function profile(int $websiteId, bool $force = false): ?array
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();

        if (! $website) {
            return null;
        }

        $workspaceId = (int) ($website->workspace_id ?? 0);
        $workspace   = $workspaceId > 0
            ? DB::table('workspaces')->where('id', $workspaceId)->first()
            : null;

        $existing = DB::table('ad_inventory_profiles')->where('website_id', $websiteId)->first();

        // An admin override is authoritative. Automation may refresh the
        // *other* fields around it but must not silently reclassify it.
        $explicitSlug = null;
        if (! $force
            && $existing
            && ($existing->source ?? null) === 'explicit'
            && AdIndustryTaxonomy::isValidIndustry($existing->industry_slug ?? null)) {
            $explicitSlug = $existing->industry_slug;
        }

        // ── Industry ────────────────────────────────────────────────────
        $industry = $this->industry->classify(
            ['template_industry' => $website->template_industry ?? null],
            [
                'industry'      => $workspace->industry ?? null,
                'business_name' => $workspace->business_name ?? null,
                'name'          => $workspace->name ?? null,
            ],
            $explicitSlug
        );

        // ── Location ────────────────────────────────────────────────────
        $location = $this->location->parse($workspace->location ?? null);

        // ── Interests ───────────────────────────────────────────────────
        $interests = $this->interests->derive(
            $websiteId,
            $workspaceId,
            $industry['industry_slug']
        );

        // ── Descriptive metrics ─────────────────────────────────────────
        $pageCount = $this->pageCount($websiteId);
        $hasBlog   = $this->hasBlog($websiteId, $workspaceId);

        $quality = $this->qualityScore(
            pageCount: $pageCount,
            hasBlog: $hasBlog,
            interestCount: count($interests['interests']),
            industryResolved: $industry['industry_slug'] !== null,
            locationConfidence: (float) $location['confidence'],
            isPublished: ($website->status ?? null) === 'published',
        );

        $now = CarbonImmutable::now();

        $payload = [
            'workspace_id'        => $workspaceId,

            'industry_slug'       => $industry['industry_slug'],
            'archetype'           => $industry['archetype'],
            'iab_categories'      => $this->json($industry['iab_categories']),

            'business_country'    => $location['country'],
            'business_region'     => $location['region'],
            'business_city'       => $location['city'],
            'service_areas'       => $this->json($location['service_areas']),
            'location_confidence' => round((float) $location['confidence'], 2),

            'interests'           => $this->json($interests['interests']),
            'content_topics'      => $this->json($interests['content_topics']),

            'languages'           => $this->json($this->languages($website)),
            'page_count'          => $pageCount,
            'has_blog'            => $hasBlog,
            'quality_score'       => $quality,

            'confidence'          => round((float) $industry['confidence'], 2),
            'source'              => $industry['source'],
            'evidence'            => $this->json([
                'industry'   => $industry['evidence'],
                'location'   => $location['evidence'],
                'interests'  => $interests['evidence'],
                'profiled_by'=> 'InventoryProfileService',
                'forced'     => $force,
            ]),

            'profiled_at'         => $now,
            'stale_after'         => $now->addDays(max(1, app(AdSettingsService::class)->int(\App\Engines\Ads\Support\AdSettings::PROFILE_STALE_DAYS))),
            'updated_at'          => $now,
        ];

        try {
            if ($existing) {
                DB::table('ad_inventory_profiles')->where('website_id', $websiteId)->update($payload);
            } else {
                DB::table('ad_inventory_profiles')->insert(
                    $payload + ['website_id' => $websiteId, 'created_at' => $now]
                );
            }
        } catch (Throwable $e) {
            // Profiling is an offline enrichment job. It must never take down
            // the caller; a failed site is reported and retried next run.
            Log::warning('ADS888 InventoryProfileService: profile write failed', [
                'website_id' => $websiteId,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        return $this->read($websiteId);
    }

    /**
     * Profile many websites.
     *
     * @param  bool  $staleOnly  restrict to profiles past `stale_after` plus
     *                           websites with no profile at all
     * @return array{processed: int, skipped: int, failed: int, results: array<int,array<string,mixed>>}
     */
    public function profileAll(bool $staleOnly = false, bool $force = false, ?int $limit = null): array
    {
        $query = DB::table('websites')
            ->whereNull('deleted_at')
            ->orderBy('id');

        if ($staleOnly) {
            $query->where(function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('ad_inventory_profiles')
                        ->whereColumn('ad_inventory_profiles.website_id', 'websites.id');
                })->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('ad_inventory_profiles')
                        ->whereColumn('ad_inventory_profiles.website_id', 'websites.id')
                        ->where('ad_inventory_profiles.stale_after', '<=', CarbonImmutable::now());
                });
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $processed = 0;
        $failed    = 0;
        $results   = [];

        foreach ($query->pluck('id') as $websiteId) {
            $profile = $this->profile((int) $websiteId, $force);

            if ($profile === null) {
                $failed++;
                continue;
            }

            $processed++;
            $results[(int) $websiteId] = $profile;
        }

        return [
            'processed' => $processed,
            'skipped'   => 0,
            'failed'    => $failed,
            'results'   => $results,
        ];
    }

    /** Read a stored profile with JSON columns decoded. */
    public function read(int $websiteId): ?array
    {
        $row = DB::table('ad_inventory_profiles')->where('website_id', $websiteId)->first();

        if (! $row) {
            return null;
        }

        $profile = (array) $row;

        foreach (['iab_categories', 'service_areas', 'interests', 'content_topics', 'languages', 'evidence'] as $key) {
            $profile[$key] = $this->decode($profile[$key] ?? null);
        }

        $profile['sellable_for_targeting'] = IndustryClassifier::isSellableForTargeting(
            $profile['source'] ?? null,
            (float) ($profile['confidence'] ?? 0)
        );

        return $profile;
    }

    /**
     * Inventory that cannot be sold to a targeted campaign — the admin worklist.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unclassified(): array
    {
        $rows = DB::table('ad_inventory_profiles as p')
            ->leftJoin('websites as w', 'w.id', '=', 'p.website_id')
            ->where(function ($q) {
                $q->where('p.source', 'unknown')
                    ->orWhere('p.confidence', '<', IndustryClassifier::MIN_CONFIDENCE_FOR_PAID_TARGETING);
            })
            ->orderBy('p.website_id')
            ->get([
                'p.website_id', 'p.workspace_id', 'p.industry_slug', 'p.archetype',
                'p.business_country', 'p.confidence', 'p.location_confidence',
                'p.source', 'w.subdomain', 'w.status',
            ]);

        return array_map(static fn ($r) => (array) $r, $rows->all());
    }

    /**
     * Remove profiles whose website no longer exists (or was soft-deleted).
     * Explicit reaping instead of a cascading FK — see the migration docblock.
     */
    public function prune(): int
    {
        return DB::table('ad_inventory_profiles')
            ->whereNotIn('website_id', function ($q) {
                $q->select('id')->from('websites')->whereNull('deleted_at');
            })
            ->delete();
    }

    /** Aggregate counts for the admin dashboard and the reach estimator. */
    public function summary(): array
    {
        $total = DB::table('ad_inventory_profiles')->count();

        $bySource = DB::table('ad_inventory_profiles')
            ->select('source', DB::raw('count(*) as c'))
            ->groupBy('source')
            ->pluck('c', 'source')
            ->toArray();

        $sellable = DB::table('ad_inventory_profiles')
            ->where('source', '!=', 'unknown')
            ->where('confidence', '>=', IndustryClassifier::MIN_CONFIDENCE_FOR_PAID_TARGETING)
            ->count();

        $byCountry = DB::table('ad_inventory_profiles')
            ->whereNotNull('business_country')
            ->select('business_country', DB::raw('count(*) as c'))
            ->groupBy('business_country')
            ->orderByDesc('c')
            ->pluck('c', 'business_country')
            ->toArray();

        $byArchetype = DB::table('ad_inventory_profiles')
            ->whereNotNull('archetype')
            ->select('archetype', DB::raw('count(*) as c'))
            ->groupBy('archetype')
            ->orderByDesc('c')
            ->pluck('c', 'archetype')
            ->toArray();

        return [
            'total'                 => $total,
            'sellable_for_targeting'=> $sellable,
            'unsellable'            => $total - $sellable,
            'by_source'             => $bySource,
            'by_country'            => $byCountry,
            'by_archetype'          => $byArchetype,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private function pageCount(int $websiteId): int
    {
        try {
            return (int) DB::table('pages')->where('website_id', $websiteId)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function hasBlog(int $websiteId, int $workspaceId): bool
    {
        try {
            $hasBlogPage = DB::table('pages')
                ->where('website_id', $websiteId)
                ->where(function ($q) {
                    $q->where('slug', 'blog')->orWhere('type', 'blog');
                })
                ->exists();

            if ($hasBlogPage) {
                return true;
            }

            return DB::table('articles')->where('workspace_id', $workspaceId)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Inventory quality — a completeness measure, NOT an editorial judgement.
     * It answers "how much do we know about this inventory and how substantial
     * is it", which is what a media buyer is entitled to see.
     */
    private function qualityScore(
        int $pageCount,
        bool $hasBlog,
        int $interestCount,
        bool $industryResolved,
        float $locationConfidence,
        bool $isPublished,
    ): float {
        $score = 0.0;

        $score += $isPublished ? 0.30 : 0.0;
        $score += $industryResolved ? 0.25 : 0.0;
        $score += min($locationConfidence, 1.0) * 0.15;
        $score += min($pageCount / 5, 1.0) * 0.10;
        $score += $hasBlog ? 0.10 : 0.0;
        $score += min($interestCount / 5, 1.0) * 0.10;

        return round(min($score, 1.0), 2);
    }

    /** @return array<int,string> */
    private function languages(object $website): array
    {
        // The builder emits English templates today; `og_locale` is the only
        // per-site signal and defaults to en_US. Recorded as a single-element
        // list so multi-language inventory needs no schema change later.
        $settings = $this->decode($website->settings_json ?? null);
        $locale   = is_array($settings) ? ($settings['og_locale'] ?? null) : null;

        if (is_string($locale) && $locale !== '') {
            return [strtolower(substr($locale, 0, 2))];
        }

        return ['en'];
    }

    private function json(mixed $value): string
    {
        return json_encode($value ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
