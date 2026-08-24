<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INFRA888 · PHASE S2 STAGE C — CUSTOM DOMAIN ADOPTION
 *
 * Two live customer domains have been serving production traffic while the
 * `custom_domains` table held zero rows. INFRA888 could not answer "what
 * hostnames do we serve, for whom, and how is TLS terminated?" — so this
 * adopts the existing reality instead of pretending we provisioned it.
 *
 * ADOPTION IS NOT PROVISIONING.
 *   - no provider call (this class deliberately imports no connector)
 *   - no DNS mutation
 *   - no certificate mutation
 *   - no nginx change
 *   - no write to `websites`
 *
 * OBSERVED STATE IS MEASURED, NOT ASSUMED. Every run resolves DNS and performs
 * a TLS handshake to record what is actually true right now, stamped with
 * last_checked_at. Anything not observed is recorded as 'unknown' — never as
 * healthy. Unknown is a legitimate health state; a convenient guess is not.
 *
 * PROVIDER IS RECORDED HONESTLY. The schema defaults `provider` to
 * 'cloudflare_saas'. These hostnames are NOT on Cloudflare for SaaS — they are
 * registrar-direct DNS to our origin with certbot-issued certificates. The
 * column is therefore set explicitly. Accepting the default would have written
 * a provider binding that does not exist.
 *
 * FAILS CLOSED on any identity conflict: a hostname claimed by another
 * workspace, or by more than one live website, aborts the whole run.
 */
class AdoptCustomDomainsCommand extends Command
{
    protected $signature = 'infra:adopt-custom-domains
                            {--dry-run : observe and report the plan; change nothing}
                            {--confirm= : token printed by --dry-run}
                            {--workspace= : limit to one workspace}';

    protected $description = 'INFRA888 S2 — adopt existing live custom domains into the estate (observation only; no provider, DNS or SSL mutation)';

