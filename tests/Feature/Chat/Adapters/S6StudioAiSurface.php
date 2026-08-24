<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;

/** S6 — Studio AI ("Arthur"). The only surface with a correct reserve/commit/release credit flow. */
class S6StudioAiSurface extends ChatSurface
{
    public function key(): string  { return 's6_studio_ai'; }
    public function name(): string { return 'S6 Studio AI / Arthur (StudioAiService)'; }
    public function channelClass(): string { return 'ephemeral_tool'; }

    public function sourceFiles(): array
    {
        return ['app/Engines/Studio/Services/StudioAiService.php', 'routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
    }

    public function sendRoute(): ?string { return '/api/studio/ai/chat'; }
    public function storeTable(): ?string { return null; }
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array { return ['message']; }
    public function creditService(): ?string { return 'App\\Core\\Billing\\CreditService'; }

    public function capabilities(): array
    {
        return [
            Cap::SEND               => true,
            Cap::PERSIST_USER       => false,
            Cap::PERSIST_ASSISTANT  => false,
            Cap::HISTORY            => false,
            Cap::PAGINATION         => false,
            Cap::ACK                => false,
            Cap::TWO_PHASE          => false,
            Cap::BACKGROUND_REFRESH => false,
            Cap::READ_STATE         => false,
            Cap::READ_ALL           => false,
            Cap::UNREAD_AGGREGATE   => false,
            Cap::CREDITS            => true,
            Cap::CREDIT_RESERVE     => true,   // the one correct implementation
            Cap::STRUCTURED_ERROR   => false,
            Cap::IDEMPOTENCY        => false,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => false,  // requires a live design context fixture
            Cap::TENANCY_COLUMN     => false,
        ];
    }

    public function notes(): array
    {
        return [
            'Ephemeral: no persistence at all.',
            'FeatureGateService::canUseAI + reserve/commit/release is the credit pattern the other surfaces should adopt.',
            'Not HTTP-probed: the endpoint requires a design-context fixture the harness does not construct in P1.',
        ];
    }
}
