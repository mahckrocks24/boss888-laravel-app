<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;

/** S3 — the full-page Messages view in messages-ui.js. */
class S3MessagesPageSurface extends AgentApiSurface
{
    public function key(): string  { return 's3_messages_page'; }
    public function name(): string { return 'S3 Messages Page View (messages-ui.js)'; }

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
            Cap::BACKGROUND_REFRESH => false,  // measured gap: no timer on the page view
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
            'No background refresh: a reply produced elsewhere does not appear until the user navigates.',
        ];
    }
}
