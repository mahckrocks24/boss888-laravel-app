<?php

namespace App\Services\Domains;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Jobs\ConfigureDomainDnsJob;
use App\Jobs\ConnectDomainToWebsiteJob;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use App\Services\CustomDomainService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0015 — a domain bought from us connects itself.
 *
 * Joins the two products that never met: domain commerce (customer_domains, a name we
 * registered for the customer) and custom-domain attachment (custom_domains, the locked
 * Cloudflare-for-SaaS path that serves a hostname with our certificate). After
 * registration this service writes the domain's DNS at our registrar (www → routing
 * target, apex → redirect to www), then attaches www.<domain> to the chosen website and
 * polls until it is live. The customer does nothing.
 *
 * Every step records its state on the ownership row (dns_state / connect_state) so the
 * journey shown to the customer is derived from what actually happened, never assumed.
 *
 * Apex policy: www is canonical and the apex redirects to it. Apex proxying on the
 * locked path needs an Enterprise plan; a redirect record at the registrar does not.
 */
class DomainLinkService
{
    public const DNS_PENDING  = 'pending';
    public const DNS_SET      = 'set';
    public const DNS_EXTERNAL = 'external';   // custom nameservers: we do not manage this zone
    public const DNS_FAILED   = 'failed';

    public const CONNECT_PENDING    = 'pending';     // website chosen, nothing attempted yet
    public const CONNECT_WAITING    = 'waiting';     // attachment path gated; retried when the gate opens
    public const CONNECT_CONNECTING = 'connecting';  // hostname created; certificate/routing in progress
    public const CONNECT_ACTIVE     = 'active';
    public const CONNECT_STALLED    = 'stalled';     // not live within the cap; operator attention
    public const CONNECT_FAILED     = 'failed';

    /** Web-pointing record types we own at www and the apex. Mail/TXT/NS/etc. are never touched. */
    private const WEB_TYPES = ['A', 'AAAA', 'CNAME', 'ALIAS', 'URL', 'URL301', 'FRAME'];

    public function __construct(
        private ?NamecheapRegistrarConnector $registrar = null,
        private ?CustomDomainService $custom = null,
        private ?DomainAuditLogger $audit = null,
    ) {
        $this->audit = $audit ?: new DomainAuditLogger();
    }

    public static function make(): self
    {
        return new self(NamecheapRegistrarConnector::make(), new CustomDomainService(), new DomainAuditLogger());
    }

    private function registrar(): NamecheapRegistrarConnector
    {
        return $this->registrar ?: ($this->registrar = NamecheapRegistrarConnector::make());
    }

    private function custom(): CustomDomainService
    {
        return $this->custom ?: ($this->custom = new CustomDomainService());
    }

    // ------------------------------------------------------------ helpers --

    /** Tenancy: a website may only be named by the workspace that owns it. */
    public static function websiteInWorkspace(int $websiteId, int $workspaceId): bool
    {
        return DB::table('websites')->where('id', $websiteId)->where('workspace_id', $workspaceId)->exists();
    }

    public static function websiteName(?int $websiteId): ?string
    {
        if (! $websiteId) {
            return null;
        }

        return DB::table('websites')->where('id', $websiteId)->value('name');
    }

    /** The hostname we attach: www is canonical, the apex redirects to it. */
    public static function hostnameFor(string $domain): string
    {
        return 'www.' . strtolower(trim($domain));
    }

    public static function autoDnsEnabled(): bool
    {
        return config('domains.auto_dns.enabled') === true;
    }

    private static function stallMinutes(): int
    {
        return max(5, (int) config('domains.link.stall_minutes', 60));
    }

    // ---------------------------------------------------------- attach/detach --

