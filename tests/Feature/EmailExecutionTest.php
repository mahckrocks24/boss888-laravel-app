<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Core\TaskSystem\Orchestrator;

class EmailExecutionTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        config(['connectors.email.driver' => 'postmark', 'connectors.email.postmark_token' => 'test-token']);
    }

    /** @test */
    public function send_email_success_via_postmark()
    {
        Http::fake([
            'api.postmarkapp.com/email' => Http::response([
                'MessageID' => 'msg-001',
                'SubmittedAt' => now()->toIso8601String(),
            ], 200),
        ]);

        $task = $this->createTask([
            'engine' => 'marketing',
            'action' => 'send_email',
            'payload_json' => [
                'to' => 'client@example.com',
                'subject' => 'Test Email',
                'body' => '<p>Hello</p>',
                'html' => true,
            ],
            'credit_cost' => 1,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('completed', $task->status);
        $this->assertCreditBalance(5000 - 1);
    }

    /** @test */
    public function provider_failure_releases_credits()
    {
        Http::fake([
            'api.postmarkapp.com/email' => Http::response(['error' => 'Bad request'], 422),
        ]);

        $task = $this->createTask([
            'engine' => 'marketing',
            'action' => 'send_email',
            'payload_json' => [
                'to' => 'fail@example.com',
                'subject' => 'Fail',
                'body' => 'x',
            ],
            'credit_cost' => 1,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('failed', $task->status);
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
    }
}
