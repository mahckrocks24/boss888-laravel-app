<?php

namespace App\Http\Controllers\Api\Admin;

use App\Jobs\RegisterDomainJob;
use App\Jobs\SyncCustomerDomainJob;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use App\Services\Domains\DomainCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Internal operator console for domain commerce.
 *
 * Unlike the customer controller, this one DOES expose wholesale cost, markup,
 * margin, provider identity and provider error codes -- an operator cannot
 * diagnose a failed registration without them. It still never exposes API keys
 * or credentials.
 */
class DomainAdminController
{
    /** Cross-tenant domain search. */
    public function domains(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $workspaceId = (int) $request->query('workspace_id', 0);

        $query = CustomerDomain::query()->orderByDesc('id');

        if ($q !== '') {
            $query->where('domain', 'like', '%' . $q . '%');
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($workspaceId > 0) {
            $query->where('workspace_id', $workspaceId);
        }

        $rows = $query->limit(200)->get()->map(fn (CustomerDomain $d) => [
            'id'             => $d->id,
            'domain'         => $d->domain,
            'workspace_id'   => (int) $d->workspace_id,
            'status'         => $d->status,
            'provider'       => $d->provider,
            'registered_at'  => $d->registered_at?->toDateString(),
            'expires_at'     => $d->expires_at?->toDateString(),
            'days_to_expiry' => $d->daysUntilExpiry(),
            'auto_renew'     => (bool) $d->auto_renew,
            'is_locked'      => (bool) $d->is_locked,
            'nameservers'    => $d->nameservers(),
            'provider_order_id'       => $d->provider_order_id,
            'provider_transaction_id' => $d->provider_transaction_id,
            'last_synced_at' => $d->last_synced_at?->toIso8601String(),
        ]);

        return response()->json(['domains' => $rows, 'count' => $rows->count()]);
    }

    /** Orders across all tenants, with commercial internals. */
    public function orders(Request $request): JsonResponse
    {
        $svc = DomainCommerceService::make();
        $query = DomainOrder::query()->with('items')->orderByDesc('id');

        if (($status = trim((string) $request->query('status', ''))) !== '') {
            $query->where('status', $status);
        }

        if (($wsId = (int) $request->query('workspace_id', 0)) > 0) {
            $query->where('workspace_id', $wsId);
        }

        $orders = $query->limit(100)->get()->map(fn (DomainOrder $o) => $svc->presentOrderForAdmin($o));

        return response()->json(['orders' => $orders, 'count' => $orders->count()]);
    }

    /** Items that need operator attention: failed, or owed a refund. */
    public function attention(): JsonResponse
    {
        $rows = DomainOrderItem::whereIn('status', [
            DomainOrderItem::STATUS_FAILED,
            DomainOrderItem::STATUS_REFUND_DUE,
        ])->orderByDesc('id')->limit(100)->get()->map(fn (DomainOrderItem $i) => [
            'item_id'      => $i->id,
            'order_id'     => (int) $i->domain_order_id,
            'workspace_id' => (int) $i->workspace_id,
            'domain'       => $i->domain,
            'status'       => $i->status,
            'attempts'     => (int) $i->attempts,
            'error_code'   => $i->last_error_code,
            'error'        => $i->last_error,
            'retail_minor' => (int) $i->retail_minor,
            'paid'         => $i->order?->isPaid() ?? false,
            'retryable'    => $i->status !== DomainOrderItem::STATUS_REGISTERED,
        ]);

        return response()->json([
            'items' => $rows,
            'count' => $rows->count(),
            'note'  => 'refund_due means the customer paid but the domain was not registered.',
        ]);
    }

    /**
     * Retry a failed registration.
     *
     * Safe because the job and adapter both re-check ownership before spending:
     * if the previous attempt actually succeeded, the retry is recognised as a
     * replay and nothing is charged.
     */
    public function retry(Request $request, int $itemId): JsonResponse
    {
        $item = DomainOrderItem::find($itemId);

        if ($item === null) {
            return response()->json(['error' => 'Order item not found.'], 404);
        }

        if ($item->status === DomainOrderItem::STATUS_REGISTERED) {
            return response()->json(['error' => 'Already registered; nothing to retry.'], 422);
        }

        if (! ($item->order?->isPaid() ?? false)) {
            return response()->json(['error' => 'Refusing to register: the order is not paid.'], 422);
        }

        $item->update(['status' => DomainOrderItem::STATUS_PENDING, 'last_error' => null]);
        RegisterDomainJob::dispatch($item->id)->onQueue('tasks-high');

        return response()->json([
            'queued'  => true,
            'item_id' => $item->id,
            'domain'  => $item->domain,
            'note'    => 'The adapter re-checks the registrar before spending, so a replay cannot double-charge.',
        ]);
    }

    /** Force a fresh read of one domain from the registrar. */
    public function sync(Request $request, int $id): JsonResponse
    {
        $domain = CustomerDomain::find($id);

        if ($domain === null) {
            return response()->json(['error' => 'Domain not found.'], 404);
        }

        SyncCustomerDomainJob::dispatch($domain->domain, $request->user()?->id)->onQueue('tasks');

        return response()->json(['queued' => true, 'domain' => $domain->domain]);
    }

    /** Queue a sync for every domain whose data is stale. */
    public function syncAll(Request $request): JsonResponse
    {
        $staleMinutes = max(5, (int) $request->query('stale_minutes', 60));

        $domains = CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)
            ->where(function ($q) use ($staleMinutes) {
                $q->whereNull('last_synced_at')
                  ->orWhere('last_synced_at', '<', now()->subMinutes($staleMinutes));
            })
            ->limit(200)
            ->pluck('domain');

        foreach ($domains as $d) {
            SyncCustomerDomainJob::dispatch($d, $request->user()?->id)->onQueue('tasks-low');
        }

        return response()->json(['queued' => $domains->count(), 'stale_minutes' => $staleMinutes]);
    }

