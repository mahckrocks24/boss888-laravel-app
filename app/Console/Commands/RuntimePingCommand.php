<?php

namespace App\Console\Commands;

use App\Connectors\RuntimeClient;
use Illuminate\Console\Command;

/**
 * RuntimePingCommand
 *
 * `php artisan runtime:ping`
 *
 * Phase 0.6 diagnostic: verifies the Laravel↔Runtime bridge is alive
 * and the RUNTIME_SECRET is accepted by the runtime's requireSecret
 * middleware.
 *
 * Prints three checks:
 *   1. Public  GET /health          — is the runtime process reachable at all?
 *   2. Secret  GET /internal/health — does our RUNTIME_SECRET match WP_SECRET?
 *   3. Optional /ai/run smoke test  — does DeepSeek work end-to-end? (with --llm)
 *
 * Exit code 0 = all checks passed, non-zero = at least one failed.
 *
 * Created: 2026-04-11.
 */
class RuntimePingCommand extends Command
{
    protected $signature = 'runtime:ping
        {--llm : Also hit /ai/run with a tiny prompt to smoke-test DeepSeek}';

    protected $description = 'Ping the Node.js runtime and verify the shared-secret handshake';

    public function handle(RuntimeClient $client): int
    {
        $this->info('── Runtime Bridge Ping ──');
        $this->line('');

        if (! $client->isConfigured()) {
            $this->error('RuntimeClient not configured.');
            $this->line('  Expected env vars:');
            $this->line('    RUNTIME_URL    = https://levelup-runtime2-production.up.railway.app');
            $this->line('    RUNTIME_SECRET = <matches Railway WP_SECRET>');
            return self::FAILURE;
        }

        $this->line('  Base URL: ' . $client->baseUrl());
        $this->line('');

        $failed = 0;

        // ── Check 1: public /health ─────────────────────────────────────
        $this->line('<fg=cyan>[1/2] GET /health</>          (public, no auth)');
        $public = $client->health();
        if (($public['ok'] ?? false) === true) {
            $this->info('      ✓ runtime reachable');
            $this->line('        status : ' . ($public['status']  ?? 'n/a'));
            $this->line('        version: ' . ($public['version'] ?? 'n/a'));
            $this->line('        phase  : ' . ($public['phase']   ?? 'n/a'));
            $agents = $public['agents'] ?? [];
            $tools  = $public['tools']  ?? [];
            $this->line('        agents : ' . count($agents) . ' registered' .
                (count($agents) ? ' (' . implode(', ', array_slice($agents, 0, 6)) . (count($agents) > 6 ? ', …' : '') . ')' : ''));
            $this->line('        tools  : ' . count($tools) . ' registered');
            $cfg = $public['config'] ?? [];
            if ($cfg) {
                $flags = [];
                foreach ($cfg as $k => $v) { $flags[] = $k . '=' . ($v ? '✓' : '✗'); }
                $this->line('        config : ' . implode(' ', $flags));
            }
        } else {
            $this->error('      ✗ runtime unreachable');
            $this->line('        error: ' . ($public['error'] ?? 'http_' . ($public['http_code'] ?? '?')));
            $failed++;
        }
        $this->line('');

        // ── Check 2: protected /internal/health ─────────────────────────
        $this->line('<fg=cyan>[2/2] GET /internal/health</> (protected, uses RUNTIME_SECRET)');
        $internal = $client->internalHealth();
        if (($internal['ok'] ?? false) === true) {
            $this->info('      ✓ shared-secret accepted');
            $body = $internal['body'] ?? [];
            if (isset($body['redis'])) $this->line('        redis : ' . $body['redis']);
            if (isset($body['queue']) && is_array($body['queue'])) {
                $q = $body['queue'];
                $this->line(sprintf(
                    '        queue : active=%d waiting=%d delayed=%d completed=%d failed=%d',
                    $q['active']    ?? 0,
                    $q['waiting']   ?? 0,
                    $q['delayed']   ?? 0,
                    $q['completed'] ?? 0,
                    $q['failed']    ?? 0
                ));
            }
        } else {
            $this->error('      ✗ internal health failed');
            $code = $internal['http_code'] ?? '?';
            $this->line('        http_code: ' . $code);
            if ($code === 401) {
                $this->line('        hint     : RUNTIME_SECRET on Laravel does not match WP_SECRET on Railway');
            }
            if (isset($internal['error'])) $this->line('        error    : ' . $internal['error']);
            if (isset($internal['body']))  $this->line('        body     : ' . json_encode($internal['body']));
            $failed++;
        }
        $this->line('');

        // ── Optional: /ai/run smoke test ────────────────────────────────
        if ($this->option('llm')) {
            $this->line('<fg=cyan>[+] POST /ai/run</>            (smoke test — hits DeepSeek)');
            $result = $client->aiRun(
                'improve_draft',
                'Rewrite this in 10 words: The quick brown fox.',
                ['tone' => 'concise'],
                120
            );
            if (($result['success'] ?? false) === true) {
                $this->info('      ✓ LLM responded');
                $text = $result['text'] ?? json_encode($result['raw'] ?? []);
                $this->line('        response: ' . mb_substr((string) $text, 0, 200));
            } else {
                $this->error('      ✗ LLM call failed');
                $this->line('        error: ' . ($result['error'] ?? 'unknown'));
                $failed++;
            }
            $this->line('');
        }

        // ── Summary ─────────────────────────────────────────────────────
        if ($failed === 0) {
            $this->info('✅ Runtime bridge is healthy.');
            return self::SUCCESS;
        }

        $this->error("❌ {$failed} check(s) failed.");
        return self::FAILURE;
    }
}
