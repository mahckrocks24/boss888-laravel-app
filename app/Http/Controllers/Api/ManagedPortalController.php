<?php

namespace App\Http\Controllers\Api;

use App\Core\Managed\ManagedStatus;
use App\Core\Managed\ManagedWorkspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * MANAGED-1 (RFC-0029, DEC-0086) — the managed portal's two reads.
 *
 *   GET managed/portal    who is signed in, the status line, the package (Billing), whether Email is open.
 *   GET managed/receipt   the receipt for the package, when one is on file.
 *
 * The workspace is the token's. A platform admin may name a managed workspace with ?workspace_id= to see the
 * portal exactly as the client sees it; nobody else can name one.
 */
class ManagedPortalController
{
    public function portal(Request $request)
    {
        $wsId = $this->workspaceId($request);
        if (! $wsId) {
            return response()->json(['success' => false, 'error' => 'This account does not have a managed package.', 'code' => 'not_managed'], 404);
        }

        $ws = DB::table('workspaces')->where('id', $wsId)->first(['id', 'name', 'business_name']);
        $user = $request->user();
        $contract = DB::table('managed_contracts')->where('workspace_id', $wsId)->orderByDesc('term_end')->first();

        return response()->json([
            'success'   => true,
            'workspace' => ['id' => (int) $ws->id, 'name' => $ws->business_name ?: $ws->name],
            'user'      => [
                'name'              => $user?->name,
                'email'             => $user?->email,
                'is_platform_admin' => (bool) ($user?->is_platform_admin),
                'role'              => $user ? DB::table('workspace_users')->where('workspace_id', $wsId)->where('user_id', $user->id)->value('role') : null,
                'member_since'      => $user?->created_at?->toDateString(),
            ],
            'status'    => ManagedStatus::forWorkspace($wsId),
            'package'   => $contract ? $this->package($contract) : null,
            'support'   => [
                'email' => config('managed.support_email'),
                'phone' => config('managed.support_phone'),
            ],
        ]);
    }

    public function receipt(Request $request)
    {
        $wsId = $this->workspaceId($request);
        $contract = $wsId ? DB::table('managed_contracts')->where('workspace_id', $wsId)->orderByDesc('term_end')->first() : null;
        if (! $contract || ! $contract->receipt_path || ! Storage::disk('local')->exists($contract->receipt_path)) {
            return response()->json(['success' => false, 'error' => 'No receipt is on file yet.'], 404);
        }
        $name = 'Receipt-' . ($contract->receipt_ref ?: $contract->id) . '.pdf';

        return Storage::disk('local')->download($contract->receipt_path, $name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    private function workspaceId(Request $request): ?int
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if (ManagedWorkspaces::isManaged($wsId)) {
            return $wsId;
        }
        $asked = (int) $request->query('workspace_id');
        if ($asked && ! empty($request->user()?->is_platform_admin) && ManagedWorkspaces::isManaged($asked)) {
            return $asked;
        }

        return null;
    }

    private function package(object $c): array
    {
        $today = now()->startOfDay();
        $end = \Illuminate\Support\Carbon::parse($c->term_end);
        $support = $c->support_until ? \Illuminate\Support\Carbon::parse($c->support_until) : null;

        return [
            'name'          => $c->package_name,
            'inclusions'    => json_decode($c->inclusions_json ?: '[]', true) ?: [],
            'currency'      => $c->currency,
            'amount'        => round($c->amount_minor / 100, 2),
            'paid_at'       => $c->paid_at,
            'term_start'    => $c->term_start,
            'term_end'      => $c->term_end,
            'active'        => $today->lte($end),
            'days_left'     => max(0, (int) $today->diffInDays($end, false)),
            'support'       => $support ? [
                'label'    => $c->support_label,
                'until'    => $c->support_until,
                'active'   => $today->lte($support),
                'days_left'=> max(0, (int) $today->diffInDays($support, false)),
            ] : null,
            'invoice_ref'   => $c->invoice_ref,
            'receipt_ref'   => $c->receipt_ref,
            'has_receipt'   => (bool) $c->receipt_path,
        ];
    }
}
