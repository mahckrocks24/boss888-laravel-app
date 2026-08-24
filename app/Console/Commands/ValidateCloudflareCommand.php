<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * INFRA888 · PHASE S2 STAGE G — CLOUDFLARE READ-ONLY VALIDATION
 *
 * Proves whether we can talk to Cloudflare at all, and with what authority,
 * WITHOUT creating, changing or deleting anything. Every request below is a
 * GET. No custom hostname is created. No DNS record is written.
 *
 * The point is to distinguish failure modes that all look alike from the
 * outside — a bad token, a valid token with too few permissions, a valid
 * token pointed at the wrong account, and a provider having a bad day are
 * four different decisions, and "Cloudflare didn't work" is not an answer
 * anyone can act on.
 *
 * Secrets are never printed. The token is shown only as a short fingerprint
 * so two humans can confirm they are discussing the same credential.
 */
class ValidateCloudflareCommand extends Command
{
    protected $signature = 'infra:validate-cloudflare {--json : machine-readable output}';

    protected $description = 'INFRA888 S2 — read-only validation of Cloudflare credentials, account, zone and SaaS readiness (no mutation)';

    private const API = 'https://api.cloudflare.com/client/v4';

    /** @var array<int,array<string,string>> */
    private array $results = [];

    public function handle(): int
    {
        $token = (string) config('cloudflare.api_token');
        $zoneId = (string) config('cloudflare.zone_id');
        $accountId = (string) config('cloudflare.account_id');

        $this->line('');
        $this->line('INFRA888 S2 — CLOUDFLARE READ-ONLY VALIDATION');
        $this->line('  every call below is a GET · nothing is created, changed or deleted');
        $this->line('');

        // ── configuration presence ──────────────────────────────────────────
        $this->check('config.token_present', $token !== '', $token !== ''
            ? 'present, fingerprint ' . $this->fingerprint($token)
            : 'CLOUDFLARE_API_TOKEN is not set');

        $this->check('config.zone_id_present', $zoneId !== '', $zoneId !== ''
            ? 'present, ' . $this->fingerprint($zoneId)
            : 'CLOUDFLARE_ZONE_ID is not set');

        $this->check('config.account_id_present', $accountId !== '', $accountId !== ''
            ? 'present, ' . $this->fingerprint($accountId)
            : 'CLOUDFLARE_ACCOUNT_ID is not set — required for Cloudflare for SaaS');

        $this->check('config.saas_gate_closed', config('cloudflare.saas_enabled') === false,
            config('cloudflare.saas_enabled') === false
                ? 'saas_enabled=false — production mutation gate is CLOSED (correct until activation is approved)'
                : 'saas_enabled=TRUE — the production mutation gate is OPEN');

        if ($token === '') {
            $this->render();
            $this->error('CREDENTIAL_FAILURE: no API token configured. Cannot validate further.');

            return self::FAILURE;
        }

        // ── token identity and validity ─────────────────────────────────────
        $verify = $this->get('/user/tokens/verify', $token);

        if ($verify['transport_error'] !== null) {
            $this->check('token.verify', false, 'TRANSIENT_PROVIDER_FAILURE: ' . $verify['transport_error']);
            $this->render();

            return self::FAILURE;
        }

        if ($verify['status'] === 401 || $verify['status'] === 403) {
            $this->check('token.verify', false, 'CREDENTIAL_FAILURE: token rejected (HTTP ' . $verify['status'] . ')');
            $this->render();
            $this->error('Stop. The configured Cloudflare token is invalid or revoked.');

            return self::FAILURE;
        }

        $tokenStatus = $verify['body']['result']['status'] ?? 'unknown';
        $this->check('token.verify', $verify['ok'] && $tokenStatus === 'active',
            'token status: ' . $tokenStatus . ' (HTTP ' . $verify['status'] . ')');

        // ── account identity ────────────────────────────────────────────────
        $accounts = $this->get('/accounts', $token);

        if ($accounts['status'] === 403) {
            $this->check('account.read', false, 'INSUFFICIENT_SCOPE: token cannot list accounts (needs Account:Read)');
        } else {
            $list = $accounts['body']['result'] ?? [];
            $names = array_map(fn ($a) => ($a['name'] ?? '?') . ' [' . $this->fingerprint((string) ($a['id'] ?? '')) . ']', $list);
            $this->check('account.read', $accounts['ok'], $accounts['ok']
                ? count($list) . ' account(s): ' . implode(', ', $names)
                : 'HTTP ' . $accounts['status']);

            if ($accountId !== '' && $list !== []) {
                $match = false;
                foreach ($list as $a) {
                    if (($a['id'] ?? '') === $accountId) {
                        $match = true;
                    }
                }
                $this->check('account.matches_config', $match, $match
                    ? 'configured account ID is one this token can see'
                    : 'WRONG_ACCOUNT: configured CLOUDFLARE_ACCOUNT_ID is not visible to this token');
            }
        }

        // ── zone identity ───────────────────────────────────────────────────
        if ($zoneId !== '') {
            $zone = $this->get('/zones/' . $zoneId, $token);

            if ($zone['status'] === 404) {
                $this->check('zone.read', false, 'WRONG_ZONE: zone ID not found or not visible to this token');
            } elseif ($zone['status'] === 403) {
                $this->check('zone.read', false, 'INSUFFICIENT_SCOPE: token cannot read this zone (needs Zone:Read)');
            } else {
                $z = $zone['body']['result'] ?? [];
                $this->check('zone.read', $zone['ok'], $zone['ok']
                    ? 'zone ' . ($z['name'] ?? '?') . ' · status ' . ($z['status'] ?? '?') . ' · plan ' . ($z['plan']['name'] ?? '?')
                    : 'HTTP ' . $zone['status']);

                $this->check('zone.active', ($z['status'] ?? '') === 'active',
                    'zone status: ' . ($z['status'] ?? 'unknown'));
            }

            // ── DNS read ────────────────────────────────────────────────────
            $dns = $this->get('/zones/' . $zoneId . '/dns_records?per_page=5', $token);
            $this->check('dns.read', $dns['ok'], $dns['ok']
                ? 'readable · ' . ($dns['body']['result_info']['total_count'] ?? '?') . ' record(s) in zone'
                : ($dns['status'] === 403 ? 'INSUFFICIENT_SCOPE: needs Zone:DNS:Read' : 'HTTP ' . $dns['status']));

            // ── custom hostnames (Cloudflare for SaaS) ──────────────────────
            $ch = $this->get('/zones/' . $zoneId . '/custom_hostnames?per_page=5', $token);

            if ($ch['status'] === 403) {
                $this->check('saas.custom_hostnames.read', false,
                    'INSUFFICIENT_SCOPE: needs Zone:SSL and Certificates:Read for custom hostnames');
            } elseif ($ch['status'] === 404 || $ch['status'] === 405) {
                $this->check('saas.custom_hostnames.read', false,
                    'UNSUPPORTED_FEATURE: Cloudflare for SaaS is not enabled on this zone/plan');
            } else {
                $total = $ch['body']['result_info']['total_count'] ?? 0;
                $this->check('saas.custom_hostnames.read', $ch['ok'], $ch['ok']
                    ? 'readable · ' . $total . ' existing custom hostname(s) — provider orphan check baseline'
                    : 'HTTP ' . $ch['status']);
            }

            // ── fallback origin: required before any custom hostname works ──
            $fb = $this->get('/zones/' . $zoneId . '/custom_hostnames/fallback_origin', $token);

            if ($fb['ok']) {
                $origin = $fb['body']['result']['origin'] ?? null;
                $fbStatus = $fb['body']['result']['status'] ?? 'unknown';
                $this->check('saas.fallback_origin', $origin !== null && $fbStatus === 'active',
                    $origin === null
                        ? 'MISSING_CUSTOM_ORIGIN: no SaaS fallback origin is configured on this zone'
                        : 'origin ' . $origin . ' · status ' . $fbStatus);
            } else {
                $this->check('saas.fallback_origin', false, $fb['status'] === 404
                    ? 'MISSING_CUSTOM_ORIGIN: no fallback origin set (required for Cloudflare for SaaS)'
                    : 'HTTP ' . $fb['status']);
            }
        }

        $this->render();

        $failed = count(array_filter($this->results, fn ($r) => $r['ok'] === false));

        if ($failed > 0) {
            $this->warn("{$failed} check(s) did not pass. Cloudflare is NOT ready for production activation.");

            return self::FAILURE;
        }

        $this->info('All read-only checks passed. No mutation was performed.');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function get(string $path, string $token): array
    {
        try {
            $res = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->get(self::API . $path);

            return [
                'ok' => $res->successful() && (bool) ($res->json('success') ?? false),
                'status' => $res->status(),
                'body' => $res->json() ?? [],
                'transport_error' => null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'transport_error' => $e->getMessage()];
        }
    }

    private function check(string $name, bool $ok, string $detail): void
    {
        $this->results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    /** Enough to identify a value between two people; never enough to use it. */
    private function fingerprint(string $secret): string
    {
        return $secret === '' ? '(empty)' : 'sha256:' . substr(hash('sha256', $secret), 0, 12);
    }

    private function render(): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ($this->results as $r) {
            $this->line(sprintf('  [%s] %-32s %s', $r['ok'] ? 'PASS' : 'FAIL', $r['name'], $r['detail']));
        }

        $this->line('');
    }
}
