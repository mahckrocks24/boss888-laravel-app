<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Core\TaskSystem\Orchestrator;
use App\Models\CreditTransaction;

class CreativeExecutionTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function generate_image_success_with_valid_asset()
    {
        Http::fake([
            '*/creative888/v1/generate' => Http::response([
                'id' => 'img_001',
                'url' => 'https://cdn.example.com/image.png',
                'type' => 'image',
                'size' => 250000,
                'status' => 'completed',
            ], 200),
        ]);

        $task = $this->createTask([
            'engine' => 'creative',
            'action' => 'generate_image',
            'payload_json' => ['prompt' => 'A sunset over Dubai Marina', 'aspect_ratio' => '16:9'],
            'credit_cost' => 10,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('completed', $task->status);
        $this->assertCreditBalance(5000 - 10);
    }

    /** @test */
    public function async_polling_resolves_correctly()
    {
        $pollCount = 0;
        Http::fake(function ($request) use (&$pollCount) {
            if (str_contains($request->url(), 'creative888/v1/generate')) {
                return Http::response(['job_id' => 'job_async_1', 'status' => 'in_progress'], 200);
            }
            if (str_contains($request->url(), 'creative888/v1/jobs/job_async_1')) {
                $pollCount++;
                if ($pollCount < 2) {
                    return Http::response(['status' => 'in_progress'], 200);
                }
                return Http::response([
                    'status' => 'completed',
                    'id' => 'img_async',
                    'url' => 'https://cdn.example.com/async.png',
                    'type' => 'image',
                    'size' => 300000,
                ], 200);
            }
            return Http::response([], 200);
        });

        // Reduce poll interval for test speed
        config(['connectors.creative.poll_interval_ms' => 10]);

        $task = $this->createTask([
            'engine' => 'creative',
            'action' => 'generate_image',
            'payload_json' => ['prompt' => 'Async test'],
            'credit_cost' => 10,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('completed', $task->status);
        $this->assertGreaterThanOrEqual(2, $pollCount);
    }

    /** @test */
    public function invalid_asset_size_fails_verification_and_releases_credits()
    {
        Http::fake([
            '*/creative888/v1/generate' => Http::response([
                'id' => 'img_tiny',
                'url' => 'https://cdn.example.com/tiny.png',
                'type' => 'image',
                'size' => 100, // Below threshold
                'status' => 'completed',
            ], 200),
        ]);

        $task = $this->createTask([
            'engine' => 'creative',
            'action' => 'generate_image',
            'payload_json' => ['prompt' => 'Tiny image test'],
            'credit_cost' => 10,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('failed', $task->status);
        $this->assertCreditBalance(5000); // Released
        $this->assertReservedBalance(0);
    }
}