    /**
     * Point a bought domain at one of the workspace's websites. Idempotent for the same
     * website; a different website is a detach-then-attach.
     */
    public function attach(CustomerDomain $d, int $websiteId): array
    {
        if (! self::websiteInWorkspace($websiteId, (int) $d->workspace_id)) {
            return ['error' => 'Website not found.', 'code' => 'WEBSITE_NOT_FOUND'];
        }

        if ($d->status !== CustomerDomain::STATUS_ACTIVE) {
            return ['error' => 'This domain is not active, so it cannot be connected yet.', 'code' => 'DOMAIN_NOT_ACTIVE'];
        }

        $other = CustomerDomain::where('website_id', $websiteId)->where('id', '!=', $d->id)->first();
        if ($other !== null) {
            return [
                'error' => 'Another domain from us (' . $other->domain . ') is already set up for this website. Detach it first.',
                'code'  => 'WEBSITE_TAKEN',
            ];
        }

        if ((int) $d->website_id === $websiteId && in_array($d->connect_state, [self::CONNECT_ACTIVE, self::CONNECT_CONNECTING, self::CONNECT_WAITING], true)) {
            return ['ok' => true, 'idempotent' => true];   // already on its way, or there
        }

        if ($d->website_id && (int) $d->website_id !== $websiteId) {
            $this->releaseWebsite($d);
        }

        $d->update([
            'website_id'         => $websiteId,
            'connect_state'      => self::CONNECT_PENDING,
            'link_error'         => null,
            'connect_started_at' => null,
        ]);

        $this->audit->websiteAttachRequested($d, $websiteId);
        $this->advance($d->fresh());

        return ['ok' => true];
    }

    public function detach(CustomerDomain $d): array
    {
        if (! $d->website_id) {
            return ['ok' => true, 'idempotent' => true];
        }

        $this->releaseWebsite($d);

        $d->update([
            'website_id'         => null,
            'connect_state'      => null,
            'link_error'         => null,
            'connect_started_at' => null,
        ]);

        $this->audit->websiteDetached($d);

        return ['ok' => true];
    }

    /** Take our hostname off the website, if it is the one attached. Never touches a domain we did not attach. */
    private function releaseWebsite(CustomerDomain $d): void
    {
        $current = (string) DB::table('websites')->where('id', (int) $d->website_id)->value('custom_domain');

        if ($current !== '' && $current === self::hostnameFor($d->domain)) {
            try {
                $this->custom()->disconnect((int) $d->website_id);
            } catch (\Throwable $e) {
                Log::warning('[DomainLink] disconnect on detach failed', ['domain' => $d->domain, 'error' => $e->getMessage()]);
            }
        }
    }

    // --------------------------------------------------------------- advance --

    /**
     * Queue whatever the next automatic step is. Called after registration, after attach,
     * and by the sweep. Safe to call repeatedly: each job re-reads state before acting.
     */
    public function advance(CustomerDomain $d): void
    {
        if ($d->status !== CustomerDomain::STATUS_ACTIVE) {
            return;   // registration is not done; nothing to set up yet
        }

        if ($d->dns_state === null || $d->dns_state === self::DNS_PENDING) {
            if (self::autoDnsEnabled()) {
                ConfigureDomainDnsJob::dispatch($d->domain)->onQueue('tasks');
            }

            return;
        }

        if ($d->website_id && in_array($d->connect_state, [self::CONNECT_PENDING, self::CONNECT_WAITING], true)) {
            ConnectDomainToWebsiteJob::dispatch($d->domain)->onQueue('tasks');
        }
    }

    // ------------------------------------------------------------------ dns --

