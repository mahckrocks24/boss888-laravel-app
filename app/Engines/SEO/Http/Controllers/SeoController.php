<?php

namespace App\Engines\SEO\Http\Controllers;

use App\Http\Controllers\Api\BaseEngineController;
use App\Engines\SEO\Services\SeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoController extends BaseEngineController
{
    public function __construct(private SeoService $seo) {}

    protected function engineSlug(): string { return 'seo'; }

    // ═══════════════════════════════════════════════════════
    // READS — direct to service
    // ═══════════════════════════════════════════════════════

    public function aiStatus(Request $r): JsonResponse
    {
        return $this->readJson($this->seo->aiStatus($this->wsId($r)));
    }

    public function linkSuggestions(Request $r): JsonResponse
    {
        return $this->readJson(['links' => $this->seo->linkSuggestions($this->wsId($r), $r->all())]);
    }

    public function outboundLinks(Request $r): JsonResponse
    {
        return $this->readJson(['links' => $this->seo->outboundLinks($this->wsId($r), $r->all())]);
    }

    public function listGoals(Request $r): JsonResponse
    {
        return $this->readJson(['goals' => $this->seo->listGoals($this->wsId($r))]);
    }

    public function getGoal(Request $r, int $id): JsonResponse
    {
        return $this->readJson($this->seo->getGoal($this->wsId($r), $id));
    }

    public function agentStatus(Request $r): JsonResponse
    {
        return $this->readJson($this->seo->agentStatus($this->wsId($r)));
    }

    public function listKeywords(Request $r): JsonResponse
    {
        // listKeywords() now returns {keywords, usage, scan} — pass through directly
        return $this->readJson($this->seo->listKeywords($this->wsId($r), $r->all()));
    }

    public function listAudits(Request $r): JsonResponse
    {
        return $this->readJson(['audits' => $this->seo->listAudits($this->wsId($r), $r->all())]);
    }

    public function getAudit(Request $r, int $id): JsonResponse
    {
        return $this->readJson($this->seo->getAudit($this->wsId($r), $id));
    }

    public function dashboard(Request $r): JsonResponse
    {
        return $this->readJson($this->seo->getDashboard($this->wsId($r)));
    }

    public function report(Request $r): JsonResponse
    {
        return $this->readJson($this->seo->getReport($this->wsId($r)));
    }

    // ═══════════════════════════════════════════════════════
    // WRITES — through AI OS pipeline (credits, intelligence)
    // ═══════════════════════════════════════════════════════

    // ── Analysis Tools (cost credits) ────────────────────
    public function serpAnalysis(Request $r): JsonResponse
    {
        $r->validate(['keyword' => 'required_without:url|string', 'url' => 'required_without:keyword|string']);
        return $this->executeAction($r, 'serp_analysis', $r->all());
    }

    public function aiReport(Request $r): JsonResponse
    {
        $r->validate(['url' => 'required|string']);
        return $this->executeAction($r, 'ai_report', $r->all());
    }

    public function deepAudit(Request $r): JsonResponse
    {
        $r->validate(['url' => 'required|string']);
        return $this->executeAction($r, 'deep_audit', $r->all());
    }

    // ── Content Delegation (cross-engine, costs credits) ──
    public function improveDraft(Request $r): JsonResponse
    {
        return $this->executeAction($r, 'improve_draft', $r->all());
    }

    public function writeArticle(Request $r): JsonResponse
    {
        return $this->executeAction($r, 'write_article', $r->all());
    }

    // ── Link Management ──────────────────────────────────
    public function generateLinks(Request $r): JsonResponse
    {
        return $this->executeAction($r, 'generate_links', $r->all());
    }

    public function insertLink(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'insert_link', ['link_id' => $id]);
    }

    public function dismissLink(Request $r, int $id): JsonResponse
    {
        // Dismiss is low-cost, direct
        return $this->readJson(['dismissed' => $this->seo->dismissLink($this->wsId($r), $id)]);
    }

    public function checkOutbound(Request $r): JsonResponse
    {
        return $this->executeAction($r, 'check_outbound', $r->all());
    }

    // ── Goals (autonomous = costs credits) ───────────────
    public function createGoal(Request $r): JsonResponse
    {
        $r->validate(['title' => 'required|string|max:255']);
        return $this->executeAction($r, 'autonomous_goal', $r->all(), 'manual', 201);
    }

    public function pauseGoal(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'pause_goal', ['goal_id' => $id]);
    }

    public function resumeGoal(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'resume_goal', ['goal_id' => $id]);
    }

    // ── Keywords ─────────────────────────────────────────
    public function addKeyword(Request $r): JsonResponse
    {
        $r->validate(['keyword' => 'required|string|max:255']);
        return $this->executeAction($r, 'add_keyword', $r->all(), 'manual', 201);
    }

    public function deleteKeyword(Request $r, int $id): JsonResponse
    {
        $this->seo->deleteKeyword($this->wsId($r), $id);
        return $this->readJson(['success' => true]);
    }
}
