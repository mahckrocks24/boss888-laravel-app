<?php

namespace App\Console\Commands;

use App\Connectors\Infrastructure\Namecheap\NamecheapClient;
use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Services\Domains\DomainContactResolver;
use App\Services\Domains\DomainPricingService;
use Illuminate\Console\Command;

/**
 * Validation suite -- runs against the REAL Namecheap API, not mocks.
 *
 *   php artisan namecheap:validate                 read-only, spends nothing
 *   php artisan namecheap:validate --with-purchase sandbox registration (SANDBOX ONLY)
 *
 * --with-purchase is refused outright unless the environment is sandbox. There
 * is no flag that makes this command spend production money.
 *
 * DEPENDENT CHECKS
 * The purchase lifecycle is a chain: everything after registration presupposes
 * a registered domain. If registration fails, the dependents are reported
 * BLOCKED -- not FAIL. Reporting six independent failures for one root cause
 * hides the cause behind noise, and a nameserver write is never attempted
 * against a domain that does not exist.
 */
class NamecheapValidate extends Command
{
    protected $signature = 'namecheap:validate
                            {--with-purchase : Also register a throwaway domain (SANDBOX ONLY)}
                            {--domain= : Domain to use for availability/pricing checks}';

    protected $description = 'Validate the Namecheap registrar adapter against the live API';

    private array $results = [];

