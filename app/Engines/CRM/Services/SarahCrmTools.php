<?php

namespace App\Engines\CRM\Services;

use App\Models\Activity;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRM-SARAH-3: what Sarah can do with Clients from the chat ("who hasn't heard from us?", "brief me on Maria",
 * "move Hannah to offer made", "log that I called Ben", "book a call with Priya tomorrow at 3", "what needs me today").
 * Every answer uses the business's own words and stages (CrmPacks); nothing here sends anything to a client.
 */
class SarahCrmTools
{
    /** A client by id, or by name within the workspace (and business when given). @return array{0:?object,1:array} [lead, other matches] */
    private function resolve(int $ws, array $p): array
    {
        $id = (int) ($p['client_id'] ?? $p['lead_id'] ?? 0);
        if ($id) return [DB::table('leads')->where('workspace_id', $ws)->where('id', $id)->whereNull('deleted_at')->first(), []];
        $name = trim((string) ($p['client'] ?? $p['name'] ?? ''));
        if ($name === '') return [null, []];
        $q = DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->where(fn ($w) => $w->where('name', 'like', '%' . addcslashes($name, '%_\\') . '%')->orWhere('email', $name));
        if (! empty($p['business_id'])) $q->where('business_id', (int) $p['business_id']);
        $rows = $q->orderByDesc('updated_at')->limit(5)->get();
        if ($rows->count() === 1) return [$rows->first(), []];
        $exact = $rows->filter(fn ($r) => mb_strtolower($r->name) === mb_strtolower($name));
        if ($exact->count() === 1) return [$exact->first(), []];
        return [null, $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'business' => DB::table('businesses')->where('id', $r->business_id)->value('name')])->values()->all()];
    }

    private function row(object $l): array
    {
        $pack = CrmPacks::forBusiness($l->business_id ? (int) $l->business_id : null);
        $st = CrmPacks::stageOf($pack, $l->stage, $l->status);
        $last = DB::table('activities')->where('lead_id', $l->id)->whereIn('type', ['note', 'call', 'email', 'meeting'])->max('created_at');
        return ['client_id' => $l->id, 'name' => $l->name, 'business' => $l->business_id ? DB::table('businesses')->where('id', $l->business_id)->value('name') : null,
            'stage' => collect($pack['stages'])->firstWhere('key', $st)['name'] ?? $st, 'came_through' => $l->channel, 'added' => Carbon::parse($l->created_at)->diffForHumans(),
            'last_contact' => $last ? Carbon::parse($last)->diffForHumans() : 'never', 'phone' => $l->phone, 'email' => $l->email, 'value' => (float) $l->deal_value ?: null];
    }

    public function findClients(int $ws, array $p): array
    {
        $q = Lead::where('workspace_id', $ws);
        if (! empty($p['business_id'])) $q->where('business_id', (int) $p['business_id']);
        elseif (! empty($p['business'])) { $b = DB::table('businesses')->where('workspace_id', $ws)->where('name', 'like', '%' . addcslashes((string) $p['business'], '%_\\') . '%')->value('id'); if ($b) $q->where('business_id', $b); }
        if (! empty($p['search'])) { $s = '%' . addcslashes((string) $p['search'], '%_\\') . '%'; $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('email', 'like', $s)->orWhere('phone', 'like', $s)); }
        if (! empty($p['channel'])) $q->where('channel', $p['channel']);
        $human = "(SELECT MAX(a.created_at) FROM activities a WHERE a.lead_id = leads.id AND a.type IN ('note','call','email','meeting'))";
        $view = (string) ($p['view'] ?? '');
        match ($view) {
            'not_contacted', 'no_reply', 'waiting' => $q->where('status', 'new')->whereRaw("$human IS NULL"),
            'new_this_week' => $q->where('created_at', '>=', now()->subDays(7)),
            'gone_quiet' => $q->whereIn('status', ['contacted', 'qualified'])->whereRaw("COALESCE($human, leads.updated_at) < ?", [now()->subDays(14)]),
            'won' => $q->where('status', 'converted'),
            'lost' => $q->where('status', 'lost'),
            default => null,
        };
        if (! empty($p['stage'])) {
            $want = mb_strtolower(trim((string) $p['stage']));
            $rows = $q->orderByDesc('created_at')->limit(300)->get()->filter(function ($l) use ($want) {
                $pack = CrmPacks::forBusiness($l->business_id ? (int) $l->business_id : null);
                $st = CrmPacks::stageOf($pack, $l->stage, $l->status);
                $name = mb_strtolower(collect($pack['stages'])->firstWhere('key', $st)['name'] ?? $st);
                return $st === $want || $name === $want || str_contains($name, $want);
            });
            $total = $rows->count(); $rows = $rows->take(min((int) ($p['limit'] ?? 20), 50));
        } else {
            $total = (clone $q)->count();
            $rows = $q->orderByDesc('created_at')->limit(min((int) ($p['limit'] ?? 20), 50))->get();
        }
        return ['total' => $total, 'clients' => $rows->map(fn ($l) => $this->row((object) $l->getAttributes()))->values()->all()];
    }

    public function clientBrief(int $ws, array $p): array
    {
        [$l, $others] = $this->resolve($ws, $p);
        if (! $l) return ['found' => false, 'did_you_mean' => $others, 'note' => $others ? 'Several clients match; ask which one.' : 'No client by that name.'];
        $sum = app(SarahClients::class)->summary($ws, (int) $l->id);
        return ['found' => true] + $this->row($l) + [
            'summary' => $sum['summary'] ?? null, 'next_step' => $sum['next_step'] ?? null,
            'open_tasks' => DB::table('activities')->where('lead_id', $l->id)->where('type', 'task')->where('completed', 0)->pluck('subject')->all(),
            'upcoming' => DB::table('calendar_events')->where('lead_id', $l->id)->where('starts_at', '>=', now()->subDay())->whereNotIn('category', ['cancelled'])->orderBy('starts_at')->limit(3)->get(['title', 'starts_at'])->all(),
            'reply_waiting_to_send' => DB::table('crm_reply_drafts')->where('lead_id', $l->id)->where('status', 'draft')->exists(),
            'link' => '/app/crm/' . $l->id,
        ];
    }

    public function moveClient(int $ws, array $p, ?int $userId): array
    {
        [$l, $others] = $this->resolve($ws, $p);
        if (! $l) return ['success' => false, 'did_you_mean' => $others, 'error' => $others ? 'Several clients match; ask which one.' : 'No client by that name.'];
        $pack = CrmPacks::forBusiness($l->business_id ? (int) $l->business_id : null);
        $want = mb_strtolower(trim((string) ($p['stage'] ?? '')));
        $stage = collect($pack['stages'])->first(fn ($s) => $s['key'] === $want || mb_strtolower($s['name']) === $want)
            ?? collect($pack['stages'])->first(fn ($s) => str_contains(mb_strtolower($s['name']), $want) && $want !== '');
        if (! $stage) return ['success' => false, 'error' => 'That stage is not in this business\'s setup.', 'stages' => array_column($pack['stages'], 'name')];
        app(CrmService::class)->updateLead((int) $l->id, ['status' => $stage['status']], $userId, $ws);
        DB::table('leads')->where('id', $l->id)->update(['stage' => $stage['key'], 'updated_at' => now()]);
        return ['success' => true, 'client' => $l->name, 'now' => $stage['name']];
    }

    public function noteClient(int $ws, array $p, ?int $userId): array
    {
        [$l, $others] = $this->resolve($ws, $p);
        if (! $l) return ['success' => false, 'did_you_mean' => $others, 'error' => $others ? 'Several clients match; ask which one.' : 'No client by that name.'];
        $kind = in_array(($p['kind'] ?? 'note'), ['note', 'call', 'meeting', 'email'], true) ? $p['kind'] : 'note';
        $text = mb_substr(trim((string) ($p['text'] ?? '')), 0, 2000);
        if ($text === '') return ['success' => false, 'error' => 'Nothing to write down.'];
        Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => (int) $l->id, 'type' => $kind, 'completed' => 1,
            'subject' => ['note' => 'Note', 'call' => 'Call', 'meeting' => 'Meeting', 'email' => 'Email'][$kind] . ' (told Sarah)', 'description' => $text, 'performed_by' => $userId]);
        if ($kind !== 'note') DB::table('leads')->where('id', $l->id)->update(['last_contacted_at' => now()]);
        if ($kind !== 'note' && $l->status === 'new') {
            $pack = CrmPacks::forBusiness($l->business_id ? (int) $l->business_id : null);
            DB::table('leads')->where('id', $l->id)->update(['status' => 'contacted', 'stage' => collect($pack['stages'])->firstWhere('status', 'contacted')['key'] ?? null]);
        }
        return ['success' => true, 'client' => $l->name, 'logged' => $kind];
    }

    public function scheduleClient(int $ws, array $p, ?int $userId): array
    {
        $l = null;
        if (! empty($p['client_id']) || ! empty($p['client'])) {
            [$l, $others] = $this->resolve($ws, $p);
            if (! $l) return ['success' => false, 'did_you_mean' => $others, 'error' => $others ? 'Several clients match; ask which one.' : 'No client by that name.'];
        }
        $cal = app(\App\Engines\Calendar\Services\CalendarService::class);
        $tz = $cal->ownerTz($ws);
        try { $start = Carbon::parse((string) ($p['when'] ?? ''), $tz); } catch (\Throwable $e) { return ['success' => false, 'error' => 'I need a day and time (e.g. 2026-10-02 15:00).']; }
        if ($start->lt(Carbon::now($tz)->subMinutes(5))) return ['success' => false, 'error' => 'That time has already passed.'];
        $kind = in_array(($p['kind'] ?? 'call'), ['call', 'meeting', 'appointment', 'follow_up', 'strategy_meeting'], true) ? $p['kind'] : 'call';
        $mins = max(10, min(480, (int) ($p['minutes'] ?? 30)));
        $title = trim((string) ($p['title'] ?? '')) ?: (['call' => 'Call', 'meeting' => 'Meeting', 'appointment' => 'Appointment', 'follow_up' => 'Follow up', 'strategy_meeting' => 'Strategy meeting'][$kind] . ($l ? ' with ' . $l->name : ''));
        $id = $cal->createEvent($ws, ['title' => mb_substr($title, 0, 190), 'category' => $kind, 'engine' => 'sarah', 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->copy()->addMinutes($mins)->format('Y-m-d H:i:s'),
            'reference_type' => $l ? 'Lead' : null, 'reference_id' => $l->id ?? null, 'lead_id' => $l->id ?? null, 'business_id' => $l->business_id ?? null,
            'remind_minutes' => isset($p['remind_minutes']) ? (int) $p['remind_minutes'] : 30, 'status' => 'scheduled', 'created_by' => $userId, 'description' => $p['notes'] ?? null]);
        return ['success' => true, 'event_id' => $id, 'title' => $title, 'when' => $start->format('D j M, g:i A'), 'reminder' => 'Sarah reminds the owner ' . (isset($p['remind_minutes']) ? (int) $p['remind_minutes'] : 30) . ' minutes before'];
    }

    public function clientsToday(int $ws, array $p): array
    {
        $human = "(SELECT COUNT(*) FROM activities a WHERE a.lead_id = leads.id AND a.type IN ('note','call','email','meeting'))";
        $waiting = DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->where('status', 'new')->whereRaw("$human = 0")->orderBy('created_at')->limit(8)->get(['id', 'name', 'created_at']);
        $tz = app(\App\Engines\Calendar\Services\CalendarService::class)->ownerTz($ws);
        $from = Carbon::now($tz)->startOfDay()->format('Y-m-d H:i:s'); $to = Carbon::now($tz)->endOfDay()->format('Y-m-d H:i:s');
        $q = DB::table('calendar_events')->where('workspace_id', $ws)->whereBetween('starts_at', [$from, $to])->where(fn ($s) => $s->whereNull('status')->orWhereNotIn('status', ['cancelled']));
        \App\Engines\Calendar\Http\Controllers\MyCalendarController::userScope($q);
        return [
            'enquiries_waiting_for_a_reply' => $waiting->map(fn ($l) => ['client_id' => $l->id, 'name' => $l->name, 'waiting_since' => Carbon::parse($l->created_at)->diffForHumans()])->all(),
            'on_the_calendar_today' => $q->orderBy('starts_at')->get(['title', 'starts_at', 'category'])->map(fn ($e) => ['what' => $e->title, 'at' => Carbon::parse($e->starts_at)->format('g:i A'), 'kind' => $e->category])->all(),
            'tasks_due' => DB::table('activities as a')->join('leads as l', 'l.id', '=', 'a.lead_id')->where('a.workspace_id', $ws)->where('a.type', 'task')->where('a.completed', 0)->whereNull('l.deleted_at')
                ->where(fn ($x) => $x->whereNull('a.scheduled_at')->orWhere('a.scheduled_at', '<=', $to))->limit(8)->get(['a.subject', 'l.name'])->map(fn ($t) => ['task' => $t->subject, 'client' => $t->name])->all(),
            'replies_ready_to_send' => DB::table('crm_reply_drafts')->where('workspace_id', $ws)->where('status', 'draft')->where('created_at', '>=', now()->subDays(3))->count(),
            'link' => '/app/crm',
        ];
    }
}
