<?php

namespace App\Engines\Studio\Projection\Html\Contracts;

/**
 * STUDIO888 · Projection/Html — a supplied Studio document the adapter mutates.
 *
 * Two forms implement this: RawHtmlDocument (a raw HTML string) and
 * StructuredFieldsDocument ({template_slug, fields}). The adapter talks only to
 * this interface, so it never re-resolves targets or reformats unrelated
 * content. Field paths are 'text', 'style.<prop>', or 'visible'. Targets are
 * exact data-field names / structured field keys — never selectors.
 */
interface HtmlProjectionDocument
{
    public const FORM_RAW        = 'raw';
    public const FORM_STRUCTURED = 'structured';

    public function form(): string;

    /** Deterministic version token of the canonical document (see hashing model). */
    public function versionToken(): string;

    /** Number of elements/keys matching this target id (0 = missing, >1 = ambiguous). */
    public function count(string $target): int;

    /** Whether a field path can be applied to this target on this document form. */
    public function supports(string $target, string $path): bool;

    /** Current value for a field path ('text' decoded, 'style.<prop>', or 'visible' bool), or null. */
    public function get(string $target, string $path): mixed;

    /** Apply a value; returns true if the value changed. Assumes supports() already passed. */
    public function set(string $target, string $path, mixed $value): bool;

    /** Opaque internal state for atomic batch snapshot/restore. */
    public function snapshotState(): mixed;

    public function restoreState(mixed $state): void;

    /** The current, output-sanitised document payload (string for raw, array for structured). */
    public function payload(): mixed;
}
