<?php

namespace Tests\Feature\Chat\Support;

/** Normalised view of a real HTTP response from a chat surface. */
class ProbeResponse
{
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function get(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    /** Top-level keys that look like an undocumented bare reply (clause E-04/E-05). */
    public function bareReplyFields(): array
    {
        $legacy = ['reply', 'text', 'response', 'ack', 'answer', 'output'];

        return array_values(array_intersect($legacy, array_keys($this->body)));
    }

    public function jsonText(): string
    {
        return json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
