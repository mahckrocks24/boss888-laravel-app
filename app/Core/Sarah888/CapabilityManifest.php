<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 — CANONICAL CAPABILITY MANIFEST.
 *
 * WHAT THIS IS FOR.
 * Sarah told the owner "I don't have the capability to run actual SERP analysis or
 * access external tools directly" while `DataForSeoConnector` was configured, james
 * held `serp_analysis`, and ws 2 had eight completed serp_analysis tasks. Separately,
 * Runtime V2 told us "Search Console isn't connected yet" for ws 2 while
 * `gsc_connections` held a live token for sc-domain:cheflisted.com.
 *
 * Both are the same defect: capability truth had no single owner, so Laravel prose,
 * runtime guesswork, `agent_capabilities`, and prompt text each answered "what can
 * Sarah do?" independently. This is the one authoritative answer.
 *
 * WHAT IT DELIBERATELY DOES NOT DECIDE.
 * `requires_approval`. That is NOT a property of a capability — it is derived per task
 * at TaskService.php:195-235 from category default, the cap-map approval mode, and the
 * SpendContext cost gate, and it can be raised by cost at execution time. Measured in
 * ws 2: `deep_audit` appears 21x with requires_approval=0 and 97x with =1. A manifest
 * that asserted a single value would be wrong 21% of the time for that one action.
 * So this publishes the INPUTS to that decision and lets governance stay authoritative.
 *
 * EXECUTOR IS ALWAYS LARAVEL.
 * Measured 2026-08-13: Runtime's `/ai/run task=serp_analysis` returns success:true with
 * model gpt-4o-mini, zero URLs and zero ranking positions — it generates prose about
 * what a SERP would contain. The real DataForSEO call returns 161 results with
 * positions and URLs in 3.2s. Runtime selects and interprets; Laravel executes, because
 * Laravel owns the credentials.
 *
 * PROVENANCE IS PART OF THE CONTRACT.
 * Every entry carries `evidence_source`. A capability claim Sarah cannot attribute is a
 * capability claim she should not make.
 *
 * THREE SOURCES, ONE ANSWER.
 *   1. `agent_capabilities`          — what agents may call.
 *   2. CONNECTOR_CAPABILITIES        — Laravel-native reads with a wired executor.
 *   3. observed task actions         — mutations the platform demonstrably performs
 *                                      that the registry never listed (see
 *                                      admittedObserved()).
 * The registry is NOT the platform's capability universe. Treating it as one is what
 * made Sarah deny `publish_article` while the owner had two publishes queued.
 */
final class CapabilityManifest
{
    /** Capabilities executed through an EXTERNAL vendor connector, and which one. */
    private const PROVIDER_MAP = [
        'serp_analysis'       => 'dataforseo',
        'competitor_serp'     => 'dataforseo',
        'competitor_keywords' => 'dataforseo',
        'competitor_gaps'     => 'dataforseo',
        'keyword_research'    => 'dataforseo',
        'track_keyword_rank'  => 'dataforseo',
        'related_keywords'    => 'dataforseo',
    ];

    /**
     * Capabilities whose evidence comes from a per-WORKSPACE connection rather than a
     * platform-wide credential. These are the ones the runtime kept guessing about.
     */
    private const WORKSPACE_SCOPED = [
        'gsc_performance' => 'gsc',
        'gsc_queries'     => 'gsc',
        // NOT ai_report: that is the SEO engine's AI report (SeoService,
        // seo_ai_reports, parameter `url`) and has nothing to do with Search
        // Console. Mapping it here made ws 990100 report "Search Console is not
        // connected" for a capability that never needed it.
    ];