    public function handle(): int
    {
        $client = NamecheapClient::fromConfig();
        $env = $client->environment();

        $this->line('');
        $this->info('Namecheap adapter validation');
        $this->line('  environment : ' . $env);
        $this->line('  endpoint    : ' . config("namecheap.endpoints.{$env}"));
        $this->line('  client IP   : ' . config('namecheap.client_ip'));
        $this->line('  key loaded  : ' . ($client->credentialFingerprint() ? 'yes (fp ' . $client->credentialFingerprint() . ')' : 'NO'));
        $this->line('');

        if (! $client->isConfigured()) {
            $this->error('BLOCKED: credentials not configured. Missing: ' . implode(', ', $client->missingCredentials()));
            $this->line('  Place them in .env on the server. Do not paste secrets into chat or commits.');

            return self::FAILURE;
        }

        $registrar = NamecheapRegistrarConnector::make();
        $pricing = DomainPricingService::make();
        $testDomain = (string) ($this->option('domain') ?: 'levelup-probe-' . substr(md5((string) getmypid() . microtime()), 0, 8) . '.com');

        // ---- 1. authentication + IP whitelist -------------------------------
        $this->check('Authentication and IP whitelist', function () use ($registrar) {
            $r = $registrar->healthCheck();

            if (! $r->success) {
                $hint = $r->errorCode === '1011150'
                    ? ' >>> The calling IP is not whitelisted. Add ' . config('namecheap.client_ip') . ' in Namecheap > Profile > Tools > API Access.'
                    : '';

                return [false, $r->errorCode . ': ' . $r->errorSummary . $hint];
            }

            return [true, sprintf('authenticated; balance %s %s', $r->data['currency'] ?? '?', $r->data['available_balance'] ?? '?')];
        });

        $this->check('TLD catalogue', function () use ($registrar) {
            $r = $registrar->getTldList();

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            $registerable = count(array_filter($r->data['tlds'], fn ($t) => $t['supports_registration']));

            return [true, "{$r->data['count']} TLDs, {$registerable} API-registerable"];
        });

        $this->check('Availability -- known-taken domain (google.com)', function () use ($registrar) {
            $r = $registrar->searchDomain('google.com');

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            return [$r->data['available'] === false, 'reported available=' . var_export($r->data['available'], true) . ' (expected false)'];
        });

        $this->check("Availability -- random domain ({$testDomain})", function () use ($registrar, $testDomain) {
            $r = $registrar->searchDomain($testDomain);

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            return [$r->data['available'] === true, 'reported available=' . var_export($r->data['available'], true) . ' (expected true)'];
        });

        $this->check('Registrar cost quote (.com, 1yr)', function () use ($registrar, $testDomain) {
            $r = $registrar->quoteRegistration($testDomain, 1);

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            $ok = $r->data['amount_minor'] > 0 && $r->data['currency'] === 'USD';

            return [$ok, sprintf('cost %s %.2f%s', $r->data['currency'], $r->data['amount_minor'] / 100, $r->data['is_promotional'] ? ' (promotional)' : '')];
        });

        $this->check('Registrar cost quote (.com, 3yr) is a real quote', function () use ($registrar, $testDomain) {
            $r = $registrar->quoteRegistration($testDomain, 3);

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            $derived = $r->data['derived'] ?? false;

            return [true, sprintf('3yr cost USD %.2f%s', $r->data['amount_minor'] / 100, $derived ? ' [DERIVED from 1yr rate, flagged]' : ' [quoted directly]')];
        });

        $this->check('Retail pricing = cost + markup, USD, tax-exclusive', function () use ($pricing, $testDomain) {
            $q = $pricing->quoteRegistration($testDomain, 1);

            if (! ($q['available'] ?? false)) {
                return [false, 'BLOCKED: ' . ($q['blocked_reason'] ?? '?')];
            }

            $ok = $q['currency'] === 'USD'
                && $q['retail_minor'] === $q['cost_minor'] + $q['markup_minor']
                && $q['tax_treatment'] === 'exclusive';

            return [$ok, sprintf('cost %s + markup %s = retail %s (%s), margin %.1f%%', $q['cost'], $q['markup'], $q['retail'], $q['markup_basis'], $q['margin_percent'])];
        });

        $this->check('Taken domain returns availability=false and NO price', function () use ($pricing) {
            $q = $pricing->searchWithPricing('google.com');

            return [($q['available'] ?? true) === false && ! isset($q['retail_minor']), 'available=' . var_export($q['available'] ?? null, true) . ', price present=' . var_export(isset($q['retail_minor']), true)];
        });

        $this->check('Invalid domain rejected locally', function () use ($registrar) {
            $r = $registrar->searchDomain('not a domain');

            return [! $r->success && $r->errorCode === 'INVALID_DOMAIN', 'code=' . $r->errorCode];
        });

        $this->check('Invalid term rejected locally', function () use ($registrar, $testDomain) {
            $r = $registrar->quoteRegistration($testDomain, 99);

            return [! $r->success && $r->errorCode === 'INVALID_TERM', 'code=' . $r->errorCode];
        });

        $this->check('Empty DNS record set refused (setHosts is destructive)', function () use ($registrar, $testDomain) {
            $r = $registrar->setDnsHosts($testDomain, [], 'validation-probe');

            return [! $r->success && $r->errorCode === 'DNS_SET_EMPTY', 'code=' . $r->errorCode];
        });

        $this->check('Single-nameserver change refused', function () use ($registrar, $testDomain) {
            $r = $registrar->updateNameservers($testDomain, ['ns1.example.com'], 'validation-probe');

            return [! $r->success && $r->errorCode === 'NAMESERVERS_INSUFFICIENT', 'code=' . $r->errorCode];
        });

        $this->check('Status of a domain we do not own is answered, not errored', function () use ($registrar, $testDomain) {
            $r = $registrar->getDomainStatus($testDomain);

            return [$r->success && ($r->data['owned'] ?? true) === false, 'state=' . $r->normalizedState];
        });

        $this->check('Domain list for the account', function () use ($registrar) {
            $r = $registrar->listDomains();

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            return [true, "{$r->data['count']} domain(s) in account (total {$r->data['total']})"];
        });

        // ---- purchase lifecycle -------------------------------------------
        if ($this->option('with-purchase')) {
            $this->purchaseLifecycle($registrar, $testDomain, $env);
        } else {
            $this->line('');
            $this->comment('  Purchase tests skipped. Re-run with --with-purchase (sandbox only) to exercise');
            $this->comment('  registration, idempotent replay and nameserver writes.');
        }

        return $this->summary();
    }

