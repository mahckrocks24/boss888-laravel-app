<?php

namespace App\Engines\CRM\Services;

use App\Models\Lead;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Activity;
use App\Models\Note;
use App\Connectors\DeepSeekConnector;
use App\Engines\Creative\Services\CreativeService;
use App\Core\Intelligence\EngineIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmService
{
    public function __construct(
        private DeepSeekConnector         $llm,
        private CreativeService            $creative,
        private EngineIntelligenceService  $engineIntel,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    // ── Creative blueprint helper ────────────────────────────────────────────
    private function blueprint(int $wsId, string $type, array $context = []): array
    {
        try {
            $result = $this->creative->generateThroughBlueprint('crm', $type, $wsId, $context);
            return $result['output'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OUTREACH GENERATION (AI — routes through Creative)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate outreach email copy for a lead or contact.
     * Routes through Creative blueprint, then through RuntimeClient::aiRun().
     *
     * REFACTORED 2026-04-12 (Phase 2L CRM1 / doc 14): now routes through
     * RuntimeClient::aiRun('email_generation', ...) instead of direct
     * DeepSeekConnector. Outreach is essentially a one-off personalized email,
     * so the runtime's email_generation task type is the right fit.
     *
     * Hands vs brain pattern: runtime generates, Laravel persists to a new
     * crm_outreach_drafts table (TODO — for now still ephemeral, but at least
     * the bypass site is eliminated).
     */
    public function generateOutreach(int $wsId, array $params): array
    {
        $leadId  = $params['lead_id'] ?? null;
        $stage   = $params['pipeline_stage'] ?? 'new';
        $goal    = $params['goal'] ?? 'Book a discovery call';
        $leadCtx = '';

        if ($leadId) {
            $lead    = DB::table('leads')->where('id', $leadId)->first();
            $leadCtx = $lead ? "Lead: {$lead->name}, Company: {$lead->company}, Status: {$lead->status}" : '';
        }

        // ── Creative blueprint (R5) ─────────────────────────────────────────
        $bp = $this->blueprint($wsId, 'outreach', [
            'lead_context'   => $leadCtx,
            'pipeline_stage' => $stage,
            'goal'           => $goal,
        ]);
        $opening    = $bp['opening_strategy'] ?? 'Start with a relevant insight';
        $valueProp  = $bp['value_proposition'] ?? '';
        $ctaText    = $bp['cta'] ?? $goal;
        $toneInstr  = $bp['tone_instructions'] ?? 'professional and warm';
        $avoid      = $bp['avoid'] ?? '';
        // ───────────────────────────────────────────────────────────────────

        $context = array_filter([
            'lead_context'     => $leadCtx ?: null,
            'pipeline_stage'   => $stage,
            'goal'             => $goal,
            'opening_strategy' => $opening,
            'value_proposition'=> $valueProp ?: null,
            'cta'              => $ctaText,
            'tone'             => $toneInstr,
            'avoid'            => $avoid ?: null,
            'agent_voice'      => 'Elena — CRM and sales specialist',
        ], fn($v) => $v !== null && $v !== '');

        $userPrompt = "Write a personalized outreach email.\n"
                    . "Goal: {$goal}\n"
                    . ($leadCtx ? "Lead: {$leadCtx}\n" : '')
                    . "Pipeline stage: {$stage}\n"
                    . "Output as JSON: {\"subject\":\"...\",\"body\":\"...\",\"follow_up_note\":\"...\"}";

        $result = $this->runtime->aiRun('email_generation', $userPrompt, $context, 600);

        $this->engineIntel->recordToolUsage('crm', 'generate_outreach', $result['success'] ? 0.85 : 0.3);

        // Try to parse JSON from the runtime text response
        $parsed = null;
        if ($result['success'] && !empty($result['text'])) {
            $maybe = json_decode($result['text'], true);
            if (is_array($maybe)) $parsed = $maybe;
        }

        if ($result['success'] && $parsed) {
            return array_merge($parsed, [
                'generated'      => true,
                'lead_id'        => $leadId,
                'pipeline_stage' => $stage,
                'blueprint_used' => !empty($bp),
                'source'         => 'runtime',
            ]);
        }

        // Persist what we got even if JSON parsing failed
        if ($result['success'] && !empty($result['text'])) {
            return [
                'generated'      => true,
                'subject'        => "Outreach: {$goal}",
                'body'           => $result['text'],
                'lead_id'        => $leadId,
                'pipeline_stage' => $stage,
                'source'         => 'runtime',
                'note'           => 'JSON parse failed — full response in body',
            ];
        }

        return [
            'generated' => false,
            'subject'   => "Following up — {$goal}",
            'body'      => "Hi,\n\nI wanted to reach out regarding {$goal}.\n\nBest regards",
            'lead_id'   => $leadId,
            'source'    => 'runtime',
            'error'     => $result['error'] ?? 'AI generation failed',
        ];
    }

    /**
     * Generate a follow-up email for an existing lead based on pipeline stage.
     */
    public function generateFollowUp(int $wsId, array $params): array
    {
        return $this->generateOutreach($wsId, array_merge($params, [
            'goal'           => $params['goal'] ?? 'Follow up on our previous conversation',
            'pipeline_stage' => $params['pipeline_stage'] ?? 'follow_up',
        ]));
    }

    public function createLead(int $wsId, array $data): Lead
    {
        $lead = Lead::create([
            'workspace_id' => $wsId,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'website' => $data['website'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'source' => $data['source'] ?? null,
            'status' => 'new',
            'score' => 0,
            'deal_value' => $data['deal_value'] ?? 0,
            'assigned_to' => $data['assigned_to'] ?? null,
            'metadata_json' => $data['metadata'] ?? null,
            'tags_json' => $data['tags'] ?? null,
        ]);

        // Auto-score on creation
        $lead->update(['score' => $this->calculateScore($lead)]);

        // Log activity
        $this->logActivityInternal($wsId, 'Lead', $lead->id, 'lead_created', 'Lead created', $data['user_id'] ?? null);

        return $lead->fresh();
    }

    public function updateLead(int $leadId, array $data, ?int $userId = null): Lead
    {
        $lead = Lead::findOrFail($leadId);
        $oldStatus = $lead->status;

        $fillable = ['name', 'email', 'phone', 'company', 'website', 'city', 'country',
                     'source', 'status', 'deal_value', 'assigned_to', 'metadata_json', 'tags_json'];
        $update = array_intersect_key($data, array_flip($fillable));

        // Status flow validation
        if (isset($update['status'])) {
            $this->validateStatusTransition($oldStatus, $update['status']);
            if ($update['status'] === 'converted' && !$lead->converted_at) {
                $update['converted_at'] = now();
            }
            if ($update['status'] === 'contacted' && !$lead->last_contacted_at) {
                $update['last_contacted_at'] = now();
            }
        }

        $lead->update($update);

        // Re-score
        $lead->update(['score' => $this->calculateScore($lead)]);

        // Log change
        if (isset($update['status']) && $update['status'] !== $oldStatus) {
            $this->logActivityInternal($lead->workspace_id, 'Lead', $lead->id, 'status_changed',
                "Status changed: {$oldStatus} → {$update['status']}", $userId);
        }

        return $lead->fresh();
    }

    public function deleteLead(int $leadId): void
    {
        $lead = Lead::findOrFail($leadId);
        $lead->delete(); // soft delete
    }

    public function restoreLead(int $leadId): Lead
    {
        $lead = Lead::withTrashed()->findOrFail($leadId);
        $lead->restore();
        return $lead;
    }

    public function getLead(int $wsId, int $leadId): array
    {
        $lead = Lead::where('workspace_id', $wsId)->findOrFail($leadId);

        $activities = Activity::where('activitable_type', 'Lead')
            ->where('activitable_id', $leadId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $notes = Note::where('notable_type', 'Lead')
            ->where('notable_id', $leadId)
            ->orderByDesc('created_at')
            ->get();

        $deals = Deal::where('lead_id', $leadId)
            ->orderByDesc('created_at')
            ->get();

        // Build timeline: merge activities + notes + deal events, sorted by date
        $timeline = collect();
        foreach ($activities as $a) {
            $timeline->push(['type' => 'activity', 'subtype' => $a->type, 'description' => $a->description,
                'subject' => $a->subject, 'date' => $a->created_at, 'user_id' => $a->performed_by]);
        }
        foreach ($notes as $n) {
            $timeline->push(['type' => 'note', 'description' => $n->body, 'date' => $n->created_at, 'user_id' => $n->created_by]);
        }
        foreach ($deals as $d) {
            $timeline->push(['type' => 'deal', 'description' => "Deal: {$d->title} ({$d->currency} " . number_format($d->value, 2) . ")",
                'subtype' => $d->stage, 'date' => $d->created_at]);
        }

        return [
            'lead' => $lead,
            'activities' => $activities,
            'notes' => $notes,
            'deals' => $deals,
            'timeline' => $timeline->sortByDesc('date')->values()->toArray(),
        ];
    }

    public function listLeads(int $wsId, array $filters = []): array
    {
        $q = Lead::where('workspace_id', $wsId);

        // Status filter
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $q->whereIn('status', $statuses);
        }

        // Source filter
        if (!empty($filters['source'])) $q->where('source', $filters['source']);

        // Assigned filter
        if (!empty($filters['assigned_to'])) $q->where('assigned_to', $filters['assigned_to']);

        // Score range
        if (isset($filters['score_min'])) $q->where('score', '>=', (int)$filters['score_min']);
        if (isset($filters['score_max'])) $q->where('score', '<=', (int)$filters['score_max']);

        // Deal value range
        if (isset($filters['value_min'])) $q->where('deal_value', '>=', $filters['value_min']);
        if (isset($filters['value_max'])) $q->where('deal_value', '<=', $filters['value_max']);

        // Date range
        if (!empty($filters['created_after'])) $q->where('created_at', '>=', $filters['created_after']);
        if (!empty($filters['created_before'])) $q->where('created_at', '<=', $filters['created_before']);

        // Tags filter
        if (!empty($filters['tag'])) {
            $tag = $filters['tag'];
            $q->whereRaw("JSON_CONTAINS(tags_json, ?)", ['"' . $tag . '"']);
        }

        // Search (name, email, phone, company)
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(function ($q2) use ($s) {
                $q2->where('name', 'like', "%{$s}%")
                   ->orWhere('email', 'like', "%{$s}%")
                   ->orWhere('company', 'like', "%{$s}%")
                   ->orWhere('phone', 'like', "%{$s}%")
                   ->orWhere('city', 'like', "%{$s}%");
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $allowed = ['created_at', 'name', 'score', 'deal_value', 'status', 'last_contacted_at'];
        if (in_array($sortBy, $allowed)) $q->orderBy($sortBy, $sortDir);

        $total = $q->count();
        $limit = min((int)($filters['limit'] ?? 50), 200);
        $offset = (int)($filters['offset'] ?? 0);
        $leads = $q->limit($limit)->offset($offset)->get();

        // Source breakdown for filter UI
        $sources = Lead::where('workspace_id', $wsId)
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->pluck('count', 'source')
            ->toArray();

        return [
            'leads' => $leads,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'sources' => $sources,
        ];
    }

    public function scoreLead(int $leadId, ?int $manualScore = null): Lead
    {
        $lead = Lead::findOrFail($leadId);
        $score = $manualScore ?? $this->calculateScore($lead);
        $lead->update(['score' => max(0, min(100, $score))]);
        return $lead;
    }

    public function assignLead(int $leadId, ?int $userId, ?int $performedBy = null): Lead
    {
        $lead = Lead::findOrFail($leadId);
        $lead->update(['assigned_to' => $userId]);
        $this->logActivityInternal($lead->workspace_id, 'Lead', $leadId, 'assigned',
            "Lead assigned to user #{$userId}", $performedBy);
        return $lead->fresh();
    }

    public function importLeads(int $wsId, array $rows, ?int $userId = null): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            if (empty($row['name']) && empty($row['email'])) {
                $skipped++;
                continue;
            }

            // Deduplicate by email
            if (!empty($row['email'])) {
                $exists = Lead::where('workspace_id', $wsId)->where('email', $row['email'])->exists();
                if ($exists) { $skipped++; $errors[] = "Row {$i}: duplicate email {$row['email']}"; continue; }
            }

            try {
                $this->createLead($wsId, array_merge($row, ['user_id' => $userId]));
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Row {$i}: {$e->getMessage()}";
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'total_rows' => count($rows)];
    }

    public function exportLeads(int $wsId, array $filters = []): string
    {
        $result = $this->listLeads($wsId, array_merge($filters, ['limit' => 10000]));
        $leads = $result['leads'];

        $headers = ['ID', 'Name', 'Email', 'Phone', 'Company', 'Source', 'Status', 'Score', 'Deal Value', 'City', 'Country', 'Created'];
        $csv = implode(',', $headers) . "\n";

        foreach ($leads as $lead) {
            $csv .= implode(',', [
                $lead->id,
                '"' . str_replace('"', '""', $lead->name ?? '') . '"',
                '"' . ($lead->email ?? '') . '"',
                '"' . ($lead->phone ?? '') . '"',
                '"' . str_replace('"', '""', $lead->company ?? '') . '"',
                '"' . ($lead->source ?? '') . '"',
                $lead->status,
                $lead->score,
                $lead->deal_value,
                '"' . ($lead->city ?? '') . '"',
                '"' . ($lead->country ?? '') . '"',
                $lead->created_at?->toDateString() ?? '',
            ]) . "\n";
        }

        return $csv;
    }

    // ═══════════════════════════════════════════════════════════
    // CONTACTS
    // ═══════════════════════════════════════════════════════════

    public function createContact(int $wsId, array $data): Contact
    {
        return Contact::create([
            'workspace_id' => $wsId,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'position' => $data['position'] ?? null,
            'source' => $data['source'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'metadata_json' => $data['metadata'] ?? null,
            'tags_json' => $data['tags'] ?? null,
        ]);
    }

    public function updateContact(int $contactId, array $data): Contact
    {
        $contact = Contact::findOrFail($contactId);
        $contact->update(array_intersect_key($data,
            array_flip(['name', 'email', 'phone', 'company', 'position', 'source', 'metadata_json', 'tags_json'])));
        return $contact->fresh();
    }

    public function deleteContact(int $contactId): void
    {
        Contact::findOrFail($contactId)->delete();
    }

    public function getContact(int $wsId, int $contactId): array
    {
        $contact = Contact::where('workspace_id', $wsId)->findOrFail($contactId);
        $deals = Deal::where('contact_id', $contactId)->get();
        $activities = Activity::where('activitable_type', 'Contact')->where('activitable_id', $contactId)->orderByDesc('created_at')->get();
        $notes = Note::where('notable_type', 'Contact')->where('notable_id', $contactId)->orderByDesc('created_at')->get();
        $lead = $contact->lead_id ? Lead::find($contact->lead_id) : null;

        return ['contact' => $contact, 'deals' => $deals, 'activities' => $activities, 'notes' => $notes, 'lead' => $lead];
    }

    public function listContacts(int $wsId, array $filters = []): array
    {
        $q = Contact::where('workspace_id', $wsId);
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(fn($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                ->orWhere('company', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }
        if (!empty($filters['company'])) $q->where('company', $filters['company']);
        $total = $q->count();
        $contacts = $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->offset($filters['offset'] ?? 0)->get();
        return ['contacts' => $contacts, 'total' => $total];
    }

    public function mergeContacts(int $wsId, int $keepId, int $mergeId): Contact
    {
        $keep = Contact::where('workspace_id', $wsId)->findOrFail($keepId);
        $merge = Contact::where('workspace_id', $wsId)->findOrFail($mergeId);

        // Transfer associations to keep
        Deal::where('contact_id', $mergeId)->update(['contact_id' => $keepId]);
        Activity::where('activitable_type', 'Contact')->where('activitable_id', $mergeId)
            ->update(['activitable_id' => $keepId]);
        Note::where('notable_type', 'Contact')->where('notable_id', $mergeId)
            ->update(['notable_id' => $keepId]);

        // Fill empty fields from merge into keep
        $fillable = ['email', 'phone', 'company', 'position', 'source'];
        foreach ($fillable as $field) {
            if (empty($keep->$field) && !empty($merge->$field)) {
                $keep->$field = $merge->$field;
            }
        }
        $keep->save();

        // Soft-delete merged contact
        $merge->delete();

        return $keep->fresh();
    }

    public function findDuplicateContacts(int $wsId): array
    {
        $byEmail = Contact::where('workspace_id', $wsId)->whereNotNull('email')
            ->select('email', DB::raw('GROUP_CONCAT(id) as ids'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('email')->having('cnt', '>', 1)->get();

        $byPhone = Contact::where('workspace_id', $wsId)->whereNotNull('phone')
            ->select('phone', DB::raw('GROUP_CONCAT(id) as ids'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('phone')->having('cnt', '>', 1)->get();

        return [
            'email_duplicates' => $byEmail->map(fn($r) => ['email' => $r->email, 'ids' => explode(',', $r->ids), 'count' => $r->cnt])->toArray(),
            'phone_duplicates' => $byPhone->map(fn($r) => ['phone' => $r->phone, 'ids' => explode(',', $r->ids), 'count' => $r->cnt])->toArray(),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // DEALS
    // ═══════════════════════════════════════════════════════════

    public function createDeal(int $wsId, array $data): Deal
    {
        $stage = $data['stage'] ?? 'discovery';
        $stageObj = DB::table('pipeline_stages')->where('workspace_id', $wsId)->where('slug', $stage)->first();

        $deal = Deal::create([
            'workspace_id' => $wsId,
            'lead_id' => $data['lead_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'title' => $data['title'],
            'value' => $data['value'] ?? 0,
            'currency' => $data['currency'] ?? 'AED',
            'stage' => $stage,
            'probability' => $data['probability'] ?? ($stageObj->default_probability ?? 0),
            'expected_close' => $data['expected_close'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'metadata_json' => $data['metadata'] ?? null,
        ]);

        $this->logActivityInternal($wsId, 'Deal', $deal->id, 'deal_created',
            "Deal created: {$deal->title} ({$deal->currency} " . number_format($deal->value, 2) . ")", $data['user_id'] ?? null);

        return $deal;
    }

    public function updateDeal(int $dealId, array $data, ?int $userId = null): Deal
    {
        $deal = Deal::findOrFail($dealId);
        $deal->update(array_intersect_key($data,
            array_flip(['title', 'value', 'currency', 'probability', 'expected_close', 'assigned_to', 'lead_id', 'contact_id', 'metadata_json'])));
        return $deal->fresh();
    }

    public function updateDealStage(int $dealId, string $newStage, ?int $userId = null): Deal
    {
        $deal = Deal::findOrFail($dealId);
        $oldStage = $deal->stage;

        // Get stage probability
        $stageObj = DB::table('pipeline_stages')
            ->where('workspace_id', $deal->workspace_id)
            ->where('slug', $newStage)->first();

        $update = ['stage' => $newStage];
        if ($stageObj) $update['probability'] = $stageObj->default_probability;

        // If won/lost, update lead status
        if ($stageObj && $stageObj->is_won && $deal->lead_id) {
            Lead::where('id', $deal->lead_id)->update(['status' => 'converted', 'converted_at' => now()]);
        }
        if ($stageObj && $stageObj->is_lost && $deal->lead_id) {
            Lead::where('id', $deal->lead_id)->update(['status' => 'lost']);
        }

        $deal->update($update);

        $this->logActivityInternal($deal->workspace_id, 'Deal', $deal->id, 'stage_changed',
            "Stage changed: {$oldStage} → {$newStage}", $userId);

        return $deal->fresh();
    }

    public function getDeal(int $wsId, int $dealId): array
    {
        $deal = Deal::where('workspace_id', $wsId)->findOrFail($dealId);
        $lead = $deal->lead_id ? Lead::find($deal->lead_id) : null;
        $contact = $deal->contact_id ? Contact::find($deal->contact_id) : null;
        $activities = Activity::where('activitable_type', 'Deal')->where('activitable_id', $dealId)->orderByDesc('created_at')->get();
        $notes = Note::where('notable_type', 'Deal')->where('notable_id', $dealId)->orderByDesc('created_at')->get();

        return ['deal' => $deal, 'lead' => $lead, 'contact' => $contact, 'activities' => $activities, 'notes' => $notes];
    }

    public function listDeals(int $wsId, array $filters = []): array
    {
        $q = Deal::where('workspace_id', $wsId);
        if (!empty($filters['stage'])) $q->where('stage', $filters['stage']);
        if (!empty($filters['assigned_to'])) $q->where('assigned_to', $filters['assigned_to']);
        if (!empty($filters['search'])) $q->where('title', 'like', '%' . $filters['search'] . '%');
        $total = $q->count();
        return ['deals' => $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get(), 'total' => $total];
    }

    // ═══════════════════════════════════════════════════════════
    // PIPELINE
    // ═══════════════════════════════════════════════════════════

    public function getStages(int $wsId): array
    {
        $stages = DB::table('pipeline_stages')
            ->where('workspace_id', $wsId)
            ->orderBy('position')
            ->get();

        if ($stages->isEmpty()) {
            $this->seedDefaultStages($wsId);
            $stages = DB::table('pipeline_stages')->where('workspace_id', $wsId)->orderBy('position')->get();
        }

        return $stages->toArray();
    }

    public function createStage(int $wsId, array $data): int
    {
        $maxPos = DB::table('pipeline_stages')->where('workspace_id', $wsId)->max('position') ?? -1;
        return DB::table('pipeline_stages')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'position' => $data['position'] ?? ($maxPos + 1),
            'color' => $data['color'] ?? '#3B82F6',
            'default_probability' => $data['probability'] ?? 0,
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function updateStage(int $stageId, array $data): void
    {
        DB::table('pipeline_stages')->where('id', $stageId)->update(array_merge(
            array_intersect_key($data, array_flip(['name', 'color', 'position', 'default_probability', 'is_won', 'is_lost'])),
            ['updated_at' => now()]
        ));
    }

    public function deleteStage(int $stageId): void
    {
        DB::table('pipeline_stages')->where('id', $stageId)->delete();
    }

    public function reorderStages(int $wsId, array $stageIds): void
    {
        foreach ($stageIds as $pos => $id) {
            DB::table('pipeline_stages')->where('workspace_id', $wsId)->where('id', $id)
                ->update(['position' => $pos, 'updated_at' => now()]);
        }
    }

    public function getPipeline(int $wsId): array
    {
        $stages = $this->getStages($wsId);
        $deals = Deal::where('workspace_id', $wsId)->whereNull('deleted_at')->get();

        return array_map(function ($stage) use ($deals) {
            $stageDeals = $deals->where('stage', $stage->slug ?? $stage['slug']);
            return [
                'id' => $stage->id ?? null,
                'name' => $stage->name ?? $stage['name'],
                'slug' => $stage->slug ?? $stage['slug'],
                'color' => $stage->color ?? '#3B82F6',
                'probability' => $stage->default_probability ?? 0,
                'is_won' => (bool)($stage->is_won ?? false),
                'is_lost' => (bool)($stage->is_lost ?? false),
                'deals' => $stageDeals->values()->toArray(),
                'deal_count' => $stageDeals->count(),
                'total_value' => $stageDeals->sum('value'),
            ];
        }, $stages);
    }

    // ═══════════════════════════════════════════════════════════
    // ACTIVITIES
    // ═══════════════════════════════════════════════════════════

    public function logActivity(int $wsId, array $data): Activity
    {
        return Activity::create([
            'workspace_id' => $wsId,
            'activitable_type' => $data['entity_type'] ?? 'Lead',
            'activitable_id' => $data['entity_id'],
            'type' => $data['type'], // call, email, meeting, task, note
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'metadata_json' => $data['metadata'] ?? null,
            'performed_by' => $data['user_id'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'completed' => $data['completed'] ?? false,
            'completed_at' => !empty($data['completed']) ? now() : null,
        ]);
    }

    public function completeActivity(int $activityId): Activity
    {
        $activity = Activity::findOrFail($activityId);
        $activity->update(['completed' => true, 'completed_at' => now()]);
        return $activity;
    }

    public function listActivities(int $wsId, string $entityType, int $entityId): array
    {
        return Activity::where('workspace_id', $wsId)
            ->where('activitable_type', $entityType)
            ->where('activitable_id', $entityId)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function getTodayView(int $wsId): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $dueToday = Activity::where('workspace_id', $wsId)
            ->where('completed', false)
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->orderBy('scheduled_at')
            ->get();

        $overdue = Activity::where('workspace_id', $wsId)
            ->where('completed', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', $todayStart)
            ->orderBy('scheduled_at')
            ->get();

        $completedToday = Activity::where('workspace_id', $wsId)
            ->where('completed', true)
            ->whereBetween('completed_at', [$todayStart, $todayEnd])
            ->orderByDesc('completed_at')
            ->get();

        $upcoming = Activity::where('workspace_id', $wsId)
            ->where('completed', false)
            ->where('scheduled_at', '>', $todayEnd)
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get();

        return [
            'due_today' => $dueToday,
            'overdue' => $overdue,
            'completed_today' => $completedToday,
            'upcoming' => $upcoming,
            'summary' => [
                'due_count' => $dueToday->count(),
                'overdue_count' => $overdue->count(),
                'completed_count' => $completedToday->count(),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // NOTES
    // ═══════════════════════════════════════════════════════════

    public function addNote(int $wsId, string $entityType, int $entityId, string $body, ?int $userId = null): Note
    {
        return Note::create([
            'workspace_id' => $wsId,
            'notable_type' => $entityType,
            'notable_id' => $entityId,
            'body' => $body,
            'created_by' => $userId,
        ]);
    }

    public function listNotes(int $wsId, string $entityType, int $entityId): array
    {
        return Note::where('workspace_id', $wsId)
            ->where('notable_type', $entityType)
            ->where('notable_id', $entityId)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function deleteNote(int $noteId): void
    {
        Note::findOrFail($noteId)->delete();
    }

    // ═══════════════════════════════════════════════════════════
    // REVENUE & REPORTING
    // ═══════════════════════════════════════════════════════════

    public function getRevenueSummary(int $wsId): array
    {
        $deals = Deal::where('workspace_id', $wsId)->whereNull('deleted_at');

        $totalPipeline = (clone $deals)->whereNotIn('stage', ['closed_won', 'closed_lost'])->sum('value');
        $won = (clone $deals)->where('stage', 'closed_won')->sum('value');
        $lost = (clone $deals)->where('stage', 'closed_lost')->sum('value');
        $dealCount = (clone $deals)->count();
        $avgDeal = (clone $deals)->avg('value') ?? 0;

        // Weighted pipeline (value * probability)
        $weightedPipeline = (clone $deals)->whereNotIn('stage', ['closed_won', 'closed_lost'])
            ->select(DB::raw('SUM(value * probability / 100) as weighted'))->value('weighted') ?? 0;

        // Monthly trends (last 6 months)
        $monthlyWon = DB::table('deals')
            ->where('workspace_id', $wsId)->where('stage', 'closed_won')
            ->where('updated_at', '>=', now()->subMonths(6))
            ->select(DB::raw("DATE_FORMAT(updated_at, '%Y-%m') as month"), DB::raw('SUM(value) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('month')->orderBy('month')->get()->toArray();

        // Forecast (expected close in next 90 days, weighted)
        $forecast = (clone $deals)->whereNotIn('stage', ['closed_won', 'closed_lost'])
            ->where('expected_close', '>=', now())
            ->where('expected_close', '<=', now()->addDays(90))
            ->select(DB::raw('SUM(value * probability / 100) as weighted'), DB::raw('SUM(value) as total'), DB::raw('COUNT(*) as count'))
            ->first();

        return [
            'total_pipeline' => round($totalPipeline, 2),
            'weighted_pipeline' => round($weightedPipeline, 2),
            'won' => round($won, 2),
            'lost' => round($lost, 2),
            'deal_count' => $dealCount,
            'avg_deal_value' => round($avgDeal, 2),
            'conversion_rate' => $this->conversionRate($wsId),
            'win_rate' => $this->winRate($wsId),
            'monthly_won' => $monthlyWon,
            'forecast_90d' => [
                'weighted' => round($forecast->weighted ?? 0, 2),
                'total' => round($forecast->total ?? 0, 2),
                'count' => $forecast->count ?? 0,
            ],
        ];
    }

    public function getReporting(int $wsId): array
    {
        // Lead sources breakdown
        $leadSources = Lead::where('workspace_id', $wsId)
            ->select('source', DB::raw('COUNT(*) as count'),
                DB::raw("SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) as converted"))
            ->groupBy('source')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => [
                'source' => $r->source ?? 'unknown',
                'count' => $r->count,
                'converted' => $r->converted,
                'conversion_rate' => $r->count > 0 ? round(($r->converted / $r->count) * 100, 1) : 0,
            ])->toArray();

        // Conversion funnel
        $funnel = [];
        foreach (['new', 'contacted', 'qualified', 'converted'] as $status) {
            $funnel[] = [
                'stage' => $status,
                'count' => Lead::where('workspace_id', $wsId)->where('status', $status)->count(),
            ];
        }
        $funnel[] = ['stage' => 'lost', 'count' => Lead::where('workspace_id', $wsId)->where('status', 'lost')->count()];

        // Activity stats (last 30 days)
        $activityStats = Activity::where('workspace_id', $wsId)
            ->where('created_at', '>=', now()->subDays(30))
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        // Pipeline by stage
        $pipelineByStage = Deal::where('workspace_id', $wsId)->whereNull('deleted_at')
            ->select('stage', DB::raw('COUNT(*) as count'), DB::raw('SUM(value) as total'))
            ->groupBy('stage')
            ->get()
            ->map(fn($r) => ['stage' => $r->stage, 'count' => $r->count, 'total' => round($r->total, 2)])
            ->toArray();

        // Avg days to conversion
        $avgConversion = Lead::where('workspace_id', $wsId)
            ->where('status', 'converted')
            ->whereNotNull('converted_at')
            ->selectRaw('AVG(DATEDIFF(converted_at, created_at)) as avg_days')
            ->value('avg_days');

        return [
            'lead_sources' => $leadSources,
            'conversion_funnel' => $funnel,
            'activity_stats' => $activityStats,
            'pipeline_by_stage' => $pipelineByStage,
            'avg_days_to_conversion' => round($avgConversion ?? 0, 1),
            'total_leads' => Lead::where('workspace_id', $wsId)->count(),
            'total_contacts' => Contact::where('workspace_id', $wsId)->count(),
            'total_deals' => Deal::where('workspace_id', $wsId)->count(),
            'total_activities_30d' => array_sum($activityStats),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════

    private function calculateScore(Lead $lead): int
    {
        $score = 0;

        // Contact completeness
        if (!empty($lead->email)) $score += 15;
        if (!empty($lead->phone)) $score += 10;
        if (!empty($lead->company)) $score += 10;
        if (!empty($lead->website)) $score += 5;

        // Engagement signals
        if (!empty($lead->last_contacted_at)) $score += 10;
        if ($lead->status === 'contacted') $score += 10;
        if ($lead->status === 'qualified') $score += 25;

        // Deal value
        if ($lead->deal_value > 0) $score += 10;
        if ($lead->deal_value > 10000) $score += 5;

        // Activity count
        $activityCount = Activity::where('activitable_type', 'Lead')->where('activitable_id', $lead->id)->count();
        $score += min($activityCount * 3, 15);

        return min($score, 100);
    }

    private function validateStatusTransition(string $from, string $to): void
    {
        $allowed = [
            'new' => ['contacted', 'qualified', 'lost'],
            'contacted' => ['qualified', 'converted', 'lost'],
            'qualified' => ['converted', 'lost', 'contacted'],
            'converted' => [],
            'lost' => ['new', 'contacted'],
        ];

        if (!in_array($to, $allowed[$from] ?? [])) {
            if ($from === $to) return; // Same status is fine
            throw new \InvalidArgumentException("Cannot transition lead from '{$from}' to '{$to}'");
        }
    }

    private function conversionRate(int $wsId): float
    {
        $total = Lead::where('workspace_id', $wsId)->count();
        if ($total === 0) return 0;
        $converted = Lead::where('workspace_id', $wsId)->where('status', 'converted')->count();
        return round(($converted / $total) * 100, 2);
    }

    private function winRate(int $wsId): float
    {
        $closed = Deal::where('workspace_id', $wsId)->whereIn('stage', ['closed_won', 'closed_lost'])->count();
        if ($closed === 0) return 0;
        $won = Deal::where('workspace_id', $wsId)->where('stage', 'closed_won')->count();
        return round(($won / $closed) * 100, 2);
    }

    private function logActivityInternal(int $wsId, string $type, int $id, string $actType, string $desc, ?int $userId = null): void
    {
        Activity::create([
            'workspace_id' => $wsId, 'activitable_type' => $type, 'activitable_id' => $id,
            'type' => $actType, 'description' => $desc, 'performed_by' => $userId,
        ]);
    }

    private function seedDefaultStages(int $wsId): void
    {
        $defaults = [
            ['name' => 'Discovery', 'slug' => 'discovery', 'color' => '#3B82F6', 'position' => 0, 'default_probability' => 10],
            ['name' => 'Proposal', 'slug' => 'proposal', 'color' => '#7C3AED', 'position' => 1, 'default_probability' => 30],
            ['name' => 'Negotiation', 'slug' => 'negotiation', 'color' => '#F59E0B', 'position' => 2, 'default_probability' => 60],
            ['name' => 'Closed Won', 'slug' => 'closed_won', 'color' => '#00E5A8', 'position' => 3, 'default_probability' => 100, 'is_won' => true],
            ['name' => 'Closed Lost', 'slug' => 'closed_lost', 'color' => '#F87171', 'position' => 4, 'default_probability' => 0, 'is_lost' => true],
        ];

        foreach ($defaults as $stage) {
            DB::table('pipeline_stages')->insert(array_merge($stage, [
                'workspace_id' => $wsId, 'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
