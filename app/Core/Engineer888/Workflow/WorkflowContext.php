<?php

namespace App\Core\Engineer888\Workflow;

/**
 * State carried between stages.
 *
 * Deliberately a plain accumulating bag rather than a typed pipeline object:
 * stages add facts as they discover them, and later stages read whatever
 * earlier ones proved. A stage that needs something absent says so through its
 * inputs() contract and blocks, rather than failing on a null.
 */
final class WorkflowContext
{
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(
        public readonly object $task,
        public readonly object $project,
        public readonly string $repoPath,
    ) {}

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** Decoded JSON column, or a default. */
    public function taskJson(string $column, mixed $default = []): mixed
    {
        $raw = $this->task->{$column} ?? null;
        if ($raw === null) { return $default; }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : $default;
    }
}
