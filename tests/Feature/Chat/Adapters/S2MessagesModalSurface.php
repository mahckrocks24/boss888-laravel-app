<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;

/** S2 — the lu-messages-floater modal in messages-ui.js. */
class S2MessagesModalSurface extends AgentApiSurface
{
    public function key(): string  { return 's2_messages_modal'; }
    public function name(): string { return 'S2 Messages Widget Modal (messages-ui.js)'; }

    public function sourceFiles(): array
    {
        return ['public/app/js/messages-ui.js', 'routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
    }

    public function capabilities(): array
    {
        return [
            Cap::SEND               => true,
            Cap::PERSIST_USER       => true,
            Cap::PERSIST_ASSISTANT  => true,
            Cap::HISTORY            => true,
            Cap::PAGINATION         => false,
            Cap::ACK                => true,
            Cap::TWO_PHASE          => true,
            Cap::BACKGROUND_REFRESH => true,   // 5s _msgRefreshOpenThread
            Cap::READ_STATE         => true,
            Cap::READ_ALL           => true,
            Cap::UNREAD_AGGREGATE   => true,
            Cap::CREDITS            => true,
            Cap::CREDIT_RESERVE     => false,
            Cap::STRUCTURED_ERROR   => false,
            Cap::IDEMPOTENCY        => false,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => true,
            Cap::TENANCY_COLUMN     => true,
        ];
    }

    public function notes(): array
    {
        return [
            'Two-phase rendering and the 5s thread refresh were added 2026-07-26 in response to the Sarah incident.',
            'Shares agent_messages with S1 and S3.',
        ];
    }
}