    /**
     * Laravel-native capabilities that are NOT agent tools.
     *
     * `agent_capabilities` enumerates what AGENTS may call. Sarah can also read
     * through connectors the agent registry never lists — Search Console being the
     * one the owner asks about most. Leaving them out would make the manifest
     * answer "no" to something Laravel demonstrably can do, which is the failure
     * this class exists to prevent. Each declares its own evidence source.
     */
    private const CONNECTOR_CAPABILITIES = [
        'content_state' => [
            'provider'   => 'internal',
            'engine'     => 'write',
            'category'   => 'research',
            'read'       => true,
            'cost'       => 0,
            'params'     => [],
            'agents'     => ['sarah', 'priya'],
            'evidence'   => 'articles table (authoritative)',
            'purpose'    => 'How much content exists: published, drafts, scheduled, and how many lack a featured image.',
        ],
        'list_articles' => [
            'provider'   => 'internal',
            'engine'     => 'write',
            'category'   => 'research',
            'read'       => true,
            'cost'       => 0,
            'params'     => [],
            'agents'     => ['sarah', 'priya'],
            'evidence'   => 'articles table (authoritative)',
            // Added 2026-08-14. Asked to publish "a pile of finished drafts", Runtime
            // had `publish_article` (which needs an article_id) and `content_state`
            // (which only counts), so it invented `list_posts`, got TOOL_UNAVAILABLE
            // and told the owner publishing was not connected. It needed a way to see
            // the actual items.
            'purpose'    => 'List individual articles with id, title and status. Use this to find the specific article_id needed to publish or improve one — content_state gives counts, this gives the items themselves. Optional parameters: status (default draft), limit.',
        ],
        'recent_tasks' => [
            'provider'   => 'internal',
            'engine'     => 'platform',
            'category'   => 'research',
            'read'       => true,
            'cost'       => 0,
            'params'     => [],
            'agents'     => ['sarah'],
            'evidence'   => 'tasks table (authoritative execution record)',
            // Added 2026-08-14 from the P5 matrix. Asked "Did that SERP analysis actually
            // run or not?", the shadow had no way to look up execution history, so it
            // reached for `serp_analysis` — an intent to run a NEW one — which was refused
            // for missing parameters, and Sarah then reported her own refusal as though it
            // answered the owner's question about the past. "Did X run?" is a core trust
            // question for an assistant and it needs the execution record, not a re-run.
            'purpose'    => 'What actually ran recently, and what happened to it: task action, status, when it started and finished, and why it failed. Use this for any question about whether something ran, is still running, failed, or is waiting — never re-run an action to find out.',
        ],
        'email_readiness' => [
            'provider'   => 'internal',
            'engine'     => 'marketing',
            'category'   => 'research',
            'read'       => true,
            'cost'       => 0,
            'params'     => [],
            'agents'     => ['sarah'],
            'evidence'   => 'mail config + workspace sender identity',
            'purpose'    => 'Whether email can be sent: platform transport readiness AND whether THIS workspace has a sender identity. Use this for any question about email capability.',
        ],
        'gsc_performance' => [
            'provider'   => 'gsc',
            'engine'     => 'seo',
            'category'   => 'research',
            'read'       => true,
            'cost'       => 0,
            'params'     => [],
            'agents'     => ['sarah', 'james'],
            'evidence'   => 'GscClient::isConnected() + gsc_metrics',
            'purpose'    => 'Search Console performance for this workspace: clicks, impressions, CTR and AVERAGE position by query and page.',
        ],
    ];

    /**
     * How much execution history an UNREGISTERED action needs before the manifest
     * will publish it. One completed task is proof Laravel really can do it; a row
     * that only ever sat pending or failed proves nothing.
     */
    private const OBSERVED_MIN_COMPLETED = 1;

    /**
     * Capabilities that READ LIKE a lookup but actually COMMISSION WORK.
     *
     * `isRead()` classified these as reads because the verb or the `research` category
     * says so. Execution history says otherwise: invoking one creates a task that runs,
     * costs credits and changes state. Measured 2026-08-14 (platform-wide `tasks.action`):
     *
     *   deep_audit      222 runs, 35 completed, max cost 3
     *   ai_report         1 run,                max cost 2
     *   check_outbound    1 run,  1 completed,  max cost 2
     *   list_posts        1 run,                max cost 0
     *
     * Calling that a read is what made the manifest advertise a synchronous lookup the
     * gateway had no executor for — Runtime selected `list_posts`, got TOOL_UNAVAILABLE,
     * and Sarah told the owner publishing was not connected.
     *
     * As mutations they are answered by governance BEFORE an executor is consulted, which
     * is both truthful and exactly how `publish_article` already behaves. A credit cost is
     * itself the tell: a lookup does not bill.
     */
    private const TASK_CREATING = [
        'deep_audit', 'ai_report', 'check_outbound', 'list_posts',
    ];

