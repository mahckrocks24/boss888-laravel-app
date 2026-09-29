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
            // CRM-PACKS-4b: a visit is due when the last one is older than the business's recall and nothing is booked
            'due_for_visit' => $q->where('status', 'converted')
                ->whereRaw("NOT EXISTS (SELECT 1 FROM calendar_events e WHERE e.lead_id = leads.id AND e.starts_at > NOW() AND COALESCE(e.status,'') NOT IN ('cancelled','no_show','done'))")
                ->whereRaw("COALESCE((SELECT MAX(e.starts_at) FROM calendar_events e WHERE e.lead_id = leads.id AND e.starts_at <= NOW() AND COALESCE(e.status,'') NOT IN ('cancelled','no_show')), leads.converted_at, leads.updated_at) < ?", [now()->subDays(max(1, (int) ($pack['recall_days'] ?? 0) ?: 180))]),
            'no_shows' => $q->whereRaw("EXISTS (SELECT 1 FROM calendar_events e WHERE e.lead_id = leads.id AND e.status = 'no_show' AND e.starts_at > NOW() - INTERVAL 180 DAY)"),
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
            // CRM-PACKS-4a: quotes, deposits and invoices for this client
            'payments' => DB::table('crm_payment_requests')->where('workspace_id', $ws)->where('lead_id', $l->id)->orderByDesc('id')->limit(20)->get(['id', 'kind', 'number', 'title', 'currency', 'total', 'status', 'due_date', 'sent_at', 'viewed_at', 'accepted_at', 'paid_at', 'paid_amount', 'token'])
                ->map(function ($p) { $pay = app(\App\Engines\CRM\Services\CrmPayments::class); $o = (array) $p; $o['total_text'] = $pay->money($p->currency, (float) $p->total); $o['link'] = $pay->link($p); unset($o['token']); return $o; })->values(),
            'payments_account' => (bool) app(\App\Engines\CRM\Services\CrmPayments::class)->account($ws),
            'visits' => (function () use ($l, $pack) {   // CRM-PACKS-4b
                $ev = DB::table('calendar_events')->where('lead_id', $l->id)->whereNotIn('category', ['task_deadline', 'follow_up', 'email', 'reminder']);
                $past = (clone $ev)->where('starts_at', '<=', now())->whereNotIn(DB::raw("COALESCE(status,'')"), ['cancelled', 'no_show'])->whereNotIn('category', ['booking_pending', 'booking_declined', 'callback_pending', 'cancelled']);
                $last = (clone $past)->max('starts_at');
                $next = (clone $ev)->where('starts_at', '>', now())->whereNotIn(DB::raw("COALESCE(status,'')"), ['cancelled', 'no_show', 'done'])->min('starts_at');
                $recall = (int) ($pack['recall_days'] ?? 0);
                return ['count' => (clone $past)->count(), 'last' => $last, 'next' => $next, 'no_shows' => (clone $ev)->where('status', 'no_show')->count(), 'recall_days' => $recall,
                    'due_on' => $recall && $last && ! $next ? \Carbon\Carbon::parse($last)->addDays($recall)->toDateString() : null];
            })(),
            'payments_currency' => app(\App\Engines\CRM\Services\CrmPayments::class)->account($ws)->currency ?? 'USD',
            'sarah' => ($meta['sarah_summary'] ?? null), // CRM-SARAH-3: refreshed by GET /clients/{id}/summary when stale
            'drafts' => DB::table('crm_reply_drafts')->where('workspace_id', $ws)->where('lead_id', $l->id)->where('status', 'draft')->orderByDesc('id')->limit(3)->get(['id', 'source', 'subject', 'body', 'reason', 'created_at'])->values(),
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

    /** GET /crm/clients/{id}/summary — Sarah's summary and next step (written again only when something changed). */
    public function summary(Request $r, int $id): JsonResponse
    {
        $out = app(\App\Engines\CRM\Services\SarahClients::class)->summary($this->wsId($r), $id, $r->boolean('refresh'));
        return $out ? $this->readJson(['success' => true] + $out) : response()->json(['success' => false, 'message' => 'No summary yet.'], 404);
    }

    /** PUT /crm/setup/{businessId}/fields {fields:[{label, type, options?}]} — the owner's own details kept about each client. */
    public function customFields(Request $r, int $businessId): JsonResponse
    {
        $b = DB::table('businesses')->where('workspace_id', $this->wsId($r))->where('id', $businessId)->whereNull('deleted_at')->first(['id', 'settings_json']);
        if (! $b) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $set = json_decode((string) $b->settings_json, true) ?: [];
        $keep = [];
        foreach (array_slice((array) $r->input('fields', []), 0, 20) as $f) {
            $label = mb_substr(trim(strip_tags((string) ($f['label'] ?? ''))), 0, 60);
            if ($label === '') continue;
            $type = in_array($f['type'] ?? 'text', ['text', 'textarea', 'number', 'date', 'select'], true) ? $f['type'] : 'text';
            $key = (string) ($f['key'] ?? '') ?: 'c_' . substr(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)), 0, 30);
            $opts = $type === 'select' ? array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), (array) ($f['options'] ?? [])))) : [];
            if ($type === 'select' && ! $opts) continue;
            $keep[] = ['key' => preg_replace('/[^a-z0-9_]/', '', $key), 'label' => $label, 'type' => $type, 'options' => $opts];
        }
        $set['crm']['custom_fields'] = $keep;
        DB::table('businesses')->where('id', $businessId)->update(['settings_json' => json_encode($set, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return $this->readJson(['success' => true, 'pack' => \App\Engines\CRM\Services\CrmPacks::forBusiness($businessId)]);
    }

    /**
     * POST /crm/clients/import {business_id?, rows:[{name,email,phone,company,source,stage,value,notes,fields:{}}], on_duplicate: skip|update, batch?}
     * The browser reads and maps the CSV; each chunk (≤500 rows) is checked here. One batch id ties the chunks together
     * so the whole import can be undone for 24 hours.
     */
    public function import(Request $r): JsonResponse
    {
        $ws = $this->wsId($r); $b = $this->biz($r);
        if ($b === -1) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $rows = array_slice((array) $r->input('rows', []), 0, 500);
        $batch = preg_match('/^imp_[a-z0-9]{12}$/', (string) $r->input('batch')) ? (string) $r->input('batch') : 'imp_' . strtolower(\Illuminate\Support\Str::random(12));
        $upd = $r->input('on_duplicate') === 'update';
        $pack = $this->packFor($b);
        $fieldKeys = collect($pack['fields'])->pluck('key')->all();
        $crm = app(\App\Engines\CRM\Services\CrmService::class);
        $made = 0; $updated = 0; $skipped = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 190);
            $email = trim((string) ($row['email'] ?? '')); $phone = mb_substr(trim((string) ($row['phone'] ?? '')), 0, 60);
            if ($email !== '' && ! \App\Engines\CRM\Services\ClientIdentity::emailKey($email)) { $errors[] = ['row' => $i, 'why' => 'email looks wrong: ' . mb_substr($email, 0, 60)]; $email = ''; }
            if ($name === '' && $email === '' && $phone === '') { $skipped++; continue; }
            if ($name === '') $name = $email ?: $phone;
            $stageName = mb_strtolower(trim((string) ($row['stage'] ?? '')));
            $stage = $stageName !== '' ? collect($pack['stages'])->first(fn ($s) => mb_strtolower($s['name']) === $stageName || $s['key'] === $stageName) : null;
            $fields = array_filter(array_intersect_key((array) ($row['fields'] ?? []), array_flip($fieldKeys)), fn ($v) => trim((string) $v) !== '');
            $existing = \App\Engines\CRM\Services\ClientIdentity::existingProfile($ws, $b ?: null, $email ?: null, $phone ?: null);
            if ($existing) {
                if (! $upd) { $skipped++; continue; }
                $meta = json_decode((string) $existing->metadata_json, true) ?: [];
                $meta['fields'] = array_merge((array) ($meta['fields'] ?? []), $fields);
                DB::table('leads')->where('id', $existing->id)->update(array_filter(['name' => $name, 'phone' => $phone ?: null, 'company' => mb_substr((string) ($row['company'] ?? ''), 0, 190) ?: null,
                    'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()], fn ($v) => $v !== null));
                $updated++; continue;
            }
            try {
                $lead = $crm->createLead($ws, ['_origin' => 'import', 'business_id' => $b ?: null, 'name' => $name, 'email' => $email ?: null, 'phone' => $phone ?: null,
                    'company' => mb_substr((string) ($row['company'] ?? ''), 0, 190) ?: null, 'source' => 'import', 'status' => $stage['status'] ?? 'new', 'deal_value' => (float) preg_replace('/[^0-9.]/', '', (string) ($row['value'] ?? '')) ?: 0,
                    'metadata' => ['fields' => $fields, 'import_batch' => $batch, 'imported_source' => mb_substr((string) ($row['source'] ?? ''), 0, 60) ?: null]]);
                if ($stage) DB::table('leads')->where('id', $lead->id)->update(['stage' => $stage['key']]);
                if (trim((string) ($row['notes'] ?? '')) !== '') \App\Models\Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => $lead->id, 'type' => 'note', 'description' => mb_substr((string) $row['notes'], 0, 2000), 'performed_by' => $this->userId($r)]);
                $made++;
            } catch (\Throwable $e) { $errors[] = ['row' => $i, 'why' => 'could not be saved']; }
        }
        return $this->readJson(['success' => true, 'batch' => $batch, 'created' => $made, 'updated' => $updated, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 50)]);
    }

    /** POST /crm/imports/{batch}/undo — within 24 hours, the clients that import created are archived again. */
    public function undoImport(Request $r, string $batch): JsonResponse
    {
        if (! preg_match('/^imp_[a-z0-9]{12}$/', $batch)) return response()->json(['success' => false, 'message' => 'Unknown import.'], 404);
        $n = DB::table('leads')->where('workspace_id', $this->wsId($r))->whereNull('deleted_at')->where('created_at', '>=', now()->subDay())
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.import_batch')) = ?", [$batch])->update(['deleted_at' => now()]);
        return $this->readJson(['success' => true, 'archived' => $n]);
    }

    /** GET /crm/clients/{id}/catalogue — what the client is interested in, the business's items, and (property) matches. */
    public function catalogue(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $l = DB::table('leads')->where('workspace_id', $ws)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $l) return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        $cat = app(\App\Engines\CRM\Services\CrmCatalogue::class);
        $pack = $this->packFor($l->business_id ? (int) $l->business_id : null);
        [$kinds, $label] = \App\Engines\CRM\Services\CrmCatalogue::KINDS[$pack['key']] ?? \App\Engines\CRM\Services\CrmCatalogue::KINDS['general'];
        $items = $cat->items($ws, $l->business_id ? (int) $l->business_id : null, $kinds)->map(fn ($it) => $cat->shape($it))->values();
        $ids = array_map('intval', (array) ((json_decode((string) $l->metadata_json, true) ?: [])['interests'] ?? []));
        return $this->readJson(['label' => $label, 'items' => $items, 'interests' => $items->filter(fn ($i) => in_array($i['id'], $ids, true))->values(),
            'matches' => $cat->matches($ws, $l, $pack), 'is_property' => $pack['key'] === 'property']);
    }

    /** PUT /crm/clients/{id}/interests {ids:[]} */
    public function interests(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $l = DB::table('leads')->where('workspace_id', $ws)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $l) return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        $meta = json_decode((string) $l->metadata_json, true) ?: [];
        $meta['interests'] = array_slice(array_values(array_unique(array_map('intval', (array) $r->input('ids', [])))), 0, 30);
        DB::table('leads')->where('id', $id)->update(['metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return $this->readJson(['success' => true]);
    }

    /** POST /crm/clients/{id}/send-items {ids:[], note?} — email the chosen items to the client as the business. */
    public function sendItems(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $l = DB::table('leads')->where('workspace_id', $ws)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $l) return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        $res = app(\App\Engines\CRM\Services\CrmCatalogue::class)->send($ws, $l, (array) $r->input('ids', []), mb_substr(trim((string) $r->input('note', '')), 0, 1000), $this->userId($r));
        return ! empty($res['success']) ? $this->readJson($res) : response()->json(['success' => false, 'message' => $res['error'] ?? 'Not sent.'], 422);
    }

    /** POST /crm/clients/{id}/payments {kind, title?, items[], currency?, due_date?, note?, send?} */
    public function createPayment(Request $r, int $id): JsonResponse
    {
        $res = app(\App\Engines\CRM\Services\CrmPayments::class)->create($this->wsId($r), $id, $r->all(), $this->userId($r));
        return ! empty($res['success']) ? $this->readJson($res, 201) : response()->json(['success' => false, 'message' => $res['error'] ?? 'Not created.'], 422);
    }

    /** POST /crm/payments/{id}/send — send (or send again) */
    public function sendPayment(Request $r, int $id): JsonResponse
    {
        $res = app(\App\Engines\CRM\Services\CrmPayments::class)->send($this->wsId($r), $id, $this->userId($r));
        return ! empty($res['sent']) ? $this->readJson(['success' => true] + $res) : response()->json(['success' => false, 'message' => $res['error'] ?? 'Not sent.', 'link' => $res['link'] ?? null], 422);
    }

    /** POST /crm/payments/{id}/cancel — withdraw a request that is not paid */
    public function cancelPayment(Request $r, int $id): JsonResponse
    {
        $n = DB::table('crm_payment_requests')->where('workspace_id', $this->wsId($r))->where('id', $id)->whereNotIn('status', ['paid'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        return $n ? $this->readJson(['success' => true]) : response()->json(['success' => false, 'message' => 'Paid requests cannot be withdrawn.'], 422);
    }

    /** GET /crm/drafts — replies Sarah wrote that are waiting for the owner. */
    public function drafts(Request $r): JsonResponse
    {
        $ws = $this->wsId($r); $b = $this->biz($r);
        $rows = DB::table('crm_reply_drafts as d')->join('leads as l', 'l.id', '=', 'd.lead_id')->where('d.workspace_id', $ws)->where('d.status', 'draft')->whereNull('l.deleted_at')
            ->when($b && $b > 0, fn ($q) => $q->where('l.business_id', $b))->where('d.created_at', '>=', now()->subDays(7))->orderByDesc('d.id')->limit(20)
            ->get(['d.id', 'd.source', 'd.subject', 'd.body', 'd.reason', 'd.created_at', 'l.id as lead_id', 'l.name', 'l.email', 'l.business_id']);
        return $this->readJson(['drafts' => $rows]);
    }

    /** POST /crm/drafts/{id}/send {body?} — the owner sends (or edits and sends) a reply Sarah wrote. */
    public function sendDraft(Request $r, int $id): JsonResponse
    {
        $body = $r->filled('body') ? mb_substr(trim((string) $r->input('body')), 0, 4000) : null;
        $res = app(\App\Engines\CRM\Services\SarahClients::class)->sendDraft($this->wsId($r), $id, $this->userId($r), $body);
        return ! empty($res['success']) ? $this->readJson(['success' => true]) : response()->json(['success' => false, 'message' => $res['error'] ?? 'Not sent.'], 422);
    }

    /** POST /crm/drafts/{id}/skip */
    public function skipDraft(Request $r, int $id): JsonResponse
    {
        app(\App\Engines\CRM\Services\SarahClients::class)->skipDraft($this->wsId($r), $id);
        return $this->readJson(['success' => true]);
    }

    /** GET|PUT /crm/autoreply/{businessId} {setting: off|draft|send} — Sarah answering new enquiries for this business. */
    public function autoreply(Request $r, int $businessId): JsonResponse
    {
        $ws = $this->wsId($r);
        if (! DB::table('businesses')->where('workspace_id', $ws)->where('id', $businessId)->whereNull('deleted_at')->exists()) return response()->json(['success' => false, 'message' => 'Business not found.'], 404);
        $sc = app(\App\Engines\CRM\Services\SarahClients::class);
        if ($r->isMethod('put')) {
            $s = (string) $r->input('setting');
            if (! in_array($s, ['off', 'draft', 'send'], true)) return response()->json(['success' => false, 'message' => 'Unknown setting.'], 422);
            $s === 'off' ? $sc->setAutoreply($ws, $businessId, 'off', null, $this->userId($r)) : $sc->setAutoreply($ws, $businessId, 'on', $s, $this->userId($r));
        }
        $row = $sc->autoreplyRow($ws, $businessId);
        $setting = $row->status === 'on' ? $row->mode : 'off';
        return $this->readJson(['success' => true, 'setting' => $setting, 'status' => $row->status, 'sent_count' => (int) $row->sent_count, 'last_sent_at' => $row->last_sent_at,
            'available' => is_file(storage_path('app/speedlead.on'))]);
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
        $replyHours = []; $first = [];
        if ($leads->count()) {
            $first = DB::table('activities')->whereIn('lead_id', $leads->pluck('id'))->whereIn('type', ['call', 'email', 'meeting', 'note'])
                ->selectRaw('lead_id, MIN(created_at) t')->groupBy('lead_id')->pluck('t', 'lead_id');
            foreach ($leads as $l) if (isset($first[$l->id])) $replyHours[] = max(0, (strtotime($first[$l->id]) - strtotime((string) $l->created_at)) / 3600);
        }
        sort($replyHours);
        $median = $replyHours ? $replyHours[intdiv(count($replyHours), 2)] : null;
        $won = $leads->where('status', 'converted');
        // CRM-PACKS-4e: the trend, conversion by channel, reply speed, no-shows, money, and businesses side by side
        $weeks = [];
        for ($i = 11; $i >= 0; $i--) { $st = now()->startOfWeek()->subWeeks($i); $weeks[$st->toDateString()] = ['week' => $st->format('j M'), 'new' => 0, 'won' => 0]; }
        $from12 = now()->startOfWeek()->subWeeks(11);
        $scoped = fn () => Lead::where('workspace_id', $ws)->when($b, fn ($q) => $q->where('business_id', $b));
        foreach ($scoped()->where('created_at', '>=', $from12)->get(['created_at']) as $l) { $k = \Carbon\Carbon::parse($l->created_at)->startOfWeek()->toDateString(); if (isset($weeks[$k])) $weeks[$k]['new']++; }
        foreach ($scoped()->whereNotNull('converted_at')->where('converted_at', '>=', $from12)->get(['converted_at']) as $l) { $k = \Carbon\Carbon::parse($l->converted_at)->startOfWeek()->toDateString(); if (isset($weeks[$k])) $weeks[$k]['won']++; }
        $byCh = [];
        foreach ($leads as $l) { $c = DB::table('leads')->where('id', $l->id)->value('channel') ?: 'other'; $byCh[$c] ??= ['channel' => $c, 'new' => 0, 'won' => 0]; $byCh[$c]['new']++; if ($l->status === 'converted') $byCh[$c]['won']++; }
        $byCh = array_values($byCh); usort($byCh, fn ($x, $y) => $y['new'] <=> $x['new']);
        foreach ($byCh as &$c) $c['rate'] = $c['new'] ? round($c['won'] / $c['new'] * 100) : 0; unset($c);
        $speed = ['within_1h' => 0, 'within_24h' => 0, 'later' => 0, 'not_yet' => 0];
        foreach ($leads as $l) {
            $h = null;
            foreach ($replyHours as $_) break;
            $first = $first ?? [];
            $t = $first[$l->id] ?? null;
            if ($t === null) { $speed['not_yet']++; continue; }
            $h = (strtotime($t) - strtotime((string) $l->created_at)) / 3600;
            $h <= 1 ? $speed['within_1h']++ : ($h <= 24 ? $speed['within_24h']++ : $speed['later']++);
        }
        $ev = DB::table('calendar_events as e')->join('leads as l', 'l.id', '=', 'e.lead_id')->where('e.workspace_id', $ws)->when($b, fn ($q) => $q->where('l.business_id', $b))
            ->where('e.starts_at', '>=', $since)->where('e.starts_at', '<=', now())->whereIn('e.category', ['appointment', 'booking_confirmed', 'meeting', 'call', 'callback_confirmed']);
        $held = (clone $ev)->whereNotIn(DB::raw("COALESCE(e.status,'')"), ['cancelled'])->count();
        $noShow = (clone $ev)->where('e.status', 'no_show')->count();
        $pay = DB::table('crm_payment_requests as p')->where('p.workspace_id', $ws)->when($b, fn ($q) => $q->where('p.business_id', $b));
        $money = ['collected' => round((float) (clone $pay)->where('p.status', 'paid')->where('p.paid_at', '>=', $since)->sum('p.paid_amount'), 2),
            'outstanding' => round((float) (clone $pay)->whereIn('p.status', ['sent', 'viewed', 'accepted'])->where('p.kind', '!=', 'quote')->sum('p.total'), 2),
            'quotes_open' => (clone $pay)->where('p.kind', 'quote')->whereIn('p.status', ['sent', 'viewed'])->count(),
            'currency' => app(\App\Engines\CRM\Services\CrmPayments::class)->account($ws)->currency ?? 'USD'];
        $perBiz = [];
        if (! $b) foreach (DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->get(['id', 'name']) as $bz) {
            $q = Lead::where('workspace_id', $ws)->where('business_id', $bz->id)->where('created_at', '>=', $since);
            $n = (clone $q)->count(); if (! $n) continue;
            $perBiz[] = ['name' => $bz->name, 'new' => $n, 'won' => (clone $q)->where('status', 'converted')->count(), 'value' => round((float) (clone $q)->where('status', 'converted')->sum('deal_value'), 2)];
        }
        return $this->readJson([
            'trend' => array_values($weeks), 'by_channel' => $byCh, 'reply_speed' => $speed,
            'visits' => ['held' => $held, 'no_shows' => $noShow, 'no_show_rate' => $held ? round($noShow / $held * 100) : 0],
            'money' => $money, 'per_business' => $perBiz,
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
