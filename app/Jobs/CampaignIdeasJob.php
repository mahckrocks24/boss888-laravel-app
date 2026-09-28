<?php

namespace App\Jobs;

use App\Core\Campaigns\CampaignPlanner;
use App\Core\Campaigns\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CAMPAIGNS-1: Sarah thinks of campaign ideas for one business and brings them to the owner in her chat.
 * From chat (after her own reply), from the Campaigns page, or from the monthly cycle.
 */
class CampaignIdeasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 14;
    public int $timeout = 300;

    public function __construct(public int $wsId, public ?int $businessId, public string $source = 'sarah_chat', public string $ask = '', public ?int $afterMessageId = null, public int $count = 3)
    {
        $this->onQueue('default');
    }

    public function handle(CampaignPlanner $planner, CampaignService $campaigns): void
    {
        if ($this->afterMessageId) {
            $answered = DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('id', '>', $this->afterMessageId)
                ->where(fn ($q) => $q->whereNull('metadata_json')->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.phase')), '') <> 'ack'"))->exists();
            if (! $answered && $this->attempts() < $this->tries) { $this->release(10); return; }
        }
        // SARAH-GATE-1b: ideas are Sarah's AI work; on a plan without her the owner hears why, and nothing is generated
        try {
            if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah($this->wsId)) {
                if (! in_array($this->source, ['sarah_signal', 'sarah_monthly'], true)) app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($this->wsId, 'sarah', \App\Core\Billing\SarahPaused::text($this->wsId), ['notification_type' => 'plan_required']);
                return;
            }
        } catch (\Throwable $e) {}
        $lock = Cache::lock('campaign-ideas:' . $this->wsId . ':' . ($this->businessId ?? 0), 280);
        if (! $lock->get()) return;
        try {
            $ideas = $planner->ideas($this->wsId, $this->businessId, $this->count, $this->ask);
            if (! $ideas) {
                // WATCH-1: ideas Sarah started herself (a signal, the monthly cycle) retry quietly — the owner never asked, so no failure note
                if (in_array($this->source, ['sarah_signal', 'sarah_monthly'], true)) {
                    if ($this->attempts() < 3) $this->release(180);
                    else \Illuminate\Support\Facades\Log::warning('[CAMPAIGNS-1] quiet ideas gave up', ['ws' => $this->wsId, 'source' => $this->source]);
                    return;
                }
                app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($this->wsId, 'sarah', 'I could not finish the campaign ideas just now. I will try again shortly, or ask me again in a moment.', ['notification_type' => 'campaign_ideas_failed']);
                return;
            }
            $biz = app(\App\Core\Brand\BrandProfileService::class)->business($this->wsId, $this->businessId);
            $ids = $campaigns->saveIdeas($this->wsId, $biz->id ?? $this->businessId, $ideas, $this->source);
            $cards = [];
            foreach ($ids as $id) {
                $full = $campaigns->show($this->wsId, $id);
                $steps = [];
                foreach ($full['phases'] as $p) foreach ($p['items'] as $it) $steps[] = ['title' => $it['title'], 'kind' => $it['kind'], 'channel' => $it['channel'], 'at' => $it['scheduled_at'], 'phase' => $p['name']];
                $cards[] = array_intersect_key($full, array_flip(['id', 'title', 'objective', 'why_now', 'offer', 'channels', 'starts_on', 'ends_on', 'kpi', 'credit_estimate', 'steps_total'])) + ['steps' => array_slice($steps, 0, 4)];
            }
            $name = $biz->name ?? 'your business';
            $facts = ['business' => $name, 'ideas' => array_map(fn ($c) => ['title' => $c['title'], 'why_now' => $c['why_now'], 'target' => $c['kpi']['label'] ?? null, 'dates' => $c['starts_on'] . ' to ' . $c['ends_on']], $cards)];
            $fallback = 'Here are ' . count($cards) . ' campaign ideas for ' . $name . '. Each has a clear goal, dates and every step planned. Launch the one you like and my team will run it; you approve each post before it goes out.';
            $words = app(\App\Core\Brand\BrandIntakeService::class)->sarahWords($this->wsId, 'campaign_ideas',
                "Write Sarah's short chat message (2-3 sentences) presenting campaign ideas she designed to grow the business: name the business, one line on why these fit now (from FACTS), and say that launching approves the whole plan once, her team runs every step on its date, and the owner still okays each post before it goes out. The ideas are listed right after your message, numbered; do not list them yourself and do not mention cards or buttons. Warm, confident, no emojis, no internal codes.",
                $facts, $fallback);
            // CHAT-FIRST-1: the ideas in words, so the companion app shows everything the web card shows
            $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('j M') : '';
            $words .= \App\Core\Growth\ChatReplies::APP_PART . implode("\n\n", array_map(fn ($c, $i) => ($i + 1) . '. **' . $c['title'] . '** — ' . $fmt($c['starts_on']) . '–' . $fmt($c['ends_on']) . (! empty($c['kpi']['label']) ? ' · target: ' . $c['kpi']['label'] : '') . ' · ' . (int) $c['steps_total'] . ' steps'
                . (! empty($c['why_now']) ? "\n   " . $c['why_now'] : ''), $cards, array_keys($cards)))
                . "\n\nReply " . (count($cards) === 1 ? '**launch it**' : '**launch 1**' . (count($cards) > 1 ? ', **launch 2**' : '') . ' …') . ' or **not now**.';
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($this->wsId, 'sarah', $words, ['notification_type' => 'campaign_ideas',
                'card' => ['type' => 'campaign_ideas', 'business_id' => $biz->id ?? null, 'business_name' => $name, 'ideas' => $cards]]);
        } catch (\Throwable $e) {
            Log::warning('[CAMPAIGNS-1] ideas job failed', ['ws' => $this->wsId, 'e' => $e->getMessage()]);
        } finally {
            optional($lock)->release();
        }
    }
}
