<?php

namespace App\Services\Domains;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Connectors\Infrastructure\ProviderResult;

/**
 * Retail pricing for domain products.
 *
 * The single rule:  retail = registrar cost + markup  (+ tax, applied last)
 *
 * Three things this service deliberately does NOT do:
 *
 *  1. It does not invent prices. Every cost comes from a live Namecheap quote.
 *     If Namecheap cannot be reached, the quote is BLOCKED -- never estimated,
 *     never defaulted to a remembered figure.
 *
 *  2. It does not convert currency. All customer-facing prices are USD. If the
 *     registrar quotes anything else, the quote is refused and surfaced for a
 *     human decision. Implementing FX conversion is out of scope and has not
 *     been approved.
 *
 *  3. It does not apply tax itself. Tax is computed by the billing layer at
 *     checkout, where the customer's jurisdiction is known. This service
 *     returns a tax-exclusive retail price and says so explicitly.
 */
class DomainPricingService
{
    public function __construct(
        private readonly NamecheapRegistrarConnector $registrar
    ) {
    }

    public static function make(?string $environment = null): self
    {
        return new self(NamecheapRegistrarConnector::make($environment));
    }

    /**
     * Full customer-facing quote for registering a domain.
     *
     * @return array{available:bool,...} always shaped, never throws
     */
    public function quoteRegistration(string $domain, int $years = 1): array
    {
        return $this->quote($domain, $years, 'register');
    }

    public function quoteRenewal(string $domain, int $years = 1): array
    {
        return $this->quote($domain, $years, 'renew');
    }

    public function quoteTransfer(string $domain): array
    {
        return $this->quote($domain, 1, 'transfer');
    }

    private function quote(string $domain, int $years, string $action): array
    {
        $cost = match ($action) {
            'register' => $this->registrar->quoteRegistration($domain, $years),
            'renew'    => $this->registrar->quoteRenewal($domain, $years),
            'transfer' => $this->registrar->quoteTransfer($domain),
        };

        if (! $cost->success) {
            return $this->blocked($domain, $action, $years, $cost);
        }

        $costMinor = (int) ($cost->data['amount_minor'] ?? 0);
        $currency = (string) ($cost->data['currency'] ?? '');

        if ($costMinor <= 0) {
            return $this->blocked($domain, $action, $years, null, 'Registrar returned a non-positive cost');
        }

        // Guard the invariant rather than trusting it: the connector already
        // refuses non-USD, but retail must never be emitted in a currency the
        // platform does not sell in.
        if ($currency !== 'USD') {
            return $this->blocked(
                $domain,
                $action,
                $years,
                null,
                "Registrar quoted {$currency}. All customer-facing prices are USD and currency conversion is not implemented."
            );
        }

        $markup = $this->markupMinor($costMinor);
        $retailMinor = $costMinor + $markup;

        return [
            'available'     => true,
            'domain'        => $domain,
            'action'        => $action,
            'years'         => $years,
            'currency'      => 'USD',

            // Cost -- internal only. Never render this to a customer.
            'cost_minor'    => $costMinor,
            'cost'          => $this->fmt($costMinor),

            'markup_minor'  => $markup,
            'markup'        => $this->fmt($markup),
            'markup_basis'  => $this->markupBasis($costMinor),

            // Retail -- what the customer pays, EXCLUDING tax.
            'retail_minor'  => $retailMinor,
            'retail'        => $this->fmt($retailMinor),
            'tax_treatment' => 'exclusive',
            'tax_note'      => 'Tax is calculated at checkout from the customer billing jurisdiction; it is not included here.',

            'margin_minor'  => $markup,
            'margin_percent'=> round(($markup / $costMinor) * 100, 2),

            'is_premium'    => (bool) ($cost->data['is_promotional'] ?? false),
            'cost_derived'  => (bool) ($cost->data['derived'] ?? false),
            'source'        => 'namecheap.users.getPricing (live)',
            'environment'   => $this->registrar->environment(),
            'quote_ttl_seconds' => (int) config('namecheap.pricing.quote_ttl_seconds', 900),
        ];
    }

