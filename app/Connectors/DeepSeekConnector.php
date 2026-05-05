<?php

namespace App\Connectors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DeepSeekConnector
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('llm.deepseek.api_key', env('DEEPSEEK_API_KEY', ''));
        // Fallback: check platform_settings if env key is empty
        if (empty($this->apiKey)) {
            try {
                $row = \Illuminate\Support\Facades\DB::table('platform_settings')
                    ->where('key', 'api_key_deepseek')
                    ->first();
                if ($row && $row->value) {
                    $this->apiKey = $row->is_sensitive
                        ? \Illuminate\Support\Facades\Crypt::decryptString($row->value)
                        : $row->value;
                }
            } catch (\Throwable $e) {
                // Silent — env fallback is fine
            }
        }
        $this->baseUrl = config('llm.deepseek.base_url', 'https://api.deepseek.com');
        $this->model = config('llm.deepseek.model', 'deepseek-chat');
        $this->timeout = config('llm.deepseek.timeout', 30);
    }

    /**
     * Send a chat completion request to DeepSeek.
     */
    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 2000,
        ];

        if (! empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/v1/chat/completions", $payload);

            if ($response->failed()) {
                Log::error('DeepSeek API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['success' => false, 'error' => 'DeepSeek API error: ' . $response->status()];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            return [
                'success' => true,
                'content' => $content,
                'usage' => $data['usage'] ?? [],
                'model' => $data['model'] ?? $this->model,
            ];
        } catch (\Throwable $e) {
            Log::error('DeepSeek API exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a JSON-mode chat request (structured output).
     */
    public function chatJson(array $messages, array $options = []): array
    {
        $options['response_format'] = ['type' => 'json_object'];
        $result = $this->chat($messages, $options);

        if ($result['success'] && ! empty($result['content'])) {
            $parsed = json_decode($result['content'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['parsed'] = $parsed;
            } else {
                $result['parsed'] = null;
                $result['parse_error'] = 'Failed to parse JSON from LLM response';
            }
        }

        return $result;
    }

    /**
     * Health check.
     */
    public function healthCheck(): bool
    {
        if (empty($this->apiKey)) return false;

        $cacheKey = 'deepseek:health';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return (bool) $cached;

        $result = $this->chat([
            ['role' => 'user', 'content' => 'Reply with exactly: ok'],
        ], ['max_tokens' => 5, 'temperature' => 0]);

        $healthy = $result['success'] && str_contains(strtolower($result['content'] ?? ''), 'ok');
        Cache::put($cacheKey, $healthy, 300); // 5min cache

        return $healthy;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }
}
