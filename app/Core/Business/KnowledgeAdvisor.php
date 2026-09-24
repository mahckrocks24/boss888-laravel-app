<?php

namespace App\Core\Business;

use App\Models\Business;
use App\Models\BusinessFact;
use Illuminate\Support\Facades\DB;

/**
 * K9 (2026-09-25) — what Sarah should say about a business's knowledge.
 *
 * Not a score. "Knowledge Health 73%" tells an owner nothing they can act on
 * and invents precision out of an absence. Every item this returns is a
 * sentence with a consequence and a question, because that is the only form an
 * owner can answer.
 *
 * Good:  "Your address is on your Contact page but isn't in your structured
 *         business data, so Google and ChatGPT can't tell where you are.
 *         Want me to add it?"
 * Bad:   "Completeness 62%."
 *
 * Three sources, all of them already true:
 *   - identity fields the business does not have (K4)
 *   - candidates it has but nobody has confirmed (K4 provenance)
 *   - facts that changed after a page published them (K8)
 *
 * Nothing here writes, and nothing here invents a fact. A candidate is quoted
 * back as a question, never asserted.
 */
final class KnowledgeAdvisor
{
    /** Identity fields worth asking about, and what their absence costs. */
    private const CONSEQUENCE = [
        'phone' => 'no answer engine can tell someone how to reach you',
        'address_json' => 'Google and ChatGPT cannot tell where you are based',
        'email' => 'there is no contact address in your structured data',
        'opening_hours_json' => 'nobody can be told when you are open',
        'sameas_json' => 'search engines cannot connect your website to your social accounts',
    ];

    /** Industries where a postal address is not expected. */
    private const NOT_A_PLACE = ['news', 'publisher', 'magazine', 'software', 'it services', 'consulting', 'agency'];

    public function __construct(
        private readonly SchemaComposer $composer,
        private readonly FactUsageScanner $usage,
    ) {
    }

    /**
     * Observations for one business, most consequential first.
     *
     * @return list<array{kind: string, field: string, say: string, action: string}>
     */
    public function forBusiness(int $businessId): array
    {
        $business = Business::find($businessId);
        if (! $business) {
            return [];
        }

        return array_merge(
            $this->stale($business),
            $this->unconfirmed($business),
            $this->missing($business),
            $this->entity($business),
        );
    }

    /** A fact changed after pages published it. The most urgent kind: it is now wrong. */
    private function stale(Business $business): array
    {
        $out = [];
        $byFact = [];
        foreach ($this->usage->stale((int) $business->id) as $row) {
            $byFact[$row['fact_type'] . ':' . $row['fact_key']][] = $row;
        }

        foreach ($byFact as $key => $rows) {
            [$type, $factKey] = explode(':', $key, 2);
            $pages = count($rows);
            $what = $type === 'identity' ? $this->fieldName($factKey) : 'one of your claims';
            $out[] = [
                'kind' => 'stale',
                'field' => $factKey,
                'say' => "You changed {$what}. " . ($pages === 1 ? 'One page still shows' : "{$pages} pages still show")
                    . ' the old value, and so does your ' . $rows[0]['property'] . ' in the site\'s structured data.',
                'action' => 'republish those pages so the public version matches',
            ];
        }

        return $out;
    }

    /** Something is stored but nobody has vouched for it. Ask, do not assert. */
    private function unconfirmed(Business $business): array
    {
        $out = [];
        foreach (array_keys(self::CONSEQUENCE) as $field) {
            $value = $business->{$field} ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($business->identityIsPublishable($field)) {
                continue;
            }

            $source = $business->identitySource($field);
            $shown = $this->shortValue($value);
            $origin = $source === 'ai_generated'
                ? 'It came from the text on your site, which I wrote, so I will not publish it as fact until you say it is right.'
                : 'It came from your site settings and nobody has confirmed it.';

            $out[] = [
                'kind' => 'unconfirmed',
                'field' => $field,
                'say' => "I have {$this->fieldName($field)} for you as {$shown}, but it is not published. {$origin}",
                'action' => "confirm it and I will add it to your structured data",
            ];
        }

        foreach (BusinessFact::where('business_id', $business->id)->whereNull('deleted_at')
            ->whereIn('source', BusinessFact::SOURCE_WITHHELD)->orderBy('id')->limit(5)->get() as $fact) {
            $out[] = [
                'kind' => 'unconfirmed',
                'field' => 'fact:' . $fact->id,
                'say' => "You have a {$fact->kind} recorded — \"{$fact->label}\" — that nobody has confirmed, so it is not published.",
                'action' => 'confirm it, or tell me to drop it',
            ];
        }

        return $out;
    }

    /** Nothing is stored at all, and the absence has a cost. */
    private function missing(Business $business): array
    {
        $out = [];
        $industry = strtolower((string) $business->industry);

        foreach (self::CONSEQUENCE as $field => $cost) {
            $value = $business->{$field} ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                continue;
            }
            if ($field === 'address_json' && $this->isNotAPlace($industry)) {
                continue;
            }
            if ($field === 'opening_hours_json' && $this->isNotAPlace($industry)) {
                continue;
            }

            $out[] = [
                'kind' => 'missing',
                'field' => $field,
                'say' => "I do not have {$this->fieldName($field)} for you, so {$cost}.",
                'action' => 'tell me and I will publish it',
            ];
        }

        return $out;
    }

    /**
     * The published page still states a generic type.
     *
     * A site rendered live takes its entity from SchemaComposer and is correct
     * by construction, so there is nothing to say about it. A site that was
     * exported to static HTML still carries the type it was built with — a flat
     * LocalBusiness for every industry — and will keep carrying it until it is
     * republished. That is the only case worth raising, and it is checked
     * against the export on disk rather than by guessing.
     */
    private function entity(Business $business): array
    {
        $websiteId = (int) DB::table('websites')->where('business_id', $business->id)
            ->whereNull('deleted_at')->where('status', 'published')->orderBy('id')->value('id');
        if (! $websiteId) {
            return [];
        }

        $exported = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($exported)) {
            return [];
        }

        $expected = $this->composer->typeFor($business->industry, $business->schema_type);
        if ($expected === 'LocalBusiness' || $expected === 'Organization') {
            return [];
        }

        return [[
            'kind' => 'entity',
            'field' => 'schema_type',
            'say' => "Your published pages still tell search engines you are a generic LocalBusiness. You are a {$expected}, which is what lets them answer questions about your kind of business.",
            'action' => 'republish the site and I will state the right type',
        ]];
    }

    // ---- wording ---------------------------------------------------------

    private function fieldName(string $field): string
    {
        return [
            'phone' => 'a phone number',
            'email' => 'an email address',
            'address_json' => 'a business address',
            'opening_hours_json' => 'opening hours',
            'sameas_json' => 'social profiles',
            'founding_date' => 'a founding date',
            'area_served_json' => 'the areas you serve',
        ][$field] ?? 'that detail';
    }

    private function isNotAPlace(string $industry): bool
    {
        foreach (self::NOT_A_PLACE as $needle) {
            if (str_contains($industry, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function shortValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', array_slice(array_values($value), 0, 2)));
        }
        $value = trim((string) $value);

        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : $value;
    }
}
