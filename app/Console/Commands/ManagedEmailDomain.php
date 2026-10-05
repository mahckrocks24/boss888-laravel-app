<?php

namespace App\Console\Commands;

use App\Core\Managed\ManagedWorkspaces;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Observation\Custody;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * MANAGED-2 (RFC-0029, DEC-0086) — staff put a managed client's domain on Business Email.
 *
 * The client never sees DNS: domain and DNS are ours to run (Owner: "the ones they need only"). This command does
 * the part the portal deliberately leaves out:
 *
 *   1. records the domain for the workspace (custody: managed by us);
 *   2. registers it with the mail service (engine DOMAIN_ONBOARD, operator-approved);
 *   3. reads the DNS records the service needs and shows them;
 *   4. --publish-dns: writes them into our Cloudflare zone for the domain. An existing MX record is NEVER replaced
 *      unless --replace-mx is given — that is the moment a domain's mail moves, and it is a decision, not a step;
 *   5. --verify: asks the service to check the records.
 *
 * Read-only on the provider unless the network and mutation gates are open (BUSINESS_EMAIL_PROVIDER_*_ENABLED).
 */
class ManagedEmailDomain extends Command
{
    protected $signature = 'managed:email-domain
        {workspace : Managed workspace id}
        {domain : The domain, e.g. ptaa.org.ph}
        {--publish-dns : Write the required records into our Cloudflare zone}
        {--replace-mx : Allow replacing existing MX records (moves the domain\'s mail)}
        {--verify : Ask the mail service to verify the records}
        {--actor=1 : Staff user id recorded as the actor}';

    protected $description = 'Put a managed client\'s domain on Business Email (staff only)';

    public function handle(BusinessEmailEngine $engine): int
    {
        $ws = (int) $this->argument('workspace');
        $name = strtolower(trim((string) $this->argument('domain')));
        $actor = (int) $this->option('actor');

        if (! ManagedWorkspaces::isManaged($ws)) {
            $this->error("Workspace {$ws} is not a managed workspace.");

            return self::FAILURE;
        }
        if (! preg_match('/^(?=.{3,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $name)) {
            $this->error('That is not a domain name.');

            return self::FAILURE;
        }
        if ($engine->connector() === null) {
            $this->error('The mail service is not configured (INFRA_EMAIL_CONNECTOR).');

            return self::FAILURE;
        }

        $domain = WorkspaceContext::run($ws, fn () => EmailDomain::query()->where('domain', $name)->first()
            ?? EmailDomain::create([
                'domain'             => $name,
                'custody'            => Custody::MANAGED_BY_US,
                'created_by_user_id' => $actor,
            ]));
        $this->info("Domain #{$domain->id} {$name} ({$domain->lifecycle_state})");

        $ctx = fn (string $cap) => new EmailOperationContext(
            workspaceId: $ws, capability: $cap, domain: $domain->fresh(), actorUserId: $actor,
            source: 'operator', approved: true, approvedBy: $actor,
        );

        if ($engine->providerRef($domain) === null) {
            $r = WorkspaceContext::run($ws, fn () => $engine->execute($ctx(Registry::DOMAIN_ONBOARD)));
            $this->line('Register with the mail service: ' . ($r->success ? 'ok' : 'FAILED ' . $r->errorCode));
            if (! $r->success) {
                return self::FAILURE;
            }
        } else {
            $this->line('Already registered with the mail service.');
        }

        $dns = WorkspaceContext::run($ws, fn () => $engine->dnsRequirements($ctx(Registry::DOMAIN_ONBOARD)));
        if (! $dns->success) {
            $this->error('Could not read the DNS records: ' . $dns->errorCode);

            return self::FAILURE;
        }
        $records = WorkspaceContext::run($ws, fn () => EmailDomain::find($domain->id)->settings_json['dns_requirements'] ?? []);
        $this->table(['purpose', 'type', 'name', 'value', 'prio'], array_map(fn ($r) => [
            $r['purpose'] ?? '', $r['type'] ?? '', $r['name'] ?? '', mb_strimwidth((string) ($r['value'] ?? ''), 0, 60, '…'), $r['priority'] ?? '',
        ], $records));

        if ($this->option('publish-dns')) {
            if (! $this->publish($name, $records, (bool) $this->option('replace-mx'))) {
                return self::FAILURE;
            }
        }

        if ($this->option('verify')) {
            $v = WorkspaceContext::run($ws, fn () => $engine->execute($ctx(Registry::DOMAIN_VERIFY)));
            $this->line('Verify: ' . ($v->success ? 'ok' : 'not yet (' . $v->errorCode . ')'));
        }

        return self::SUCCESS;
    }

    /** Upsert the records in the domain's Cloudflare zone. Returns false on any refusal. */
    private function publish(string $domain, array $records, bool $replaceMx): bool
    {
        $token = (string) env('CLOUDFLARE_API_TOKEN');
        $cf = fn () => Http::withToken($token)->acceptJson()->timeout(20)->baseUrl('https://api.cloudflare.com/client/v4/');

        // The zone is the longest suffix of the domain that Cloudflare knows.
        $zone = null;
        $labels = explode('.', $domain);
        for ($i = 0; $i < count($labels) - 1 && ! $zone; $i++) {
            $try = implode('.', array_slice($labels, $i));
            $zone = $cf()->get('zones', ['name' => $try])->json('result.0.id');
        }
        if (! $zone) {
            $this->error("No Cloudflare zone for {$domain} is reachable with our token.");

            return false;
        }

        foreach ($records as $r) {
            $type = strtoupper((string) ($r['type'] ?? ''));
            $host = $this->fqdn((string) ($r['name'] ?? '@'), $domain);
            $value = (string) ($r['value'] ?? '');
            if (! in_array($type, ['MX', 'TXT', 'CNAME'], true) || $value === '') {
                continue;
            }

            $existing = $cf()->get("zones/{$zone}/dns_records", ['type' => $type, 'name' => $host, 'per_page' => 100])->json('result') ?? [];

            if ($type === 'MX') {
                $same = collect($existing)->first(fn ($e) => rtrim(strtolower($e['content']), '.') === rtrim(strtolower($value), '.'));
                if ($same) {
                    $this->line("= MX {$host} {$value}");
                    continue;
                }
                $foreign = collect($existing)->reject(fn ($e) => str_contains(strtolower($e['content']), $this->mxFamily($records)));
                if ($foreign->isNotEmpty() && ! $replaceMx) {
                    $this->error("{$host} already has mail exchangers (" . $foreign->pluck('content')->implode(', ') . '). Publishing would move its mail. Re-run with --replace-mx when that is decided.');

                    return false;
                }
                foreach ($foreign as $f) {
                    $cf()->delete("zones/{$zone}/dns_records/{$f['id']}");
                    $this->warn("- MX {$host} {$f['content']} (replaced)");
                }
            }

            if ($type === 'TXT' && str_starts_with(strtolower($value), 'v=spf1')) {
                // One SPF record per name: merge into an existing one rather than adding a second.
                $spf = collect($existing)->first(fn ($e) => str_starts_with(strtolower(trim($e['content'], '"')), 'v=spf1'));
                if ($spf) {
                    $merged = $this->mergeSpf(trim($spf['content'], '"'), $value);
                    if ($merged !== trim($spf['content'], '"')) {
                        $cf()->patch("zones/{$zone}/dns_records/{$spf['id']}", ['content' => $merged]);
                        $this->line("~ TXT {$host} {$merged}");
                    } else {
                        $this->line("= TXT {$host} (SPF already includes it)");
                    }
                    continue;
                }
            }

            $same = collect($existing)->first(fn ($e) => trim(strtolower($e['content']), '".') === trim(strtolower($value), '".'));
            if ($same) {
                $this->line("= {$type} {$host}");
                continue;
            }
            if ($type === 'CNAME' && ! empty($existing)) {
                $cf()->patch("zones/{$zone}/dns_records/{$existing[0]['id']}", ['content' => $value, 'proxied' => false]);
                $this->line("~ CNAME {$host} {$value}");
                continue;
            }

            $body = ['type' => $type, 'name' => $host, 'content' => $value, 'ttl' => 1, 'proxied' => false];
            if ($type === 'MX') {
                $body['priority'] = (int) ($r['priority'] ?? 10);
            }
            $res = $cf()->post("zones/{$zone}/dns_records", $body);
            if (! $res->json('success')) {
                $this->error("Cloudflare refused {$type} {$host}: " . json_encode($res->json('errors')));

                return false;
            }
            $this->line("+ {$type} {$host} " . mb_strimwidth($value, 0, 60, '…'));
        }

        return true;
    }

    private function fqdn(string $name, string $domain): string
    {
        $name = rtrim(strtolower(trim($name)), '.');
        if ($name === '' || $name === '@' || $name === $domain) {
            return $domain;
        }

        return str_ends_with($name, '.' . $domain) ? $name : $name . '.' . $domain;
    }

    /** The mail service's MX hosts share a suffix; existing MX outside it belong to someone else. */
    private function mxFamily(array $records): string
    {
        foreach ($records as $r) {
            if (strtoupper((string) ($r['type'] ?? '')) === 'MX') {
                $parts = explode('.', rtrim(strtolower((string) $r['value']), '.'));

                return implode('.', array_slice($parts, -2));
            }
        }

        return '#none#';
    }

    private function mergeSpf(string $current, string $wanted): string
    {
        preg_match_all('/\binclude:\S+/i', $wanted, $m);
        $out = $current;
        foreach ($m[0] as $inc) {
            if (stripos($out, $inc) === false) {
                $out = preg_replace('/\s+([~?\-+]all)\s*$/i', ' ' . $inc . ' $1', $out, 1) ?? $out;
            }
        }

        return $out;
    }
}