    /**
     * Availability + price in one call, which is what a domain search screen
     * actually needs. A taken domain returns available=false and NO price,
     * rather than a price the customer cannot buy.
     */
    public function searchWithPricing(string $domain, int $years = 1): array
    {
        $search = $this->registrar->searchDomain($domain);

        if (! $search->success) {
            return [
                'available'      => false,
                'domain'         => $domain,
                'blocked'        => true,
                'blocked_reason' => $search->errorSummary,
                'error_code'     => $search->errorCode,
            ];
        }

        $isAvailable = (bool) ($search->data['available'] ?? false);

        if (! $isAvailable) {
            return [
                'available'   => false,
                'domain'      => $domain,
                'blocked'     => false,
                'reason'      => 'Domain is already registered',
                'premium'     => (bool) ($search->data['premium'] ?? false),
            ];
        }

        // A premium domain is priced by the availability response, not the
        // standard TLD rate. Using the standard rate here would undercharge by
        // orders of magnitude, so premium names are surfaced for manual pricing.
        if (($search->data['premium'] ?? false) === true) {
            $premiumCost = (float) ($search->data['premium_registration_price'] ?? 0);

            if ($premiumCost <= 0) {
                return [
                    'available'      => true,
                    'domain'         => $domain,
                    'premium'        => true,
                    'blocked'        => true,
                    'blocked_reason' => 'Premium domain with no premium price returned; requires manual pricing.',
                ];
            }

            $costMinor = (int) round($premiumCost * 100);
            $markup = $this->markupMinor($costMinor);

            return [
                'available'     => true,
                'domain'        => $domain,
                'premium'       => true,
                'years'         => $years,
                'currency'      => 'USD',
                'cost_minor'    => $costMinor,
                'cost'          => $this->fmt($costMinor),
                'markup_minor'  => $markup,
                'markup'        => $this->fmt($markup),
                'retail_minor'  => $costMinor + $markup,
                'retail'        => $this->fmt($costMinor + $markup),
                'tax_treatment' => 'exclusive',
                'source'        => 'namecheap.domains.check (premium price)',
                'requires_review' => true,
                'review_reason' => 'Premium registrations are non-refundable and priced per-name; confirm before selling.',
            ];
        }

        $quote = $this->quoteRegistration($domain, $years);
        $quote['premium'] = false;

        return $quote;
    }

    /**
     * Markup = max(percentage of cost, flat minimum). The flat minimum exists so
     * a $0.99 promotional TLD still covers the cost of supporting the domain.
     */
    /**
     * The margin rule, exposed so no other surface has to restate it. Public search prices twenty domains
     * from a bulk price list rather than one at a time; it must apply THIS rule, not a copy of it.
     */
    public static function markupFor(int $costMinor): int
    {
        $percent = (float) config('namecheap.pricing.markup_percent', 30.0);
        $minimum = (int) round(((float) config('namecheap.pricing.markup_minimum_usd', 4.00)) * 100);

        return max((int) round($costMinor * ($percent / 100)), $minimum);
    }

    private function markupMinor(int $costMinor): int
    {
        return self::markupFor($costMinor);
    }

    private function markupBasis(int $costMinor): string
    {
        $percent = (float) config('namecheap.pricing.markup_percent', 30.0);
        $minimum = (int) round(((float) config('namecheap.pricing.markup_minimum_usd', 4.00)) * 100);
        $pctValue = (int) round($costMinor * ($percent / 100));

        return $pctValue >= $minimum
            ? "{$percent}% of registrar cost"
            : 'flat minimum $' . number_format($minimum / 100, 2) . " (exceeds {$percent}%)";
    }

    /* ==================================================================== DOMAIN-TERMS-1 (Owner 2026-09-25) ==
     * The customer never sees the base price P (registrar cost + margin). They see the LIST price per year
     * D = P × list_multiplier, less any discount we run. One year costs D. The bundle costs first_year ($1) for
     * year one and D for every further year — the bundle's full value is recovered over the later years, which is
     * the GoDaddy shape the Owner asked for. Renewals are D per year. No discount can price a term below
     * wholesale + the minimum margin.
     */
    public static function listMultiplier(): float { return max(1.0, (float) config('namecheap.pricing.list_multiplier', 1.5)); }
    public static function discountPercent(): float { return min(90.0, max(0.0, (float) config('namecheap.pricing.discount_percent', 0))); }
    public static function bundleYears(): int { return max(2, min(10, (int) config('namecheap.pricing.bundle_years', 3))); }
    public static function bundleFirstYearMinor(): int { return max(0, (int) round(((float) config('namecheap.pricing.bundle_first_year_usd', 1.00)) * 100)); }

    /** P: registrar cost + margin. Internal. */
    public static function basePerYearMinor(int $costMinor): int { return $costMinor + self::markupFor($costMinor); }

    /** D: the per-year price a customer sees, discount applied. */
    public static function listPerYearMinor(int $costMinor): int
    {
        $d = self::basePerYearMinor($costMinor) * self::listMultiplier() * (1 - self::discountPercent() / 100);
        return max(1, (int) round($d));
    }

    /** Alias for the public price list, which prices twenty rows with no registrar call each. */
    public static function customerPerYearMinor(int $costMinor): int { return self::listPerYearMinor($costMinor); }

    private static function money(int $minor): string { return '$' . number_format($minor / 100, 2); }
    private static function moneyShort(int $minor): string { return $minor % 100 === 0 ? '$' . ($minor / 100) : self::money($minor); }

