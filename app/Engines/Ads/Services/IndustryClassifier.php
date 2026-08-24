<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdIndustryTaxonomy;
use App\Engines\Ads\Support\AdSettings;

/**
 * IndustryClassifier — resolves a website to a targetable industry.
 *
 * THE CASCADE (first resolution wins; the winner is recorded as `source`)
 *
 *   1. explicit    An admin override already stored on the profile.
 *                  Always wins. Never overwritten by automation.
 *   2. template    `websites.template_industry`, ONLY when it validates against
 *                  the 31 known slugs. On staging today the single populated
 *                  value is "travel", which is NOT a slug (`travel_agency` is),
 *                  so it correctly falls through rather than poisoning the
 *                  profile with an unknown code.
 *   3. classified  `workspaces.industry` (+ business name) free text, resolved
 *                  by keyword against the controlled vocabulary. Deterministic,
 *                  no AI spend, and auditable — the matched keyword is stored.
 *   4. ai          Classify the published site's own content. NOT IMPLEMENTED
 *                  in P0a: it costs credits per site and needs a spend decision.
 *                  Sites that reach this step are left `unknown` and surface in
 *                  the admin unclassified queue.
 *   5. unknown     Nothing resolved.
 *
 * CONFIDENCE AND WHY IT GATES REVENUE
 * Confidence is not decoration. Inventory below the sale threshold serves house
 * ads only and is never matched to a paid targeted campaign. An advertiser gets
 * the audience they bought or they get nothing — they are never sold a guess.
 * That rule is only enforceable because every profile records how certain we
 * are and why.
 */
final class IndustryClassifier
{
    /** Confidence per cascade step. */
    public const CONFIDENCE_EXPLICIT           = 1.00;
    public const CONFIDENCE_TEMPLATE           = 0.95;
    public const CONFIDENCE_CLASSIFIED_SLUG    = 0.75;
    public const CONFIDENCE_CLASSIFIED_ARCHETYPE = 0.50;
    public const CONFIDENCE_UNKNOWN            = 0.00;

    /**
     * Minimum confidence at which inventory may be matched to a PAID targeted
     * campaign. Below this it serves house ads only.
     *
     * Set at the archetype-only band deliberately: knowing a site is
     * "hospitality" with 0.50 confidence is not a good enough basis to charge
     * an advertiser who bought "restaurants".
     */
    public const MIN_CONFIDENCE_FOR_PAID_TARGETING = 0.75;

