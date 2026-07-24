<?php

namespace App\Core\Publisher;

/**
 * Records calls, returns scripted responses, NEVER opens a socket.
 *
 * There is deliberately no HTTP client, no curl, no stream function anywhere in
 * this class. That absence is the safety property — it is asserted by the test
 * suite, which greps this file for any networking primitive.
 */
final class MockTransport implements Transport
{
    public array $calls = [];

    /** @var array<string, array> url-substring => response */
    private array $scripted = [];
    private array $default;

    public function __construct(?array $default = null)
    {
        $this->default = $default ?? [
            'status' => 200, 'json' => ['id' => 'mock_0'], 'error' => null, 'request_id' => 'mock-req',
        ];
    }

    /** Script a response for any URL containing $match. Later matches win order-of-insertion. */
    public function on(string $match, array $response): self
    {
        $this->scripted[$match] = $response + [
            'status' => 200, 'json' => [], 'error' => null, 'request_id' => 'mock-req',
        ];
        return $this;
    }

    public function post(string $url, array $payload, array $headers = []): array
    {
        return $this->record('POST', $url, $payload);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        return $this->record('GET', $url, $query);
    }

    private function record(string $method, string $url, array $data): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'data' => $data];

        // Longest match wins, so '/media_publish' beats '/media'.
        $best = null; $bestLen = -1;
        foreach ($this->scripted as $match => $resp) {
            if (str_contains($url, $match) && strlen($match) > $bestLen) {
                $best = $resp; $bestLen = strlen($match);
            }
        }
        return $best ?? $this->default;
    }

    public function isLive(): bool
    {
        return false;
    }

    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }
}