    /**
     * Capabilities the GATEWAY executes synchronously against a read-only source.
     *
     * These are reads by construction: `ToolIntentGateway::execute()` is only ever reached
     * by a read — a mutation is settled at governance before it. So anything with a wired
     * executor is, definitionally, a read, and `cmtest` asserts that the two stay in step.
     *
     * This list exists because category-derived classification is fragile. Measured
     * 2026-08-14: `serp_analysis` has 18 task rows categorised `research` and typed as a
     * read; `competitor_serp` has exactly ONE, categorised `optimize`, and was therefore
     * typed a MUTATION — so a pure DataForSEO lookup with a wired executor answered
     * WOULD_REQUIRE_APPROVAL and never ran. The owner asked for precisely that in the Chef
     * Red transcript ("Check competitor rankings for private chef NJ", turn 14).
     *
     * One historical task row should not decide what a capability IS. What Laravel does
     * with it should.
     *
     * NOT `competitor_gaps`: 5 task runs, cost 3, no executor — that one really does
     * commission work, so mutation remains correct for it.
     */
    private const SYNC_READ_EXECUTORS = [
        'competitor_serp',
    ];

    /**
     * Registry READS with no Laravel executor. NOT offered to Runtime.
     *
     * This stopped being theoretical debt in the P5 matrix. On ws 990100
     * `gsc_performance` is not available (no Search Console connection), so asked "What is
     * Search Console telling us?" Runtime reached for the nearest listed thing —
     * `scan_site_url` — and the gateway answered TOOL_UNAVAILABLE. A live turn was spent
     * offering something Laravel cannot do.
     *
     * The rule this restores is the one this class already applies to observed actions: a
     * READ must have a real executor before Runtime may claim it. A mutation is different
     * — governance answers it before an executor is consulted — which is why only reads
     * appear here.
     *
     * They are marked UNAVAILABLE rather than deleted: the capability genuinely exists in
     * the registry, and `missing_configuration` says exactly what is absent, so the
     * manifest keeps telling the truth instead of going quiet. Wire an executor and the id
     * comes off this list — `cmtest` fails if a wired one is left here.
     *
     * WHY EACH ONE (measured 2026-08-14):
     *   list_events, check_availability   `calendar_events` exists but is empty everywhere
     *   analyze_funnel_structure          no funnel tables exist at all
     *   scan_site_url                     no task history and no backing store
     *   list_builder_pages, get_builder_page, get_site_page, get_site_pages,
     *   search_site_content               `pages` is keyed by website_id and carries NO
     *                                     workspace_id — wiring these without a join
     *                                     through `websites` would leak across tenants
     */
    private const READS_WITHOUT_EXECUTOR = [
        'analyze_funnel_structure', 'check_availability', 'get_builder_page',
        'get_site_page', 'get_site_pages', 'list_builder_pages', 'list_events',
        'scan_site_url', 'search_site_content',
    ];

    /** Read-only verbs. Everything else is treated as mutating until proven otherwise. */
    private const READ_VERBS = [
        'list', 'get', 'check', 'search', 'scan', 'analyze', 'analyse', 'audit',
        'find', 'compute', 'score', 'status', 'report', 'research', 'suggest',
    ];

