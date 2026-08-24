<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * Harness diagnostic — is the unauthenticated-send conformance failure real, or
 * an artefact of how the test issues the request?
 *
 * A false security finding is worse than none, so the exact status codes are
 * recorded here before anything is written into a report.
 */
class ChatAuthProbeTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function record_unauthenticated_status_for_every_chat_endpoint(): void
    {
        $out = [];
        $endpoints = [
            '/api/agents/sarah/messages',
            '/api/assistant',
            '/api/studio/chat',
            '/api/studio/ai/chat',
            '/api/builder/arthur/message',
            '/api/messages/read-all',
            '/api/admin/bella',
            '/api/public/chatbot/message',
        ];

        foreach ($endpoints as $u) {
            // No Authorization header at all.
            $r = $this->withHeaders(['Accept' => 'application/json'])
                ->postJson($u, ['content' => 'x', 'message' => 'x']);
            $body = substr(json_encode($r->json()) ?: '', 0, 120);
            $out[] = sprintf('  NO-AUTH   %-32s -> %d %s', $u, $r->getStatusCode(), $body);

            // Deliberately invalid bearer token.
            $r2 = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer not-a-real-token'])
                ->postJson($u, ['content' => 'x', 'message' => 'x']);
            $out[] = sprintf('  BAD-TOKEN %-32s -> %d', $u, $r2->getStatusCode());
        }

        fwrite(STDERR, "\n" . implode("\n", $out) . "\n");
        file_put_contents(storage_path('app/chat-conformance/auth-probe.txt'), implode("\n", $out));

        $this->assertTrue(true);
    }
}
