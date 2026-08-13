<?php

namespace App\Core\Engineer888\Conversation;

/**
 * What a provider said, and what it cost.
 *
 * TWO THINGS ARE DELIBERATELY SEPARATE HERE.
 *
 * `text` is what the human reads. `proposedIntent` is what the model THINKS the
 * human is asking for. The second is advisory and carries no authority
 * whatsoever: it is a hint the deterministic layer may act on, ignore, or
 * refuse. Sarah's ChatActionProposal makes the same split and states the reason
 * plainly — an ASK_FIRST turn creates zero task rows until a later explicit
 * authorization. A model that could name an action and have it happen would be
 * a model with authority, which is the one thing this subsystem must never
 * concede.
 *
 * FAILURE IS A FIRST-CLASS VALUE. There is no "" that quietly means broken.
 * A caller that ignores ->failed() gets an empty string, not a plausible
 * sentence, because a fabricated answer about engineering state is worse than
 * an admission that reasoning is unavailable.
 */
final class ConversationReply
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly ?string $proposedIntent,
        public readonly ?string $intentSubject,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $latencyMs,
        public readonly array $usage,
        public readonly ?string $error,
    ) {}

    public static function success(
        string $text, string $provider, string $model, int $latencyMs,
        array $usage = [], ?string $proposedIntent = null, ?string $intentSubject = null
    ): self {
        return new self(true, $text, $proposedIntent, $intentSubject, $provider, $model, $latencyMs, $usage, null);
    }

    public static function failure(string $provider, string $model, string $error, int $latencyMs = 0): self
    {
        return new self(false, '', null, null, $provider, $model, $latencyMs, [], $error);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /** Metering, kept apart from engineering execution evidence. */
    public function meter(): array
    {
        return [
            'capability'   => 'engineering_conversation',
            'provider'     => $this->provider,
            'model'        => $this->model,
            'latency_ms'   => $this->latencyMs,
            'usage'        => $this->usage,
            'ok'           => $this->ok,
        ];
    }
}