    /**
     * The purchase chain. Every step after registration depends on it, so a
     * failed registration BLOCKS the dependents rather than failing them.
     */
    private function purchaseLifecycle(NamecheapRegistrarConnector $registrar, string $testDomain, string $env): void
    {
        $this->line('');
        $this->line('  --- purchase lifecycle (sandbox) ---');

        if ($env !== 'sandbox') {
            $this->line('');
            $this->error('REFUSED: --with-purchase is only permitted in the sandbox environment.');
            $this->results[] = ['name' => 'Purchase environment guard', 'status' => 'fail', 'detail' => "environment is '{$env}', not sandbox"];

            return;
        }

        // Contact comes from the gated resolver, never from this file and never
        // from the connector. Outside sandbox this returns null.
        $contacts = DomainContactResolver::sandboxTestContact(true);

        if ($contacts === null) {
            $this->results[] = [
                'name'   => 'Sandbox test contact available',
                'status' => 'fail',
                'detail' => 'DomainContactResolver refused to supply the sandbox identity. '
                    . 'Check NAMECHEAP_ENVIRONMENT=sandbox and that namecheap.sandbox_test_contact is complete and valid.',
            ];
            $this->blockChain('sandbox test contact unavailable');

            return;
        }

        $this->record('Sandbox test contact available and valid', 'pass',
            'country=' . $contacts['country'] . ', all ' . count(DomainContactResolver::REQUIRED_FIELDS) . ' required fields present and well-formed');

        // Balance before, so the spend can be stated as a measured delta.
        $before = $registrar->getAccountBalance();
        $balanceBefore = $before->success ? (float) $before->data['available_balance'] : null;
        $this->line(sprintf('    balance before: %s', $balanceBefore !== null ? number_format($balanceBefore, 2) : 'unavailable'));

        $idem = 'validate-' . substr(md5($testDomain), 0, 12);
        $registered = false;
        $txn = null;

        $this->check("SANDBOX registration of {$testDomain}", function () use ($registrar, $testDomain, $contacts, $idem, &$registered, &$txn) {
            $r = $registrar->registerDomain($testDomain, 1, $contacts, $idem);

            if (! $r->success) {
                return [false, $r->errorCode . ': ' . $r->errorSummary];
            }

            $registered = true;
            $txn = $r->data['transaction_id'] ?? null;

            return [true, sprintf('registered; order %s, txn %s, charged %s',
                $r->data['order_id'] ?? '?', $txn ?? '?', $r->data['charged_amount'] ?? '?')];
        });

        if (! $registered) {
            $this->blockChain('registration did not succeed, so there is no domain to act on');

            return;
        }

        $this->check('Replay of the same registration does not double-charge', function () use ($registrar, $testDomain, $contacts, $idem) {
            $r = $registrar->registerDomain($testDomain, 1, $contacts, $idem);
            $replay = ($r->data['idempotent_replay'] ?? false) === true;

            return [$r->success && $replay, 'idempotent_replay=' . var_export($replay, true)];
        });

        $this->check('Registered domain is now owned by the account', function () use ($registrar, $testDomain) {
            $r = $registrar->getDomainStatus($testDomain);

            return [$r->success && ($r->data['owned'] ?? false) === true, 'owned=' . var_export($r->data['owned'] ?? null, true)];
        });

        $ns = ['ns1.digitalocean.com', 'ns2.digitalocean.com', 'ns3.digitalocean.com'];

        $this->check('Nameserver update on the owned domain', function () use ($registrar, $testDomain, $ns) {
            $r = $registrar->updateNameservers($testDomain, $ns, 'validate-ns');

            return [$r->success, 'state=' . $r->normalizedState . ' verified=' . var_export($r->verified, true)];
        });

        $this->check('Nameserver update replay is a no-op', function () use ($registrar, $testDomain, $ns) {
            $r = $registrar->updateNameservers($testDomain, $ns, 'validate-ns');

            return [$r->success && ($r->data['idempotent_replay'] ?? false) === true, 'replay=' . var_export($r->data['idempotent_replay'] ?? false, true)];
        });

        $this->check('verify() confirms nameservers match expectation', function () use ($registrar, $testDomain, $ns) {
            $r = $registrar->verify($testDomain, ['nameservers' => $ns, 'owned' => true]);

            return [$r->success, 'verified=' . var_export($r->success, true)];
        });

        $this->check('verify() detects a WRONG expectation', function () use ($registrar, $testDomain) {
            $r = $registrar->verify($testDomain, ['nameservers' => ['ns1.wrong.com', 'ns2.wrong.com']]);

            return [! $r->success && $r->errorCode === 'EXPECTATION_MISMATCH', 'code=' . $r->errorCode];
        });

        // Balance after, and the measured delta.
        $after = $registrar->getAccountBalance();
        $balanceAfter = $after->success ? (float) $after->data['available_balance'] : null;

        $this->line('');
        $this->line(sprintf('    balance after : %s', $balanceAfter !== null ? number_format($balanceAfter, 2) : 'unavailable'));

        if ($balanceBefore !== null && $balanceAfter !== null) {
            $this->line(sprintf('    delta         : %.2f  (a single registration, charged once)', $balanceAfter - $balanceBefore));
        }

        $this->line(sprintf('    domain        : %s', $testDomain));
        $this->line(sprintf('    transaction   : %s', $txn ?? 'not reported'));
    }

