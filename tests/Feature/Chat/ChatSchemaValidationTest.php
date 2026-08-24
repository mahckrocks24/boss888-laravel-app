<?php

namespace Tests\Feature\Chat;

use Tests\Feature\Chat\Support\JsonSchemaValidator;
use Tests\TestCase;

/**
 * Validates the machine-readable Chat Contract v1 schemas themselves, and
 * validates canonical example payloads against them.
 *
 * If these fail, the contract document and the schemas have diverged — which is
 * a defect in the contract, not in a surface.
 */
class ChatSchemaValidationTest extends TestCase
{
    private const SCHEMAS = [
        'conversation.schema.json',
        'message.schema.json',
        'error.schema.json',
        'send-request.schema.json',
        'acknowledgement.schema.json',
        'delivery.schema.json',
        'final-response.schema.json',
        'progress-event.schema.json',
        'usage.schema.json',
        'credits.schema.json',
        'attachment.schema.json',
        'tool-call.schema.json',
        'approval.schema.json',
        'read-state.schema.json',
    ];

    private function dir(): string
    {
        return base_path('contracts/chat/v1');
    }

    private function validator(): JsonSchemaValidator
    {
        return new JsonSchemaValidator($this->dir());
    }

    /** @test */
    public function every_declared_schema_file_exists_and_is_valid_json(): void
    {
        foreach (self::SCHEMAS as $file) {
            $path = $this->dir() . '/' . $file;
            $this->assertFileExists($path, "missing contract schema {$file}");
            $decoded = json_decode(file_get_contents($path), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "{$file} is not valid JSON");
            $this->assertArrayHasKey('$id', $decoded, "{$file} has no \$id");
            $this->assertArrayHasKey('title', $decoded, "{$file} has no title");
        }
    }

    /** @test */
    public function a_canonical_error_validates(): void
    {
        $v = $this->validator();
        $ok = $v->validate([
            'code'            => 'CHAT_INSUFFICIENT_CREDITS',
            'message'         => "This workspace is out of credits, so Sarah can't reply right now. Your message has been saved.",
            'retryable'       => false,
            'correlation_id'  => 'cr_01',
            'provider_called' => false,
            'persistence'     => ['user_message_saved' => true, 'assistant_message_saved' => false],
            'action'          => ['label' => 'Top up credits', 'href' => '/app/billing'],
        ], 'error.schema.json');

        $this->assertTrue($ok, 'canonical error failed validation: ' . implode(' | ', $v->errors()));
    }

    /** @test */
    public function an_error_with_a_code_outside_the_taxonomy_is_rejected(): void
    {
        $v = $this->validator();
        $ok = $v->validate([
            'code'            => 'SOMETHING_ELSE',
            'message'         => 'x',
            'retryable'       => false,
            'correlation_id'  => 'c',
            'provider_called' => false,
        ], 'error.schema.json');

        $this->assertFalse($ok, 'the closed taxonomy (X-01) did not reject an unknown code');
    }

    /** @test */
    public function a_send_request_may_not_set_authoritative_fields(): void
    {
        $v = $this->validator();
        $ok = $v->validate([
            'contract_version' => '1.0',
            'workspace_id'     => 42,                       // clause R-03 violation
            'message'          => ['content' => 'hello'],
        ], 'send-request.schema.json');

        $this->assertFalse($ok, 'clause R-03 did not reject a client-supplied workspace_id');
    }

    /** @test */
    public function a_canonical_send_request_validates(): void
    {
        $v = $this->validator();
        $ok = $v->validate([
            'contract_version' => '1.0',
            'conversation_id'  => 'conv_1',
            'agent_id'         => 'sarah',
            'message'          => [
                'client_message_id' => 'c1',
                'idempotency_key'   => 'k1',
                'content'           => 'hello',
                'content_type'      => 'text',
                'attachments'       => [],
            ],
        ], 'send-request.schema.json');

        $this->assertTrue($ok, 'canonical send request failed: ' . implode(' | ', $v->errors()));
    }

    /** @test */
    public function a_naive_timestamp_is_rejected(): void
    {
        $v = $this->validator();
        $ok = $v->validate($this->canonicalMessage(['created_at' => '2026-07-26 12:00:00']), 'message.schema.json');

        $this->assertFalse($ok, 'clause E-06 did not reject a timestamp without an explicit offset');
    }

    /** @test */
    public function a_canonical_message_validates(): void
    {
        $v = $this->validator();
        $ok = $v->validate($this->canonicalMessage(), 'message.schema.json');

        $this->assertTrue($ok, 'canonical message failed: ' . implode(' | ', $v->errors()));
    }

    /** @test */
    public function a_message_type_outside_the_enum_is_rejected(): void
    {
        $v = $this->validator();
        $ok = $v->validate($this->canonicalMessage(['type' => 'freeform']), 'message.schema.json');

        $this->assertFalse($ok, 'clause M-01 did not reject an undeclared message type');
    }

    /** @test */
    public function a_pending_approval_may_not_be_marked_executed(): void
    {
        $v = $this->validator();
        $ok = $v->validate([
            'id'             => 'ap_1',
            'capability_key' => 'infra.provision_site',
            'state'          => 'pending',
            'requested_by'   => ['type' => 'agent', 'id' => 'bella'],
            'executed'       => true,
        ], 'approval.schema.json');

        $this->assertFalse($ok, 'clause AP-02 did not reject a pending approval marked executed');
    }

    /** @test */
    public function read_state_may_not_claim_user_scope_without_declaring_it(): void
    {
        $v = $this->validator();
        $ok = $v->validate([
            'contract_version' => '1.0',
            'scope'            => 'tenant',       // not a declared scope
            'total_unread'     => 3,
        ], 'read-state.schema.json');

        $this->assertFalse($ok, 'read-state scope enum did not reject an undeclared scope');
    }

    /** @test */
    public function the_validator_reports_any_keyword_it_did_not_evaluate(): void
    {
        // Guards against a schema silently passing because the vendored
        // validator ignored the keyword that carried the constraint.
        $v = $this->validator();
        foreach (self::SCHEMAS as $file) {
            $v->validate(new \stdClass(), $file);
        }
        $this->assertSame([], $v->unsupportedKeywords(),
            'contract schemas use keywords the vendored validator does not evaluate: '
            . implode(', ', $v->unsupportedKeywords()));
    }

    private function canonicalMessage(array $overrides = []): array
    {
        return array_merge([
            'id'              => 'msg_1',
            'conversation_id' => 'conv_1',
            'workspace_id'    => 7,
            'correlation_id'  => 'cor_1',
            'sender_type'     => 'agent',
            'role'            => 'assistant',
            'type'            => 'markdown',
            'status'          => 'completed',
            'content'         => 'Here is the answer.',
            'content_type'    => 'markdown',
            'created_at'      => '2026-07-26T12:00:00Z',
            'completed_at'    => '2026-07-26T12:00:09Z',
            'provider'        => 'deepseek',
            'model'           => 'deepseek-v4-flash',
        ], $overrides);
    }
}