    /**
     * The whole manifest for one workspace.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forWorkspace(int $wsId): array
    {
        // MISSION-018 WS-1 (2026-08-24, RISK-0045): the MAX(is_active) GROUP BY
        // tool_id below is DELIBERATE and correct — this is the SARAH-LEVEL
        // manifest ("does this workspace have this capability at all"), not a
        // per-agent one. Collapsing the agent dimension is the intended
        // question here. Per-agent is_active REVOCATION is enforced downstream,
        // where it actually gates execution: AgentCapabilityService::canUse()
        // and ::getCapabilities() both filter
        // where('agent_slug', $agent)->where('is_active', true), so a tool
        // deactivated for one agent is refused to that agent even while this
        // Sarah manifest shows the workspace has the capability. Verified
        // 2026-08-24; RISK-0045's "it's undocumented" is the fix — now it isn't.
        $rows = DB::table('agent_capabilities')
            ->selectRaw('tool_id, GROUP_CONCAT(DISTINCT agent_slug) agents, MAX(is_active) any_active')
            ->groupBy('tool_id')->orderBy('tool_id')->get();

        $shapes = $this->observedShapes();
        $out = [];
        $registryIds = [];
        foreach ($rows as $r) {
            $registryIds[] = (string) $r->tool_id;
            $out[] = $this->entry((string) $r->tool_id, (string) $r->agents,
                (int) $r->any_active === 1, $shapes, $wsId);
        }
        foreach (array_keys(self::CONNECTOR_CAPABILITIES) as $id) {
            $out[] = $this->connectorEntry($id, $wsId);
        }
        foreach ($this->admittedObserved($registryIds) as $id => $shape) {
            $out[] = $this->observedEntry($id, $shape, $wsId);
        }
        return $out;
    }

    /** One capability, or null when it is not in the registry at all. */
    public function capability(int $wsId, string $capabilityId): ?array
    {
        if (isset(self::CONNECTOR_CAPABILITIES[$capabilityId])) {
            return $this->connectorEntry($capabilityId, $wsId);
        }
        $r = DB::table('agent_capabilities')->where('tool_id', $capabilityId)
            ->selectRaw('tool_id, GROUP_CONCAT(DISTINCT agent_slug) agents, MAX(is_active) any_active')
            ->groupBy('tool_id')->first();

        if (!$r) {
            // Not in the registry. It may still be something Laravel demonstrably
            // does. Passing [] as the registry list is correct here precisely
            // because we have just proven this id is not in it.
            $observed = $this->admittedObserved([]);
            return isset($observed[$capabilityId])
                ? $this->observedEntry($capabilityId, $observed[$capabilityId], $wsId)
                : null;
        }

        return $this->entry($capabilityId, (string) $r->agents,
            (int) $r->any_active === 1, $this->observedShapes(), $wsId);
    }

    /** Only what Sarah can actually use here, for prompt/context injection. */
    public function availableFor(int $wsId): array
    {
        return array_values(array_filter($this->forWorkspace($wsId),
            fn (array $c) => $c['workspace_available'] === true));
    }

    private function entry(string $id, string $agents, bool $active, array $shapes, int $wsId): array
    {
        $shape    = $shapes[$id] ?? ['engine' => null, 'category' => null, 'max_cost' => 0, 'runs' => 0];
        $provider = self::PROVIDER_MAP[$id] ?? (self::WORKSPACE_SCOPED[$id] ?? 'internal');

        $providerReady = $this->providerReady($provider, $wsId);
        $isRead        = $this->isRead($id, (string) $shape['category']);
        $cost          = (int) $shape['max_cost'];

        // A read Laravel cannot perform must not be offered — see READS_WITHOUT_EXECUTOR.
        $noExecutor = $isRead && in_array($id, self::READS_WITHOUT_EXECUTOR, true);
        $available  = $active && $providerReady['ready'] && !$noExecutor;
        if ($noExecutor) {
            $providerReady['missing'][] = 'no Laravel executor is wired for this read';
            $providerReady['configuration']['executor_wired'] = false;
        }

        return [
            'capability_id'   => $id,
            'owner_agents'    => $agents === '' ? [] : explode(',', $agents),
            // Permanent, per the 2026-08-13 architecture ruling.
            'executor'        => 'laravel',
            'provider'        => $provider,
            'engine'          => $shape['engine'],
            'operation_type'  => $isRead ? 'read' : 'mutation',
            'supports_read'     => $isRead,
            'supports_mutation' => !$isRead,

            'registry_active'       => $active,
            'provider_ready'        => $providerReady['ready'],
            'workspace_available'   => $available,
            'workspace_configuration' => $providerReady['configuration'],
            'missing_configuration' => $providerReady['missing'],

            // INPUTS to governance. Not a decision — see the class docblock.
            'approval_inputs' => [
                'category'              => $shape['category'],
                'category_default_mode' => $this->categoryDefaultMode((string) $shape['category']),
                'risk_class'            => $this->riskClass($id, (string) $shape['category'], $cost),
                'cost_class'            => $this->costClass($cost),
                'observed_max_credits'  => $cost,
            ],

            'required_parameters' => $this->requiredParameters($id),
            'evidence_source'     => [
                'registry'  => 'agent_capabilities.tool_id/is_active',
                'shape'     => $shape['runs'] > 0
                    ? "tasks history ({$shape['runs']} runs)" : 'no execution history',
                'provider'  => $providerReady['evidence'],
            ],
        ];
    }