    /**
     * The terms for a domain whose 1-year wholesale is $costMinor; $bundleWholesaleMinor is the registrar's quote
     * for the bundle term when known. Pure arithmetic; unit-tested.
     */
    public static function termsFor(int $costMinor, ?int $bundleWholesaleMinor = null): array
    {
        $n = self::bundleYears();
        $list = self::listPerYearMinor($costMinor);
        $first = self::bundleFirstYearMinor();
        $minMarkup = (int) round(((float) config('namecheap.pricing.markup_minimum_usd', 4.00)) * 100);
        // Never trust a multi-year quote below n × the 1-year cost: the registrar returns the 1-year figure for
        // .io/.co bundle quotes (measured 2026-09-25 in sandbox).
        $quoted = (int) $bundleWholesaleMinor;
        $wholesaleN = ($quoted >= (int) round($costMinor * ($n - 0.5))) ? $quoted : $n * $costMinor;

        $one = ['years' => 1, 'total_minor' => $list, 'per_year_minor' => [$list], 'wholesale_minor' => $costMinor, 'headline' => null, 'capped' => false];
        if ($one['total_minor'] < $costMinor + $minMarkup) {
            $one['total_minor'] = $costMinor + $minMarkup; $one['per_year_minor'] = [$one['total_minor']]; $one['capped'] = true;
        }
        $one['markup_minor'] = $one['total_minor'] - $costMinor;

        $perYear = [$first];
        for ($i = 1; $i < $n; $i++) { $perYear[] = $list; }
        $bundle = ['years' => $n, 'total_minor' => array_sum($perYear), 'per_year_minor' => $perYear, 'wholesale_minor' => $wholesaleN,
                   'headline' => self::moneyShort($first) . ' first year', 'capped' => false];
        if ($bundle['total_minor'] < $wholesaleN + $minMarkup) {
            // A discount cannot make the bundle a loss: the later years absorb the difference.
            $each = (int) ceil(($wholesaleN + $minMarkup - $first) / ($n - 1));
            $perYear = [$first];
            for ($i = 1; $i < $n; $i++) { $perYear[] = $each; }
            $bundle['per_year_minor'] = $perYear; $bundle['total_minor'] = array_sum($perYear); $bundle['capped'] = true;
        }
        $bundle['markup_minor'] = $bundle['total_minor'] - $wholesaleN;

        foreach ([&$one, &$bundle] as &$t) {
            $t['total'] = self::money($t['total_minor']);
            $t['per_year'] = array_map(fn ($m) => self::money($m), $t['per_year_minor']);
            $t['label'] = $t['years'] === 1 ? '1 year' : $t['years'] . ' years';
        }
        unset($t);

        return [
            'base_per_year_minor'    => self::basePerYearMinor($costMinor),   // internal
            'list_per_year_minor'    => $list,
            'list_per_year'          => self::money($list),
            'discount_percent'       => self::discountPercent(),
            'renewal_per_year_minor' => $list,
            'renewal_per_year'       => self::money($list),
            'bundle_years'           => $n,
            'terms'                  => [$one, $bundle],
        ];
    }

    /** Availability, the customer's per-year price and both terms, for a live domain. */
    public function terms(string $domain): array
    {
        $q = $this->searchWithPricing($domain, 1);
        if (($q['available'] ?? false) !== true || ($q['blocked'] ?? false) === true) {
            return $q;
        }
        if (($q['premium'] ?? false) === true) {
            // Premium names are priced per name and are non-refundable: one term, no $1 bundle.
            $q['terms'] = [['years' => 1, 'total_minor' => $q['retail_minor'], 'total' => $q['retail'], 'per_year_minor' => [$q['retail_minor']],
                             'per_year' => [$q['retail']], 'wholesale_minor' => $q['cost_minor'], 'markup_minor' => $q['markup_minor'],
                             'headline' => null, 'label' => '1 year', 'capped' => false]];
            $q['renewal_per_year_minor'] = $q['retail_minor']; $q['renewal_per_year'] = $q['retail'];
            return $q;
        }
        $bq = $this->registrar->quoteRegistration($domain, self::bundleYears());
        $t = self::termsFor((int) $q['cost_minor'], $bq->success ? (int) ($bq->data['amount_minor'] ?? 0) : null);

        return array_merge($q, $t, [
            'base_retail_minor' => $q['retail_minor'],             // P, internal
            'retail_minor'      => $t['list_per_year_minor'],      // what the customer sees for one year
            'retail'            => $t['list_per_year'],
            'markup_minor'      => $t['terms'][0]['markup_minor'],
            'markup'            => self::money($t['terms'][0]['markup_minor']),
        ]);
    }

    private function blocked(
        string $domain,
        string $action,
        int $years,
        ?ProviderResult $result = null,
        ?string $reason = null
    ): array {
        return [
            'available'      => false,
            'blocked'        => true,
            'domain'         => $domain,
            'action'         => $action,
            'years'          => $years,
            'blocked_reason' => $reason ?? ($result?->errorSummary ?? 'Registrar pricing unavailable'),
            'error_code'     => $result?->errorCode,
            // BLOCKED is not zero. A caller must not render 0.00 here.
            'retail_minor'   => null,
            'retail'         => null,
            'environment'    => $this->registrar->environment(),
        ];
    }

    private function fmt(int $minor): string
    {
        return '$' . number_format($minor / 100, 2);
    }
}
