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

        // SARAH-LEADSRC (2026-08-30): where the recent leads came from and where they stand — the only
        // measured "campaign" signal a workspace owns before analytics is connected.
        $bySource = DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw("COALESCE(NULLIF(source, ''), 'unknown') AS src, COUNT(*) AS n")
            ->groupBy('src')->orderByDesc('n')->limit(8)->pluck('n', 'src')
            ->map(fn ($n) => (int) $n)->all();
        $byStage = DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(NULLIF(status, ''), 'unknown') AS st, COUNT(*) AS n")
            ->groupBy('st')->orderByDesc('n')->limit(8)->pluck('n', 'st')
            ->map(fn ($n) => (int) $n)->all();

        return [
            'leads_last_30d'    => $recentContacts + $recentLeads,
            'leads_by_source'   => $bySource,
            'leads_by_stage'    => $byStage,
            'total_contacts'    => $totalContacts + $totalLeads,
            'total_leads'       => $totalLeads,
            'recent_form_leads' => $recentContacts,
            'recent_crm_leads'  => $recentLeads,
        ];
    }
}
