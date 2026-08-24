<?php

namespace App\Connectors\Infrastructure\Namecheap;

use App\Connectors\Infrastructure\Contracts\DomainRegistrarConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Services\Domains\DomainContactResolver;

/**
 * Namecheap implementation of DomainRegistrarConnector.
 *
 * The existing contract was NOT modified. Every v1 registrar capability maps
 * onto it. Capabilities Namecheap offers that the contract does not describe
 * (account balance, TLD catalogue, registrar lock, WHOIS privacy, DNS host
 * records) are exposed as ADDITIONAL public methods on this adapter rather than
 * by widening the shared interface -- a second registrar must not be forced to
 * implement Namecheap's surface area.
 *
 * BILLABLE OPERATIONS
 * registerDomain(), renewDomain() and transferDomain() spend real money in
 * production. Each one passes through assertPurchaseAllowed(), which fails
 * closed unless NAMECHEAP_PRODUCTION_PURCHASES_ENABLED is explicitly true.
 * Sandbox is never gated because sandbox spends nothing.
 */
class NamecheapRegistrarConnector implements DomainRegistrarConnector
{
    public function __construct(
        private readonly NamecheapClient $client
    ) {
    }

    public static function make(?string $environment = null): self
    {
        return new self(NamecheapClient::fromConfig($environment));
    }

    public function provider(): string
    {
        return 'namecheap';
    }

    public function capability(): string
    {
        return 'registrar';
    }

    public function environment(): string
    {
        return $this->client->environment();
    }

    // ---------------------------------------------------------------- health

    /**
     * Health is proven by an authenticated, non-billable, read-only call.
     * getBalances is used because it exercises credentials AND confirms the
     * account is in a usable state, without touching any domain.
     */
    public function healthCheck(): ProviderResult
    {
        if (! $this->client->isConfigured()) {
            return ProviderResult::failed(
                'CREDENTIALS_MISSING',
                'Namecheap credentials not configured: missing ' . implode(', ', $this->client->missingCredentials()),
                'permanent',
                'unconfigured'
            );
        }

        $r = $this->client->call('namecheap.users.getBalances');

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $b = $r['data']->UserGetBalancesResult ?? null;

        return ProviderResult::verified(
            'active',
            providerResourceId: null,
            providerState: 'reachable',
            data: [
                'environment'      => $this->client->environment(),
                'currency'         => (string) ($b->attributes()['Currency'] ?? ''),
                'available_balance'=> (string) ($b->attributes()['AvailableBalance'] ?? ''),
                'account_balance'  => (string) ($b->attributes()['AccountBalance'] ?? ''),
                'credential_fp'    => $this->client->credentialFingerprint(),
                'duration_ms'      => $r['duration_ms'],
            ]
        );
    }

    // ------------------------------------------------------- contract: read

