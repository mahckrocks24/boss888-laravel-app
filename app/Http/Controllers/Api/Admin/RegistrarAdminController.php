<?php

namespace App\Http\Controllers\Api\Admin;

use App\Connectors\Infrastructure\Namecheap\NamecheapClient;
use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Services\Domains\DomainPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only admin visibility for the domain registrar.
 *
 * Every figure follows the measured/BLOCKED contract: a value is either
 * genuinely measured, or explicitly marked unavailable with a reason.
 * A blocked figure is NEVER rendered as zero.
 *
 * This controller performs no billable operation. There is no route here that
 * can register, renew or transfer a domain.
 */
class RegistrarAdminController
{
    /** Configuration and connectivity status. Makes at most one read-only API call. */
    public function status(): JsonResponse
    {
        $client = NamecheapClient::fromConfig();
        $env = $client->environment();
        $configured = $client->isConfigured();

        $payload = [
            'provider'    => 'namecheap',
            'capability'  => 'registrar',
            'environment' => $env,
            'endpoint'    => (string) config("namecheap.endpoints.{$env}"),
            'client_ip'   => (string) config('namecheap.client_ip'),

            'credentials' => [
                'configured'  => $configured,
                // Identifies WHICH key is loaded without revealing any part of it.
                'fingerprint' => $client->credentialFingerprint(),
                'missing'     => $client->missingCredentials(),
            ],

            'safety' => [
                'production_purchases_enabled' => (bool) config('namecheap.production_purchases_enabled'),
                'billable_operations_blocked'  => $env === 'production'
                    && config('namecheap.production_purchases_enabled') !== true,
                'note' => 'register/renew/transfer fail closed unless production purchases are explicitly enabled.',
            ],

            'pricing' => [
                'markup_percent'     => (float) config('namecheap.pricing.markup_percent'),
                'markup_minimum_usd' => (float) config('namecheap.pricing.markup_minimum_usd'),
                'currency'           => 'USD',
                'tax_treatment'      => 'exclusive',
                'currency_conversion'=> 'not implemented',
            ],
        ];

        if (! $configured) {
            $payload['connectivity'] = [
                'available'      => false,
                'blocked_reason' => 'Namecheap credentials are not configured: missing '
                    . implode(', ', $client->missingCredentials()),
                'remediation'    => 'Run /root/namecheap-set-credentials.sh on the server.',
            ];
            $payload['account'] = ['available' => false, 'blocked_reason' => 'No credentials'];

            return response()->json($payload);
        }

        $health = NamecheapRegistrarConnector::make()->healthCheck();

        $payload['connectivity'] = $health->success
            ? ['available' => true, 'state' => $health->normalizedState, 'duration_ms' => $health->data['duration_ms'] ?? null]
            : [
                'available'      => false,
                'blocked_reason' => $health->errorSummary,
                'error_code'     => $health->errorCode,
                'remediation'    => $health->errorCode === '1011150'
                    ? 'The calling IP is not whitelisted at Namecheap. Add ' . config('namecheap.client_ip') . '.'
                    : ($health->errorCode === '1011102'
                        ? 'The API key is invalid or API access is not enabled on the Namecheap account.'
                        : null),
            ];

        $payload['account'] = $health->success
            ? [
                'available'         => true,
                'currency'          => $health->data['currency'] ?? null,
                'available_balance' => $health->data['available_balance'] ?? null,
                'account_balance'   => $health->data['account_balance'] ?? null,
                'note'              => 'Account balance is the registrar wallet. It is not spent during sandbox testing.',
            ]
            : ['available' => false, 'blocked_reason' => $health->errorSummary];

        return response()->json($payload);
    }

    /** Domains held in the registrar account. Read-only. */
    public function domains(Request $request): JsonResponse
    {
        $client = NamecheapClient::fromConfig();

        if (! $client->isConfigured()) {
            return response()->json([
                'available'      => false,
                'blocked_reason' => 'Namecheap credentials are not configured',
                'domains'        => [],
                // Deliberately null, not 0: we do not know the count.
                'count'          => null,
            ]);
        }

        $r = NamecheapRegistrarConnector::make()->listDomains(
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 100)
        );

        if (! $r->success) {
            return response()->json([
                'available'      => false,
                'blocked_reason' => $r->errorSummary,
                'error_code'     => $r->errorCode,
                'domains'        => [],
                'count'          => null,
            ]);
        }

        return response()->json([
            'available'   => true,
            'domains'     => $r->data['domains'],
            'count'       => $r->data['count'],
            'total'       => $r->data['total'],
            'page'        => $r->data['page'],
            'environment' => $r->data['environment'],
        ]);
    }

    /**
     * Availability + retail price for a single domain. Read-only; spends nothing.
     * This is the screen an operator uses to sanity-check pricing before selling.
     */
    public function quote(Request $request): JsonResponse
    {
        $domain = trim((string) $request->query('domain', ''));
        $years = max(1, min(10, (int) $request->query('years', 1)));

        if ($domain === '') {
            return response()->json(['available' => false, 'blocked_reason' => 'A domain query parameter is required'], 422);
        }

        return response()->json(
            DomainPricingService::make()->searchWithPricing($domain, $years)
        );
    }
}