    /**
     * @param  array{template_industry?: ?string}  $website   row-ish array
     * @param  array{industry?: ?string, business_name?: ?string, name?: ?string}  $workspace
     * @return array{
     *   industry_slug: string|null, archetype: string|null, iab_categories: array<int,string>,
     *   source: string, confidence: float, evidence: array<string,mixed>
     * }
     */
    public function classify(array $website, array $workspace, ?string $existingExplicit = null): array
    {
        // ── 1. explicit ─────────────────────────────────────────────────
        if ($existingExplicit !== null && AdIndustryTaxonomy::isValidIndustry($existingExplicit)) {
            return $this->result(
                $existingExplicit,
                'explicit',
                self::CONFIDENCE_EXPLICIT,
                ['industry_from' => 'admin_override']
            );
        }

        // ── 2. template ─────────────────────────────────────────────────
        $templateIndustry = $this->str($website['template_industry'] ?? null);

        if ($templateIndustry !== null) {
            if (AdIndustryTaxonomy::isValidIndustry($templateIndustry)) {
                return $this->result(
                    $templateIndustry,
                    'template',
                    self::CONFIDENCE_TEMPLATE,
                    ['industry_from' => 'websites.template_industry']
                );
            }

            // Recorded, not silently discarded: an invalid template_industry is
            // a data-quality signal worth surfacing in the admin queue.
            $invalidTemplateNote = $templateIndustry;
        }

        // ── 3. classified from free text ────────────────────────────────
        $rawIndustry  = $this->str($workspace['industry'] ?? null) ?? '';
        $businessName = $this->str($workspace['business_name'] ?? null)
            ?? $this->str($workspace['name'] ?? null)
            ?? '';

        if ($rawIndustry !== '' || $businessName !== '') {
            $match = AdIndustryTaxonomy::industryFromText($rawIndustry, $businessName);

            if ($match['slug'] !== null) {
                $evidence = [
                    'industry_from'   => 'workspaces.industry',
                    'raw_industry'    => $rawIndustry,
                    'matched_keyword' => $match['matched'],
                ];
                if (isset($invalidTemplateNote)) {
                    $evidence['rejected_template_industry'] = $invalidTemplateNote;
                }

                return $this->result(
                    $match['slug'],
                    'classified',
                    self::CONFIDENCE_CLASSIFIED_SLUG,
                    $evidence
                );
            }

            // Slug unresolved, but the Builder's classifier may still place the
            // business in an archetype. That is genuinely useful for broad
            // targeting — and honestly weaker, so it scores lower.
            $archetype = AdIndustryTaxonomy::archetypeFromText($rawIndustry, $businessName);

            if ($archetype !== null) {
                $evidence = [
                    'industry_from' => 'archetype_only',
                    'raw_industry'  => $rawIndustry,
                    'note'          => 'no industry slug matched; archetype resolved via TemplateArchetypes',
                ];
                if (isset($invalidTemplateNote)) {
                    $evidence['rejected_template_industry'] = $invalidTemplateNote;
                }

                return [
                    'industry_slug'  => null,
                    'archetype'      => $archetype,
                    'iab_categories' => [],
                    'source'         => 'classified',
                    'confidence'     => self::CONFIDENCE_CLASSIFIED_ARCHETYPE,
                    'evidence'       => $evidence,
                ];
            }
        }

        // ── 4. ai — deliberately not implemented in P0a ──────────────────
        // ── 5. unknown ──────────────────────────────────────────────────
        $evidence = [
            'industry_from' => 'unresolved',
            'raw_industry'  => $rawIndustry !== '' ? $rawIndustry : null,
            'next_step'     => 'ai_classification_or_admin_override',
        ];
        if (isset($invalidTemplateNote)) {
            $evidence['rejected_template_industry'] = $invalidTemplateNote;
        }

        return [
            'industry_slug'  => null,
            'archetype'      => null,
            'iab_categories' => [],
            'source'         => 'unknown',
            'confidence'     => self::CONFIDENCE_UNKNOWN,
            'evidence'       => $evidence,
        ];
    }

    /**
     * True when this profile may be matched to a paid targeted campaign.
     *
     * The threshold is an operational setting (`min_confidence_for_paid_targeting`);
     * the constant remains the shipped default and the fallback when settings
     * cannot be read. Failing to the constant rather than to 0 matters — a
     * settings outage must not silently make every unknown site sellable.
     */
    public static function isSellableForTargeting(?string $source, float $confidence): bool
    {
        return $source !== null
            && $source !== 'unknown'
            && $confidence >= self::minConfidenceForPaid();
    }

    public static function minConfidenceForPaid(): float
    {
        try {
            return (float) app(AdSettingsService::class)->float(AdSettings::MIN_CONFIDENCE_FOR_PAID);
        } catch (\Throwable) {
            return self::MIN_CONFIDENCE_FOR_PAID_TARGETING;
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /** @return array{industry_slug: string, archetype: string|null, iab_categories: array<int,string>, source: string, confidence: float, evidence: array<string,mixed>} */
    private function result(string $slug, string $source, float $confidence, array $evidence): array
    {
        return [
            'industry_slug'  => $slug,
            'archetype'      => AdIndustryTaxonomy::archetypeForIndustry($slug),
            'iab_categories' => AdIndustryTaxonomy::iabFor($slug),
            'source'         => $source,
            'confidence'     => $confidence,
            'evidence'       => $evidence,
        ];
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
