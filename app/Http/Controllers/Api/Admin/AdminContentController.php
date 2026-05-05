<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminContentController
{
    /**
     * All websites across workspaces with page count.
     */
    public function listWebsites(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('websites')
                ->join('workspaces', 'workspaces.id', '=', 'websites.workspace_id')
                ->whereNull('websites.deleted_at')
                ->count();

            $websites = DB::table('websites')
                ->join('workspaces', 'workspaces.id', '=', 'websites.workspace_id')
                ->leftJoin(DB::raw('(SELECT website_id, COUNT(*) as page_count FROM pages GROUP BY website_id) as pc'), 'pc.website_id', '=', 'websites.id')
                ->whereNull('websites.deleted_at')
                ->select(
                    'websites.*',
                    'workspaces.name as workspace_name',
                    DB::raw('COALESCE(pc.page_count, 0) as page_count')
                )
                ->orderBy('websites.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $websites,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch websites', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All campaigns across workspaces.
     */
    public function listCampaigns(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('campaigns')
                ->join('workspaces', 'workspaces.id', '=', 'campaigns.workspace_id')
                ->whereNull('campaigns.deleted_at')
                ->count();

            $campaigns = DB::table('campaigns')
                ->join('workspaces', 'workspaces.id', '=', 'campaigns.workspace_id')
                ->whereNull('campaigns.deleted_at')
                ->select('campaigns.*', 'workspaces.name as workspace_name')
                ->orderBy('campaigns.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $campaigns,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch campaigns', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All creative assets across workspaces.
     *
     * Rewired 2026-04-19: reads from the `media` table (99+ real rows
     * populated by MediaService / DALL-E / admin uploads) rather than
     * the empty `assets` table. Kept at /admin/assets for backward
     * compatibility — the new admin Media Library UI uses /admin/media
     * which exposes richer filters, stats, and upload.
     */
    public function listCreativeAssets(Request $request)
    {
        try {
            $perPage = max(1, min(200, (int) $request->input('per_page', 50)));
            $page    = max(1, (int) $request->input('page', 1));
            $offset  = ($page - 1) * $perPage;

            $q = DB::table('media')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'media.workspace_id');

            $total = (clone $q)->count();

            $items = $q->orderByDesc('media.created_at')
                ->offset($offset)
                ->limit($perPage)
                ->select(
                    'media.id',
                    'media.workspace_id',
                    'media.filename as name',
                    'media.filename',
                    'media.url',
                    'media.mime_type as type',
                    'media.mime_type',
                    'media.size_bytes',
                    'media.width',
                    'media.height',
                    'media.category',
                    'media.industry',
                    'media.source',
                    'media.is_platform_asset',
                    'media.use_count',
                    'media.created_at',
                    'workspaces.name as workspace_name'
                )
                ->get();

            return response()->json([
                'data'        => $items,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch assets', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * All articles across workspaces (excludes content blob for performance).
     */
    public function listArticles(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 50);
            $page    = (int) $request->input('page', 1);
            $offset  = ($page - 1) * $perPage;

            $total = DB::table('articles')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'articles.workspace_id')
                ->whereNull('articles.deleted_at')
                ->count();

            $articles = DB::table('articles')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'articles.workspace_id')
                ->whereNull('articles.deleted_at')
                ->select(
                    'articles.id',
                    'articles.workspace_id',
                    'articles.title',
                    'articles.slug',
                    'articles.status',
                    'articles.type',
                    'articles.word_count',
                    'articles.seo_score',
                    'articles.readability_score',
                    'articles.assigned_agent',
                    'articles.published_at',
                    'articles.created_at',
                    'articles.updated_at',
                    'workspaces.name as workspace_name'
                )
                ->orderBy('articles.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'data'        => $articles,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch articles', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * CRM overview stats across all workspaces.
     */
    public function crmOverview()
    {
        try {
            $totalContacts = DB::table('contacts')->whereNull('deleted_at')->count();
            $totalLeads    = DB::table('leads')->whereNull('deleted_at')->count();
            $totalDeals    = DB::table('deals')->whereNull('deleted_at')->count();

            $dealsByStage = DB::table('deals')
                ->whereNull('deleted_at')
                ->select('stage', DB::raw('COUNT(*) as count'), DB::raw('SUM(value) as total_value'))
                ->groupBy('stage')
                ->get();

            $recentContacts = DB::table('contacts')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'contacts.workspace_id')
                ->whereNull('contacts.deleted_at')
                ->select('contacts.*', 'workspaces.name as workspace_name')
                ->orderBy('contacts.created_at', 'desc')
                ->limit(10)
                ->get();

            $leadsBySource = DB::table('leads')
                ->whereNull('deleted_at')
                ->select('source', DB::raw('COUNT(*) as count'))
                ->groupBy('source')
                ->get();

            return response()->json([
                'total_contacts'  => $totalContacts,
                'total_leads'     => $totalLeads,
                'total_deals'     => $totalDeals,
                'deals_by_stage'  => $dealsByStage,
                'recent_contacts' => $recentContacts,
                'leads_by_source' => $leadsBySource,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch CRM overview', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * SEO overview stats across all workspaces.
     */
    public function seoOverview()
    {
        try {
            $totalKeywords = DB::table('seo_keywords')->count();
            $totalAudits   = DB::table('seo_audits')->count();
            $totalGoals    = DB::table('seo_goals')->count();
            $totalLinks    = DB::table('seo_links')->count();

            $recentAudits = DB::table('seo_audits')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'seo_audits.workspace_id')
                ->select('seo_audits.*', 'workspaces.name as workspace_name')
                ->orderBy('seo_audits.created_at', 'desc')
                ->limit(10)
                ->get();

            $keywordsByWorkspace = DB::table('seo_keywords')
                ->join('workspaces', 'workspaces.id', '=', 'seo_keywords.workspace_id')
                ->select('workspaces.name as workspace_name', DB::raw('COUNT(*) as keyword_count'))
                ->groupBy('workspaces.name')
                ->orderBy('keyword_count', 'desc')
                ->limit(20)
                ->get();

            return response()->json([
                'total_keywords'        => $totalKeywords,
                'total_audits'          => $totalAudits,
                'total_goals'           => $totalGoals,
                'total_links'           => $totalLinks,
                'recent_audits'         => $recentAudits,
                'keywords_by_workspace' => $keywordsByWorkspace,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch SEO overview', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Revenue / billing overview.
     */
    public function revenueOverview()
    {
        try {
            $subscriptionsByPlan = DB::table('subscriptions')
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->where('subscriptions.status', 'active')
                ->select('plans.name as plan_name', 'plans.price', DB::raw('COUNT(*) as subscriber_count'))
                ->groupBy('plans.name', 'plans.price')
                ->get();

            $mrr = $subscriptionsByPlan->sum(function ($row) {
                return $row->subscriber_count * (float) $row->price;
            });

            $creditSummary = DB::table('credit_transactions')
                ->select('type', DB::raw('COUNT(*) as tx_count'), DB::raw('SUM(amount) as total_amount'))
                ->groupBy('type')
                ->get();

            $churnLast30 = DB::table('subscriptions')
                ->where('status', 'cancelled')
                ->where('cancelled_at', '>=', now()->subDays(30))
                ->count();

            return response()->json([
                'subscriptions_by_plan' => $subscriptionsByPlan,
                'mrr'                   => round($mrr, 2),
                'credit_summary'        => $creditSummary,
                'churn_last_30_days'    => $churnLast30,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch revenue overview', 'message' => $e->getMessage()], 500);
        }
    }
}
