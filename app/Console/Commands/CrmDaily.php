<?php

namespace App\Console\Commands;

use App\Engines\CRM\Services\SarahClients;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CRM-SARAH-3: "who to contact today". Each morning at 08:00 in the owner's own time, Sarah picks up to five people
 * who need the owner — new enquiries nobody answered yet, and conversations that went quiet — and posts them in chat
 * with the reason and a message ready to send ("send #2", "send all", "not today").
 *
 * Gates: switch storage/app/crmdaily.on, onboarded, Sarah on the plan, proactive mode on, credits for the drafts.
 * `--dry-run` lists who would be suggested and writes nothing. `--workspace=` and `--now` run one workspace at once.
 */
class CrmDaily extends Command
{
    protected $signature = 'crm:daily {--dry-run} {--workspace=} {--now : ignore the 08:00 local clock (one workspace)}';
    protected $description = "Sarah's morning list: who to contact today, with messages ready to send";

    public function handle(SarahClients $sc): int
    {
        $dry = (bool) $this->option('dry-run');
        $wsQ = DB::table('workspaces')->where('onboarded', 1)->where('proactive_enabled', 1);
        if ($this->option('workspace')) $wsQ->where('id', (int) $this->option('workspace'));
        $done = 0;
        foreach ($wsQ->pluck('timezone', 'id') as $ws => $tz) {
            $tz = in_array((string) $tz, timezone_identifiers_list(), true) ? (string) $tz : 'UTC';
            $local = Carbon::now($tz);
            $force = (bool) $this->option('now') && $this->option('workspace');
            if (! $force && ((int) $local->format('G') !== 8)) continue;
            $key = 'crm-daily:' . $ws . ':' . $local->toDateString();
            if (! $dry && Cache::has($key)) continue;
            try { if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah((int) $ws)) continue; } catch (\Throwable $e) { continue; }
            $picks = $this->candidates((int) $ws, $tz);
            if (! $picks) continue;
            $this->line("ws {$ws} ({$tz}): " . implode(' | ', array_map(fn ($p) => $p['lead']->name . ' — ' . $p['reason'], $picks)));
            if ($dry) continue;
            Cache::put($key, 1, now()->addHours(26));
            try { if (! app(\App\Core\Billing\CreditService::class)->hasBalance((int) $ws, SarahClients::REPLY_CREDITS)) continue; } catch (\Throwable $e) {}
            $ids = []; $lines = []; $n = 0;
            foreach ($picks as $p) {
                $n++;
                $l = $p['lead'];
                $reply = $l->email ? $sc->writeReply((int) $ws, $l, 'follow_up', $p['reason']) : null;
                $draftId = $reply ? $sc->saveDraft((int) $ws, $l, $reply, 'daily', $p['reason']) : null;
                if ($draftId) $ids[] = $draftId;
                $lines[] = "**{$n}. {$l->name}**" . ($p['business'] ? " ({$p['business']})" : '') . " — {$p['reason']}"
                    . ($reply ? "\n_{$reply['subject']}_: " . mb_substr(preg_replace('/\s+/', ' ', $reply['body']), 0, 220) . (mb_strlen($reply['body']) > 220 ? '…' : '')
                              : ($l->phone ? "\nNo email: call them on {$l->phone}." : "\nNo email or phone: open them in Clients."));
            }
            try { app(\App\Core\Billing\CreditService::class)->debit((int) $ws, SarahClients::REPLY_CREDITS, 'crm_daily', null, ['what' => 'Who to contact today']); } catch (\Throwable $e) {}
            $msgId = $sc->say((int) $ws, 'crm_daily',
                'Good morning note: in one or two short sentences tell the owner how many people are worth contacting today and why it matters (they are listed below with a message ready for each). No greeting beyond a short good morning.',
                ['people' => array_map(fn ($p) => ['name' => $p['lead']->name, 'why' => $p['reason']], $picks), 'messages_ready' => count($ids)],
                'Good morning. ' . count($picks) . ' ' . (count($picks) === 1 ? 'person is' : 'people are') . ' worth contacting today; ' . (count($ids) ? 'I wrote a message for each one with an email.' : 'none of them has an email, so a call is best.'),
                ['card' => ['draft_ids' => $ids], 'action_url' => '/app/crm'],
                implode("\n\n", $lines) . "\n\n" . ($ids ? 'Reply **send #1** (or any number), **send all**, or **not today**. You can also edit each one in Clients.' : ''));
            if ($ids) {
                foreach (array_values($ids) as $i => $id) DB::table('crm_reply_drafts')->where('id', $id)->update(['message_id' => $msgId, 'position' => $i + 1]);
            }
            $done++;
        }
        $this->info(($dry ? '[dry run] ' : '') . "lists posted: {$done}");
        return self::SUCCESS;
    }

    /** Up to five people: unanswered enquiries first (oldest waiting first), then quiet conversations (highest value first). */
    private function candidates(int $ws, string $tz): array
    {
        $biz = DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->pluck('name', 'id');
        $human = "(SELECT MAX(a.created_at) FROM activities a WHERE a.lead_id = leads.id AND a.type IN ('note','call','email','meeting'))";
        $openDraft = "EXISTS (SELECT 1 FROM crm_reply_drafts d WHERE d.lead_id = leads.id AND d.status = 'draft' AND d.created_at > NOW() - INTERVAL 3 DAY)";
        $future = "EXISTS (SELECT 1 FROM calendar_events e WHERE e.lead_id = leads.id AND e.starts_at > NOW() AND COALESCE(e.status,'') NOT IN ('cancelled','done'))";
        $out = [];
        $new = DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->where('status', 'new')
            ->where('created_at', '<', now()->subHours(2))->where('created_at', '>', now()->subDays(21))
            ->whereRaw("$human IS NULL")->whereRaw("NOT $openDraft")->orderBy('created_at')->limit(5)->get();
        foreach ($new as $l) $out[] = ['lead' => $l, 'business' => $biz[$l->business_id] ?? null, 'reason' => 'enquired ' . Carbon::parse($l->created_at)->diffForHumans() . ' and has not heard back yet'];
        if (count($out) < 5) {
            $quiet = DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->whereIn('status', ['contacted', 'qualified'])
                ->whereRaw("$human BETWEEN NOW() - INTERVAL 14 DAY AND NOW() - INTERVAL 3 DAY")->whereRaw("NOT $openDraft")->whereRaw("NOT $future")
                ->orderByDesc('deal_value')->orderByDesc('score')->limit(5 - count($out))->select('leads.*')->selectRaw("$human AS last_touch")->get();
            foreach ($quiet as $l) $out[] = ['lead' => $l, 'business' => $biz[$l->business_id] ?? null, 'reason' => 'last heard from you ' . Carbon::parse($l->last_touch)->diffForHumans() . ' and has gone quiet'];
        }
        // CRM-PACKS-4b: clients of visit businesses whose next visit is due and not booked
        if (count($out) < 5) {
            foreach (DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')->where('status', 'converted')->whereNotNull('business_id')->orderBy('updated_at')->limit(80)->get() as $l) {
                if (count($out) >= 5) break;
                $pack = \App\Engines\CRM\Services\CrmPacks::forBusiness((int) $l->business_id);
                $days = (int) ($pack['recall_days'] ?? 0);
                if ($pack['key'] !== 'appointments' || ! $days) continue;
                if (DB::table('calendar_events')->where('lead_id', $l->id)->where('starts_at', '>', now())->whereNotIn(DB::raw("COALESCE(status,'')"), ['cancelled', 'no_show', 'done'])->exists()) continue;
                if (DB::table('crm_reply_drafts')->where('lead_id', $l->id)->where('created_at', '>', now()->subDays(14))->exists()) continue;
                $last = DB::table('calendar_events')->where('lead_id', $l->id)->where('starts_at', '<=', now())->whereNotIn(DB::raw("COALESCE(status,'')"), ['cancelled', 'no_show'])->max('starts_at') ?: $l->converted_at;
                if (! $last || Carbon::parse($last)->gt(now()->subDays($days))) continue;
                $out[] = ['lead' => $l, 'business' => $biz[$l->business_id] ?? null, 'reason' => 'last visit was ' . Carbon::parse($last)->diffForHumans() . ' and they are due to book again'];
            }
        }
        return $out;
    }
}
