<?php

namespace App\Core\Engineer888\Conversation\Providers;

use App\Core\Engineer888\Conversation\ConversationProvider;
use App\Core\Engineer888\Conversation\ConversationReply;
use App\Core\Engineer888\Conversation\ConversationRequest;
use Illuminate\Support\Facades\Http;

/**
 * Conversation over DeepSeek.
 *
 * Written from the interface, sharing no base class with the OpenAI provider.
 * The wire formats resemble each other today; that is a fact about this month,
 * not a contract, and the reasoning namespace already made the same choice for
 * the same reason.
 */
final class DeepSeekConversationProvider implements ConversationProvider
{
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'deepseek';
    }

    public function describe(): array
    {
        return [
            'model'    => (string) ($this->config['model'] ?? 'deepseek-chat'),
            'endpoint' => (string) ($this->config['endpoint'] ?? 'https://api.deepseek.com'),
        ];
    }

    public function isAvailable(): bool
    {
        return trim($this->key()) !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'DEEPSEEK_API_KEY is not configured in this environment';
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
                ->post(rtrim($this->describe()['endpoint'], '/') . '/chat/completions', [
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
            return ConversationReply::failure($this->name(), $model, 'HTTP ' . $response->status(), $latency);
        }

        $data = $response->json();
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($content === '') {
            return ConversationReply::failure($this->name(), $model, 'provider returned an empty reply', $latency);
        }

        [$text, $intent, $subject] = OpenAiConversationProvider::splitIntent($content);

        return ConversationReply::success(
            $text, $this->name(), (string) ($data['model'] ?? $model), $latency,
            (array) ($data['usage'] ?? []), $intent, $subject
        );
    }

    private function key(): string
    {
        return (string) (env('DEEPSEEK_API_KEY') ?? '');
    }
}
