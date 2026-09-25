<?php

namespace App\Jobs;

use App\Models\CustomerDomain;
use App\Services\Domains\DomainLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * DNS-AUTO-1 (RFC-0015): after registration, write the domain's web records at our
 * registrar so the customer never configures DNS. Merge-write, never replace — see
 * DomainLinkService::configureDns(). Spends nothing; safe to replay.
 */
class ConfigureDomainDnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800];
    public int $timeout = 120;

    public function __construct(public readonly string $domain)
    {
    }

    public function uniqueId(): string
    {
        return 'domain-dns-' . $this->domain;
    }

    public function handle(): void
    {
        if (! DomainLinkService::autoDnsEnabled()) {
            return;
        }

        $d = CustomerDomain::where('domain', $this->domain)->first();

        if ($d === null || $d->status !== CustomerDomain::STATUS_ACTIVE) {
            return;   // not ours, or not registered: nothing to point anywhere
        }

        if ($d->dns_state === DomainLinkService::DNS_SET || $d->dns_state === DomainLinkService::DNS_EXTERNAL) {
            DomainLinkService::make()->advance($d);   // already done; make sure the next step is queued

            return;
        }

        $r = DomainLinkService::make()->configureDns($d);

        if (! $r['ok'] && $r['retry'] && $this->attempts() < $this->tries) {
            Log::info('ConfigureDomainDnsJob: transient, will retry', ['domain' => $this->domain, 'reason' => $r['reason']]);
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ConfigureDomainDnsJob exhausted', ['domain' => $this->domain, 'error' => $e->getMessage()]);

        CustomerDomain::where('domain', $this->domain)
            ->where('dns_state', DomainLinkService::DNS_PENDING)
            ->update(['dns_state' => DomainLinkService::DNS_FAILED, 'link_error' => 'DNS job exhausted: ' . mb_substr($e->getMessage(), 0, 400)]);
    }
}
