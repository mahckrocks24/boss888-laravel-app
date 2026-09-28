<?php

namespace App\Engines\CRM\Http\Controllers;

use App\Engines\CRM\Services\ClientIdentity;
use App\Engines\CRM\Services\CrmPacks;
use App\Engines\CRM\Services\CrmService;
use App\Http\Controllers\Api\BaseEngineController;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM-UX-2 (Clients revamp Phase 2): the endpoints behind the new Clients screens — Today, the client list with
 * ready views, the board, the client record, reports and per-business setup. Everything is scoped to the workspace
 * and, when given, to one business (RFC-0011: many businesses in one workspace).
 */
class ClientsController extends BaseEngineController
{
    private const HUMAN = ['note', 'call', 'email', 'meeting', 'task'];

    public function __construct(private CrmService $crm) {}

    protected function engineSlug(): string { return 'crm'; }

    private function biz(Request $r): ?int
    {
        $b = $r->input('business_id');
        if ($b === null || $b === '' || $b === 'all' || $b === 'none') return null;
        return DB::table('businesses')->where('workspace_id', $this->wsId($r))->where('id', (int) $b)->whereNull('deleted_at')->exists() ? (int) $b : -1;
    }

    private function businesses(int $ws): array
    {
        return DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'industry', 'logo_url', 'brand_color'])
            ->map(function ($b) { $p = CrmPacks::forBusiness((int) $b->id); return ['id' => (int) $b->id, 'name' => $b->name, 'industry' => $b->industry, 'logo_url' => $b->logo_url, 'brand_color' => $b->brand_color, 'pack' => $p['key'], 'one' => $p['one'], 'many' => $p['many']]; })
            ->all();
    }

    /** GET /crm/setup?business_id= — businesses, the pack in use, and the list of packs. */
    public function setup(Request $r): JsonResponse
    {
        $ws = $this->wsId($r); $b = $this->biz($r);
        if ($b === -1) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $pack = $b ? CrmPacks::forBusiness($b) : (['key' => 'general', 'auto' => 'general', 'chosen' => false] + CrmPacks::all()['general']);
        $packs = collect(CrmPacks::all())->map(fn ($p, $k) => ['key' => $k, 'label' => $p['label'], 'about' => $p['about']])->values();
        $counts = DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->selectRaw('business_id, count(*) n')->groupBy('business_id')->pluck('n', 'business_id');
        return $this->readJson(['businesses' => $this->businesses($ws), 'pack' => $pack, 'packs' => $packs, 'counts' => $counts, 'unassigned' => (int) ($counts[''] ?? 0)]);
    }

    /** PUT /crm/setup/{businessId} {pack?, one?, many?} — the owner's choice for one business. */
    public function saveSetup(Request $r, int $businessId): JsonResponse
    {
        $b = DB::table('businesses')->where('workspace_id', $this->wsId($r))->where('id', $businessId)->whereNull('deleted_at')->first(['id', 'settings_json']);
        if (! $b) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $set = json_decode((string) $b->settings_json, true) ?: [];
        $crm = $set['crm'] ?? [];
        if ($r->has('pack')) {
            $p = (string) $r->input('pack');
            if ($p !== '' && ! isset(CrmPacks::all()[$p])) return response()->json(['success' => false, 'message' => 'Unknown setup.'], 422);
            if ($p === '') unset($crm['pack']); else $crm['pack'] = $p;
            unset($crm['one'], $crm['many']);
        }
        foreach (['one', 'many'] as $k) if ($r->filled($k)) $crm[$k] = mb_substr(trim(strip_tags((string) $r->input($k))), 0, 30);
        $set['crm'] = $crm;
        DB::table('businesses')->where('id', $businessId)->update(['settings_json' => json_encode($set, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return $this->readJson(['success' => true, 'pack' => CrmPacks::forBusiness($businessId)]);
    }

    /** The shared list query: business, view, stage, search, channel. */
    private function query(Request $r, int $ws, ?int $b, array $pack)
    {
        $q = Lead::where('leads.workspace_id', $ws);
        if ($b) $q->where('business_id', $b);
        elseif ($r->input('business_id') === 'none') $q->whereNull('business_id');
        if ($r->filled('channel')) $q->where('channel', $r->input('channel'));
        if ($r->filled('search')) {
            $s = '%' . addcslashes((string) $r->input('search'), '%_\\') . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('email', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('company', 'like', $s));
        }
        $stage = (string) $r->input('stage', '');
        $view = (string) $r->input('view', '');
        foreach ($pack['views'] as $v) if ($v['key'] === $view && ! empty($v['stage'])) $stage = $v['stage'];
        if ($stage !== '') {
            $st = CrmPacks::statusOf($pack, $stage);
            $first = collect($pack['stages'])->firstWhere('status', $st);
            // a lead stands in this stage when it says so, or (no stage yet) when this is the first stage of its status
            $q->where(fn ($w) => $w->where('stage', $stage)->orWhere(fn ($x) => $x->whereNull('stage')->where('status', $st)->whereRaw('? = ?', [$first['key'] ?? '', $stage])));
        }
        $human = "(SELECT MAX(a.created_at) FROM activities a WHERE a.lead_id = leads.id AND a.type IN ('note','call','email','meeting','task'))";
        match ($view) {
            'new_enquiries' => $q->where('status', 'new')->where('created_at', '>=', now()->subDays(14)),
            'new_today' => $q->where('created_at', '>=', now()->startOfDay()),
            'no_reply_3d' => $q->where('status', 'new')->where('created_at', '<', now()->subDays(3))->whereRaw("$human IS NULL"),
            'lapsed_60d' => $q->where('status', 'converted')->whereRaw("COALESCE($human, leads.updated_at) < ?", [now()->subDays(60)]),
            default => null,
        };
        return $q;
    }

    private function shape($l, array $pack, array $bizNames, array $packsByBiz): array
    {
        $p = $l->business_id && isset($packsByBiz[$l->business_id]) ? $packsByBiz[$l->business_id] : $pack;
        $stage = CrmPacks::stageOf($p, $l->stage, $l->status);
        $stageName = collect($p['stages'])->firstWhere('key', $stage)['name'] ?? ucfirst($stage);
        $meta = is_array($l->metadata_json) ? $l->metadata_json : (json_decode((string) $l->metadata_json, true) ?: []);
        return [
            'id' => $l->id, 'name' => $l->name, 'email' => $l->email, 'phone' => $l->phone, 'company' => $l->company,
            'business_id' => $l->business_id, 'business_name' => $l->business_id ? ($bizNames[$l->business_id] ?? null) : null,
            'stage' => $stage, 'stage_name' => $stageName, 'status' => $l->status, 'channel' => $l->channel, 'source' => $l->source,
            'score' => (int) $l->score, 'value' => (float) $l->deal_value, 'created_at' => (string) $l->created_at, 'updated_at' => (string) $l->updated_at,
            'last_activity_at' => $l->last_activity_at ?? null, 'next_task_at' => $l->next_task_at ?? null,
            'first_message' => mb_substr((string) ($meta['first_message'] ?? ''), 0, 160) ?: null,
            'duplicate' => ! empty($meta['possible_duplicate_of']),
        ];
    }

    private function packsByBiz(int $ws): array
    {
        $out = [];
        foreach (DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->pluck('id') as $id) $out[(int) $id] = CrmPacks::forBusiness((int) $id);
        return $out;
    }

    private function packFor(?int $b): array
    {
        return $b ? CrmPacks::forBusiness($b) : (['key' => 'general'] + CrmPacks::all()['general']);
    }

    /** GET /crm/clients — the list (and the board) with the ready views. */
    public function index(Request $r): JsonResponse
    {
        $ws = $this->wsId($r); $b = $this->biz($r);
        if ($b === -1) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $pack = $this->packFor($b);
        $q = $this->query($r, $ws, $b, $pack);
        $total = (clone $q)->count();
        $sort = in_array($r->input('sort'), ['name', 'created_at', 'updated_at', 'score', 'deal_value'], true) ? $r->input('sort') : 'created_at';
        $dir = $r->input('dir') === 'asc' ? 'asc' : 'desc';
        $limit = min(max((int) $r->input('limit', 50), 1), 500); $offset = max((int) $r->input('offset', 0), 0);
        $rows = $q->select('leads.*')
            ->selectRaw("(SELECT MAX(a.created_at) FROM activities a WHERE a.lead_id = leads.id AND a.type IN ('note','call','email','meeting','repeat_enquiry')) AS last_activity_at")
            ->selectRaw("(SELECT MIN(a.scheduled_at) FROM activities a WHERE a.lead_id = leads.id AND a.type = 'task' AND a.completed = 0) AS next_task_at")
            ->orderBy($sort, $dir)->orderByDesc('leads.id')->limit($limit)->offset($offset)->get();
        $bizNames = DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->pluck('name', 'id')->all();
        $pb = $this->packsByBiz($ws);
        $counts = [];
        foreach ($pack['views'] as $v) { $rr = new Request(['view' => $v['key'], 'business_id' => $r->input('business_id')]); $counts[$v['key']] = $this->query($rr, $ws, $b, $pack)->count(); }
        return $this->readJson([
            'clients' => $rows->map(fn ($l) => $this->shape($l, $pack, $bizNames, $pb))->values(),
            'total' => $total, 'limit' => $limit, 'offset' => $offset, 'view_counts' => $counts, 'pack' => $pack,
        ]);
    }

    /** GET /crm/clients/{id} — the client record. */
    public function show(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $l = Lead::where('workspace_id', $ws)->find($id);
        if (! $l) return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        $pack = $this->packFor($l->business_id ? (int) $l->business_id : null);
        $bizNames = DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->pluck('name', 'id')->all();
        $meta = is_array($l->metadata_json) ? $l->metadata_json : (json_decode((string) $l->metadata_json, true) ?: []);
        $others = $l->person_id ? Lead::where('workspace_id', $ws)->where('person_id', $l->person_id)->where('id', '!=', $l->id)->get(['id', 'business_id', 'status', 'stage', 'created_at'])
            ->map(function ($o) use ($bizNames) { $p = $this->packFor($o->business_id ? (int) $o->business_id : null); $st = CrmPacks::stageOf($p, $o->stage, $o->status);
                return ['id' => $o->id, 'business_name' => $bizNames[$o->business_id] ?? 'No business yet', 'stage_name' => collect($p['stages'])->firstWhere('key', $st)['name'] ?? $st]; })->values() : [];
        $tasks = DB::table('activities')->where('workspace_id', $ws)->where('lead_id', $l->id)->where('type', 'task')->where('completed', 0)->orderByRaw('scheduled_at IS NULL, scheduled_at')->limit(20)
            ->get(['id', 'subject', 'description', 'scheduled_at'])->map(fn ($t) => ['id' => $t->id, 'title' => $t->subject ?: $t->description, 'due' => $t->scheduled_at])->values();
        $appts = DB::table('calendar_events')->where('workspace_id', $ws)->whereIn('reference_type', ['Lead', 'lead'])->where('reference_id', $l->id)
            ->whereNotIn('category', ['cancelled', 'booking_declined'])->orderByDesc('starts_at')->limit(10)->get(['id', 'title', 'starts_at', 'category'])->values();
        $fields = (array) ($meta['fields'] ?? []);
        foreach (['service', 'preferred_date', 'preferred_time', 'party_size'] as $k) if (! isset($fields[$k]) && ! empty($meta['last_booking_request'][$k] ?? $meta[$k] ?? null)) $fields[$k] = $meta['last_booking_request'][$k] ?? $meta[$k];
        $humanCount = DB::table('activities')->where('lead_id', $l->id)->whereIn('type', self::HUMAN)->count();
        $firstReply = DB::table('activities')->where('lead_id', $l->id)->whereIn('type', ['call', 'email', 'meeting', 'note'])->min('created_at');
        $pb = $this->packsByBiz($ws);
        $row = $this->shape($l, $pack, $bizNames, $pb);
        return $this->readJson([
            'client' => $row + ['city' => $l->city, 'country' => $l->country, 'website_id' => $l->website_id, 'converted_at' => $l->converted_at ? (string) $l->converted_at : null,
                'first_message_full' => (string) ($meta['first_message'] ?? ''), 'duplicate_of' => $meta['possible_duplicate_of'] ?? []],
            'pack' => $pack, 'fields' => $fields, 'others' => $others, 'tasks' => $tasks, 'appointments' => $appts,
            'timeline' => $this->crm->leadTimeline($ws, $l->id),
            'facts' => ['human_touches' => $humanCount, 'first_reply_at' => $firstReply ? (string) $firstReply : null,
                'days_since_contact' => $row['last_activity_at'] ? (int) now()->diffInDays($row['last_activity_at']) : null],
        ]);
    }

    /** PUT /crm/clients/{id}/stage {stage} — the board and the stage bar. The lifecycle status follows the stage. */
    public function stage(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $l = Lead::where('workspace_id', $ws)->find($id);
        if (! $l) return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        $pack = $this->packFor($l->business_id ? (int) $l->business_id : null);
        $stage = (string) $r->input('stage');
        $status = CrmPacks::statusOf($pack, $stage);
        if (! $status) return response()->json(['success' => false, 'message' => 'That stage is not part of this business\'s setup.'], 422);
        $from = collect($pack['stages'])->firstWhere('key', CrmPacks::stageOf($pack, $l->stage, $l->status))['name'] ?? '';
        $to = collect($pack['stages'])->firstWhere('key', $stage)['name'] ?? $stage;
        $this->crm->updateLead($l->id, ['status' => $status], $this->userId($r), $ws);
        DB::table('leads')->where('id', $l->id)->update(['stage' => $stage, 'updated_at' => now()]);
        if ($status === $l->status) { // the status did not change, so updateLead logged nothing: record the stage move
            \App\Models\Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => $l->id, 'type' => 'status_changed',
                'description' => "Stage changed: {$from} → {$to}", 'performed_by' => $this->userId($r)]);
        }
        return $this->readJson(['success' => true, 'stage' => $stage, 'stage_name' => $to, 'status' => $status]);
    }

    /** PUT /crm/clients/{id}/fields {fields:{key:value}} — the pack's details about a client. */
    public function fields(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $l = Lead::where('workspace_id', $ws)->find($id);
        if (! $l) return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        $pack = $this->packFor($l->business_id ? (int) $l->business_id : null);
        $allowed = collect($pack['fields'])->pluck('key')->all();
        $meta = is_array($l->metadata_json) ? $l->metadata_json : (json_decode((string) $l->metadata_json, true) ?: []);
        $f = (array) ($meta['fields'] ?? []);
        foreach ((array) $r->input('fields', []) as $k => $v) {
            if (! in_array($k, $allowed, true)) continue;
            $v = mb_substr(trim((string) $v), 0, 2000);
            if ($v === '') unset($f[$k]); else $f[$k] = $v;
        }
        $meta['fields'] = $f;
        DB::table('leads')->where('id', $l->id)->update(['metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return $this->readJson(['success' => true, 'fields' => $f]);
    }

    /** POST /crm/clients — a client added by hand, straight into a business, a stage and its details. */
    public function store(Request $r): JsonResponse
    {
        $r->validate(['name' => 'required|string|max:190', 'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:60']);
        $b = $this->biz($r);
        if ($b === -1) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $pack = $this->packFor($b);
        $stage = (string) $r->input('stage', $pack['stages'][0]['key']);
        $status = CrmPacks::statusOf($pack, $stage) ?? 'new';
        $res = $this->executeActionRaw($r, 'create_lead', array_filter([
            'name' => $r->input('name'), 'email' => $r->input('email'), 'phone' => $r->input('phone'), 'company' => $r->input('company'),
            'source' => $r->input('source', 'manual'), 'status' => $status, 'business_id' => $b, 'deal_value' => (float) $r->input('value', 0),
        ], fn ($v) => $v !== null && $v !== ''));
        if (! ($res['success'] ?? false)) return response()->json($res, $res['_http'] ?? 422);
        $id = (int) ($res['data']['entity_id'] ?? 0);
        if ($id) {
            $meta = json_decode((string) DB::table('leads')->where('id', $id)->value('metadata_json'), true) ?: [];
            $allowed = collect($pack['fields'])->pluck('key')->all();
            $meta['fields'] = array_filter(array_intersect_key((array) $r->input('fields', []), array_flip($allowed)), fn ($v) => trim((string) $v) !== '');
            DB::table('leads')->where('id', $id)->update(['stage' => $stage, 'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        }
        return $this->readJson(['success' => true, 'id' => $id], 201);
    }

    private function executeActionRaw(Request $r, string $action, array $params): array
    {
        $res = app(\App\Core\EngineKernel\EngineExecutionService::class)->execute($this->wsId($r), 'crm', $action,
            array_merge($params, ['_user_id' => $this->userId($r)]), ['user_id' => $this->userId($r), 'source' => 'manual', 'priority' => 'normal', 'agent_id' => null]);
        if (! ($res['success'] ?? false)) $res['_http'] = ($res['code'] ?? '') === 'NO_CREDITS' ? 402 : 422;
        return $res;
    }

    /** POST /crm/clients/bulk {ids:[], action: stage|archive|restore, stage?} */
    public function bulk(Request $r): JsonResponse
    {
        $ws = $this->wsId($r);
        $ids = array_slice(array_map('intval', (array) $r->input('ids', [])), 0, 500);
        $action = (string) $r->input('action');
        if (! $ids) return response()->json(['success' => false, 'message' => 'Pick at least one.'], 422);
        $done = 0; $skipped = 0;
        if ($action === 'archive') {
            $done = Lead::where('workspace_id', $ws)->whereIn('id', $ids)->get()->each(fn ($l) => $l->delete())->count();
        } elseif ($action === 'restore') {
            $done = Lead::withTrashed()->where('workspace_id', $ws)->whereIn('id', $ids)->get()->each(fn ($l) => $l->restore())->count();
        } elseif ($action === 'stage') {
            foreach ($ids as $id) {
                $rr = new Request(['stage' => $r->input('stage')]); $rr->attributes->add($r->attributes->all()); $rr->setUserResolver($r->getUserResolver());
                $res = $this->stage($rr, $id)->getData(true);
                ($res['success'] ?? false) ? $done++ : $skipped++;
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Unknown action.'], 422);
        }
        return $this->readJson(['success' => true, 'done' => $done, 'skipped' => $skipped]);
    }

    /** GET /crm/today — what needs the owner today. */
    public function today(Request $r): JsonResponse
    {
        $ws = $this->wsId($r); $b = $this->biz($r);
        if ($b === -1) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $pack = $this->packFor($b);
        $bizNames = DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->pluck('name', 'id')->all();
        $pb = $this->packsByBiz($ws);
        $scope = fn ($q) => $b ? $q->where('business_id', $b) : $q;
        $human = "(SELECT COUNT(*) FROM activities a WHERE a.lead_id = leads.id AND a.type IN ('note','call','email','meeting','task'))";
        $waiting = $scope(Lead::where('workspace_id', $ws))->where('status', 'new')->whereRaw("$human = 0")->orderByDesc('created_at')->limit(8)->get();
        $waitingTotal = $scope(Lead::where('workspace_id', $ws))->where('status', 'new')->whereRaw("$human = 0")->count();
        $tasks = DB::table('activities as a')->join('leads as l', 'l.id', '=', 'a.lead_id')->whereNull('l.deleted_at')->where('a.workspace_id', $ws)
            ->when($b, fn ($q) => $q->where('l.business_id', $b))->where('a.type', 'task')->where('a.completed', 0)
            ->where(fn ($q) => $q->whereNull('a.scheduled_at')->orWhere('a.scheduled_at', '<', now()->endOfDay()))
            ->orderByRaw('a.scheduled_at IS NULL, a.scheduled_at')->limit(12)
            ->get(['a.id', 'a.subject', 'a.description', 'a.scheduled_at', 'l.id as lead_id', 'l.name as lead_name'])
            ->map(fn ($t) => ['id' => $t->id, 'title' => $t->subject ?: $t->description, 'due' => $t->scheduled_at, 'overdue' => $t->scheduled_at && $t->scheduled_at < now()->startOfDay()->toDateTimeString(), 'lead_id' => $t->lead_id, 'lead_name' => $t->lead_name])->values();
        $bookings = DB::table('calendar_events as e')->leftJoin('leads as l', fn ($j) => $j->on('l.id', '=', 'e.reference_id')->whereIn('e.reference_type', ['Lead', 'lead']))
            ->where('e.workspace_id', $ws)->whereBetween('e.starts_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->whereNotIn('e.category', ['cancelled', 'booking_declined', 'task_deadline'])->when($b, fn ($q) => $q->where(fn ($w) => $w->where('e.business_id', $b)->orWhere('l.business_id', $b)))
            ->orderBy('e.starts_at')->limit(10)->get(['e.id', 'e.title', 'e.starts_at', 'e.category', 'l.id as lead_id', 'l.name as lead_name'])
            ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at, 'pending' => $e->category === 'booking_pending', 'lead_id' => $e->lead_id, 'lead_name' => $e->lead_name])->values();
        $stalledQ = $scope(Lead::where('workspace_id', $ws))->whereIn('status', ['contacted', 'qualified'])
            ->whereRaw("COALESCE((SELECT MAX(a.created_at) FROM activities a WHERE a.lead_id = leads.id), leads.updated_at) < ?", [now()->subDays(14)]);
        $stalled = (clone $stalledQ)->orderBy('updated_at')->limit(6)->get();
        $wk = now()->subDays(7);
        return $this->readJson([
            'pack' => $pack,
            'waiting' => $waiting->map(fn ($l) => $this->shape($l, $pack, $bizNames, $pb))->values(), 'waiting_total' => $waitingTotal,
            'tasks' => $tasks, 'bookings' => $bookings,
            'stalled' => $stalled->map(fn ($l) => $this->shape($l, $pack, $bizNames, $pb))->values(), 'stalled_total' => (clone $stalledQ)->count(),
            'week' => [
                'new' => $scope(Lead::where('workspace_id', $ws))->where('created_at', '>=', $wk)->count(),
                'won' => $scope(Lead::where('workspace_id', $ws))->whereNotNull('converted_at')->where('converted_at', '>=', $wk)->count(),
                'total' => $scope(Lead::where('workspace_id', $ws))->count(),
            ],
        ]);
    }

    /** GET /crm/reports?days=30 — where enquiries come from, how fast they are answered, where they go. */
    public function reports(Request $r): JsonResponse
    {
        $ws = $this->wsId($r); $b = $this->biz($r);
        if ($b === -1) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $pack = $this->packFor($b);
        $days = in_array((int) $r->input('days'), [7, 30, 90, 365], true) ? (int) $r->input('days') : 30;
        $since = now()->subDays($days);
        $base = fn () => Lead::where('workspace_id', $ws)->when($b, fn ($q) => $q->where('business_id', $b))->where('created_at', '>=', $since);
        $byChannel = $base()->selectRaw('COALESCE(channel, "other") c, count(*) n')->groupBy('c')->orderByDesc('n')->pluck('n', 'c');
        $leads = $base()->get(['id', 'stage', 'status', 'created_at', 'converted_at', 'deal_value']);
        $stageCounts = [];
        foreach ($pack['stages'] as $s) $stageCounts[$s['key']] = 0;
        $pb = $b ? [] : $this->packsByBiz($ws);
        foreach ($leads as $l) { $st = CrmPacks::stageOf($pack, $l->stage, $l->status); if (isset($stageCounts[$st])) $stageCounts[$st]++; }
        $replyHours = [];
        if ($leads->count()) {
            $first = DB::table('activities')->whereIn('lead_id', $leads->pluck('id'))->whereIn('type', ['call', 'email', 'meeting', 'note'])
                ->selectRaw('lead_id, MIN(created_at) t')->groupBy('lead_id')->pluck('t', 'lead_id');
            foreach ($leads as $l) if (isset($first[$l->id])) $replyHours[] = max(0, (strtotime($first[$l->id]) - strtotime((string) $l->created_at)) / 3600);
        }
        sort($replyHours);
        $median = $replyHours ? $replyHours[intdiv(count($replyHours), 2)] : null;
        $won = $leads->where('status', 'converted');
        return $this->readJson([
            'days' => $days, 'pack' => $pack,
            'totals' => ['new' => $leads->count(), 'won' => $won->count(), 'lost' => $leads->where('status', 'lost')->count(),
                'won_value' => round((float) $won->sum('deal_value'), 2), 'answered' => count($replyHours),
                'median_reply_hours' => $median !== null ? round($median, 1) : null,
                'conversion' => $leads->count() ? round($won->count() / $leads->count() * 100) : 0],
            'channels' => $byChannel,
            'stages' => collect($pack['stages'])->map(fn ($s) => ['key' => $s['key'], 'name' => $s['name'], 'count' => $stageCounts[$s['key']] ?? 0])->values(),
        ]);
    }
}
