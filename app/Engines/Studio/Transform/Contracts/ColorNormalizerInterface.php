<?php

namespace App\Engines\Studio\Transform\Contracts;

/**
 * STUDIO888 · AI Transformation Engine — Colour Normalizer contract.
 *
 * Converts an accepted colour expression into a single canonical form
 * (lower-case hex). Anything that is not a recognised, safe colour returns
 * null — never an approximation, never a passthrough. This is the only place
 * colours are interpreted, so every subsystem sees identical canonical values.
 */
interface ColorNormalizerInterface
{
    /**
     * @return string|null canonical hex (e.g. "#ff0000" / "#ff0000cc"), or null if not a valid/safe colour
     */
    public function normalize(string $value): ?string;

    /** True if the value normalizes to a canonical colour. */
    public function isValid(string $value): bool;
}