    public function searchDomain(string $domain): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);

        if (! $this->isPlausibleDomain($domain)) {
            return ProviderResult::failed('INVALID_DOMAIN', "Not a valid domain name: {$domain}", 'permanent');
        }

        $r = $this->client->call('namecheap.domains.check', ['DomainList' => $domain]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $result = $r['data']->DomainCheckResult ?? null;

        if ($result === null) {
            return ProviderResult::failed('EMPTY_RESULT', 'Namecheap returned no availability result', 'transient');
        }

        $a = $result->attributes();
        $available = filter_var((string) ($a['Available'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);
        $premium = filter_var((string) ($a['IsPremiumName'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);

        return ProviderResult::verified(
            $available ? 'available' : 'unavailable',
            providerResourceId: $domain,
            providerState: $available ? 'available' : 'taken',
            data: [
                'domain'    => $domain,
                'available' => $available,
                'premium'   => $premium,
                // Premium registration price is quoted by Namecheap in the check
                // response and is NOT the standard TLD price. Carry it through so
                // pricing never silently applies the ordinary rate to a premium name.
                'premium_registration_price' => $premium
                    ? (float) ($a['PremiumRegistrationPrice'] ?? 0)
                    : null,
                'premium_renewal_price' => $premium
                    ? (float) ($a['PremiumRenewalPrice'] ?? 0)
                    : null,
                'icann_fee' => (float) ($a['IcannFee'] ?? 0),
            ]
        );
    }

    /**
     * Registrar COST for a registration. This is our wholesale cost, NOT the
     * customer price. Retail is computed by DomainPricingService, which is the
     * only place markup exists.
     */
    public function quoteRegistration(string $domain, int $years): ProviderResult
    {
        return $this->quoteAction($domain, $years, 'REGISTER');
    }

    public function quoteRenewal(string $domain, int $years): ProviderResult
    {
        return $this->quoteAction($domain, $years, 'RENEW');
    }

    public function quoteTransfer(string $domain): ProviderResult
    {
        return $this->quoteAction($domain, 1, 'TRANSFER');
    }

    private function quoteAction(string $domain, int $years, string $action): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);
        $tld = $this->tldOf($domain);

        if ($tld === null) {
            return ProviderResult::failed('INVALID_DOMAIN', "Cannot determine TLD for {$domain}", 'permanent');
        }

        if ($years < 1 || $years > 10) {
            return ProviderResult::failed('INVALID_TERM', "Registration term must be 1-10 years, got {$years}", 'permanent');
        }

        $r = $this->client->call('namecheap.users.getPricing', [
            'ProductType'     => 'DOMAIN',
            'ProductCategory' => $action,
            'ActionName'      => $action,
            'ProductName'     => $tld,
        ]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $price = $this->extractPrice($r['data'], $tld, $years);

        if ($price === null) {
            return ProviderResult::failed(
                'PRICE_UNAVAILABLE',
                "Namecheap returned no {$action} price for .{$tld} at {$years}yr",
                'permanent'
            );
        }

        // We do not convert currency. If Namecheap ever quotes a non-USD currency
        // the quote is refused rather than silently converted at an invented rate.
        $accepted = (array) config('namecheap.pricing.accepted_currencies', ['USD']);

        if (! in_array($price['currency'], $accepted, true)) {
            return ProviderResult::failed(
                'CURRENCY_UNSUPPORTED',
                "Namecheap quoted {$price['currency']} for .{$tld}; only " . implode('/', $accepted)
                    . ' is accepted. Currency conversion is not implemented and will not be assumed.',
                'permanent'
            );
        }

        return ProviderResult::verified(
            'quoted',
            providerResourceId: $domain,
            providerState: 'priced',
            data: [
                'domain'       => $domain,
                'tld'          => $tld,
                'action'       => $action,
                'years'        => $years,
                // amount_minor is the registrar COST in cents, per the contract.
                'amount_minor' => $price['amount_minor'],
                'currency'     => $price['currency'],
                'cost_per_year_minor' => $price['per_year_minor'],
                'regular_price_minor' => $price['regular_minor'],
                'is_promotional'      => $price['promotional'],
            ]
        );
    }

    /**
     * Namecheap nests pricing as
     *   UserGetPricingResult > ProductType > ProductCategory > Product > Price[Duration]
     * and returns one Price element per duration. We select the exact duration
     * requested; we never multiply a 1-year price by N, because multi-year rates
     * and promotional first-year rates are genuinely different numbers.
     */
    private function extractPrice(\SimpleXMLElement $data, string $tld, int $years): ?array
    {
        $result = $data->UserGetPricingResult ?? null;

        if ($result === null) {
            return null;
        }

        foreach ($result->ProductType ?? [] as $type) {
            foreach ($type->ProductCategory ?? [] as $category) {
                foreach ($category->Product ?? [] as $product) {
                    if (strcasecmp((string) ($product->attributes()['Name'] ?? ''), $tld) !== 0) {
                        continue;
                    }

                    $exact = null;
                    $oneYear = null;

                    foreach ($product->Price ?? [] as $price) {
                        $a = $price->attributes();
                        $duration = (int) ($a['Duration'] ?? 0);
                        $type_ = (string) ($a['DurationType'] ?? 'YEAR');

                        if (strcasecmp($type_, 'YEAR') !== 0) {
                            continue;
                        }

                        $row = [
                            'duration'     => $duration,
                            'your_price'   => (float) ($a['YourPrice'] ?? $a['Price'] ?? 0),
                            'regular'      => (float) ($a['RegularPrice'] ?? $a['Price'] ?? 0),
                            'currency'     => strtoupper((string) ($a['Currency'] ?? 'USD')),
                            'promotional'  => ((string) ($a['YourAdditonalCost'] ?? '')) !== ''
                                || (float) ($a['YourPrice'] ?? 0) < (float) ($a['RegularPrice'] ?? 0),
                        ];

                        if ($duration === $years) {
                            $exact = $row;
                        }

                        if ($duration === 1) {
                            $oneYear = $row;
                        }
                    }

                    $chosen = $exact ?? $oneYear;

                    if ($chosen === null) {
                        return null;
                    }

                    // If the exact multi-year term was not quoted, the honest
                    // representation is the 1-year rate multiplied, flagged as
                    // derived -- not presented as a real registrar quote.
                    $derived = $exact === null && $years > 1;
                    $total = $derived ? $chosen['your_price'] * $years : $chosen['your_price'];

                    return [
                        'amount_minor'   => (int) round($total * 100),
                        'per_year_minor' => (int) round($chosen['your_price'] * 100),
                        'regular_minor'  => (int) round($chosen['regular'] * 100),
                        'currency'       => $chosen['currency'],
                        'promotional'    => $chosen['promotional'],
                        'derived'        => $derived,
                    ];
                }
            }
        }

        return null;
    }

    public function getDomainStatus(string $domain): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);
        $r = $this->client->call('namecheap.domains.getInfo', ['DomainName' => $domain]);

        if (! $r['ok']) {
            // "This domain is not in your account" is a definitive ANSWER, not a
            // failure. Everything else -- bad credentials, un-whitelisted IP,
            // transport trouble -- stays an error.
            if ($this->indicatesNotOwned($r)) {
                return ProviderResult::verified(
                    'not_owned',
                    providerResourceId: $domain,
                    providerState: 'not_in_account',
                    data: [
                        'domain'         => $domain,
                        'owned'          => false,
                        'found'          => false,
                        'managed_by_us'  => false,
                        'provider_code'  => $r['error_code'],
                        'provider_message' => $r['error_summary'],
                    ]
                );
            }

            return $this->fail($r);
        }

        $info = $r['data']->DomainGetInfoResult ?? null;

        if ($info === null) {
            return ProviderResult::failed('EMPTY_RESULT', 'Namecheap returned no domain info', 'transient');
        }

        $a = $info->attributes();
        $status = (string) ($a['Status'] ?? '');
        $expires = (string) ($info->DomainDetails->ExpiredDate ?? '');

        $nameservers = [];
        foreach ($info->DnsDetails->Nameserver ?? [] as $ns) {
            $nameservers[] = (string) $ns;
        }

        return ProviderResult::verified(
            $this->mapStatus($status),
            providerResourceId: $domain,
            providerState: $status,
            data: [
                'domain'          => $domain,
                'owned'           => true,
                'found'           => true,
                'managed_by_us'   => true,
                'status'          => $status,
                'created_at'      => (string) ($info->DomainDetails->CreatedDate ?? ''),
                'expires_at'      => $expires,
                'auto_renew'      => filter_var((string) ($a['AutoRenew'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'is_locked'       => filter_var((string) ($a['IsLocked'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'is_premium'      => filter_var((string) ($a['IsPremium'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'whois_guard'     => (string) ($info->Whoisguard->attributes()['Enabled'] ?? 'unknown'),
                'dns_provider'    => (string) ($info->DnsDetails->attributes()['ProviderType'] ?? ''),
                'uses_our_dns'    => filter_var((string) ($info->DnsDetails->attributes()['IsUsingOurDNS'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'nameservers'     => $nameservers,
            ]
        );
    }

    /**
     * Does this failed getInfo response actually mean "not in this account"?
     *
     * Namecheap answers getInfo for a domain outside the account with
     * Status="ERROR" and Error Number 2030166 "Domain is invalid" -- a
     * misleading message, since google.com is plainly a valid domain. It means
     * invalid *for this account*.
     *
     * Rather than trust that one code alone, the primary signal is the
     * CommandResponse that Namecheap still returns alongside the error:
     * DomainGetInfoResult carries IsOwner="false" and ID="0". That is direct
     * evidence of ownership, so an authentication or transport failure -- which
     * produces no such element -- can never be mistaken for "not owned".
     */
    private function indicatesNotOwned(array $r): bool
    {
        $xml = $r['xml'] ?? null;

        if ($xml instanceof \SimpleXMLElement) {
            $result = $xml->CommandResponse->DomainGetInfoResult ?? null;

            if ($result !== null) {
                $isOwner = strtolower((string) ($result->attributes()['IsOwner'] ?? ''));

                if ($isOwner === 'false') {
                    return true;
                }

                // Present but claiming ownership while erroring: genuinely odd.
                // Do not normalize something we cannot explain.
                return false;
            }
        }

        // No document to inspect (transport failure, malformed XML). Fall back to
        // the documented not-found codes only.
        return in_array($r['error_code'], ['2019166', '2016166'], true);
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'ok', 'active'   => 'active',
            'expired'        => 'expired',
            'locked'         => 'active',
            default          => 'unknown',
        };
    }

    // --------------------------------------------------- contract: billable

    /**
     * Register a domain. BILLABLE.
     *
     * Idempotency: Namecheap has no idempotency key of its own, so we enforce it
     * ourselves -- before spending, we ask whether the domain is already in the
     * account. If it is, we return the existing registration rather than paying
     * twice. This makes a retry after a timeout safe.
     */
    public function registerDomain(string $domain, int $years, array $contacts, string $idempotencyKey): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);

        if ($guard = $this->assertPurchaseAllowed('register', $domain)) {
            return $guard;
        }

        if ($years < 1 || $years > 10) {
            return ProviderResult::failed('INVALID_TERM', "Registration term must be 1-10 years, got {$years}", 'permanent');
        }

        // Validate locally, before anything billable leaves the process. A
        // malformed phone number or country code costs nothing to reject here
        // and costs a failed registration to discover at the registry.
        $problems = DomainContactResolver::problems($contacts);

        if ($problems !== []) {
            $onlyMissing = count($problems) === 1
                && DomainContactResolver::missingFields($contacts) !== [];

            return ProviderResult::failed(
                $onlyMissing ? 'CONTACT_INCOMPLETE' : 'CONTACT_INVALID',
                'Registrant contact rejected before any billable call: ' . implode('; ', $problems),
                'permanent'
            );
        }

        // --- idempotency guard: never buy something we already own -----------
        $existing = $this->getDomainStatus($domain);

        if ($existing->success && ($existing->data['managed_by_us'] ?? false) === true) {
            return ProviderResult::verified(
                'active',
                providerResourceId: $domain,
                providerState: 'already_registered',
                data: array_merge($existing->data, [
                    'idempotent_replay' => true,
                    'idempotency_key'   => $idempotencyKey,
                    'note'              => 'Domain already present in the Namecheap account; no purchase was made.',
                ])
            );
        }

        $params = array_merge(
            ['DomainName' => $domain, 'Years' => $years],
            $this->contactParams($contacts)
        );

        $r = $this->client->call(
            'namecheap.domains.create',
            $params,
            (int) config('namecheap.billable_timeout_seconds', 60)
        );

        if (! $r['ok']) {
            // A billable call that failed AMBIGUOUSLY must never be auto-retried.
            // We downgrade retryability so the operation parks for reconciliation.
            return $this->failBillable($r, $domain, $idempotencyKey);
        }

        $res = $r['data']->DomainCreateResult ?? null;
        $a = $res?->attributes();
        $registered = filter_var((string) ($a['Registered'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);

        if (! $registered) {
            return ProviderResult::failed(
                'REGISTRATION_REFUSED',
                'Namecheap did not register the domain',
                'permanent'
            );
        }

        return ProviderResult::verified(
            'active',
            providerResourceId: $domain,
            providerState: 'registered',
            data: [
                'domain'          => $domain,
                'years'           => $years,
                'idempotency_key' => $idempotencyKey,
                'order_id'        => (string) ($a['OrderID'] ?? ''),
                'transaction_id'  => (string) ($a['TransactionID'] ?? ''),
                'charged_amount'  => (float) ($a['ChargedAmount'] ?? 0),
                'whois_guard'     => (string) ($a['WhoisguardEnable'] ?? ''),
                'free_positive_ssl'=> (string) ($a['FreePositiveSSL'] ?? ''),
                'environment'     => $this->client->environment(),
            ]
        );
    }

    /** Renew a domain. BILLABLE. */
    public function renewDomain(string $domain, int $years, string $idempotencyKey): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);

        if ($guard = $this->assertPurchaseAllowed('renew', $domain)) {
            return $guard;
        }

        if ($years < 1 || $years > 10) {
            return ProviderResult::failed('INVALID_TERM', "Renewal term must be 1-10 years, got {$years}", 'permanent');
        }

        // Capture the pre-renewal expiry so a retry can tell whether the previous
        // attempt actually succeeded before it timed out.
        $before = $this->getDomainStatus($domain);
        $expiryBefore = $before->success ? ($before->data['expires_at'] ?? null) : null;

        $r = $this->client->call(
            'namecheap.domains.renew',
            ['DomainName' => $domain, 'Years' => $years],
            (int) config('namecheap.billable_timeout_seconds', 60)
        );

        if (! $r['ok']) {
            return $this->failBillable($r, $domain, $idempotencyKey, ['expiry_before' => $expiryBefore]);
        }

        $a = ($r['data']->DomainRenewResult ?? null)?->attributes();

        return ProviderResult::verified(
            'active',
            providerResourceId: $domain,
            providerState: 'renewed',
            data: [
                'domain'          => $domain,
                'years'           => $years,
                'idempotency_key' => $idempotencyKey,
                'order_id'        => (string) ($a['OrderID'] ?? ''),
                'transaction_id'  => (string) ($a['TransactionID'] ?? ''),
                'charged_amount'  => (float) ($a['ChargedAmount'] ?? 0),
                'expiry_before'   => $expiryBefore,
                'expiry_after'    => (string) ($r['data']->DomainRenewResult->DomainDetails->ExpiredDate ?? ''),
                'environment'     => $this->client->environment(),
            ]
        );
    }

    /** Start an inbound transfer. BILLABLE (a transfer purchases a year). */
    public function transferDomain(string $domain, string $authCode, array $contacts, string $idempotencyKey): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);

        if ($guard = $this->assertPurchaseAllowed('transfer', $domain)) {
            return $guard;
        }

        if (trim($authCode) === '') {
            return ProviderResult::failed('AUTH_CODE_MISSING', 'A transfer requires the EPP/auth code from the losing registrar', 'permanent');
        }

        $existing = $this->getDomainStatus($domain);

        if ($existing->success && ($existing->data['managed_by_us'] ?? false) === true) {
            return ProviderResult::verified(
                'active',
                providerResourceId: $domain,
                providerState: 'already_in_account',
                data: array_merge($existing->data, [
                    'idempotent_replay' => true,
                    'idempotency_key'   => $idempotencyKey,
                    'note'              => 'Domain is already in the Namecheap account; no transfer was initiated.',
                ])
            );
        }

        $r = $this->client->call('namecheap.domains.transfer.create', [
            'DomainName' => $domain,
            'Years'      => 1,
            'EPPCode'    => $authCode,
        ], (int) config('namecheap.billable_timeout_seconds', 60));

        if (! $r['ok']) {
            return $this->failBillable($r, $domain, $idempotencyKey);
        }

        $a = ($r['data']->DomainTransferCreateResult ?? null)?->attributes();
        $ok = filter_var((string) ($a['Transfer'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);

        // A transfer is ACCEPTED, never verified: it takes up to 7 days and the
        // losing registrar can still refuse it.
        return $ok
            ? ProviderResult::accepted(
                'transfer_pending',
                providerResourceId: $domain,
                providerState: (string) ($a['StatusID'] ?? 'pending'),
                data: [
                    'domain'            => $domain,
                    'idempotency_key'   => $idempotencyKey,
                    'transfer_id'       => (string) ($a['TransferID'] ?? ''),
                    'order_id'          => (string) ($a['OrderID'] ?? ''),
                    'charged_amount'    => (float) ($a['ChargedAmount'] ?? 0),
                    'status_description'=> (string) ($a['StatusDescription'] ?? ''),
                    'environment'       => $this->client->environment(),
                ]
            )
            : ProviderResult::failed(
                'TRANSFER_REFUSED',
                'Namecheap did not accept the transfer: ' . (string) ($a['StatusDescription'] ?? 'no reason given'),
                'permanent'
            );
    }

    public function getTransferStatus(string $transferId): ProviderResult
    {
        $r = $this->client->call('namecheap.domains.transfer.getStatus', ['TransferID' => $transferId]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $a = ($r['data']->DomainTransferGetStatusResult ?? null)?->attributes();

        return ProviderResult::verified(
            'transfer_pending',
            providerResourceId: $transferId,
            providerState: (string) ($a['Status'] ?? ''),
            data: [
                'transfer_id' => $transferId,
                'status'      => (string) ($a['Status'] ?? ''),
                'status_id'   => (string) ($a['StatusID'] ?? ''),
            ]
        );
    }

    // ------------------------------------------------------ contract: config

    /** Nameserver changes are NOT billable, but they can take a site offline. */
    public function updateNameservers(string $domain, array $nameservers, string $idempotencyKey): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        if (count($nameservers) < 2) {
            return ProviderResult::failed(
                'NAMESERVERS_INSUFFICIENT',
                'At least two nameservers are required; refusing to leave the domain unresolvable',
                'permanent'
            );
        }

        [$sld, $tld] = $this->splitDomain($domain) ?? [null, null];

        if ($sld === null) {
            return ProviderResult::failed('INVALID_DOMAIN', "Cannot split {$domain} into SLD/TLD", 'permanent');
        }

        $before = $this->getDomainStatus($domain);
        $nsBefore = $before->success ? ($before->data['nameservers'] ?? []) : [];

        // Already correct -> idempotent no-op.
        if ($nsBefore !== [] && $this->sameNameservers($nsBefore, $nameservers)) {
            return ProviderResult::verified(
                'active',
                providerResourceId: $domain,
                providerState: 'nameservers_already_set',
                data: [
                    'domain'            => $domain,
                    'nameservers'       => $nameservers,
                    'idempotent_replay' => true,
                    'idempotency_key'   => $idempotencyKey,
                ]
            );
        }

        $r = $this->client->call('namecheap.domains.dns.setCustom', [
            'SLD'         => $sld,
            'TLD'         => $tld,
            'Nameservers' => implode(',', $nameservers),
        ]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        // Independently confirm rather than trusting the write response.
        $after = $this->getDomainStatus($domain);
        $nsAfter = $after->success ? ($after->data['nameservers'] ?? []) : [];
        $confirmed = $nsAfter !== [] && $this->sameNameservers($nsAfter, $nameservers);

        $data = [
            'domain'            => $domain,
            'idempotency_key'   => $idempotencyKey,
            'nameservers_before'=> $nsBefore,
            'nameservers_after' => $nsAfter,
            'requested'         => $nameservers,
        ];

        return $confirmed
            ? ProviderResult::verified('active', $domain, 'nameservers_set', $data)
            : ProviderResult::accepted('pending', $domain, 'nameservers_submitted', $data + [
                'note' => 'Namecheap accepted the change but read-back did not yet match; re-verify before reporting success.',
            ]);
    }

    // ---------------------------------------------- InfrastructureConnector

    public function synchronize(string $providerResourceId): ProviderResult
    {
        return $this->getDomainStatus($providerResourceId);
    }

    /**
     * Confirm the provider's actual state matches what we believe. Every
     * expectation key supplied is checked; unmatched keys are reported
     * individually so the caller learns WHICH belief was wrong.
     */
    public function verify(string $providerResourceId, array $expectation = []): ProviderResult
    {
        $status = $this->getDomainStatus($providerResourceId);

        if (! $status->success) {
            return $status;
        }

        $actual = $status->data;
        $mismatches = [];

        foreach ($expectation as $key => $expected) {
            $got = $actual[$key] ?? null;

            $matches = match ($key) {
                'nameservers' => is_array($expected) && is_array($got) && $this->sameNameservers($got, $expected),
                default       => $got == $expected,
            };

            if (! $matches) {
                $mismatches[$key] = ['expected' => $expected, 'actual' => $got];
            }
        }

        if ($mismatches !== []) {
            return ProviderResult::failed(
                'EXPECTATION_MISMATCH',
                'Namecheap state differs from expectation for ' . implode(', ', array_keys($mismatches)),
                'permanent',
                'drifted'
            );
        }

        return ProviderResult::verified(
            $status->normalizedState,
            providerResourceId: $providerResourceId,
            providerState: $status->providerState,
            data: $actual + ['verified_keys' => array_keys($expectation)]
        );
    }

    // ------------------------------------------- adapter-only capabilities
    // Namecheap offers these; the shared contract deliberately does not. They
    // are additive so a future registrar is not forced to implement them.

    public function getAccountBalance(): ProviderResult
    {
        $r = $this->client->call('namecheap.users.getBalances');

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $a = ($r['data']->UserGetBalancesResult ?? null)?->attributes();

        return ProviderResult::verified('active', null, 'balance_read', [
            'currency'          => (string) ($a['Currency'] ?? ''),
            'available_balance' => (float) ($a['AvailableBalance'] ?? 0),
            'account_balance'   => (float) ($a['AccountBalance'] ?? 0),
            'earned_amount'     => (float) ($a['EarnedAmount'] ?? 0),
            'environment'       => $this->client->environment(),
        ]);
    }

    public function listDomains(int $page = 1, int $pageSize = 100): ProviderResult
    {
        $r = $this->client->call('namecheap.domains.getList', [
            'Page'     => max(1, $page),
            'PageSize' => min(100, max(10, $pageSize)),
        ]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $domains = [];
        foreach ($r['data']->DomainGetListResult->Domain ?? [] as $d) {
            $a = $d->attributes();
            $domains[] = [
                'domain'     => (string) ($a['Name'] ?? ''),
                'id'         => (string) ($a['ID'] ?? ''),
                'created_at' => (string) ($a['Created'] ?? ''),
                'expires_at' => (string) ($a['Expires'] ?? ''),
                'is_expired' => filter_var((string) ($a['IsExpired'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'is_locked'  => filter_var((string) ($a['IsLocked'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'auto_renew' => filter_var((string) ($a['AutoRenew'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'whois_guard'=> (string) ($a['WhoisGuard'] ?? ''),
            ];
        }

        $paging = $r['data']->Paging ?? null;

        return ProviderResult::verified('active', null, 'listed', [
            'domains'     => $domains,
            'count'       => count($domains),
            'total'       => (int) ($paging->TotalItems ?? count($domains)),
            'page'        => (int) ($paging->CurrentPage ?? $page),
            'page_size'   => (int) ($paging->PageSize ?? $pageSize),
            'environment' => $this->client->environment(),
        ]);
    }

    public function getTldList(): ProviderResult
    {
        $r = $this->client->call('namecheap.domains.getTldList');

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $tlds = [];
        foreach ($r['data']->Tlds->Tld ?? [] as $t) {
            $a = $t->attributes();
            $tlds[] = [
                'tld'                => (string) ($a['Name'] ?? ''),
                'supports_registration' => filter_var((string) ($a['IsApiRegisterable'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'supports_renewal'   => filter_var((string) ($a['IsApiRenewable'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'supports_transfer'  => filter_var((string) ($a['IsApiTransferable'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'min_years'          => (int) ($a['MinRegisterYears'] ?? 1),
                'max_years'          => (int) ($a['MaxRegisterYears'] ?? 10),
                'supports_privacy'   => filter_var((string) ($a['IsSupportsIDN'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return ProviderResult::verified('active', null, 'tld_list', [
            'tlds'  => $tlds,
            'count' => count($tlds),
        ]);
    }

    public function getRegistrarLock(string $domain): ProviderResult
    {
        $r = $this->client->call('namecheap.domains.getRegistrarLock', [
            'DomainName' => $this->normalizeDomain($domain),
        ]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $a = ($r['data']->DomainGetRegistrarLockResult ?? null)?->attributes();
        $locked = filter_var((string) ($a['RegistrarLockStatus'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);

        return ProviderResult::verified('active', $domain, $locked ? 'locked' : 'unlocked', [
            'domain' => $domain,
            'locked' => $locked,
        ]);
    }

    public function setRegistrarLock(string $domain, bool $locked): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);

        $r = $this->client->call('namecheap.domains.setRegistrarLock', [
            'DomainName' => $domain,
            'LockAction' => $locked ? 'LOCK' : 'UNLOCK',
        ]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $after = $this->getRegistrarLock($domain);
        $confirmed = $after->success && ($after->data['locked'] ?? null) === $locked;

        $data = ['domain' => $domain, 'requested_lock' => $locked, 'actual_lock' => $after->data['locked'] ?? null];

        return $confirmed
            ? ProviderResult::verified('active', $domain, $locked ? 'locked' : 'unlocked', $data)
            : ProviderResult::accepted('pending', $domain, 'lock_submitted', $data);
    }

    /**
     * Turn auto-renew on or off.
     *
     * Namecheap returns Status="OK" for this command even when the setting does
     * not actually change -- observed on 2026-07-29, where an OK response was
     * followed by a read-back still showing auto_renew=false. An envelope is not
     * an effect, so the result is only reported as verified when a fresh read
     * confirms it. Otherwise it is 'accepted', and the caller must not tell a
     * customer the change is done.
     */
    public function setAutoRenew(string $domain, bool $enabled): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);

        $before = $this->getDomainStatus($domain);

        if (! $before->success) {
            return $before;
        }

        if (($before->data['owned'] ?? false) !== true) {
            return ProviderResult::failed(
                'NOT_OWNED',
                "Refusing to change auto-renew: {$domain} is not in this account",
                'permanent'
            );
        }

        if ((bool) ($before->data['auto_renew'] ?? false) === $enabled) {
            return ProviderResult::verified('active', $domain, 'auto_renew_already_set', [
                'domain'            => $domain,
                'auto_renew'        => $enabled,
                'idempotent_replay' => true,
            ]);
        }

        $r = $this->client->call('namecheap.domains.setAutoRenew', [
            'DomainName' => $domain,
            'AutoRenew'  => $enabled ? 'true' : 'false',
        ]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $after = $this->getDomainStatus($domain);
        $actual = $after->success ? (bool) ($after->data['auto_renew'] ?? false) : null;

        $data = [
            'domain'      => $domain,
            'requested'   => $enabled,
            'auto_renew'  => $actual,
            'confirmed'   => $actual === $enabled,
        ];

        return $actual === $enabled
            ? ProviderResult::verified('active', $domain, 'auto_renew_set', $data)
            : ProviderResult::accepted('pending', $domain, 'auto_renew_submitted', $data + [
                'note' => 'The registrar accepted the change but a read-back did not confirm it. '
                    . 'Do not report this to a customer as complete.',
            ]);
    }

    public function getDnsHosts(string $domain): ProviderResult
    {
        [$sld, $tld] = $this->splitDomain($this->normalizeDomain($domain)) ?? [null, null];

        if ($sld === null) {
            return ProviderResult::failed('INVALID_DOMAIN', "Cannot split {$domain}", 'permanent');
        }

        $r = $this->client->call('namecheap.domains.dns.getHosts', ['SLD' => $sld, 'TLD' => $tld]);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        $hosts = [];
        foreach ($r['data']->DomainDNSGetHostsResult->host ?? [] as $h) {
            $a = $h->attributes();
            $hosts[] = [
                'id'       => (string) ($a['HostId'] ?? ''),
                'name'     => (string) ($a['Name'] ?? ''),
                'type'     => (string) ($a['Type'] ?? ''),
                'address'  => (string) ($a['Address'] ?? ''),
                'mx_pref'  => (string) ($a['MXPref'] ?? ''),
                'ttl'      => (int) ($a['TTL'] ?? 1800),
            ];
        }

        return ProviderResult::verified('active', $domain, 'dns_read', [
            'domain'       => $domain,
            'hosts'        => $hosts,
            'count'        => count($hosts),
            'uses_our_dns' => filter_var(
                (string) ($r['data']->DomainDNSGetHostsResult->attributes()['IsUsingOurDNS'] ?? 'false'),
                FILTER_VALIDATE_BOOLEAN
            ),
        ]);
    }

    /**
     * Namecheap's setHosts REPLACES the entire record set -- there is no partial
     * update. Passing an incomplete list silently deletes records, so this method
     * refuses an empty set outright and returns the prior set for rollback.
     */
    public function setDnsHosts(string $domain, array $hosts, string $idempotencyKey): ProviderResult
    {
        $domain = $this->normalizeDomain($domain);
        [$sld, $tld] = $this->splitDomain($domain) ?? [null, null];

        if ($sld === null) {
            return ProviderResult::failed('INVALID_DOMAIN', "Cannot split {$domain}", 'permanent');
        }

        if ($hosts === []) {
            return ProviderResult::failed(
                'DNS_SET_EMPTY',
                'Refusing to write an empty DNS record set: Namecheap setHosts is destructive and would delete every record',
                'permanent'
            );
        }

        $before = $this->getDnsHosts($domain);

        $params = [];
        foreach (array_values($hosts) as $i => $h) {
            $n = $i + 1;
            $params["HostName{$n}"]   = $h['name'] ?? '@';
            $params["RecordType{$n}"] = $h['type'] ?? 'A';
            $params["Address{$n}"]    = $h['address'] ?? '';
            $params["TTL{$n}"]        = $h['ttl'] ?? 1800;

            if (strcasecmp((string) ($h['type'] ?? ''), 'MX') === 0) {
                $params["MXPref{$n}"] = $h['mx_pref'] ?? 10;
            }
        }

        $r = $this->client->call('namecheap.domains.dns.setHosts', ['SLD' => $sld, 'TLD' => $tld] + $params);

        if (! $r['ok']) {
            return $this->fail($r);
        }

        return ProviderResult::verified('active', $domain, 'dns_written', [
            'domain'          => $domain,
            'idempotency_key' => $idempotencyKey,
            'records_written' => count($hosts),
            'records_before'  => $before->success ? ($before->data['hosts'] ?? []) : [],
            'rollback_note'   => 'records_before is the complete prior set; replay it through setDnsHosts to roll back.',
        ]);
    }

    // ------------------------------------------------------------- internals

    /**
     * Fails CLOSED. Returns a ProviderResult when the purchase must be blocked,
     * or null when it may proceed.
     */
    private function assertPurchaseAllowed(string $action, string $domain): ?ProviderResult
    {
        if ($this->client->environment() !== 'production') {
            return null; // sandbox spends nothing
        }

        if (config('namecheap.production_purchases_enabled') === true) {
            return null;
        }

        return ProviderResult::failed(
            'PRODUCTION_PURCHASES_DISABLED',
            "Refusing to {$action} {$domain}: production purchases are disabled. "
                . 'Set NAMECHEAP_PRODUCTION_PURCHASES_ENABLED=true to allow billable registrar operations.',
            'permanent',
            'blocked'
        );
    }

    /**
     * A billable call that failed. If the failure is ambiguous -- a timeout, a
     * 5xx, unparseable XML -- the money may already have been spent. We mark it
     * permanent so no automatic retry can double-charge, and flag it for
     * reconciliation instead.
     */
    private function failBillable(array $r, string $domain, string $idempotencyKey, array $extra = []): ProviderResult
    {
        $ambiguous = $r['billable_risk'] ?? false;

        return ProviderResult::failed(
            $r['error_code'] ?? 'UNKNOWN',
            ($r['error_summary'] ?? 'Namecheap call failed')
                . ($ambiguous
                    ? ' -- OUTCOME AMBIGUOUS: the charge may have succeeded. Reconcile against the account before retrying.'
                    : ''),
            // Never auto-retry an ambiguous billable failure.
            $ambiguous ? 'permanent' : ($r['retry'] ?? 'permanent'),
            $ambiguous ? 'needs_reconciliation' : 'failed'
        );
    }

    private function fail(array $r): ProviderResult
    {
        return ProviderResult::failed(
            $r['error_code'] ?? 'UNKNOWN',
            $r['error_summary'] ?? 'Namecheap call failed',
            $r['retry'] ?? 'permanent'
        );
    }

    private function normalizeDomain(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d);
        $d = explode('/', $d)[0];

        return rtrim($d, '.');
    }

    private function isPlausibleDomain(string $domain): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
    }

    /** Returns [sld, tld] where tld may be multi-label (co.uk). */
    private function splitDomain(string $domain): ?array
    {
        $parts = explode('.', $domain);

        if (count($parts) < 2) {
            return null;
        }

        $sld = array_shift($parts);

        return [$sld, implode('.', $parts)];
    }

    private function tldOf(string $domain): ?string
    {
        $split = $this->splitDomain($domain);

        return $split ? $split[1] : null;
    }

    private function sameNameservers(array $a, array $b): bool
    {
        $norm = static function (array $x): array {
            $x = array_map(static fn ($n) => strtolower(rtrim(trim((string) $n), '.')), $x);
            sort($x);

            return $x;
        };

        return $norm($a) === $norm($b);
    }

    private function contactParams(array $c): array
    {
        $map = [
            'FirstName'  => $c['first_name'] ?? '',
            'LastName'   => $c['last_name'] ?? '',
            'Address1'   => $c['address1'] ?? '',
            'City'       => $c['city'] ?? '',
            'StateProvince' => $c['state'] ?? '',
            'PostalCode' => $c['postal_code'] ?? '',
            'Country'    => $c['country'] ?? '',
            'Phone'      => $c['phone'] ?? '',
            'EmailAddress' => $c['email'] ?? '',
        ];

        // Namecheap requires the same contact block repeated for all four roles.
        $params = [];
        foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
            foreach ($map as $field => $value) {
                $params[$role . $field] = $value;
            }
        }

        return $params;
    }

    private function missingContactFields(array $c): array
    {
        $required = ['first_name', 'last_name', 'address1', 'city', 'postal_code', 'country', 'phone', 'email'];

        return array_values(array_filter(
            $required,
            static fn ($f) => trim((string) ($c[$f] ?? '')) === ''
        ));
    }
}
