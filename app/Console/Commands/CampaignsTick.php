<?php

namespace App\Console\Commands;

use App\Core\Campaigns\CampaignService;
use App\Jobs\CampaignIdeasJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CAMPAIGNS-1: `campaigns:tick` releases dated campaign work, follows it and closes finished campaigns (every 10 min).
 * `campaigns:tick --ideas` runs the monthly idea cycle: each business in a workspace with Sarah's proactive mode on,
 * that has had no fresh ideas for 28 days, gets new ones in the owner's chat. Old routine "daily action" asks that were
 * never answered are retired quietly (Owner 2026-09-27: maintenance runs quietly; ideas are campaigns).
 */
class CampaignsTick extends Command
{
    protected $signature = 'campaigns:tick {--ideas : run the monthly idea cycle instead} {--workspace= : limit the idea cycle to one workspace}';
    protected $description = 'Campaigns: release dated work, follow it, finish campaigns; or run the monthly idea cycle';

    public function handle(CampaignService $campaigns): int
    {
        if (! $this->option('ideas')) {
            $r = $campaigns->tick();
            $this->info(json_encode($r));
            return self::SUCCESS;
        }
        // retire unanswered routine asks older than 7 days — Sarah handles maintenance herself now
        $retired = DB::table('strategy_proposals')->where('status', 'pending_approval')->where('type', 'like', 'daily_action_%')->where('created_at', '<', now()->subDays(7))
            ->update(['status' => 'superseded', 'superseded_reason' => 'retired: routine work runs quietly; ideas are campaigns (CAMPAIGNS-1)', 'updated_at' => now()]);
        $q = DB::table('workspaces')->where('proactive_enabled', 1);
        if ($this->option('workspace')) $q->where('id', (int) $this->option('workspace'));
        $queued = 0;
        foreach ($q->pluck('id') as $wsId) {
            $bizList = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->limit(5)->pluck('id')->all() ?: [null];
            foreach ($bizList as $bizId) {
                $recent = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where(fn ($w) => $bizId ? $w->where('business_id', $bizId) : $w->whereNull('business_id'))
                    ->where('created_at', '>=', now()->subDays(28))->exists();
                if ($recent) continue;
                CampaignIdeasJob::dispatch((int) $wsId, $bizId ? (int) $bizId : null, 'sarah_monthly')->delay(now()->addSeconds(20 * $queued));
                $queued++;
                break;   // one business per workspace per day: the owner gets one set of ideas at a time, the next business tomorrow
            }
        }
        $this->info("retired {$retired} routine asks; queued {$queued} idea runs");
        return self::SUCCESS;
    }
}