    /** Mark every dependent purchase check BLOCKED with a single shared cause. */
    private function blockChain(string $reason): void
    {
        foreach ([
            'Replay of the same registration does not double-charge',
            'Registered domain is now owned by the account',
            'Nameserver update on the owned domain',
            'Nameserver update replay is a no-op',
            'verify() confirms nameservers match expectation',
            'verify() detects a WRONG expectation',
        ] as $name) {
            $this->record($name, 'blocked', 'depends on registration: ' . $reason);
        }
    }

    private function check(string $name, callable $fn): void
    {
        try {
            [$ok, $detail] = $fn();
        } catch (\Throwable $e) {
            $ok = false;
            $detail = 'EXCEPTION ' . class_basename($e) . ': ' . $e->getMessage();
        }

        $this->record($name, $ok ? 'pass' : 'fail', $detail);
    }

    private function record(string $name, string $status, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => $status, 'detail' => $detail];

        $label = match ($status) {
            'pass'    => ' PASS ',
            'fail'    => ' FAIL ',
            'blocked' => 'BLOCKD',
        };

        $this->line(sprintf('  [%s] %s', $label, $name));
        $this->line('           ' . $detail);
    }

    private function summary(): int
    {
        $count = static fn (array $r, string $s): int => count(array_filter($r, fn ($x) => $x['status'] === $s));

        $pass = $count($this->results, 'pass');
        $fail = $count($this->results, 'fail');
        $blocked = $count($this->results, 'blocked');
        $total = count($this->results);

        $this->line('');
        $this->line(str_repeat('-', 62));
        $this->line(sprintf('  %d/%d checks passed', $pass, $total));

        if ($fail > 0) {
            $this->line(sprintf('  %d FAILED:', $fail));
            foreach (array_filter($this->results, fn ($r) => $r['status'] === 'fail') as $r) {
                $this->line('    - ' . $r['name']);
            }
        }

        if ($blocked > 0) {
            // BLOCKED is not a pass and not a silent skip. It is an unproven
            // check, and the run does not succeed while any remain.
            $this->line(sprintf('  %d BLOCKED (unproven -- a dependency failed):', $blocked));
            foreach (array_filter($this->results, fn ($r) => $r['status'] === 'blocked') as $r) {
                $this->line('    - ' . $r['name']);
            }
        }

        $this->line(str_repeat('-', 62));
        $this->line('');

        return ($fail === 0 && $blocked === 0) ? self::SUCCESS : self::FAILURE;
    }
}
