<?php

namespace App\Console\Commands\Tests;

use Illuminate\Console\Command;
use App\Connectors\ConnectorResolver;

class ConnectorTestCommand extends Command
{
    protected $signature = 'boss888:connector-test
        {--connector=all : Connector to test (creative|email|social|all)}';

    protected $description = 'Phase 5: Test connectors against real infrastructure';

    public function handle(ConnectorResolver $resolver): int
    {
        $target = $this->option('connector');
        $connectors = $target === 'all'
            ? $resolver->all()
            : [$target];

        $this->info('═══ Connector Test Suite ═══');
        $allPassed = true;

        foreach ($connectors as $name) {
            if (! $resolver->has($name)) {
                $this->warn("Connector '{$name}' not found, skipping.");
                continue;
            }

            $this->newLine();
            $this->info("── Testing: {$name} ──");
            $connector = $resolver->resolve($name);

            // 1. Health check
            $this->write('  Health check... ');
            $healthy = $connector->healthCheck();
            $this->line($healthy ? '✅ OK' : '❌ FAILED');
            if (! $healthy) {
                $this->warn("  Skipping action tests — connector not reachable");
                $allPassed = false;
                continue;
            }

            // 2. Test each action
            foreach ($connector->supportedActions() as $action) {
                $this->write("  Action: {$action}... ");

                $testParams = $this->getTestParams($name, $action);
                if (! $testParams) {
                    $this->line('⏭ skipped (no test params)');
                    continue;
                }

                try {
                    // Execute
                    $result = $connector->execute($action, $testParams);

                    if (! $result['success']) {
                        $this->line('❌ execution failed: ' . ($result['message'] ?? 'unknown'));
                        $allPassed = false;
                        continue;
                    }

                    // Verify
                    $verification = $connector->verifyResult($action, $testParams, $result);

                    if ($verification['verified']) {
                        $this->line('✅ executed + verified');
                    } else {
                        $this->line('⚠️ executed but verification failed: ' . $verification['message']);
                        $allPassed = false;
                    }
                } catch (\Throwable $e) {
                    $this->line('❌ exception: ' . $e->getMessage());
                    $allPassed = false;
                }
            }
        }

        $this->newLine();
        if ($allPassed) {
            $this->info('✅ ALL CONNECTOR TESTS PASSED');
        } else {
            $this->warn('⚠️ SOME CONNECTOR TESTS FAILED — review output above');
        }

        return $allPassed ? 0 : 1;
    }

    private function getTestParams(string $connector, string $action): ?array
    {
        $params = [
            'creative' => [
                'create_post' => ['title' => 'Boss888 Test Post ' . time(), 'content' => '<p>Automated test</p>', 'status' => 'draft'],
                'get_pages' => ['per_page' => 5, 'page' => 1],
                'update_seo' => null, // Requires existing post_id
                'update_post' => null,
                'update_page_content' => null,
            ],
            'creative' => [
                'generate_image' => ['prompt' => 'A simple blue square for testing', 'aspect_ratio' => '1:1'],
                'list_assets' => ['limit' => 5],
                'generate_video' => null, // Too expensive for routine testing
                'get_asset' => null,
            ],
            'email' => [
                'send_email' => ['to' => 'test@example.com', 'subject' => 'Boss888 Test', 'body' => 'Automated test email'],
            ],
            'social' => [
                'create_post' => ['platform' => 'facebook', 'content' => 'Boss888 test post ' . time()],
                'publish_post' => null,
            ],
        ];

        return $params[$connector][$action] ?? null;
    }

    private function write(string $text): void
    {
        $this->output->write($text);
    }
}
