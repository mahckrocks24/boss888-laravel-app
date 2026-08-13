<?php

namespace App\Core\Engineer888\Conversation\Providers;

use App\Core\Engineer888\Conversation\ConversationProvider;
use App\Core\Engineer888\Conversation\ConversationReply;
use App\Core\Engineer888\Conversation\ConversationRequest;

/**
 * A conversation provider for tests, and only for tests.
 *
 * It records the request it was given so a test can assert on WHAT Engineer888
 * was told rather than only on what came back. That matters here more than
 * usual: most of this rebuild's risk is in the frame, not the model, and a
 * frame that silently stops including the active project would otherwise pass
 * every test that only reads the reply.
 */
final class ScriptedConversationProvider implements ConversationProvider
{
    /** @var array<int,string> replies handed out in order, then the last repeats */
    private static array $queue = [];
    private static ?ConversationRequest $last = null;
    private static bool $available = true;

    public function __construct(private readonly array $config = []) {}

    /** @param array<int,string> $replies */
    public static function script(array $replies): void
    {
        self::$queue = $replies;
        self::$last = null;
    }

    public static function makeUnavailable(bool $unavailable = true): void
    {
        self::$available = ! $unavailable;
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$last = null;
        self::$available = true;
    }

    public static function lastRequest(): ?ConversationRequest
    {
        return self::$last;
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function describe(): array
    {
        return ['model' => (string) ($this->config['label'] ?? 'scripted-conversation'), 'endpoint' => 'memory'];
    }

    public function isAvailable(): bool
    {
        return self::$available;
    }

    public function unavailableReason(): ?string
    {
        return self::$available ? null : 'scripted provider was made unavailable by a test';
    }

    public function converse(ConversationRequest $request): ConversationReply
    {
        self::$last = $request;

        if (! self::$available) {
            return ConversationReply::failure($this->name(), 'scripted', (string) $this->unavailableReason());
        }

        $next = count(self::$queue) > 1 ? array_shift(self::$queue) : (self::$queue[0] ?? 'scripted reply');

        [$text, $intent, $subject] = OpenAiConversationProvider::splitIntent((string) $next);

        return ConversationReply::success($text, $this->name(), $this->describe()['model'], 1, [], $intent, $subject);
    }
}
