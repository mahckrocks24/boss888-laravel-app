<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;

/**
 * S10 — the multi-agent meeting room.
 *
 * meeting_messages carries NEITHER workspace_id NOR user_id. Tenant isolation
 * rests entirely on every query remembering to join through `meetings`. That is
 * measured here and reported; it is NOT migrated in P1.
 */
class S10MeetingRoomSurface extends ChatSurface
{
    public function key(): string  { return 's10_meeting_room'; }
    public function name(): string { return 'S10 Meeting Room (multi-agent)'; }
    public function channelClass(): string { return 'durable_room'; }

    public function sourceFiles(): array
    {
        return ['routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
    }

    public function sendRoute(): ?string  { return null; }   // requires a meeting fixture
    public function storeTable(): ?string { return 'meeting_messages'; }
    public function requestContentField(): ?string { return 'message'; }
    public function storeContentColumn(): string { return 'message'; }

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
            Cap::CREDITS            => true,
            Cap::CREDIT_RESERVE     => false,
            Cap::STRUCTURED_ERROR   => false,
            Cap::IDEMPOTENCY        => false,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => false,  // requires a meeting fixture
            Cap::TENANCY_COLUMN     => false,
        ];
    }

    public function notes(): array
    {
        return [
            'TENANCY RISK: meeting_messages has no workspace_id and no user_id.',
            'Not HTTP-probed: requires a meeting fixture the harness does not construct in P1.',
        ];
    }
}
