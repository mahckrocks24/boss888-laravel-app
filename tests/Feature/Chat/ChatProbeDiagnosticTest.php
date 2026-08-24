<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * Harness diagnostic — NOT a conformance test.
 *
 * Establishes empirically what each endpoint actually returns under the test
 * fixture, so that adapter declarations describe reality rather than my
 * assumptions. A blocked conformance case is not evidence; this is how the
 * blocks get removed honestly.
 */
class ChatProbeDiagnosticTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function probe_every_surface_endpoint(): void
    {
        $out = [];

        $agentExists = DB::table('agents')->where('slug', 'sarah')->exists();
        $out[] = "agents.sarah exists = " . var_export($agentExists, true)
               . " | total agents = " . DB::table('agents')->count();
        $out[] = "workspace = {$this->testWorkspace->id} | chat_meter = "
               . DB::table('workspaces')->where('id', $this->testWorkspace->id)->value('chat_meter')
               . " | balance = " . DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->value('balance');

        $endpoints = [
            ['POST', '/api/agents/sarah/messages', ['content' => 'probe', 'from' => 'User']],
            ['GET',  '/api/agents/sarah/messages', null],
            ['POST', '/api/assistant',             ['message' => 'probe', 'context' => []]],
            ['POST', '/api/studio/chat',           ['message' => 'probe', 'history' => []]],
            ['POST', '/api/studio/ai/chat',        ['message' => 'probe']],
            ['POST', '/api/builder/arthur/message',['message' => 'probe']],
            ['GET',  '/api/seo/assistant/history', null],
            ['GET',  '/api/messages/unread-count', null],
            ['POST', '/api/messages/read-all',     []],
        ];

        foreach ($endpoints as [$m, $u, $p]) {
            try {
                $r = $m === 'GET'
                    ? $this->withHeaders($this->authHeaders())->getJson($u)
                    : $this->withHeaders($this->authHeaders())->postJson($u, $p ?? []);
                $body = $r->json();
                $keys = is_array($body) ? implode(',', array_slice(array_keys($body), 0, 8)) : gettype($body);
                $err  = is_array($body) ? substr(json_encode($body['error'] ?? ''), 0, 90) : '';
                $out[] = sprintf('%-5s %-34s -> %d  keys=[%s] err=%s', $m, $u, $r->getStatusCode(), $keys, $err);
            } catch (\Throwable $e) {
                $out[] = sprintf('%-5s %-34s -> EXCEPTION %s', $m, $u, substr($e->getMessage(), 0, 110));
            }
        }

        // How do we actually induce a credit refusal? meterChat only debits on
        // the 10th chat, so a zero balance alone is not enough.
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);
        DB::table('workspaces')->where('id', $this->testWorkspace->id)->update(['chat_meter' => 9]);
        $r = $this->withHeaders($this->authHeaders())
            ->postJson('/api/agents/sarah/messages', ['content' => 'refusal probe', 'from' => 'User']);
        $out[] = 'REFUSAL (balance=0, chat_meter=9) -> ' . $r->getStatusCode()
               . ' body=' . substr(json_encode($r->json()), 0, 220);

        $stored = DB::table('agent_messages')
            ->where('workspace_id', $this->testWorkspace->id)
            ->where('content', 'refusal probe')->count();
        $out[] = "user message persisted despite refusal = {$stored}";

        $rows = DB::table('agent_messages')->where('workspace_id', $this->testWorkspace->id)->get();
        $out[] = 'agent_messages rows now = ' . $rows->count();
        foreach ($rows->take(6) as $row) {
            $out[] = '   role=' . $row->role . ' sender=' . $row->sender
                   . ' content=' . substr($row->content, 0, 48)
                   . ' meta=' . substr((string) $row->metadata_json, 0, 60);
        }

        file_put_contents(storage_path('app/chat-conformance/probe-diagnostic.txt'), implode("\n", $out));
        fwrite(STDERR, "\n" . implode("\n", $out) . "\n");

        $this->assertTrue(true);
    }
}
