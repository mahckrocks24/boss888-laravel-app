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
        // uu (2026-08-30): people captured as LEADS (chatbot, forms, Sarah) are contacts on file too —
        // the brief used to say "0 total contacts but 4 new this month".
        $totalLeads = (int) DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->count();

        $recentLeads = (int) DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        return [
            'leads_last_30d'    => $recentContacts + $recentLeads,
            'total_contacts'    => $totalContacts + $totalLeads,
            'total_leads'       => $totalLeads,
            'recent_form_leads' => $recentContacts,
            'recent_crm_leads'  => $recentLeads,
        ];
    }
}
