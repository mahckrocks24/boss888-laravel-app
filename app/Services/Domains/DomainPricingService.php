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
    private function markupMinor(int $costMinor): int
    {
        $percent = (float) config('namecheap.pricing.markup_percent', 30.0);
        $minimum = (int) round(((float) config('namecheap.pricing.markup_minimum_usd', 4.00)) * 100);

        return max((int) round($costMinor * ($percent / 100)), $minimum);
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
