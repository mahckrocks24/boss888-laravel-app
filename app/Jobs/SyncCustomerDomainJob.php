<?php

namespace App\Jobs;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Models\CustomerDomain;
use App\Services\Domains\DomainAuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes one owned domain from the registrar: expiry, lock, auto-renew,
 * WHOIS privacy and nameservers.
 *
 * Read-only against the provider. It can never register, renew or transfer,
 * so it is safe to run on a schedule and safe to retry freely.
 */
class SyncCustomerDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];
    public int $timeout = 120;

    public function __construct(
        public readonly string $domain,
        public readonly ?int $actorUserId = null,
    ) {
    }

    public function handle(): void
    {
        $record = CustomerDomain::where('domain', $this->domain)->first();

        if ($record === null) {
            Log::info('SyncCustomerDomainJob: no owned domain record', ['domain' => $this->domain]);

            return;
        }

        $result = NamecheapRegistrarConnector::make()->getDomainStatus($this->domain);

        if (! $result->success) {
            // A sync failure is not a domain failure. Record the attempt and
            // leave the last known good state in place rather than blanking it.
            $record->update(['last_synced_at' => now()]);
            Log::warning('SyncCustomerDomainJob: provider read failed', [
                'domain' => $this->domain,
                'code'   => $result->errorCode,
            ]);

            return;
        }

        // The registrar says this domain is not in our account. That is a real
        // ownership change (transferred away, or released) and must be shown.
        if (($result->data['owned'] ?? true) === false) {
            $record->update([
                'status'         => CustomerDomain::STATUS_RELEASED,
                'last_synced_at' => now(),
            ]);
            (new DomainAuditLogger())->domainSynced(
                (int) $record->workspace_id,
                $this->domain,
                ['status' => 'released'],
                $this->actorUserId
            );

            return;
        }

        $expires = $this->parseDate($result->data['expires_at'] ?? null);

        $changes = array_filter([
            'expires_at'    => $expires?->toDateString() !== $record->expires_at?->toDateString() ? $expires : null,
            'auto_renew'    => (bool) ($result->data['auto_renew'] ?? false) !== (bool) $record->auto_renew ? true : null,
            'is_locked'     => (bool) ($result->data['is_locked'] ?? false) !== (bool) $record->is_locked ? true : null,
            'nameservers'   => ($result->data['nameservers'] ?? []) !== ($record->nameservers_json ?? []) ? true : null,
        ], fn ($v) => $v !== null);

        $record->update([
            'status'           => ($result->data['status'] ?? '') === 'Expired'
                ? CustomerDomain::STATUS_EXPIRED
                : CustomerDomain::STATUS_ACTIVE,
            'expires_at'       => $expires ?? $record->expires_at,
            'auto_renew'       => (bool) ($result->data['auto_renew'] ?? false),
            'is_locked'        => (bool) ($result->data['is_locked'] ?? false),
            'whois_privacy'    => strtolower((string) ($result->data['whois_guard'] ?? '')) === 'true',
            'nameservers_json' => $result->data['nameservers'] ?? [],
            'provider_metadata_json' => [
                'provider_status' => $result->data['status'] ?? null,
                'dns_provider'    => $result->data['dns_provider'] ?? null,
                'uses_our_dns'    => $result->data['uses_our_dns'] ?? null,
            ],
            'last_synced_at'   => now(),
        ]);

        if ($changes !== []) {
            (new DomainAuditLogger())->domainSynced(
                (int) $record->workspace_id,
                $this->domain,
                $changes,
                $this->actorUserId
            );
        }
    }

    /** Namecheap returns MM/DD/YYYY. Anything unparseable becomes null, not today. */
    private function parseDate(?string $raw): ?\Illuminate\Support\Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        foreach (['m/d/Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                return \Illuminate\Support\Carbon::createFromFormat($format, trim($raw))->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
