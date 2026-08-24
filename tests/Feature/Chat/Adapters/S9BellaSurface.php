<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;

/**
 * S9 — Bella, the admin assistant. Dormant in production (0 executions).
 *
 * Measured, not enabled. GD-001 stands: Bella may REQUEST; governance DECIDES.
 */
class S9BellaSurface extends ChatSurface
{
    public function key(): string  { return 's9_bella_admin'; }
    public function name(): string { return 'S9 Bella Admin Assistant'; }
    public function channelClass(): string { return 'durable_admin'; }

    public function sourceFiles(): array
    {
        return ['app/Http/Controllers/Api/Admin/BellaController.php'];
    }

    public function sendRoute(): ?string  { return '/api/admin/bella'; }
    public function storeTable(): ?string { return 'bella_conversations'; }
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array  { return ['response']; }

    public function capabilities(): array
    {
        return [
            Cap::SEND               => true,
            Cap::PERSIST_USER       => true,
            Cap::PERSIST_ASSISTANT  => true,
            Cap::HISTORY            => true,
            Cap::PAGINATION         => false,
            Cap::ACK                => false,
            Cap::TWO_PHASE          => false,
            Cap::BACKGROUND_REFRESH => false,
            Cap::READ_STATE         => false,
            Cap::READ_ALL           => false,
            Cap::UNREAD_AGGREGATE   => false,
            Cap::CREDITS            => false,
            Cap::CREDIT_RESERVE     => false,
            Cap::STRUCTURED_ERROR   => false,
            Cap::IDEMPOTENCY        => false,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => true,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => false,  // dormant: NOT exercised by the harness
            Cap::TENANCY_COLUMN     => false,
        ];
    }

    public function notes(): array
    {
        return [
            'DORMANT: zero bella_chat executions in production. Deliberately not exercised by the harness.',
            'bella_conversations has no workspace_id — a latent tenancy defect that becomes live the moment Bella is enabled.',
            'GD-002: query_database was removed and MUST NOT be reintroduced.',
        ];
    }
}
