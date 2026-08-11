<?php

namespace Tests\Support;

use App\Core\Engineer888\Reasoning\ProviderResponse;
use App\Core\Engineer888\Reasoning\ReasoningProvider;
use App\Core\Engineer888\Reasoning\ReasoningRequest;

/**
 * A provider that answers differently on each call, and counts them.
 *
 * ScriptedReasoningProvider replays ONE authored response, which cannot express
 * the thing the bounded revision has to be proved against: a first answer that
 * is refused and a second that is not. This double takes a queue, and records
 * every request it was given so a test can assert what the second call actually
 * carried rather than assuming it carried anything.
 *
 * The call counter is the load-bearing part. "Exactly one revision" is a claim
 * about how many times a provider is asked, and the only honest way to check it
 * is to count.
 */
final class SequencedReasoningProvider implements ReasoningProvider
{
    /** @var array<int,array<string,mixed>> payloads still to be served */
    public static array $queue = [];

    /** @var array<int,ReasoningRequest> every request, in order */
    public static array $requests = [];

    public static int $calls = 0;

    public static function reset(array $queue = []): void
    {
        self::$queue = $queue;
        self::$requests = [];
        self::$calls = 0;
    }

    public function __construct(private readonly array $config = []) {}

    public function name(): string { return 'sequenced'; }

    public function describe(): array
    {
        return ['model' => 'sequenced-fixture', 'endpoint' => 'none — no network call is made',
                'deterministic' => true, 'notes' => 'a test double; not a reasoning model'];
    }

    public function isAvailable(): bool { return true; }

    public function unavailableReason(): ?string { return null; }

    public function propose(ReasoningRequest $request): ProviderResponse
    {
        self::$calls++;
        self::$requests[] = $request;

        // The last payload is repeated rather than failing, so a test that
        // asserts "no third call" fails by COUNTING a third call instead of by
        // erroring on an empty queue — which would pass for the wrong reason.
        $payload = count(self::$queue) > 1 ? array_shift(self::$queue) : (self::$queue[0] ?? null);

        if ($payload === null) {
            return ProviderResponse::failure($this->name(), 'sequenced-fixture', 'no payload queued');
        }

        return ProviderResponse::success($this->name(), 'sequenced-fixture', $payload, 0,
            ['prompt_bytes' => $request->sizeBytes(), 'call' => self::$calls]);
    }
}