    /**
     * Is the thing that actually executes this configured?
     * Platform credentials are global; GSC is per workspace, which is exactly the
     * distinction Runtime V2 got wrong.
     */
    private function providerReady(string $provider, int $wsId): array
    {
        if ($provider === 'dataforseo') {
            $ok = false;
            try {
                $c = app(\App\Connectors\DataForSeoConnector::class);
                $ok = (bool) $c->isConfigured();
            } catch (\Throwable $e) { $ok = false; }
            return ['ready' => $ok,
                    'configuration' => ['scope' => 'platform', 'credentials' => $ok],
                    'missing'  => $ok ? [] : ['DATAFORSEO_LOGIN/DATAFORSEO_PASSWORD'],
                    'evidence' => 'DataForSeoConnector::isConfigured()'];
        }

        if ($provider === 'gsc') {
            // GscClient::isConnected() is the platform's own check (it tests the
            // `connected` flag, which agents-01's router also gates on). Testing
            // access_token_enc instead could disagree with the rest of the app.
            $ok = false;
            try { $ok = (bool) app(\App\Engines\SEO\Services\GscClient::class)->isConnected($wsId); }
            catch (\Throwable $e) { $ok = false; }
            $site = $ok ? DB::table('gsc_connections')->where('workspace_id', $wsId)
                    ->value('site_url') : null;
            return ['ready' => $ok,
                    'configuration' => ['scope' => 'workspace', 'connected' => $ok, 'site' => $site],
                    'missing'  => $ok ? [] : ['gsc_connections.access_token_enc for this workspace'],
                    'evidence' => 'gsc_connections(workspace_id)'];
        }

        return ['ready' => true,
                'configuration' => ['scope' => 'internal'],
                'missing' => [],
                'evidence' => 'internal engine, no external credential'];
    }

    /** A Laravel-native connector capability, shaped like every other entry. */
    private function connectorEntry(string $id, int $wsId): array
    {
        $d = self::CONNECTOR_CAPABILITIES[$id];
        $ready = $this->providerReady($d['provider'], $wsId);
        $cost  = (int) $d['cost'];

        return [
            'capability_id'   => $id,
            'owner_agents'    => $d['agents'],
            'executor'        => 'laravel',
            'provider'        => $d['provider'],
            'engine'          => $d['engine'],
            'operation_type'  => $d['read'] ? 'read' : 'mutation',
            'supports_read'     => (bool) $d['read'],
            'supports_mutation' => !$d['read'],
            'registry_active'   => true,
            'provider_ready'    => $ready['ready'],
            'workspace_available' => $ready['ready'],
            'workspace_configuration' => $ready['configuration'],
            'missing_configuration'   => $ready['missing'],
            'approval_inputs' => [
                'category'              => $d['category'],
                'category_default_mode' => $this->categoryDefaultMode($d['category']),
                'risk_class'            => $this->riskClass($id, $d['category'], $cost),
                'cost_class'            => $this->costClass($cost),
                'observed_max_credits'  => $cost,
            ],
            'required_parameters' => $d['params'],
            'purpose'             => $d['purpose'] ?? null,
            'evidence_source' => [
                'registry' => 'CapabilityManifest::CONNECTOR_CAPABILITIES (Laravel-native)',
                'shape'    => 'declared',
                'provider' => $d['evidence'],
            ],
        ];
    }

