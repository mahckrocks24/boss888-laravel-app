<?php

namespace App\Core\Intelligence\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Reads workspace credit/billing posture for agent context.
 *
 * Sarah needs to know how much budget the workspace has before proposing
 * cost-heavy work. Owns the boundary to the credits table; engine
 * services should NOT read this directly during meetings.
 */
class BillingContextProvider
{
    public function get(int $workspaceId): array
    {
        $row = DB::table('credits')
            ->where('workspace_id', $workspaceId)
            ->first();

        $balance  = (int) ($row->balance          ?? 0);
        $reserved = (int) ($row->reserved_balance ?? 0);

        return [
            'credit_balance'   => $balance,
            'credits_reserved' => $reserved,
            'credits_available'=> max(0, $balance - $reserved),
        ];
    }
}