    /** What these hostnames actually run on today. Not Cloudflare. */
    private const PROVIDER_OBSERVED = 'origin_nginx_certbot';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $wsFilter = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $websites = DB::table('websites')
            ->whereNull('deleted_at')
            ->whereNotNull('custom_domain')
            ->where('custom_domain', '<>', '')
            ->when($wsFilter !== null, fn ($q) => $q->where('workspace_id', $wsFilter))
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'name', 'custom_domain', 'subdomain', 'domain_verified', 'status']);

        if ($websites->isEmpty()) {
            $this->warn('No websites with a custom domain found. Nothing to adopt.');

            return self::SUCCESS;
        }

        // ── STAGE H (pre-flight): identity conflicts abort before any write ──
        $conflicts = [];
        $byHostname = [];

        foreach ($websites as $w) {
            $host = $this->canonical($w->custom_domain);
            $byHostname[$host][] = $w;
        }

        foreach ($byHostname as $host => $claimants) {
            if (count($claimants) > 1) {
                $ids = implode(', ', array_map(fn ($c) => "website {$c->id} (ws {$c->workspace_id})", $claimants));
                $conflicts[] = "hostname {$host} is claimed by more than one live website: {$ids}";
            }

            $existing = DB::table('custom_domains')->where('hostname', $host)->first();

            if ($existing !== null) {
                $claim = $claimants[0];

                if ((int) $existing->workspace_id !== (int) $claim->workspace_id) {
                    $conflicts[] = "hostname {$host} is already recorded under workspace {$existing->workspace_id} "
                        . "but website {$claim->id} belongs to workspace {$claim->workspace_id}";
                }

                if ((int) $existing->website_id !== (int) $claim->id) {
                    $conflicts[] = "hostname {$host} is already bound to website {$existing->website_id}, "
                        . "not website {$claim->id}";
                }
            }
        }

        if ($conflicts !== []) {
            $this->error('REFUSED — hostname identity conflicts must be resolved before adoption:');
            foreach ($conflicts as $c) {
                $this->line('  · ' . $c);
            }

            return self::FAILURE;
        }

        // ── STAGE A (per-run): observe live state ───────────────────────────
        $plan = [];

        foreach ($websites as $w) {
            $host = $this->canonical($w->custom_domain);

            $plan[] = [
                'website_id' => (int) $w->id,
                'workspace_id' => (int) $w->workspace_id,
                'name' => (string) $w->name,
                'hostname' => $host,
                'is_apex' => substr_count($host, '.') <= 1,
                'builder_verified' => (bool) $w->domain_verified,
                'already_adopted' => DB::table('custom_domains')->where('hostname', $host)->exists(),
                'observed' => $this->observe($host),
            ];
        }

        // ── report ──────────────────────────────────────────────────────────
        $this->line('');
        $this->line('INFRA888 S2 — CUSTOM DOMAIN ADOPTION' . ($dryRun ? '  [DRY RUN]' : ''));
        $this->line('');

        $toAdopt = 0;

        foreach ($plan as $p) {
            $o = $p['observed'];
            $action = $p['already_adopted'] ? 'skip — already adopted' : 'ADOPT (observed only)';

            if (! $p['already_adopted']) {
                $toAdopt++;
            }

            $this->line(sprintf('  %s   (ws %d · website %d)', $p['hostname'], $p['workspace_id'], $p['website_id']));
            $this->line(sprintf('    resolves to      : %s', $o['a_records'] === [] ? '(none)' : implode(', ', $o['a_records'])));
            $this->line(sprintf('    nameservers      : %s', $o['nameservers'] === [] ? '(none)' : implode(', ', $o['nameservers'])));
            $this->line(sprintf('    MX (email risk)  : %s', $o['mx_records'] === [] ? 'none — no email depends on this zone' : implode(', ', $o['mx_records'])));
            $this->line(sprintf('    routing model    : %s', $o['routing_model']));
            $this->line(sprintf('    edge proxied     : %s', $o['proxied'] === null ? 'unknown' : ($o['proxied'] ? 'yes' : 'no')));
            $this->line(sprintf('    TLS              : %s', $o['ssl_status']));
            $this->line(sprintf('    certificate      : %s', $o['cert_issuer'] ?? 'unknown'));
            $this->line(sprintf('    expires          : %s', $o['cert_expires_at'] ?? 'unknown'));
            $this->line(sprintf('    SANs             : %s', $o['cert_sans'] === [] ? 'unknown' : implode(', ', $o['cert_sans'])));
            $this->line(sprintf('    action           : %s', $action));
            $this->line('');
        }

        $this->line(sprintf('  websites with a custom domain : %d', count($plan)));
        $this->line(sprintf('  already adopted               : %d', count($plan) - $toAdopt));
        $this->line(sprintf('  to adopt                      : %d', $toAdopt));
        $this->line('');
        $this->line('  no provider call · no DNS change · no SSL change · no website change');
        $this->line('  provider recorded as "' . self::PROVIDER_OBSERVED . '" — these hostnames are NOT on Cloudflare');
        $this->line('');

        if ($toAdopt === 0) {
            $this->info('All custom domains already adopted. Nothing to do.');

            return self::SUCCESS;
        }

        $token = substr(hash('sha256', 'adopt-custom-domains|' . implode(',', array_column($plan, 'hostname'))), 0, 24);

        if ($dryRun) {
            $this->info("DRY RUN — nothing written. Confirmation token: {$token}");

            return self::SUCCESS;
        }

        if (! hash_equals($token, (string) ($this->option('confirm') ?? ''))) {
            $this->error('REFUSED: confirmation token does not match this plan. Re-run with --dry-run.');

            return self::FAILURE;
        }

        // ── STAGE C: adopt ──────────────────────────────────────────────────
        $created = ['domains' => 0, 'assets' => 0];

        foreach ($plan as $p) {
            if ($p['already_adopted']) {
                continue;
            }

            DB::transaction(function () use ($p, &$created) {
                $o = $p['observed'];
                $now = now();

                // The hosted-site asset S1 created for this website, so the domain
                // is linked to the estate rather than floating beside it.
                $hostedSite = DB::table('infra_hosted_sites')
                    ->where('website_id', $p['website_id'])
                    ->whereNull('deleted_at')
                    ->first();

                $domainId = DB::table('custom_domains')->insertGetId([
                    'website_id' => $p['website_id'],
                    'workspace_id' => $p['workspace_id'],
                    'domain' => $p['hostname'],
                    'hostname' => $p['hostname'],
                    'is_apex' => $p['is_apex'],

                    // Explicit: the column default is 'cloudflare_saas' and that
                    // would be a provider binding we do not have.
                    'provider' => self::PROVIDER_OBSERVED,
                    'provider_hostname_id' => null,

                    'state' => 'connected',
                    'ownership_status' => $p['builder_verified'] ? 'verified' : 'unverified',
                    'ssl_status' => $o['ssl_status'],
                    'routing_status' => $o['routing_model'],
                    'verification_method' => 'adopted_observed',
                    'verification_records' => null,

                    'metadata' => json_encode([
                        'adopted' => true,
                        'adopted_by' => 'infra:adopt-custom-domains',
                        'adopted_at' => $now->toDateTimeString(),
                        'adoption_phase' => 'INFRA888-S2-StageC',

                        // Custody and provenance — recorded honestly.
                        'custody' => 'managed',
                        'managed_by_infra888' => false,
                        'provisioned_by_infra888' => false,
                        'provisioning_source' => 'builder_platform',
                        'original_system_of_record' => 'websites.custom_domain',
                        'dns_controlled_by_infra888' => false,
                        'ssl_automated_by_infra888' => false,

                        // Observed state, kept separate from any desired state.
                        'observed' => $o,
                        'desired_state' => null,

                        // Rollback reference: restoring service needs no INFRA888
                        // action, because adoption changed nothing operationally.
                        'rollback' => [
                            'mechanism' => 'delete this custom_domains row',
                            'operational_impact_of_rollback' => 'none — adoption is a record, not a routing change',
                            'pre_adoption_routing' => $o['routing_model'],
                            'pre_adoption_a_records' => $o['a_records'],
                            'pre_adoption_cert_issuer' => $o['cert_issuer'],
                            'pre_adoption_cert_expires_at' => $o['cert_expires_at'],
                        ],

                        'estate_link' => [
                            'hosted_site_id' => $hostedSite->id ?? null,
                            'hosted_site_asset_id' => $hostedSite->asset_id ?? null,
                            'note' => 'Formal graph edges are owned by S6 Digital Estate; linked by reference here.',
                        ],
                    ], JSON_UNESCAPED_SLASHES),

                    'last_checked_at' => $now,
                    'connected_at' => $now,
                    'verified_at' => $p['builder_verified'] ? $now : null,
                    // Only stamped when we actually observed a live certificate.
                    'ssl_issued_at' => $o['ssl_status'] === 'active' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created['domains']++;

                DB::table('infra_assets')->insert([
                    'asset_uid' => (string) Str::uuid(),
                    'workspace_id' => $p['workspace_id'],
                    'asset_type' => 'domain',
                    'name' => $p['hostname'],
                    'management_mode' => 'adopted',
                    'lifecycle_state' => 'active',
                    'health_state' => $o['ssl_status'] === 'active' && $o['a_records'] !== [] ? 'healthy' : 'unknown',
                    'risk_state' => 'ok',
                    // No provider binding: we hold no credential for this zone.
                    'provider_id' => null,
                    'source_type' => 'custom_domain',
                    'source_id' => $domainId,
                    'metadata_json' => json_encode([
                        'adopted_by' => 'infra:adopt-custom-domains',
                        'adopted_at' => $now->toDateTimeString(),
                        'custody' => 'managed',
                        'provisioned_by_infra888' => false,
                        'registrar_dns' => $o['nameservers'],
                        'routing_model' => $o['routing_model'],
                        'hosted_site_asset_id' => $hostedSite->asset_id ?? null,
                        'note' => 'Live customer hostname adopted during S2. DNS is at the registrar and '
                            . 'is not under INFRA888 control; TLS is issued by certbot on the origin.',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created['assets']++;
            });
        }

        $this->line('');
        $this->info(sprintf('Adopted %d custom domain(s) and %d domain asset(s).', $created['domains'], $created['assets']));
        $this->line('  Nothing was provisioned. DNS, certificates and routing are exactly as they were.');

        return self::SUCCESS;
    }

    /** Lowercase, trim, drop any trailing root dot. */
    private function canonical(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    /**
     * Measure what is true right now. Read-only: DNS queries and one TLS
     * handshake. Anything we cannot establish is reported as unknown.
     *
     * @return array<string,mixed>
     */
    private function observe(string $host): array
    {
        $out = [
            'observed_at' => now()->toDateTimeString(),
            'a_records' => [],
            'nameservers' => [],
            'mx_records' => [],
            'www_cname' => null,
            'proxied' => null,
            'routing_model' => 'unknown',
            'ssl_status' => 'unknown',
            'cert_issuer' => null,
            'cert_subject' => null,
            'cert_sans' => [],
            'cert_expires_at' => null,
            'cert_renewal_mechanism' => null,
            'http_status' => null,
        ];

        foreach ((array) @dns_get_record($host, DNS_A) as $r) {
            if (isset($r['ip'])) {
                $out['a_records'][] = $r['ip'];
            }
        }

        foreach ((array) @dns_get_record($host, DNS_NS) as $r) {
            if (isset($r['target'])) {
                $out['nameservers'][] = $r['target'];
            }
        }

        foreach ((array) @dns_get_record($host, DNS_MX) as $r) {
            if (isset($r['target'])) {
                $out['mx_records'][] = $r['target'];
            }
        }

        foreach ((array) @dns_get_record('www.' . $host, DNS_CNAME) as $r) {
            if (isset($r['target'])) {
                $out['www_cname'] = $r['target'];
            }
        }

        // Cloudflare's published edge ranges start 104.16-31, 172.64-71, 188.114.
        $out['proxied'] = false;
        foreach ($out['a_records'] as $ip) {
            if (preg_match('/^(104\.(1[6-9]|2[0-9]|3[01])\.|172\.6[4-9]\.|172\.7[01]\.|188\.114\.)/', $ip)) {
                $out['proxied'] = true;
            }
        }

        $cert = $this->peerCertificate($host);

        if ($cert !== null) {
            $out['cert_issuer'] = $cert['issuer'];
            $out['cert_subject'] = $cert['subject'];
            $out['cert_sans'] = $cert['sans'];
            $out['cert_expires_at'] = $cert['expires_at'];
            $out['ssl_status'] = $cert['expired'] ? 'expired' : 'active';

            if (str_contains(strtolower($cert['issuer']), "let's encrypt")) {
                $out['cert_renewal_mechanism'] = 'certbot_origin';
            } elseif (str_contains(strtolower($cert['issuer']), 'cloudflare')) {
                $out['cert_renewal_mechanism'] = 'cloudflare_edge';
            }
        }

        if ($out['proxied'] === true) {
            $out['routing_model'] = 'cloudflare_proxied';
        } elseif ($out['a_records'] !== [] && $out['cert_renewal_mechanism'] === 'certbot_origin') {
            $out['routing_model'] = 'registrar_dns_direct_origin';
        } elseif ($out['a_records'] !== []) {
            $out['routing_model'] = 'direct_origin';
        }

        return $out;
    }

    /**
     * One outbound TLS handshake. No request is sent and nothing is mutated.
     *
     * @return array<string,mixed>|null
     */
    private function peerCertificate(string $host): ?array
    {
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $client = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        if (! isset($params['options']['ssl']['peer_certificate'])) {
            return null;
        }

        $parsed = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);

        if (! is_array($parsed)) {
            return null;
        }

        $sans = [];

        if (isset($parsed['extensions']['subjectAltName'])) {
            foreach (explode(',', (string) $parsed['extensions']['subjectAltName']) as $entry) {
                $entry = trim($entry);

                if (str_starts_with($entry, 'DNS:')) {
                    $sans[] = substr($entry, 4);
                }
            }
        }

        $issuer = $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? 'unknown');

        if (isset($parsed['issuer']['CN']) && isset($parsed['issuer']['O'])) {
            $issuer = $parsed['issuer']['O'] . ' / ' . $parsed['issuer']['CN'];
        }

        return [
            'issuer' => (string) $issuer,
            'subject' => (string) ($parsed['subject']['CN'] ?? 'unknown'),
            'sans' => $sans,
            'expires_at' => isset($parsed['validTo_time_t'])
                ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t'])
                : null,
            'expired' => isset($parsed['validTo_time_t']) && (int) $parsed['validTo_time_t'] < time(),
        ];
    }
}
