<?php

namespace App\Console\Commands;

use App\Jobs\ConfigureDomainDnsJob;
use App\Jobs\ConnectDomainToWebsiteJob;
use App\Models\CustomerDomain;
use App\Services\Domains\DomainLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0015: move every bought-and-linked domain one step further. Runs every five
 * minutes. DNS still pending → queue the DNS job; website chosen but attachment
 * pending or waiting on the gate → queue the connect job (only when the gate is open);
 * hostname connecting → poll it. Idempotent; the jobs re-read state before acting.
 */
class DomainLinkSweepCommand extends Command
{
    protected $signature = 'domains:link-sweep {--limit=100} {--dry-run}';
    protected $description = 'Advance bought domains through DNS set-up and website attachment (RFC-0015)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');
        $gateOpen = (bool) config('cloudflare.saas_enabled', false);
        $svc = DomainLinkService::make();

        $dns = 0; $connect = 0; $polled = 0; $stalled = 0;

        // 1. DNS not yet written.
        if (DomainLinkService::autoDnsEnabled()) {
            $rows = CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('dns_state')->orWhere('dns_state', DomainLinkService::DNS_PENDING))
                ->where('updated_at', '<', now()->subMinutes(2))   // let a just-dispatched job finish first
                ->limit($limit)->get();
            foreach ($rows as $d) {
                $dns++;
                if (! $dry) { ConfigureDomainDnsJob::dispatch($d->domain)->onQueue('tasks'); }
            }
        }

        // 2. Website chosen; attachment not started or waiting on the gate.
        if ($gateOpen) {
            $rows = CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)
                ->whereNotNull('website_id')
                ->where('dns_state', DomainLinkService::DNS_SET)
                ->whereIn('connect_state', [DomainLinkService::CONNECT_PENDING, DomainLinkService::CONNECT_WAITING])
                ->limit($limit)->get();
            foreach ($rows as $d) {
                $connect++;
                if (! $dry) { ConnectDomainToWebsiteJob::dispatch($d->domain)->onQueue('tasks'); }
            }
        }

        // 3. Hostname created; certificate/routing in progress.
        $rows = CustomerDomain::where('status', CustomerDomain::STATUS_ACTIVE)
            ->whereNotNull('website_id')
            ->where('connect_state', DomainLinkService::CONNECT_CONNECTING)
            ->limit($limit)->get();
        foreach ($rows as $d) {
            $polled++;
            if ($dry) { continue; }
            try {
                $r = $svc->poll($d);
                if ($r['state'] === DomainLinkService::CONNECT_STALLED) { $stalled++; }
            } catch (\Throwable $e) {
                Log::warning('domains:link-sweep poll failed', ['domain' => $d->domain, 'error' => $e->getMessage()]);
            }
        }

        $this->info(sprintf('%sdns queued=%d connect queued=%d polled=%d stalled=%d (gate %s)',
            $dry ? '[dry-run] ' : '', $dns, $connect, $polled, $stalled, $gateOpen ? 'open' : 'closed'));

        return self::SUCCESS;
    }
}