    /**
     * Provider interaction history for a domain or order.
     * Reads the shared audit table; contains codes and summaries, never
     * credentials or raw provider envelopes.
     */
    public function events(Request $request): JsonResponse
    {
        $query = DB::table('infra_events')
            ->where('source', 'domain_commerce')
            ->orderByDesc('id');

        if (($domain = trim((string) $request->query('domain', ''))) !== '') {
            $query->where('provider_resource_id', $domain);
        }

        if (($orderId = (int) $request->query('order_id', 0)) > 0) {
            $query->where(function ($q) use ($orderId) {
                $q->where(fn ($x) => $x->where('owner_type', 'domain_order')->where('owner_id', $orderId))
                  ->orWhereIn('owner_id', DomainOrderItem::where('domain_order_id', $orderId)->pluck('id'));
            });
        }

        if (($wsId = (int) $request->query('workspace_id', 0)) > 0) {
            $query->where('workspace_id', $wsId);
        }

        $rows = $query->limit(200)->get()->map(fn ($e) => [
            'id'           => $e->id,
            'at'           => $e->created_at,
            'event'        => $e->event,
            'severity'     => $e->severity,
            'workspace_id' => $e->workspace_id,
            'owner'        => $e->owner_type . '#' . $e->owner_id,
            'from'         => $e->from_state,
            'to'           => $e->to_state,
            'provider'     => $e->provider,
            'domain'       => $e->provider_resource_id,
            'summary'      => $e->summary,
            'context'      => json_decode((string) $e->context_json, true),
        ]);

        return response()->json(['events' => $rows, 'count' => $rows->count()]);
    }

    /** Commercial roll-up: revenue, cost and margin from domain sales. */
    public function summary(): JsonResponse
    {
        $paid = DomainOrder::whereNotNull('paid_at');

        $revenue = (int) (clone $paid)->sum('subtotal_minor');
        $cost = (int) (clone $paid)->sum('cost_total_minor');

        return response()->json([
            'orders_total'     => DomainOrder::count(),
            'orders_paid'      => (clone $paid)->count(),
            'domains_owned'    => CustomerDomain::count(),
            'domains_active'   => CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)->count(),
            'expiring_30_days' => CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(30)])
                ->count(),
            'items_needing_attention' => DomainOrderItem::whereIn('status', [
                DomainOrderItem::STATUS_FAILED, DomainOrderItem::STATUS_REFUND_DUE,
            ])->count(),
            'revenue' => '$' . number_format($revenue / 100, 2),
            'cost'    => '$' . number_format($cost / 100, 2),
            'margin'  => '$' . number_format(($revenue - $cost) / 100, 2),
            'margin_percent' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : null,
        ]);
    }
}
