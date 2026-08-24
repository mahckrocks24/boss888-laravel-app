<?php

namespace Tests\Feature\Chat\Support;

/**
 * Dependency-free JSON Schema (draft-07 subset) validator.
 *
 * WHY NOT A LIBRARY
 * The only host this suite runs on is the live application server. Adding
 * justinrainbow/json-schema would mean a composer install against the vendor
 * tree that serves production traffic, for a test-only concern. P1 is explicitly
 * a no-risk phase, so the validator is vendored here instead.
 *
 * SUPPORTED KEYWORDS
 *   type · required · properties · additionalProperties · enum · const
 *   minLength · minimum · maximum · items · oneOf · anyOf · allOf · not
 *   if/then/else · pattern · format(date-time) · $ref (sibling file, local #)
 *
 * Anything outside that set is ignored rather than silently passed: call
 * unsupportedKeywords() to see what a schema used that this validator did not
 * evaluate. A schema relying on an unevaluated keyword is a harness defect and
 * is reported as such, never as a pass.
 */
class JsonSchemaValidator
{
    private string $baseDir;
    private array $errors = [];
    private array $unsupported = [];
    private array $cache = [];

    private const SUPPORTED = [
        '$schema', '$id', 'title', 'description', 'default', 'examples',
        'type', 'required', 'properties', 'additionalProperties', 'enum', 'const',
        'minLength', 'maxLength', 'minimum', 'maximum', 'items', 'oneOf', 'anyOf',
        'allOf', 'not', 'if', 'then', 'else', 'pattern', 'format', '$ref',
        'minItems', 'maxItems',
    ];

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public function errors(): array { return $this->errors; }
    public function unsupportedKeywords(): array { return array_values(array_unique($this->unsupported)); }

    public function loadSchema(string $file): array
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }
        $path = str_starts_with($file, '/') ? $file : $this->baseDir . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException("Schema not found: {$path}");
        }
        $raw = file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Schema is not valid JSON ({$file}): " . json_last_error_msg());
        }

        return $this->cache[$file] = $decoded;
    }

    /** @return bool true when $data conforms to the schema in $schemaFile */
    public function validate($data, string $schemaFile): bool
    {
        $this->errors = [];
        $this->unsupported = [];
        $schema = $this->loadSchema($schemaFile);
        $this->check($data, $schema, '$');

        return $this->errors === [];
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[] = "{$path}: {$message}";
    }

    private function resolve(array $schema): array
    {
        if (!isset($schema['$ref'])) {
            return $schema;
        }
        $ref = $schema['$ref'];
        if ($ref === '#' || $ref === '') {
            return $schema;
        }
        // Only sibling-file refs are used by this contract.
        $resolved = $this->loadSchema($ref);
        unset($schema['$ref']);

        return array_merge($resolved, $schema);
    }

    private function check($data, array $schema, string $path): void
    {
        $schema = $this->resolve($schema);

        foreach (array_keys($schema) as $kw) {
            if (!in_array($kw, self::SUPPORTED, true)) {
                $this->unsupported[] = $kw;
            }
        }

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $this->fail($path, 'must equal ' . json_encode($schema['const']) . ', got ' . json_encode($data));
        }

        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $this->fail($path, 'must be one of ' . json_encode($schema['enum']) . ', got ' . json_encode($data));
        }

        if (isset($schema['type']) && !$this->matchesType($data, $schema['type'])) {
            $this->fail($path, 'expected type ' . json_encode($schema['type']) . ', got ' . $this->typeOf($data));
            // A type mismatch makes the remaining keyword checks meaningless.
            return;
        }

        if (is_string($data)) {
            if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
                $this->fail($path, "shorter than minLength {$schema['minLength']}");
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
                $this->fail($path, "longer than maxLength {$schema['maxLength']}");
            }
            if (isset($schema['pattern']) && !preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/', $data)) {
                $this->fail($path, "does not match pattern {$schema['pattern']}");
            }
            if (($schema['format'] ?? null) === 'date-time' && !$this->isRfc3339($data)) {
                $this->fail($path, 'is not an RFC 3339 date-time with an explicit offset (clause E-06)');
            }
        }

        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $this->fail($path, "below minimum {$schema['minimum']}");
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $this->fail($path, "above maximum {$schema['maximum']}");
            }
        }

        if (is_array($data) && $this->isList($data)) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                $this->fail($path, "fewer than minItems {$schema['minItems']}");
            }
            if (isset($schema['items'])) {
                foreach ($data as $i => $item) {
                    $this->check($item, $schema['items'], "{$path}[{$i}]");
                }
            }
        }

        if (is_array($data) && !$this->isList($data)) {
            foreach (($schema['required'] ?? []) as $req) {
                if (!array_key_exists($req, $data)) {
                    $this->fail($path, "missing required property '{$req}'");
                }
            }
            $props = $schema['properties'] ?? [];
            foreach ($data as $k => $v) {
                if (isset($props[$k])) {
                    $this->check($v, $props[$k], "{$path}.{$k}");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $this->fail($path, "unexpected property '{$k}' (additionalProperties is false)");
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->check($v, $schema['additionalProperties'], "{$path}.{$k}");
                }
            }
        }

        foreach (['allOf' => 'all', 'anyOf' => 'any', 'oneOf' => 'one'] as $kw => $mode) {
            if (!isset($schema[$kw])) {
                continue;
            }
            $passed = 0;
            foreach ($schema[$kw] as $sub) {
                if ($this->subPasses($data, $sub, $path)) {
                    $passed++;
                }
            }
            $total = count($schema[$kw]);
            if ($mode === 'all' && $passed !== $total) {
                $this->fail($path, "failed allOf ({$passed}/{$total} subschemas matched)");
            }
            if ($mode === 'any' && $passed === 0) {
                $this->fail($path, 'failed anyOf (no subschema matched)');
            }
            if ($mode === 'one' && $passed !== 1) {
                $this->fail($path, "failed oneOf ({$passed} subschemas matched, expected exactly 1)");
            }
        }

        if (isset($schema['not']) && $this->subPasses($data, $schema['not'], $path)) {
            $reason = $schema['not']['description'] ?? 'matched a prohibited subschema';
            $this->fail($path, "violates 'not': {$reason}");
        }

        if (isset($schema['if'])) {
            $branch = $this->subPasses($data, $schema['if'], $path) ? 'then' : 'else';
            if (isset($schema[$branch])) {
                $this->check($data, $schema[$branch], $path);
            }
        }
    }

    /** Evaluate a subschema without polluting the caller's error list. */
    private function subPasses($data, array $sub, string $path): bool
    {
        $saved = $this->errors;
        $this->errors = [];
        $this->check($data, $sub, $path);
        $ok = $this->errors === [];
        $this->errors = $saved;

        return $ok;
    }

    private function matchesType($data, $type): bool
    {
        foreach ((array) $type as $t) {
            $ok = match ($t) {
                'object'  => is_array($data) && !$this->isList($data),
                'array'   => is_array($data) && $this->isList($data),
                'string'  => is_string($data),
                'integer' => is_int($data),
                'number'  => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null'    => $data === null,
                default   => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function typeOf($data): string
    {
        if ($data === null)  return 'null';
        if (is_bool($data))  return 'boolean';
        if (is_int($data))   return 'integer';
        if (is_float($data)) return 'number';
        if (is_string($data)) return 'string';
        if (is_array($data)) return $this->isList($data) ? 'array' : 'object';

        return gettype($data);
    }

    private function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    private function isRfc3339(string $v): bool
    {
        // Explicit offset required — a naive "2026-07-26 12:00:00" is a failure.
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $v);
    }
}
