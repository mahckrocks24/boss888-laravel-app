<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SyncCustomerDomainJob;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Services\Domains\DomainCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing domain commerce: search, buy, and manage owned domains.
 *
 * BRANDING
 * Everything returned here is LevelUp Growth. The internal engine name and the
 * upstream registrar are never surfaced to a customer -- the customer is buying
 * from LevelUp Growth, and who we buy from is our commercial business.
 *
 * TENANCY
 * workspace_id comes from the authenticated token via JwtAuthMiddleware, never
 * from user input. Every read and write is scoped to it. A customer cannot
 * reference another workspace's order or domain by guessing an id.
 */
class CustomerDomainController
{
    private const BRAND = 'LevelUp Growth';

    /** The workspace the caller is authenticated for. Never trusts the request body. */
    private function workspaceId(Request $request): ?int
    {
        $id = $request->attributes->get('workspace_id');

        return $id === null ? null : (int) $id;
    }

    private function denied(): JsonResponse
    {
        return response()->json(['error' => 'No workspace context for this request.'], 403);
    }

    // -------------------------------------------------------------- search --

    public function search(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $domain = trim((string) $request->query('domain', ''));
        $years = (int) $request->query('years', 1);

        if ($domain === '') {
            return response()->json(['error' => 'Enter a domain name to search.'], 422);
        }

        $result = DomainCommerceService::make()->search($domain, $years);

        return response()->json($this->customerSafe($result));
    }

    public function searchTransfer(Request $request): JsonResponse
    {
        if ($this->workspaceId($request) === null) {
            return $this->denied();
        }

        $domain = trim((string) $request->query('domain', ''));

        if ($domain === '') {
            return response()->json(['error' => 'Enter a domain name.'], 422);
        }

        return response()->json($this->customerSafe(
            DomainCommerceService::make()->searchTransfer($domain)
        ));
    }

    // -------------------------------------------------------------- orders --

