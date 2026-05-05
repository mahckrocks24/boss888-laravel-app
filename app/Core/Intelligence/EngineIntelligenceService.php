<?php

namespace App\Core\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Engine Intelligence Layer — each engine has its own brain.
 *
 * Separate from agent intelligence. This layer tells agents:
 *   1. What tools exist in this engine (blueprints)
 *   2. How to use each tool effectively (credit costs, parameters)
 *   3. What has worked before (effectiveness data from past executions)
 *   4. Constraints and plan limits
 *   5. Best practices per engine + region
 *
 * ── v2.0 — No more ghost layer ─────────────────────────────────────
 *   1. seedAll() bulk-seeds all 11 engines in one call
 *   2. recordToolUsage() is self-healing — creates stub rows for known
 *      engines on first use instead of silently no-oping
 *   3. All 11 engines have blueprints, practices, and constraints
 */
class EngineIntelligenceService
{
    /**
     * Canonical list of all 11 engines. Source of truth.
     */
    public const ENGINES = [
        'crm', 'seo', 'write', 'creative', 'marketing', 'social',
        'builder', 'calendar', 'beforeafter', 'traffic', 'manualedit',
    ];

    public function getBriefing(string $engine): array
    {
        return [
            'engine' => $engine,
            'tools' => $this->getToolBlueprints($engine),
            'best_practices' => $this->getBestPractices($engine),
            'constraints' => $this->getConstraints($engine),
            'effectiveness_data' => $this->getEffectivenessData($engine),
        ];
    }