    /**
     * Actions Laravel demonstrably executes that `agent_capabilities` never lists.
     *
     * WHY THIS SOURCE EXISTS. The registry enumerates what AGENTS may call. It is not
     * the set of actions the platform can perform. Measured 2026-08-14: ws 2 alone runs
     * 19 task actions with no registry row, including `publish_article` — 63 runs, 45
     * completed, 8 pending right now. Runtime-native Sarah, reading only the registry,
     * answered "there's no publish action for articles" while the owner had two of them
     * queued. That answer was true of the manifest and false of the platform, and it
     * made Runtime-native Sarah NARROWER than the legacy path she is meant to replace.
     *
     * MUTATIONS ONLY, AND WHY THAT IS NOT AN ARBITRARY LINE.
     * A mutation never needs an executor to be answered honestly: ToolIntentGateway
     * settles it at governance (REQUIRES_APPROVAL / WOULD_REQUIRE_APPROVAL) before the
     * executor is ever consulted, so publishing it cannot produce a false success. A
     * READ is the opposite — it runs, and with no executor wired it would come back
     * TOOL_UNAVAILABLE after Sarah had already offered it. That is the "capability claim
     * she cannot attribute" this class exists to prevent. Reads are therefore admitted
     * only by wiring a real executor, never by observing history.
     *
     * @param  array<int, string> $registryIds ids already published from the registry
     * @return array<string, array{engine:string,category:string,max_cost:int,runs:int,completed:int}>
     */
    private function admittedObserved(array $registryIds): array
    {
        $out = [];
        foreach ($this->observedShapes() as $id => $s) {
            if (in_array($id, $registryIds, true)) continue;
            if (isset(self::CONNECTOR_CAPABILITIES[$id])) continue;
            if ((int) ($s['completed'] ?? 0) < self::OBSERVED_MIN_COMPLETED) continue;
            if ($this->isRead($id, (string) $s['category'])) continue;
            $out[$id] = $s;
        }
        return $out;
    }

    /**
     * An action evidenced only by execution history, shaped like every other entry.
     *
     * `registry_active` is true because the gateway reads it as "may this proceed to
     * governance", and 45 completed runs is stronger evidence of that than a registry
     * flag. `evidence_source.registry` states plainly that no registry row exists, so
     * the claim stays attributable. `owner_agents` is empty because that is the honest
     * answer: no agent in the registry claims this action.
     */
    private function observedEntry(string $id, array $shape, int $wsId): array
    {
        $provider = self::PROVIDER_MAP[$id] ?? 'internal';
        $ready    = $this->providerReady($provider, $wsId);
        $cost     = (int) $shape['max_cost'];

        return [
            'capability_id'   => $id,
            'owner_agents'    => [],
            'executor'        => 'laravel',
            'provider'        => $provider,
            'engine'          => $shape['engine'],
            'operation_type'  => 'mutation',
            'supports_read'     => false,
            'supports_mutation' => true,

            'registry_active'       => true,
            'provider_ready'        => $ready['ready'],
            'workspace_available'   => $ready['ready'],
            'workspace_configuration' => $ready['configuration'],
            'missing_configuration' => $ready['missing'],

            'approval_inputs' => [
                'category'              => $shape['category'],
                'category_default_mode' => $this->categoryDefaultMode((string) $shape['category']),
                'risk_class'            => $this->riskClass($id, (string) $shape['category'], $cost),
                'cost_class'            => $this->costClass($cost),
                'observed_max_credits'  => $cost,
            ],

            'required_parameters' => $this->requiredParameters($id),
            'evidence_source'     => [
                'registry'  => 'NOT in agent_capabilities — admitted on execution history',
                'shape'     => "tasks history ({$shape['runs']} runs, {$shape['completed']} completed)",
                'provider'  => $ready['evidence'],
            ],
        ];
    }