    /**
     * Write the domain's web records at our registrar. MERGES with what is there: the
     * registrar's set-hosts call replaces the whole zone, so mail and verification records
     * are read first and written back untouched. Returns ['ok'=>bool,'retry'=>bool,'reason'=>?].
     */
    public function configureDns(CustomerDomain $d): array
    {
        $read = $this->registrar()->getDnsHosts($d->domain);

        if (! $read->success) {
            $retry = ($read->retryClassification ?? 'permanent') === 'transient';
            $this->markDns($d, $retry ? self::DNS_PENDING : self::DNS_FAILED, (string) $read->errorSummary);

            return ['ok' => false, 'retry' => $retry, 'reason' => (string) $read->errorSummary];
        }

        if (($read->data['uses_our_dns'] ?? false) !== true) {
            // Custom nameservers: this zone is managed elsewhere. The customer keeps the
            // manual path (add the records at their DNS provider). Not an error.
            $this->markDns($d, self::DNS_EXTERNAL, null);
            $this->audit->dnsSkipped($d, 'custom nameservers');

            return ['ok' => false, 'retry' => false, 'reason' => 'external_dns'];
        }

        $current = $read->data['hosts'] ?? [];

        if (self::dnsLooksSet($current, $d->domain)) {
            $this->markDns($d, self::DNS_SET, null);
            $this->advance($d->fresh());

            return ['ok' => true, 'retry' => false, 'reason' => 'already_set'];
        }

        $merged = self::mergeRecords($current, $d->domain);
        $key = 'lvl-dns-' . $d->id . '-' . substr(md5(json_encode(self::wantedRecords($d->domain))), 0, 12);

        $write = $this->registrar()->setDnsHosts($d->domain, $merged, $key);

        if (! $write->success) {
            $retry = ($write->retryClassification ?? 'permanent') === 'transient';
            $this->markDns($d, $retry ? self::DNS_PENDING : self::DNS_FAILED, (string) $write->errorSummary);

            return ['ok' => false, 'retry' => $retry, 'reason' => (string) $write->errorSummary];
        }

        $this->markDns($d, self::DNS_SET, null);
        $this->audit->dnsConfigured($d, self::wantedRecords($d->domain), count($current));
        $this->advance($d->fresh());

        return ['ok' => true, 'retry' => false, 'reason' => null];
    }

    /** The two records we own. */
    public static function wantedRecords(string $domain): array
    {
        $domain = strtolower(trim($domain));

        return [
            ['name' => 'www', 'type' => 'CNAME',  'address' => rtrim((string) config('cloudflare.routing_target'), '.') . '.', 'ttl' => 1800],
            ['name' => '@',   'type' => 'URL301', 'address' => 'https://www.' . $domain,                                       'ttl' => 1800],
        ];
    }

    /**
     * Keep everything that is not a web-pointing record at www or the apex (mail, TXT, NS,
     * CAA, SRV, other hosts), drop the registrar's parking records at those two names, and
     * append ours. Pure; unit-tested.
     */
    public static function mergeRecords(array $current, string $domain): array
    {
        $kept = [];

        foreach ($current as $h) {
            $name = strtolower((string) ($h['name'] ?? ''));
            $type = strtoupper((string) ($h['type'] ?? ''));

            if (in_array($name, ['www', '@'], true) && in_array($type, self::WEB_TYPES, true)) {
                continue;   // ours to replace
            }

            $row = [
                'name'    => (string) ($h['name'] ?? '@'),
                'type'    => $type ?: 'A',
                'address' => (string) ($h['address'] ?? ''),
                'ttl'     => (int) ($h['ttl'] ?? 1800) ?: 1800,
            ];
            if ($type === 'MX') {
                $row['mx_pref'] = (int) ($h['mx_pref'] ?? 10) ?: 10;
            }
            $kept[] = $row;
        }

        return array_merge($kept, self::wantedRecords($domain));
    }

