<?php

namespace App\Core\Intelligence\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Reads workspace CRM posture (contact + lead counts) for agent context.
 *
 * Owns the boundary to contacts/leads tables. Note: T3.2 contact form pipeline
 * writes to `contacts`; legacy CRM lead capture writes to `leads`. Both
 * are sampled.
 */
class CRMContextProvider
{
    public function get(int $workspaceId): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        $recentContacts = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $totalContacts = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->count();

        $recentLeads = (int) DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        return [
            'leads_last_30d'    => $recentContacts + $recentLeads,
            'total_contacts'    => $totalContacts,
            'recent_form_leads' => $recentContacts,
            'recent_crm_leads'  => $recentLeads,
        ];
    }
}
