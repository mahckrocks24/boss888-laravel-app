<?php

namespace App\Core\Engineer888\Conversation\Providers;

use App\Core\Engineer888\Conversation\ConversationProvider;
use App\Core\Engineer888\Conversation\ConversationReply;
use App\Core\Engineer888\Conversation\ConversationRequest;
use Illuminate\Support\Facades\Http;

/**
 * Conversation over an OpenAI chat completion.
 *
 * Written from the interface and nothing else, in the same way
 * OpenAiReasoningProvider is: it shares no base class with the DeepSeek
 * provider even though the wire formats happen to resemble each other today.
 * A shared base would make one vendor's response shape the definition of
 * "correct", and the next provider that differs would be the one that has to
 * bend.
 *
 * TEXT OUT, NOT JSON. The reasoning provider asks for response_format
 * json_object because a candidate is a document. A conversational turn is
 * prose, and forcing it through JSON produced exactly the stilted register
 * this rebuild exists to remove. The optional structured intent is carried on a
 * trailing sentinel line instead, and is stripped before the human sees it.
 */
final class OpenAiConversationProvider implements ConversationProvider
{
    /** The model may end its reply with this; it never reaches the reader. */
    public const INTENT_PREFIX = '@@INTENT:';

    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'openai';
    }

    public function describe(): array
    {
        return [
            'model'    => (string) ($this->config['model'] ?? 'gpt-4o'),
            'endpoint' => (string) ($this->config['endpoint'] ?? 'https://api.openai.com'),
        ];
    }

    public function isAvailable(): bool
    {
        return trim($this->key()) !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'OPENAI_API_KEY is not configured in this environment';
    }

    public function converse(ConversationRequest $request): ConversationReply
    {
        $model = $this->describe()['model'];
        $started = microtime(true);

        $messages = [['role' => 'system', 'content' => $request->systemText()]];

        foreach ($request->normalisedHistory() as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['body']];
        }

        $messages[] = ['role' => 'user', 'content' => $request->turn];

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withHeaders(['Authorization' => 'Bearer ' . $this->key()])
                ->post(rtrim($this->describe()['endpoint'], '/') . '/v1/chat/completions', [
                    'model'       => $model,
                    'temperature' => (float) ($this->config['temperature'] ?? 0.4),
                    'max_tokens'  => (int) ($this->config['max_tokens'] ?? 1200),
                    'messages'    => $messages,
                ]);
        } catch (\Throwable $e) {
            return ConversationReply::failure($this->name(), $model,
                get_class($e) . ': ' . $e->getMessage(),
                (int) round((microtime(true) - $started) * 1000));
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        if ($response->failed()) {
            // Status only. Provider error bodies have been observed to echo
            // request content, and this request carries engineering state.
            return ConversationReply::failure($this->name(), $model, 'HTTP ' . $response->status(), $latency);
        }

        $data = $response->json();
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($content === '') {
            return ConversationReply::failure($this->name(), $model, 'provider returned an empty reply', $latency);
        }

        [$text, $intent, $subject] = self::splitIntent($content);

        return ConversationReply::success(
            $text, $this->name(), (string) ($data['model'] ?? $model), $latency,
            (array) ($data['usage'] ?? []), $intent, $subject
        );
    }

    /**
     * Peel the advisory intent sentinel off the end of a reply.
     *
     * @return array{0:string,1:?string,2:?string}
     */
    public static function splitIntent(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $intent = null;
        $subject = null;

        // TOLERANT ON THE WAY IN, ABSOLUTE ON THE WAY OUT.
        //
        // Measured 2026-08-13: asked for "@@INTENT: EXECUTE_REQUEST | ..." the
        // model emitted "@@EXECUTE_REQUEST | ...". A strict parser missed it,
        // so the governed escalation did not happen AND the raw sentinel was
        // printed to Boss. Both halves of that are unacceptable, and the second
        // is the worse one: a leaked control token in a conversation about
        // execution reads like a command he was not supposed to see.
        //
        // So any @@-prefixed line is recognised whatever follows the marker,
        // and EVERY @@-prefixed line is removed from the visible text even when
        // it parses to nothing. A sentinel never reaches the reader.
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || ! str_starts_with($trimmed, '@@')) { continue; }

            $raw = trim(substr($trimmed, 2));

            if (str_starts_with(strtoupper($raw), 'INTENT:')) {
                $raw = trim(substr($raw, 7));
            }

            $parts = array_map('trim', explode('|', $raw, 2));
            $name = strtoupper(preg_replace('/[^A-Za-z_]/', '', $parts[0] ?? ''));

            if ($intent === null && $name !== '') {
                $intent = $name;
                $subject = ($parts[1] ?? '') !== '' ? $parts[1] : null;
            }

            unset($lines[$i]);
        }

        return [trim(implode("\n", $lines)), $intent, $subject];
    }

    private function key(): string
    {
        return (string) (env('OPENAI_API_KEY') ?? '');
    }
}