    public function createOrder(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $cart = $request->input('items', []);

        if (! is_array($cart)) {
            return response()->json(['error' => 'Cart must be a list of domains.'], 422);
        }

        $result = DomainCommerceService::make()->createOrder(
            $wsId,
            $request->user()?->id,
            $cart,
            $request->input('idempotency_key')
        );

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result, 201);
    }

    public function checkout(Request $request, int $orderId): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $order = DomainOrder::forWorkspace($wsId)->find($orderId);

        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        $result = DomainCommerceService::make()->startCheckout($order, $request->user()?->id);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function orders(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $svc = DomainCommerceService::make();

        $orders = DomainOrder::forWorkspace($wsId)
            ->with('items')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (DomainOrder $o) => $svc->presentOrder($o));

        return response()->json(['orders' => $orders, 'count' => $orders->count()]);
    }

    public function order(Request $request, int $orderId): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $order = DomainOrder::forWorkspace($wsId)->with('items')->find($orderId);

        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        return response()->json(['order' => DomainCommerceService::make()->presentOrder($order)]);
    }

    // ------------------------------------------------------ owned domains --

    public function index(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $domains = CustomerDomain::forWorkspace($wsId)
            ->orderBy('domain')
            ->get()
            ->map(fn (CustomerDomain $d) => $this->presentDomain($d));

        return response()->json([
            'domains'  => $domains,
            'count'    => $domains->count(),
            'provider' => self::BRAND,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $domain = CustomerDomain::forWorkspace($wsId)->find($id);

        if ($domain === null) {
            return response()->json(['error' => 'Domain not found.'], 404);
        }

        return response()->json(['domain' => $this->presentDomain($domain, true)]);
    }

    /** Ask for a fresh read from the registrar. Read-only; spends nothing. */
    public function sync(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $domain = CustomerDomain::forWorkspace($wsId)->find($id);

        if ($domain === null) {
            return response()->json(['error' => 'Domain not found.'], 404);
        }

        SyncCustomerDomainJob::dispatch($domain->domain, $request->user()?->id)->onQueue('tasks');

        return response()->json([
            'queued'  => true,
            'message' => 'Refreshing domain details. This usually takes a few seconds.',
        ]);
    }

    /**
     * Customer-friendly activity timeline, built from the shared infra_events
     * table. No new audit system.
     *
     * Only an allow-listed set of events is exposed, each re-worded for a
     * customer. Internal events (provider error codes, refund bookkeeping,
     * retry attempts) are deliberately withheld -- a customer does not need to
     * see that our third attempt succeeded, only that their domain is ready.
     */
    public function timeline(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $domain = CustomerDomain::forWorkspace($wsId)->find($id);

        if ($domain === null) {
            return response()->json(['error' => 'Domain not found.'], 404);
        }

        $itemId = $domain->domain_order_item_id;
        $orderId = $itemId ? \App\Models\DomainOrderItem::where('id', $itemId)->value('domain_order_id') : null;

        $events = \Illuminate\Support\Facades\DB::table('infra_events')
            ->where('source', 'domain_commerce')
            ->where('workspace_id', $wsId)
            ->where(function ($q) use ($orderId, $itemId, $domain) {
                if ($orderId) {
                    $q->orWhere(fn ($x) => $x->where('owner_type', 'domain_order')->where('owner_id', $orderId));
                }
                if ($itemId) {
                    $q->orWhere(fn ($x) => $x->where('owner_type', 'domain_order_item')->where('owner_id', $itemId));
                }
                $q->orWhere('provider_resource_id', $domain->domain);
            })
            ->orderBy('id')
            ->get();

        $steps = [];
        $seen = [];

        foreach ($events as $e) {
            $mapped = $this->customerEvent($e->event);

            if ($mapped === null) {
                continue;   // internal-only event
            }

            // Collapse repeats: three registration attempts are one step to a
            // customer, stamped with the first time it started.
            if (isset($seen[$mapped['key']])) {
                continue;
            }

            $seen[$mapped['key']] = true;

            $steps[] = [
                'key'   => $mapped['key'],
                'label' => $mapped['label'],
                'detail'=> $mapped['detail'],
                'at'    => $e->created_at,
                'state' => 'done',
            ];
        }

        // Show the remaining steps as upcoming, so the customer sees the whole
        // journey rather than only what has happened so far.
        $expected = $this->timelineTemplate();
        $doneKeys = array_column($steps, 'key');

        foreach ($expected as $tpl) {
            if (! in_array($tpl['key'], $doneKeys, true)) {
                $steps[] = $tpl + ['at' => null, 'state' => 'pending'];
            }
        }

        // Preserve template order.
        $order = array_column($expected, 'key');
        usort($steps, fn ($a, $b) => array_search($a['key'], $order) <=> array_search($b['key'], $order));

        $complete = $domain->status === CustomerDomain::STATUS_ACTIVE;

        return response()->json([
            'domain'   => $domain->domain,
            'complete' => $complete,
            'steps'    => $steps,
        ]);
    }

    /** The canonical customer journey. */
    private function timelineTemplate(): array
    {
        return [
            ['key' => 'ordered',    'label' => 'Order placed',        'detail' => 'Your domain order was created.'],
            ['key' => 'paid',       'label' => 'Payment received',    'detail' => 'Payment confirmed by our payment provider.'],
            ['key' => 'queued',     'label' => 'Registration queued', 'detail' => 'Your domain was queued for registration.'],
            ['key' => 'registering','label' => 'Registering domain',  'detail' => 'We are securing your domain name.'],
            ['key' => 'registered', 'label' => 'Registration complete','detail' => 'Your domain is registered to you.'],
            ['key' => 'dns',        'label' => 'DNS configured',      'detail' => 'Name servers are set and your domain is ready to use.'],
        ];
    }

    /**
     * Internal event -> customer step. Anything absent from this map is never
     * shown: that is the allow-list that keeps provider codes and refund
     * bookkeeping off a customer screen.
     */
    private function customerEvent(string $event): ?array
    {
        $tpl = collect($this->timelineTemplate())->keyBy('key');

        $map = [
            'domain.order.created'          => 'ordered',
            'domain.order.paid'             => 'paid',
            'domain.order.provisioning'     => 'queued',
            'domain.registration.attempt'   => 'registering',
            'domain.registration.succeeded' => 'registered',
            'domain.synced'                 => 'dns',
        ];

        $key = $map[$event] ?? null;

        return $key === null ? null : $tpl[$key];
    }

    /**
     * Turn auto-renew on or off.
     *
     * Reports honestly: if the registrar accepts the change but a read-back does
     * not confirm it, the customer is told it is still being applied rather than
     * being shown a toggle that lies.
     */
    public function setAutoRenew(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        if ($wsId === null) {
            return $this->denied();
        }

        $domain = CustomerDomain::forWorkspace($wsId)->find($id);

        if ($domain === null) {
            return response()->json(['error' => 'Domain not found.'], 404);
        }

        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);

        $result = \App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector::make()
            ->setAutoRenew($domain->domain, $enabled);

        if (! $result->success) {
            return response()->json([
                'ok'      => false,
                'message' => 'We could not change auto-renew just now. Please try again shortly.',
            ], 422);
        }

        $actual = $result->data['auto_renew'] ?? null;

        if ($actual !== null) {
            $domain->update(['auto_renew' => (bool) $actual, 'last_synced_at' => now()]);
        }

        return response()->json([
            'ok'         => true,
            'auto_renew' => (bool) ($actual ?? $enabled),
            'confirmed'  => $result->verified,
            'message'    => $result->verified
                ? ($enabled ? 'Auto-renew is on.' : 'Auto-renew is off.')
                : 'Your change was submitted and is still being applied. We will confirm shortly.',
        ]);
    }

    // ------------------------------------------------------------ present --

    private function presentDomain(CustomerDomain $d, bool $detailed = false): array
    {
        $out = [
            'id'            => $d->id,
            'domain'        => $d->domain,
            'status'        => $d->status,
            'status_label'  => $d->customerStatusLabel(),
            'registered_on' => $d->registered_at?->toDateString(),
            'expires_on'    => $d->expires_at?->toDateString(),
            'renewal_date'  => $d->renewalDate(),
            'days_until_expiry' => $d->daysUntilExpiry(),
            'expiring_soon' => $d->isExpiringSoon(),
            'auto_renew'    => (bool) $d->auto_renew,
            'transfer_lock' => (bool) $d->is_locked,
            'privacy'       => (bool) $d->whois_privacy,
            'nameservers'   => $d->nameservers(),
            'dns_managed_by'=> ($d->provider_metadata_json['uses_our_dns'] ?? false) ? self::BRAND : 'Custom nameservers',
            // The customer's registrar of record is LevelUp Growth. The upstream
            // wholesale provider is deliberately not disclosed.
            'registrar'     => self::BRAND,
            'last_updated'  => $d->last_synced_at?->toIso8601String(),
        ];

        if ($detailed) {
            $out['order_reference'] = $d->domain_order_item_id ? 'LVL-' . $d->domain_order_item_id : null;
            $out['support_contact'] = 'support@levelupgrowth.io';
            $out['help_center']     = self::BRAND . ' Help Center';
        }

        return $out;
    }

    /**
     * Strip anything internal from a service payload before it reaches a
     * customer: wholesale cost, markup, provider identity, provider error codes.
     */
    private function customerSafe(array $payload): array
    {
        foreach (['cost_minor', 'cost', 'markup_minor', 'markup', 'markup_basis', 'margin_minor',
                  'margin_percent', 'source', 'environment', 'error_code', 'cost_derived',
                  'provider_code', 'provider_message'] as $internal) {
            unset($payload[$internal]);
        }

        if (isset($payload['renewal']) && is_array($payload['renewal'])) {
            foreach (['cost_minor', 'markup_minor'] as $internal) {
                unset($payload['renewal'][$internal]);
            }
        }

        // A blocked search should tell the customer something useful, not leak
        // the provider's own error text.
        if (($payload['blocked'] ?? false) === true) {
            $payload['blocked_reason'] = 'Domain search is temporarily unavailable. Please try again shortly.';
        }

        $payload['sold_by'] = self::BRAND;

        return $payload;
    }
}
