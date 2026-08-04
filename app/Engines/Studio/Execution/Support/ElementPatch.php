<?php

namespace App\Engines\Studio\Execution\Support;

use App\Engines\Studio\Document\StudioElement;

/**
 * STUDIO888 · Execution — immutable element patch helpers.
 *
 * Produces a new StudioElement with selected fields overridden, reads a field
 * by path, and snapshots the mutable-relevant fields. Keeps the Phase-2
 * StudioElement value object untouched (no wither added there). No HTML, no I/O.
 */
final class ElementPatch
{
    /**
     * Clone $e overriding only the keys present in $o (supports 'text', 'style',
     * 'visible', 'locked', 'selected'; extend as needed).
     */
    public static function with(StudioElement $e, array $o): StudioElement
    {
        $k = fn (string $key, $default) => array_key_exists($key, $o) ? $o[$key] : $default;

        return new StudioElement(
            id:           $e->id,
            role:         $e->role,
            text:         $k('text', $e->text),
            visualTags:   $e->visualTags,
            semanticTags: $e->semanticTags,
            bbox:         $e->bbox,
            parent:       $e->parent,
            children:     $e->children,
            layer:        $e->layer,
            visible:      $k('visible', $e->visible),
            locked:       $k('locked', $e->locked),
            selected:     $k('selected', $e->selected),
            style:        $k('style', $e->style),
            capabilities: $e->capabilities,
            relationships: $e->relationships,
            state:        $e->state,
            confidence:   $e->confidence,
        );
    }

    /** Clone $e setting a single style property. */
    public static function withStyle(StudioElement $e, string $property, mixed $value): StudioElement
    {
        $style = $e->style;
        $style[$property] = $value;

        return self::with($e, ['style' => $style]);
    }

    /** Read a field by path: 'text' | 'visible' | 'locked' | 'selected' | 'role' | 'style.<key>'. */
    public static function get(StudioElement $e, string $path): mixed
    {
        if (str_starts_with($path, 'style.')) {
            return $e->style[substr($path, 6)] ?? null;
        }

        return match ($path) {
            'text'     => $e->text,
            'visible'  => $e->visible,
            'locked'   => $e->locked,
            'selected' => $e->selected,
            'role'     => $e->role,
            default    => null,
        };
    }

    /** Renderer-neutral snapshot of the mutable-relevant fields. */
    public static function snapshot(StudioElement $e): array
    {
        return [
            'id'       => $e->id,
            'role'     => $e->role,
            'text'     => $e->text,
            'style'    => $e->style,
            'visible'  => $e->visible,
            'locked'   => $e->locked,
            'selected' => $e->selected,
        ];
    }
}
