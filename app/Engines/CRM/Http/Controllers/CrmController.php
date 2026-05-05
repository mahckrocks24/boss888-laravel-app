<?php

namespace App\Engines\CRM\Http\Controllers;

use App\Http\Controllers\Api\BaseEngineController;
use App\Engines\CRM\Services\CrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmController extends BaseEngineController
{
    public function __construct(private CrmService $crm) {}

    protected function engineSlug(): string { return 'crm'; }

    // ═══════════════════════════════════════════════════════
    // READS — direct to service (no credits, no pipeline)
    // ═══════════════════════════════════════════════════════

    public function listLeads(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->listLeads($this->wsId($r), $r->all()));
    }

    public function getLead(Request $r, int $id): JsonResponse
    {
        return $this->readJson($this->crm->getLead($this->wsId($r), $id));
    }

    public function exportLeads(Request $r): \Illuminate\Http\Response
    {
        $csv = $this->crm->exportLeads($this->wsId($r), $r->all());
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="leads-' . date('Y-m-d') . '.csv"']);
    }

    public function listContacts(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->listContacts($this->wsId($r), $r->all()));
    }

    public function getContact(Request $r, int $id): JsonResponse
    {
        return $this->readJson($this->crm->getContact($this->wsId($r), $id));
    }

    public function findDuplicates(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->findDuplicateContacts($this->wsId($r)));
    }

    public function listDeals(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->listDeals($this->wsId($r), $r->all()));
    }

    public function getDeal(Request $r, int $id): JsonResponse
    {
        return $this->readJson($this->crm->getDeal($this->wsId($r), $id));
    }

    public function pipeline(Request $r): JsonResponse
    {
        return $this->readJson(['pipeline' => $this->crm->getPipeline($this->wsId($r))]);
    }

    public function stages(Request $r): JsonResponse
    {
        return $this->readJson(['stages' => $this->crm->getStages($this->wsId($r))]);
    }

    public function listActivities(Request $r): JsonResponse
    {
        $r->validate(['entity_type' => 'required|string', 'entity_id' => 'required|integer']);
        return $this->readJson(['activities' => $this->crm->listActivities($this->wsId($r), $r->input('entity_type'), $r->input('entity_id'))]);
    }

    public function todayView(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->getTodayView($this->wsId($r)));
    }

    public function listNotes(Request $r): JsonResponse
    {
        $r->validate(['entity_type' => 'required|string', 'entity_id' => 'required|integer']);
        return $this->readJson(['notes' => $this->crm->listNotes($this->wsId($r), $r->input('entity_type'), $r->input('entity_id'))]);
    }

    public function revenue(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->getRevenueSummary($this->wsId($r)));
    }

    public function reporting(Request $r): JsonResponse
    {
        return $this->readJson($this->crm->getReporting($this->wsId($r)));
    }

    // ═══════════════════════════════════════════════════════
    // WRITES — through AI OS pipeline (credits, approvals, intelligence)
    // ═══════════════════════════════════════════════════════

    public function createLead(Request $r): JsonResponse
    {
        $r->validate(['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255']);
        return $this->executeAction($r, 'create_lead', $r->all(), 'manual', 201);
    }

    public function updateLead(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'update_lead', array_merge($r->all(), ['lead_id' => $id]));
    }

    public function deleteLead(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'delete_lead', ['lead_id' => $id]);
    }

    public function restoreLead(Request $r, int $id): JsonResponse
    {
        // Restore is a direct operation (no credits needed)
        return $this->readJson($this->crm->restoreLead($id));
    }

    public function scoreLead(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'score_lead', ['lead_id' => $id, 'score' => $r->input('score')]);
    }

    public function assignLead(Request $r, int $id): JsonResponse
    {
        return $this->executeAction($r, 'assign_lead', ['lead_id' => $id, 'assigned_to' => $r->input('assigned_to')]);
    }

    public function importLeads(Request $r): JsonResponse
    {
        $r->validate(['rows' => 'required|array|min:1']);
        return $this->executeAction($r, 'import_leads', ['rows' => $r->input('rows')], 'manual', 201);
    }

    public function createContact(Request $r): JsonResponse
    {
        $r->validate(['name' => 'required|string|max:255']);
        return $this->executeAction($r, 'create_contact', $r->all(), 'manual', 201);
    }

    public function updateContact(Request $r, int $id): JsonResponse
    {
        // Direct update (no credits)
        return $this->readJson($this->crm->updateContact($id, $r->all()));
    }

    public function deleteContact(Request $r, int $id): JsonResponse
    {
        $this->crm->deleteContact($id);
        return $this->readJson(['success' => true]);
    }

    public function mergeContacts(Request $r): JsonResponse
    {
        $r->validate(['keep_id' => 'required|integer', 'merge_id' => 'required|integer']);
        return $this->executeAction($r, 'merge_contacts', ['keep_id' => $r->input('keep_id'), 'merge_id' => $r->input('merge_id')]);
    }

    public function createDeal(Request $r): JsonResponse
    {
        $r->validate(['title' => 'required|string|max:255', 'value' => 'required|numeric|min:0']);
        return $this->executeAction($r, 'create_deal', $r->all(), 'manual', 201);
    }

    public function updateDeal(Request $r, int $id): JsonResponse
    {
        // Direct update (no credits for field changes)
        return $this->readJson($this->crm->updateDeal($id, $r->all(), $this->userId($r)));
    }

    public function updateDealStage(Request $r, int $id): JsonResponse
    {
        $r->validate(['stage' => 'required|string']);
        // Stage change goes through pipeline — triggers automations
        return $this->executeAction($r, 'update_deal_stage', ['deal_id' => $id, 'stage' => $r->input('stage')]);
    }

    public function createStage(Request $r): JsonResponse
    {
        $r->validate(['name' => 'required|string|max:100']);
        $id = $this->crm->createStage($this->wsId($r), $r->all());
        return $this->readJson(['stage_id' => $id], 201);
    }

    public function updateStage(Request $r, int $id): JsonResponse
    {
        $this->crm->updateStage($id, $r->all());
        return $this->readJson(['success' => true]);
    }

    public function deleteStage(int $id): JsonResponse
    {
        $this->crm->deleteStage($id);
        return $this->readJson(['success' => true]);
    }

    public function reorderStages(Request $r): JsonResponse
    {
        $r->validate(['stage_ids' => 'required|array']);
        $this->crm->reorderStages($this->wsId($r), $r->input('stage_ids'));
        return $this->readJson(['success' => true]);
    }

    public function logActivity(Request $r): JsonResponse
    {
        $r->validate(['type' => 'required|string', 'entity_id' => 'required|integer']);
        return $this->executeAction($r, 'log_activity', array_merge($r->all(), ['entity_type' => $r->input('entity_type', 'Lead')]));
    }

    public function completeActivity(Request $r, int $id): JsonResponse
    {
        return $this->readJson($this->crm->completeActivity($id));
    }

    public function addNote(Request $r): JsonResponse
    {
        $r->validate(['entity_type' => 'required|string', 'entity_id' => 'required|integer', 'body' => 'required|string']);
        return $this->executeAction($r, 'add_note', $r->all());
    }

    public function deleteNote(int $id): JsonResponse
    {
        $this->crm->deleteNote($id);
        return $this->readJson(['success' => true]);
    }
}
