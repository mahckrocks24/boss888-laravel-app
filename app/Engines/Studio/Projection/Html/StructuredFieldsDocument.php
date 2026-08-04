<?php

namespace App\Engines\Studio\Projection\Html;

use App\Engines\Studio\Projection\Html\Contracts\HtmlProjectionDocument;

/**
 * STUDIO888 · Projection/Html — a structured template document {template_slug, fields, ...}.
 *
 * Text operations mutate the matching `fields` value; template_slug, unknown
 * metadata, and unrelated fields are preserved EXACTLY. The document is NEVER
 * collapsed into raw HTML.
 *
 * Design decision (evidence-based): the structured template model has no
 * representation for per-element inline style — styling comes from template CSS
 * + brand tokens at render time. Rather than invent a silent ad-hoc style field
 * the renderer would ignore, per-element STYLE and HIDE/SHOW are classified
 * UNSUPPORTED for structured documents in Phase 4B (text is supported).
 */
final class StructuredFieldsDocument implements HtmlProjectionDocument
{
    /** @param array $payload decoded {template_slug, fields, ...} */
    public function __construct(private array $payload)
    {
        if (! isset($this->payload['fields']) || ! is_array($this->payload['fields'])) {
            $this->payload['fields'] = [];
        }
    }

    public function form(): string
    {
        return self::FORM_STRUCTURED;
    }

    public function versionToken(): string
    {
        return 's:' . substr(hash('sha256', self::canonicalJson($this->payload)), 0, 16);
    }

    public function count(string $target): int
    {
        return array_key_exists($target, $this->payload['fields']) ? 1 : 0;
    }

    public function supports(string $target, string $path): bool
    {
        // Structured documents support text only (see class docblock decision).
        return $path === 'text' && $this->count($target) === 1;
    }

    public function get(string $target, string $path): mixed
    {
        if ($path === 'text') {
            $v = $this->payload['fields'][$target] ?? null;

            return $v === null ? null : (string) $v;
        }

        return null;
    }

    public function set(string $target, string $path, mixed $value): bool
    {
        if ($path !== 'text') {
            return false;
        }
        $new = (string) $value;
        $old = array_key_exists($target, $this->payload['fields']) ? (string) $this->payload['fields'][$target] : null;
        if ($old === $new) {
            return false;
        }
        $this->payload['fields'][$target] = $new;

        return true;
    }

    public function snapshotState(): mixed
    {
        return $this->payload;
    }

    public function restoreState(mixed $state): void
    {
        $this->payload = (array) $state;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function templateSlug(): ?string
    {
        $s = $this->payload['template_slug'] ?? null;

        return is_string($s) ? $s : null;
    }

    /** Deterministic canonical JSON (recursive key sort). */
    public static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::sortDeep($value));
    }

    private static function sortDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sortDeep($v);
            }
            if ($isAssoc) {
                ksort($out);
            }

            return $out;
        }

        return $value;
    }
}