    /** True when both of our records are already present as written. */
    public static function dnsLooksSet(array $current, string $domain): bool
    {
        $wanted = self::wantedRecords($domain);
        $norm = fn (string $s): string => rtrim(strtolower(trim($s)), '.');

        foreach ($wanted as $w) {
            $found = false;
            foreach ($current as $h) {
                if (strtolower((string) ($h['name'] ?? '')) === $w['name']
                    && strtoupper((string) ($h['type'] ?? '')) === $w['type']
                    && $norm((string) ($h['address'] ?? '')) === $norm($w['address'])) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function markDns(CustomerDomain $d, string $state, ?string $error): void
    {
        $d->update([
            'dns_state'  => $state,
            'link_error' => $error !== null && $error !== '' ? mb_substr($error, 0, 500) : ($state === self::DNS_SET ? null : $d->link_error),
        ]);
    }

    // -------------------------------------------------------------- connect --

    /**
     * One attempt to attach www.<domain> to the linked website through the locked
     * custom-hostname path. Returns ['done'=>bool,'state'=>string].
     */
    public function connect(CustomerDomain $d): array
    {
        if (! $d->website_id) {
            return ['done' => true, 'state' => (string) $d->connect_state];
        }

        if ($d->dns_state !== self::DNS_SET) {
            // DNS first — without our records the hostname can never validate.
            return ['done' => true, 'state' => (string) $d->connect_state];
        }

        $websiteId = (int) $d->website_id;
        $hostname  = self::hostnameFor($d->domain);

        if (! $d->connect_started_at) {
            $d->update(['connect_started_at' => now()]);
        }

        // Something else already attached to this website (a domain the customer owns
        // elsewhere): refuse rather than replace it.
        $current = (string) DB::table('websites')->where('id', $websiteId)->value('custom_domain');
        if ($current !== '' && $current !== $hostname) {
            $this->markConnect($d, self::CONNECT_FAILED, 'Another domain (' . $current . ') is connected to this website.');

            return ['done' => true, 'state' => self::CONNECT_FAILED];
        }

        $res = $this->custom()->connect($websiteId, $hostname);

        if (($res['gated'] ?? false) === true) {
            $this->markConnect($d, self::CONNECT_WAITING, null);

            return ['done' => false, 'state' => self::CONNECT_WAITING];
        }

        if (($res['success'] ?? false) !== true) {
            $this->markConnect($d, self::CONNECT_FAILED, (string) ($res['error'] ?? 'Connection failed.'));

            return ['done' => true, 'state' => self::CONNECT_FAILED];
        }

        if (($res['active'] ?? false) === true) {
            $this->markLive($d);

            return ['done' => true, 'state' => self::CONNECT_ACTIVE];
        }

        $this->markConnect($d, self::CONNECT_CONNECTING, null);

        return ['done' => false, 'state' => self::CONNECT_CONNECTING];
    }

    /** Re-check a connecting hostname; stall it past the cap. */
    public function poll(CustomerDomain $d): array
    {
        if (! $d->website_id || $d->connect_state !== self::CONNECT_CONNECTING) {
            return ['done' => true, 'state' => (string) $d->connect_state];
        }

        $res = $this->custom()->verify((int) $d->website_id);

        if (($res['active'] ?? false) === true) {
            $this->markLive($d);

            return ['done' => true, 'state' => self::CONNECT_ACTIVE];
        }

        $started = $d->connect_started_at;
        if ($started && $started->lt(now()->subMinutes(self::stallMinutes()))) {
            $this->markConnect($d, self::CONNECT_STALLED, (string) ($res['last_error'] ?? 'Not live within ' . self::stallMinutes() . ' minutes.'));
            $this->audit->websiteConnectStalled($d);

            return ['done' => true, 'state' => self::CONNECT_STALLED];
        }

        return ['done' => false, 'state' => self::CONNECT_CONNECTING];
    }

    private function markLive(CustomerDomain $d): void
    {
        $was = $d->connect_state;
        $this->markConnect($d, self::CONNECT_ACTIVE, null);

        if ($was !== self::CONNECT_ACTIVE) {
            $this->audit->websiteConnected($d);
        }
    }

    private function markConnect(CustomerDomain $d, string $state, ?string $error): void
    {
        $d->update([
            'connect_state' => $state,
            'link_error'    => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }

    // -------------------------------------------------------------- journey --

    /**
     * The five-step status line, derived from the rows. States: done | current | pending |
     * failed | skipped. The summary is the one sentence a customer needs.
     */
    public function journey(CustomerDomain $d): array
    {
        $item  = $d->domain_order_item_id ? DomainOrderItem::find($d->domain_order_item_id) : null;
        $order = $item?->order;

        $paid = $order ? $order->isPaid() : ($d->status === CustomerDomain::STATUS_ACTIVE);
        $registered = $d->status === CustomerDomain::STATUS_ACTIVE
            || ($item && $item->status === DomainOrderItem::STATUS_REGISTERED);
        $regFailed = $item && in_array($item->status, [DomainOrderItem::STATUS_FAILED, DomainOrderItem::STATUS_REFUND_DUE], true);

        $steps = [];
        $steps[] = ['key' => 'paid', 'label' => 'Paid', 'state' => $paid ? 'done' : 'current',
            'detail' => $paid ? 'Payment received.' : 'Waiting for payment.'];

        $steps[] = ['key' => 'registering', 'label' => 'Registering',
            'state'  => $registered ? 'done' : ($regFailed ? 'failed' : ($paid ? 'current' : 'pending')),
            'detail' => $registered ? 'The domain is registered to you.' : ($regFailed ? 'Registration did not complete. We are on it and nothing further is charged.' : 'Securing the name at the registry.')];

        $dnsState = (string) $d->dns_state;
        $steps[] = ['key' => 'dns', 'label' => 'Setting up DNS',
            'state'  => match ($dnsState) {
                self::DNS_SET      => 'done',
                self::DNS_EXTERNAL => 'skipped',
                self::DNS_FAILED   => 'failed',
                default            => $registered ? 'current' : 'pending',
            },
            'detail' => match ($dnsState) {
                self::DNS_SET      => 'Your domain points at your website.',
                self::DNS_EXTERNAL => 'This domain uses its own name servers, so add the records at your DNS provider.',
                self::DNS_FAILED   => 'We could not write the DNS records automatically. We are on it.',
                default            => 'Pointing the domain at your website. Nothing to do on your side.',
            }];

        $cs = (string) $d->connect_state;
        $hasSite = (bool) $d->website_id;
        $steps[] = ['key' => 'securing', 'label' => 'Securing',
            'state'  => ! $hasSite ? 'pending' : match ($cs) {
                self::CONNECT_ACTIVE     => 'done',
                self::CONNECT_CONNECTING, self::CONNECT_WAITING => 'current',
                self::CONNECT_STALLED, self::CONNECT_FAILED     => 'failed',
                default                  => $dnsState === self::DNS_SET ? 'current' : 'pending',
            },
            'detail' => ! $hasSite ? 'Choose a website to connect this domain to.' : match ($cs) {
                self::CONNECT_ACTIVE     => 'Certificate issued and routing live.',
                self::CONNECT_WAITING    => 'We are finishing this step on our side. Nothing to do on yours.',
                self::CONNECT_STALLED    => 'This is taking longer than it should. Our team has been alerted.',
                self::CONNECT_FAILED     => (string) ($d->link_error ?: 'We could not connect the domain. Our team has been alerted.'),
                default                  => 'Issuing your certificate and switching routing on — usually under 10 minutes.',
            }];

        $live = $hasSite && $cs === self::CONNECT_ACTIVE;
        $steps[] = ['key' => 'live', 'label' => 'Live', 'state' => $live ? 'done' : 'pending',
            'detail' => $live ? 'Visitors can use your domain.' : 'Your website will answer at your domain.'];

        $current = null;
        foreach ($steps as $s) {
            if (in_array($s['state'], ['current', 'failed'], true)) { $current = $s; break; }
        }

        return [
            'steps'    => $steps,
            'complete' => $live,
            'summary'  => $live ? 'Live at https://' . self::hostnameFor($d->domain)
                : ($current ? $current['label'] . ' — ' . $current['detail'] : ($hasSite ? 'Waiting.' : 'Not connected to a website.')),
            'live_url' => $live ? 'https://' . self::hostnameFor($d->domain) : null,
        ];
    }
}
