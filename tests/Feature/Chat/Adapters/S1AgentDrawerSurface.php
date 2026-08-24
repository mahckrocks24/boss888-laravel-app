<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;

/** S1 — the agent drawer in core.js. The platform's primary chat surface. */
class S1AgentDrawerSurface extends AgentApiSurface
{
    public function key(): string  { return 's1_agent_drawer'; }
    public function name(): string { return 'S1 Agent Drawer (core.js)'; }

    public function sourceFiles(): array
    {
        return ['public/app/js/core.js', 'routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
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
            Cap::BACKGROUND_REFRESH => true,   // /api/agent/events poll
            Cap::READ_STATE         => true,
            Cap::READ_ALL           => true,
            Cap::UNREAD_AGGREGATE   => true,
            Cap::CREDITS            => true,
            Cap::CREDIT_RESERVE     => false,  // meterChat only; no reserve/release
            Cap::STRUCTURED_ERROR   => false,
            Cap::IDEMPOTENCY        => false,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => true,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => true,
            Cap::TENANCY_COLUMN     => true,
        ];
    }

    public function notes(): array
    {
        return [
            'Reference implementation: highest parity of any surface.',
            'Background refresh is the 2.5s /api/agent/events poll, not a real-time transport.',
        ];
    }
}
