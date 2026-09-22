<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * business:audit {workspace} — READ-ONLY inventory for RFC-0011 (many businesses in one workspace).
 *
 * For every website of the workspace: the rows that belong to it (by website_id, or by URL host where the table
 * carries only a URL) and, per table, the rows that name no website at all (the "unassigned" set that today falls
 * to the first website under RISK-0198). Then the workspace-level tables that would carry a business_id under U1.
 * Nothing is written. Used as U0's baseline and as the acceptance check after every migration unit.
 */
class BusinessAuditCommand extends Command
{
    protected $signature = 'business:audit {workspace : workspace id} {--json : machine-readable output}';
    protected $description = 'Read-only inventory of website-bound and workspace-level rows for the business-profile plan (RFC-0011)';

    /** table => [site column, url column] */
    private const SITE_TABLES = [
        'articles' => ['website_id', null],
        'leads' => ['website_id', null],
        'social_posts' => ['website_id', null],
        'chatbot_settings' => ['website_id', null],
        'chatbot_knowledge_sources' => ['website_id', null],
        'chatbot_knowledge_chunks' => ['website_id', null],
        'chatbot_sessions' => ['website_id', null],
        'chatbot_widget_tokens' => ['website_id', null],
        'seo_settings' => ['website_id', null],
        'seo_content_index' => ['website_id', 'url'],
        'gsc_connections' => ['website_id', 'site_url'],
        'seo_keywords' => [null, 'target_url'],
        'seo_audits' => [null, 'url'],
        'seo_audit_items' => [null, 'url'],
        'seo_link_graph' => [null, 'url'],
        'seo_links' => [null, 'url'],
        'seo_redirects' => [null, 'url'],
        'seo_serp_results' => [null, 'url'],
    ];

    private const WORKSPACE_TABLES = [
        'tasks', 'meetings', 'workspace_goals', 'projects', 'approvals', 'calendar_events', 'social_accounts',
        'studio_brand_kits', 'creative_brand_identities', 'sarah_commitments', 'seo_goals', 'seo_clusters',
        'workspace_memory', 'agent_workspace_memory',
    ];

    /** The column that names a page or site on a URL-keyed table, whatever it is called there. */
    private function urlColumn(string $table, ?string $preferred): ?string
    {
        foreach (array_values(array_filter([$preferred, 'url', 'target_url', 'site_url', 'source_url', 'page_url', 'from_url'])) as $c) {
            if (Schema::hasColumn($table, $c)) { return $c; }
        }
        return null;
    }

    public function handle(): int
    {
        $wsId = (int) $this->argument('workspace');
        $ws = DB::table('workspaces')->where('id', $wsId)->first();
        if (! $ws) { $this->error("workspace {$wsId} not found"); return 1; }

        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')->get(['id', 'name', 'subdomain', 'custom_domain', 'domain', 'template_industry', 'created_at']);
        $hosts = [];
        foreach ($sites as $s) {
            $h = [];
            foreach (['custom_domain', 'domain', 'subdomain'] as $c) { $v = strtolower(trim((string) ($s->$c ?? ''))); if ($v !== '') { $h[] = preg_replace('#^https?://#', '', $v); } }
            $hosts[$s->id] = array_values(array_unique($h));
        }

        $out = ['workspace' => ['id' => $wsId, 'name' => $ws->name, 'business_name' => $ws->business_name ?? null, 'industry' => $ws->industry ?? null, 'location' => $ws->location ?? null],
            'websites' => [], 'unassigned' => [], 'workspace_level' => []];

        foreach ($sites as $s) {
            $row = ['id' => $s->id, 'name' => $s->name, 'hosts' => $hosts[$s->id], 'template_industry' => $s->template_industry, 'rows' => []];
            foreach (self::SITE_TABLES as $t => [$siteCol, $urlCol]) {
                if (! Schema::hasTable($t)) { continue; }
                $q = DB::table($t)->where('workspace_id', $wsId);
                if (Schema::hasColumn($t, 'deleted_at')) { $q->whereNull('deleted_at'); }
                if ($siteCol) { $n = (clone $q)->where($siteCol, $s->id)->count(); }
                else {
                    $n = 0; $urlCol = $this->urlColumn($t, $urlCol); if (! $urlCol) { continue; }
                    if ($hosts[$s->id]) { $qq = clone $q; $qq->where(function ($w) use ($hosts, $s, $urlCol) { foreach ($hosts[$s->id] as $h) { $w->orWhere($urlCol, 'like', '%' . $h . '%'); } }); $n = $qq->count(); }
                }
                if ($n > 0) { $row['rows'][$t] = $n; }
            }
            $out['websites'][] = $row;
        }

        foreach (self::SITE_TABLES as $t => [$siteCol, $urlCol]) {
            if (! Schema::hasTable($t)) { continue; }
            $q = DB::table($t)->where('workspace_id', $wsId);
            if (Schema::hasColumn($t, 'deleted_at')) { $q->whereNull('deleted_at'); }
            $total = (clone $q)->count();
            if ($siteCol) { $un = (clone $q)->where(function ($w) use ($siteCol) { $w->whereNull($siteCol)->orWhere($siteCol, 0); })->count(); }
            else { $urlCol = $this->urlColumn($t, $urlCol); if (! $urlCol) { continue; } $un = (clone $q)->where(function ($w) use ($urlCol) { $w->whereNull($urlCol)->orWhere($urlCol, ''); })->count(); }
            if ($total > 0) { $out['unassigned'][$t] = ['total' => $total, 'naming_no_website' => $un]; }
        }

        foreach (self::WORKSPACE_TABLES as $t) {
            if (! Schema::hasTable($t)) { continue; }
            $q = DB::table($t)->where('workspace_id', $wsId);
            if (Schema::hasColumn($t, 'deleted_at')) { $q->whereNull('deleted_at'); }
            $n = $q->count();
            if ($n > 0) { $out['workspace_level'][$t] = $n; }
        }
        $out['workspace_level']['workspace_memory_keys'] = DB::table('workspace_memory')->where('workspace_id', $wsId)->orderBy('key')->pluck('key')->all();

        if ($this->option('json')) { $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return 0; }

        $this->info("Workspace {$wsId} — {$ws->name} (business_name: " . ($ws->business_name ?? '—') . ', industry: ' . ($ws->industry ?? '—') . ', location: ' . ($ws->location ?? '—') . ')');
        $this->line('');
        $this->info('Per website (rows that name it):');
        foreach ($out['websites'] as $w) {
            $this->line(sprintf('  #%d %-28s %-34s %s', $w['id'], mb_substr($w['name'], 0, 28), implode(',', $w['hosts']) ?: '(no host)', $w['rows'] ? http_build_query($w['rows'], '', ', ') : '(nothing)'));
        }
        $this->line('');
        $this->info('Rows naming no website (fall to the FIRST website today — RISK-0198):');
        foreach ($out['unassigned'] as $t => $c) { if ($c['naming_no_website'] > 0) { $this->line(sprintf('  %-28s %d of %d', $t, $c['naming_no_website'], $c['total'])); } }
        $this->line('');
        $this->info('Workspace-level (would carry business_id under U1; NULL = default business):');
        foreach ($out['workspace_level'] as $t => $c) { if ($t === 'workspace_memory_keys') { $this->line('  workspace_memory keys: ' . implode(', ', $c)); } else { $this->line(sprintf('  %-28s %d', $t, $c)); } }
        return 0;
    }
}