    public function buildEnginePrompt(string $engine): string
    {
        $briefing = $this->getBriefing($engine);
        $lines = ["=== {$engine} Engine Intelligence ==="];

        if (!empty($briefing['tools'])) {
            $lines[] = "\nAvailable tools:";
            foreach ($briefing['tools'] as $tool) {
                $meta = json_decode($tool->metadata_json ?? '{}', true) ?: [];
                $cost = $meta['credit_cost'] ?? 0;
                $lines[] = "- {$tool->key} ({$cost} credits): " . substr($tool->content, 0, 200);
                if ($tool->effectiveness_score !== null) {
                    $lines[] = "  Effectiveness: " . round($tool->effectiveness_score * 100) . "% (used {$tool->usage_count} times)";
                } elseif ($tool->usage_count > 0) {
                    $lines[] = "  Usage: {$tool->usage_count} times (no scored outcomes yet)";
                }
            }
        }

        if (!empty($briefing['best_practices'])) {
            $lines[] = "\nBest practices:";
            foreach ($briefing['best_practices'] as $bp) {
                $lines[] = "- {$bp->content}";
            }
        }

        if (!empty($briefing['constraints'])) {
            $lines[] = "\nConstraints:";
            foreach ($briefing['constraints'] as $c) {
                $lines[] = "- {$c->content}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Record that a tool was used and its outcome.
     * SELF-HEALING: Creates stub rows for known engines on first use
     * instead of silently no-oping. Fixes the ghost-layer failure mode.
     */
    public function recordToolUsage(string $engine, string $toolKey, ?float $effectivenessScore = null): void
    {
        $existing = DB::table('engine_intelligence')
            ->where('engine', $engine)
            ->where('knowledge_type', 'tool_blueprint')
            ->where('key', $toolKey)
            ->first();

        // Self-heal: create a stub row for known engines on first use.
        if (!$existing && in_array($engine, self::ENGINES, true)) {
            $this->ensureBlueprintExists($engine, $toolKey);
            $existing = DB::table('engine_intelligence')
                ->where('engine', $engine)
                ->where('knowledge_type', 'tool_blueprint')
                ->where('key', $toolKey)
                ->first();
        }

        if (!$existing) return;

        $update = [
            'usage_count' => DB::raw('usage_count + 1'),
            'updated_at' => now(),
        ];

        if ($effectivenessScore !== null) {
            $oldScore = $existing->effectiveness_score;
            $oldCount = $existing->usage_count ?? 0;

            if ($oldScore === null || $oldCount === 0) {
                $update['effectiveness_score'] = round($effectivenessScore, 2);
            } else {
                $newScore = ($oldScore * $oldCount + $effectivenessScore) / ($oldCount + 1);
                $update['effectiveness_score'] = round($newScore, 2);
            }
        }

        DB::table('engine_intelligence')->where('id', $existing->id)->update($update);
    }

    private function ensureBlueprintExists(string $engine, string $toolKey): void
    {
        $defaults = $this->getDefaultBlueprints($engine);
        if (isset($defaults[$toolKey])) {
            $data = $defaults[$toolKey];
            $this->store($engine, 'tool_blueprint', $toolKey, $data['description'], $data['metadata'] ?? []);
        } else {
            $this->store($engine, 'tool_blueprint', $toolKey, "Auto-created stub for {$engine}.{$toolKey}", ['credit_cost' => 0, 'auto_created' => true]);
        }
    }

    public function store(string $engine, string $type, string $key, string $content, array $metadata = []): void
    {
        DB::table('engine_intelligence')->updateOrInsert(
            ['engine' => $engine, 'knowledge_type' => $type, 'key' => $key],
            [
                'content' => $content,
                'metadata_json' => json_encode($metadata),
                'updated_at' => now(),
                'created_at' => DB::raw('IFNULL(created_at, NOW())'),
            ]
        );
    }

    public function seedEngineIntelligence(string $engine): array
    {
        $counts = ['blueprints' => 0, 'practices' => 0, 'constraints' => 0];

        foreach ($this->getDefaultBlueprints($engine) as $key => $data) {
            $this->store($engine, 'tool_blueprint', $key, $data['description'], $data['metadata'] ?? []);
            $counts['blueprints']++;
        }

        foreach ($this->getDefaultPractices($engine) as $i => $practice) {
            $this->store($engine, 'best_practice', "practice_{$i}", $practice);
            $counts['practices']++;
        }

        foreach ($this->getDefaultConstraints($engine) as $i => $constraint) {
            $this->store($engine, 'constraint', "constraint_{$i}", $constraint);
            $counts['constraints']++;
        }

        return $counts;
    }

    /**
     * Seed intelligence data for ALL 11 engines.
     * Idempotent — safe to re-run (uses updateOrInsert).
     */
    public function seedAll(): array
    {
        $report = [];
        foreach (self::ENGINES as $engine) {
            $report[$engine] = $this->seedEngineIntelligence($engine);
        }
        return $report;
    }

    public function hasBeenSeeded(): bool
    {
        return DB::table('engine_intelligence')
            ->where('knowledge_type', 'tool_blueprint')
            ->exists();
    }

    // ── Queries ──────────────────────────────────────────

    private function getToolBlueprints(string $engine): array
    {
        return DB::table('engine_intelligence')
            ->where('engine', $engine)->where('knowledge_type', 'tool_blueprint')
            ->orderByDesc('usage_count')->get()->toArray();
    }

    private function getBestPractices(string $engine): array
    {
        return DB::table('engine_intelligence')
            ->where('engine', $engine)->where('knowledge_type', 'best_practice')
            ->get()->toArray();
    }

    private function getConstraints(string $engine): array
    {
        return DB::table('engine_intelligence')
            ->where('engine', $engine)->where('knowledge_type', 'constraint')
            ->get()->toArray();
    }

    private function getEffectivenessData(string $engine): array
    {
        return DB::table('engine_intelligence')
            ->where('engine', $engine)->where('knowledge_type', 'effectiveness_data')
            ->orderByDesc('effectiveness_score')
            ->limit(10)->get()->toArray();
    }

    // ── Default Data — ALL 11 ENGINES ────────────────────────

    private function getDefaultBlueprints(string $engine): array
    {
        $map = [
            'crm' => [
                'create_lead' => ['description' => 'Create a new lead. Requires: name. Optional: email, phone, company, source, website, city, country. Auto-scores on creation. Fires lead_created automation trigger.', 'metadata' => ['credit_cost' => 0]],
                'update_lead' => ['description' => 'Update lead fields. Supports partial updates. Triggers lead_updated automation.', 'metadata' => ['credit_cost' => 0]],
                'score_lead' => ['description' => 'Score a lead 0-100. Auto-scoring uses: email completeness (+15), phone (+10), company (+10), website (+5), engagement signals, deal value, activity count.', 'metadata' => ['credit_cost' => 0]],
                'assign_lead' => ['description' => 'Assign lead to team member. Updates assigned_to field and notifies assignee.', 'metadata' => ['credit_cost' => 0]],
                'import_leads' => ['description' => 'Bulk import leads from CSV rows. Deduplicates by email. Returns imported/skipped/errors counts.', 'metadata' => ['credit_cost' => 0]],
                'create_contact' => ['description' => 'Create a contact (person associated with a company). Supports full contact fields.', 'metadata' => ['credit_cost' => 0]],
                'merge_contacts' => ['description' => 'Merge duplicate contacts. Keeps master record, migrates activities/deals.', 'metadata' => ['credit_cost' => 0]],
                'create_deal' => ['description' => 'Create a deal linked to a lead/contact. Pipeline stages: discovery (10%) to proposal (30%) to negotiation (60%) to closed_won (100%) or closed_lost (0%).', 'metadata' => ['credit_cost' => 0]],
                'update_deal_stage' => ['description' => 'Move a deal to a new pipeline stage. Probability auto-updates from stage.', 'metadata' => ['credit_cost' => 0]],
                'log_activity' => ['description' => 'Log an activity (call, email, meeting, note) against a lead/contact/deal. Feeds timeline and Today View.', 'metadata' => ['credit_cost' => 0]],
                'add_note' => ['description' => 'Add a free-form note to a CRM entity. Timestamped, attributed to user.', 'metadata' => ['credit_cost' => 0]],
            ],
            'seo' => [
                'serp_analysis' => ['description' => 'Analyze search engine results for a keyword/URL. Returns ranking data, competitors, opportunities.', 'metadata' => ['credit_cost' => 1]],
                'deep_audit' => ['description' => 'Full technical SEO audit of a website. Checks: page speed, mobile-friendliness, meta tags, schema, crawlability, Core Web Vitals.', 'metadata' => ['credit_cost' => 3]],
                'ai_report' => ['description' => 'AI-generated comprehensive SEO report with recommendations.', 'metadata' => ['credit_cost' => 2]],
                'add_keyword' => ['description' => 'Add a keyword to tracking. Monitors rank, volume, difficulty, CPC over time.', 'metadata' => ['credit_cost' => 0]],
                'autonomous_goal' => ['description' => 'Set an autonomous SEO goal. Agent will create and execute a multi-step plan to achieve it.', 'metadata' => ['credit_cost' => 5]],
                'link_suggestions' => ['description' => 'Suggest internal/external links for a page based on topical relevance.', 'metadata' => ['credit_cost' => 1]],
                'check_outbound' => ['description' => 'Verify outbound links on a page for broken targets and relevance.', 'metadata' => ['credit_cost' => 0]],
            ],
            'write' => [
                'write_article' => ['description' => 'AI-generate a full article. Requires: title or topic. Optional: type, tone, length, keywords, audience. Creates draft with version history.', 'metadata' => ['credit_cost' => 3]],
                'improve_draft' => ['description' => 'AI-improve an existing draft. Enhances readability, SEO, engagement.', 'metadata' => ['credit_cost' => 2]],
                'generate_outline' => ['description' => 'AI-generate article outline with headings and key points.', 'metadata' => ['credit_cost' => 1]],
                'generate_headlines' => ['description' => 'Generate 5-10 headline variations for a topic. A/B test candidates.', 'metadata' => ['credit_cost' => 1]],
                'generate_meta' => ['description' => 'Generate SEO meta title and description for an article.', 'metadata' => ['credit_cost' => 1]],
            ],
            'creative' => [
                'create_asset' => ['description' => 'Create an asset record. Used for tracking uploaded and generated media.', 'metadata' => ['credit_cost' => 0]],
                'generate_image' => ['description' => 'AI-generate an image. Provider: OpenAI gpt-image-1. Supports: various styles, sizes, brand-aligned generation.', 'metadata' => ['credit_cost' => 2]],
                'generate_video' => ['description' => 'AI-generate a video. Provider: MiniMax Hailuo-02 then Runway fallback. Async job queue. Max 5 seconds.', 'metadata' => ['credit_cost' => 5]],
                'upscale_image' => ['description' => 'Upscale an image to higher resolution while preserving quality.', 'metadata' => ['credit_cost' => 1]],
            ],
            'marketing' => [
                'create_campaign' => ['description' => 'Create email/SMS/WhatsApp campaign. Requires: name, type. Set recipients, schedule, template.', 'metadata' => ['credit_cost' => 0]],
                'schedule_campaign' => ['description' => 'Schedule a campaign for future send. Auto-creates calendar event.', 'metadata' => ['credit_cost' => 0]],
                'send_campaign' => ['description' => 'Send a campaign immediately to all matching recipients. Uses 1 credit per recipient.', 'metadata' => ['credit_cost' => 1]],
                'create_automation' => ['description' => 'Create automation workflow. Triggers: lead_created, deal_stage_changed, form_submitted, tag_added, date_reached.', 'metadata' => ['credit_cost' => 0]],
                'create_template' => ['description' => 'Create reusable email/SMS template. Supports merge tags.', 'metadata' => ['credit_cost' => 0]],
            ],
            'social' => [
                'social_create_post' => ['description' => 'Create social media post. Supports: Instagram, Facebook, Twitter, LinkedIn, Snapchat, TikTok. Schedule or publish immediately.', 'metadata' => ['credit_cost' => 0]],
                'social_schedule_post' => ['description' => 'Schedule a post for future publish. Auto-creates calendar event.', 'metadata' => ['credit_cost' => 0]],
                'social_publish_post' => ['description' => 'Publish a draft post immediately to connected accounts.', 'metadata' => ['credit_cost' => 0]],
            ],
            'builder' => [
                'wizard_generate' => ['description' => 'Arthur AI website wizard. Input: business name, industry, goal. Generates complete website with pages.', 'metadata' => ['credit_cost' => 5]],
                'create_website' => ['description' => 'Create empty website. Add pages, sections, elements manually or via AI.', 'metadata' => ['credit_cost' => 0]],
                'create_page' => ['description' => 'Create a new page inside a website. Schema v1 (sections, containers, elements).', 'metadata' => ['credit_cost' => 0]],
                'publish_website' => ['description' => 'Publish a website to live URL. Triggers deploy pipeline.', 'metadata' => ['credit_cost' => 0]],
            ],
            'calendar' => [
                'create_event' => ['description' => 'Create a calendar event. Supports: task deadlines, meetings, campaign launches, content publish dates, social posts.', 'metadata' => ['credit_cost' => 0]],
                'update_event' => ['description' => 'Update an existing event. Drag-drop reschedule supported.', 'metadata' => ['credit_cost' => 0]],
                'delete_event' => ['description' => 'Delete a calendar event. Cascade removes linked reminders.', 'metadata' => ['credit_cost' => 0]],
            ],
            'beforeafter' => [
                'create_design' => ['description' => 'Create an interior design record. Input: before image URL, room type, style. Status: processing to completed.', 'metadata' => ['credit_cost' => 2]],
                'generate_after_image' => ['description' => 'AI-generate the after image via gpt-image-1. Includes Geometry Analyzer and 7-section design report.', 'metadata' => ['credit_cost' => 3]],
                'generate_design_report' => ['description' => 'Generate 7-section structured design report (room analysis, recommendations, palette, furniture, lighting, materials, budget).', 'metadata' => ['credit_cost' => 1]],
            ],
            'traffic' => [
                'create_rule' => ['description' => 'Create a traffic defense rule. Types: IP block, country block, referrer filter, user agent filter, behavior pattern.', 'metadata' => ['credit_cost' => 0]],
                'toggle_rule' => ['description' => 'Enable or disable a traffic rule without deleting it.', 'metadata' => ['credit_cost' => 0]],
                'evaluate_traffic' => ['description' => 'Score an incoming request against all active rules. Returns allow/flag/block with reasoning.', 'metadata' => ['credit_cost' => 0]],
            ],
            'manualedit' => [
                'create_canvas' => ['description' => 'Create a new canvas for manual image/creative editing. Loads initial state and operation history.', 'metadata' => ['credit_cost' => 0]],
                'apply_operation' => ['description' => 'Apply a canvas operation (layer add, transform, effect, text, shape). All mutations flow through this single dispatcher.', 'metadata' => ['credit_cost' => 0]],
                'export_canvas' => ['description' => 'Export canvas to PNG/JPG via server-side GD renderer. Deterministic, no AI.', 'metadata' => ['credit_cost' => 0]],
            ],
        ];

        return $map[$engine] ?? [];
    }

    private function getDefaultPractices(string $engine): array
    {
        $map = [
            'crm' => [
                'Score leads immediately on creation',
                'Follow up within 24 hours of qualification',
                'Log every interaction as an activity',
                'Use pipeline stages consistently',
                'Export and backup leads monthly',
            ],
            'seo' => [
                'Run deep audit before any SEO campaign',
                'Track keywords before and after content publish',
                'Build internal links between related content',
                'Prioritize Arabic long-tail keywords for MENA markets',
                'Check Core Web Vitals monthly',
            ],
            'write' => [
                'Always create outline before full article',
                'Target 1500-2500 words for SEO-focused blog posts',
                'Include 1 image per 300 words',
                'Use H2/H3 structure for readability',
                'Localize content for target market language and culture',
            ],
            'creative' => [
                'Use brand colors in all generated images',
                'Create multiple variations for A/B testing',
                'Optimize image dimensions per platform',
                'Video thumbnails should include text overlay',
            ],
            'marketing' => [
                'A/B test subject lines on every campaign',
                'Send test emails before scheduling',
                'Best send times: Tuesday/Wednesday 10am local',
                'Segment audiences by engagement level',
            ],
            'social' => [
                'Post consistency matters more than frequency',
                'Use platform-native features (Reels, Stories, Threads)',
                'Engage with comments within 1 hour',
                'Best posting times vary by platform and region',
            ],
            'builder' => [
                'Always include header and navigation menu in every site',
                'Use blueprint templates for speed, AI only for customization',
                'Provide fallback content if AI is unavailable',
                'Mobile responsiveness is mandatory, not optional',
                'Keep Arthur wizard prompts under 3 steps',
            ],
            'calendar' => [
                'Color-code events by engine/category for scanability',
                'Sync scheduled campaigns and social posts automatically',
                'Use timezone-aware timestamps (workspace location)',
                'Send reminders 15 minutes before time-sensitive events',
            ],
            'beforeafter' => [
                'Center-crop upload to nearest DALL-E aspect ratio',
                'Run Geometry Analyzer before generation to understand room layout',
                'Always generate the 7-section structured design report alongside the image',
                'Use ResizeObserver for slider responsiveness',
                'Delegate all AI generation to CREATIVE888 — BeforeAfter is UI only',
            ],
            'traffic' => [
                'Enable rules incrementally — monitor false positives first',
                'IP blocks are last resort; prefer behavior + pattern detection',
                'Review stats weekly and tune thresholds',
                'Whitelist known good bots (Googlebot, Bingbot) before blocking unknowns',
            ],
            'manualedit' => [
                'All canvas mutations must flow through single apply_operation dispatcher',
                'Use layer groups for complex compositions',
                'Export at final size only — preview at reduced resolution',
                'Save canvas state on every operation for undo/redo',
            ],
        ];
        return $map[$engine] ?? [];
    }

    private function getDefaultConstraints(string $engine): array
    {
        $map = [
            'crm' => [
                'Max 50000 leads per workspace (Growth plan)',
                'CSV import limited to 10000 rows per batch',
                'Lead score range 0-100',
                'Pipeline stages must follow defined order',
            ],
            'seo' => [
                'Max 50 tracked keywords per workspace (Growth plan)',
                'Deep audit limited to 100 pages',
                'SERP data refreshes every 24 hours',
            ],
            'write' => [
                'Max article length: 5000 words per generation',
                'Plagiarism check required before publish',
                'Arabic content requires RTL formatting',
            ],
            'creative' => [
                'Max image size: 4096x4096',
                'Video max duration: 5 seconds (Hailuo-02)',
                'No copyrighted content generation',
                'Brand guidelines must be followed',
            ],
            'marketing' => [
                'Max 1000 recipients per campaign (Growth plan)',
                'WhatsApp requires Business API approval',
                'SMS requires number verification',
            ],
            'social' => [
                'Instagram/Facebook require OAuth 2.0 connected account',
                'LinkedIn posts limited to 3000 characters',
                'Twitter/X posts limited to 280 characters',
                'TikTok requires mobile-optimized 9:16 video assets',
            ],
            'builder' => [
                'Max 50 pages per website (Growth plan)',
                'Max 100 sections per page',
                'Header and footer are mandatory on every page',
                'AI customization is optional — templates must work standalone',
            ],
            'calendar' => [
                'Events must have a workspace_id (no cross-workspace leakage)',
                'Recurring events limited to 365 instances',
                'Past events are read-only',
            ],
            'beforeafter' => [
                'Before image required — no text-only generation',
                'Image must be uploaded to shared storage before processing',
                'Design reports are generated synchronously with the after image',
                'Room type must match a supported value',
            ],
            'traffic' => [
                'Rules evaluated in priority order',
                'Block actions require reason logging',
                'Max 100 active rules per workspace',
                'Whitelist always overrides blocklist',
            ],
            'manualedit' => [
                'Canvas max dimension 4000x4000 pixels',
                'Max 50 layers per canvas',
                'Export via GD only — no client-side rendering trust',
                'Operations must be PHP 7.4 compatible',
            ],
        ];
        return $map[$engine] ?? [];
    }
}