    /**
     * Engine, category and observed cost, from real execution history.
     * There is no static capability config in this codebase — verified 2026-08-13,
     * config/capabilities.php does not exist and agent_capabilities.constraints is
     * empty on all 381 rows. History is therefore the only evidence, and rows with a
     * NULL category are ignored rather than allowed to erase a known one.
     */
    private function observedShapes(): array
    {
        $out = [];
        foreach (DB::table('tasks')
            ->selectRaw("action, engine, category, COUNT(*) c, MAX(credit_cost) maxcost,
                         SUM(status = 'completed') done")
            ->whereNotNull('category')->where('category', '!=', '')
            ->groupBy('action', 'engine', 'category')
            ->orderByDesc('c')->get() as $r) {
            $a = (string) $r->action;
            if (isset($out[$a])) {                       // keep the most common shape
                $out[$a]['max_cost']  = max($out[$a]['max_cost'], (int) $r->maxcost);
                $out[$a]['runs']     += (int) $r->c;
                $out[$a]['completed']+= (int) $r->done;
                continue;
            }
            $out[$a] = ['engine' => (string) $r->engine, 'category' => (string) $r->category,
                        'max_cost' => (int) $r->maxcost, 'runs' => (int) $r->c,
                        'completed' => (int) $r->done];
        }
        return $out;
    }

    /** Mirrors TaskService.php:195-199. Reported as an INPUT, never as the decision. */
    private function categoryDefaultMode(string $category): string
    {
        return match ($category) {
            'publish'                           => 'protected',
            'create', 'crm', 'campaign'         => 'review',
            'research', 'optimize', 'operations' => 'auto',
            default                             => 'review',
        };
    }

    /**
     * Mirrors ChatActionProposal::riskTier(), which is private and belongs to the
     * governance path. cmtest asserts the two agree, so the copy cannot drift silently.
     */
    public function riskClass(string $action, string $category, int $cost): string
    {
        $a = strtolower($action);
        if (str_contains($a, 'delete') || str_contains($a, 'remove')
            || str_contains($a, 'destroy')) return 'destructive';
        if ($category === 'publish' || str_contains($a, 'publish')) return 'publish';
        if (str_contains($a, 'send') || str_contains($a, 'email')) return 'outbound';
        if ($cost > 0) return 'spend';
        return 'standard';
    }

    private function costClass(int $maxCredits): string
    {
        if ($maxCredits <= 0) return 'free';
        if ($maxCredits <= 3) return 'low';
        return 'metered';
    }

    private function isRead(string $id, string $category): bool
    {
        // Commissioning work is never a read, whatever the verb or category suggests.
        if (in_array($id, self::TASK_CREATING, true)) return false;
        // Conversely, what the gateway executes synchronously IS a read, whatever a single
        // historical task row happened to be categorised as.
        if (in_array($id, self::SYNC_READ_EXECUTORS, true)) return true;
        if (in_array($category, ['research'], true)) return true;
        foreach (self::READ_VERBS as $v)
            if (str_starts_with($id, $v . '_') || $id === $v) return true;
        return false;
    }

    /**
     * What a tool intent must carry for Laravel to execute it deterministically.
     * Sourced from the connector signature where one exists, so it cannot drift from
     * the code that will actually run.
     */
    private function requiredParameters(string $id): array
    {
        return match ($id) {
            // `location` is NOT required: ToolIntentGateway defaults it to 'United States'
            // when absent. Declaring a defaulted parameter "required" had one measurable
            // effect — 2026-08-14, it made Runtime invent a location the owner had never
            // mentioned, which the parameter-provenance rule then correctly refused as a
            // guess. A parameter the executor supplies for itself was never required; the
            // old declaration was simply wrong, and it turned a sensible default into a
            // fabrication.
            'serp_analysis', 'competitor_serp' => ['keyword'],
            'competitor_keywords'              => ['domain'],
            'competitor_gaps'                  => ['domain'],
            'keyword_research', 'related_keywords' => ['keyword'],
            'track_keyword_rank'               => ['keyword', 'domain'],
            'publish_article'                  => ['article_id'],
            'improve_draft', 'generate_meta'   => ['article_id'],
            'insert_link'                      => ['article_id', 'target_url'],
            'create_lead'                      => ['name'],
            'update_lead', 'delete_lead', 'move_lead' => ['lead_id'],
            'generate_image', 'generate_image_mini'   => ['prompt'],
            default                            => [],
        };
    }
}
