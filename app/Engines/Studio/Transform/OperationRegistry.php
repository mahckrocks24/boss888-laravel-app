<?php

namespace App\Engines\Studio\Transform;

use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;

/**
 * STUDIO888 · AI Transformation Engine — default Operation Registry.
 *
 * Holds every operation the engine knows about, each as an immutable
 * OperationDefinition. The default set is declared as DATA (see defaults()),
 * not as branching logic — there is no switch statement. New operations are
 * added by registering a definition (self-registration in later phases), never
 * by editing control flow.
 *
 * This registry is the vocabulary only. It does NOT resolve targets, execute,
 * or verify — those are separate subsystems (Selection Engine, Executor,
 * Verification) delivered in later phases behind their own interfaces.
 */
final class OperationRegistry implements OperationRegistryInterface
{
    private const SCHEMA_VERSION = 1;

    /** @var array<string, OperationDefinition> */
    private array $definitions = [];

    public function __construct(bool $withDefaults = true)
    {
        if ($withDefaults) {
            foreach (self::defaults() as $def) {
                $this->register($def);
            }
        }
    }

    public function register(OperationDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): ?OperationDefinition
    {
        return $this->definitions[$type] ?? null;
    }

    public function all(): array
    {
        return $this->definitions;
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * The canonical Phase-1 operation vocabulary.
     *
     * Availability here is the ONLY source of truth for what the AI may plan.
     * Media that Studio does not yet execute (video/audio) is declared but
     * marked MATURITY_UNAVAILABLE so it is discoverable, self-documenting, and
     * unplannable — never invented by a prompt.
     *
     * @return OperationDefinition[]
     */
    public static function defaults(): array
    {
        $D = OperationDefinition::class;

        // Shared style-property allowlist for element-scoped styling.
        $styleProps = [
            'color', 'background-color', 'border-color', 'border-width', 'border-radius',
            'opacity', 'font-size', 'font-family', 'font-weight', 'line-height',
            'letter-spacing', 'text-align', 'text-decoration', 'text-transform',
            'box-shadow', 'fill', 'stroke',
        ];

        return [
            // ---- TEXT ----
            new $D(
                type: 'set_text', category: 'text', valueKind: OperationDefinition::KIND_TEXT,
                supportedTargets: ['text', 'headline', 'body', 'cta', 'stat', 'label'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),

            // ---- STYLE (element-scoped; property-bearing) ----
            new $D(
                type: 'set_style', category: 'style',
                allowedProperties: $styleProps,
                valueKind: OperationDefinition::KIND_COLOR, // colour is the default kind; validator keys off property below in later phases
                supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),
            new $D(
                type: 'set_typography', category: 'style',
                allowedProperties: ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-transform'],
                valueKind: OperationDefinition::KIND_TEXT,
                supportedTargets: ['text', 'headline', 'body', 'cta', 'stat', 'label'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_LIGHT,
            ),
            new $D(
                type: 'set_opacity', category: 'style', allowedProperties: ['opacity'],
                valueKind: OperationDefinition::KIND_NUMBER, range: ['min' => 0.0, 'max' => 1.0, 'units' => []],
                supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),
            new $D(
                type: 'set_shadow', category: 'style', allowedProperties: ['box-shadow'],
                valueKind: OperationDefinition::KIND_ENUM, enum: ['none', 'sm', 'md', 'lg', 'xl'],
                supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),

            // ---- GEOMETRY ----
            new $D(
                type: 'move', category: 'geometry', valueKind: OperationDefinition::KIND_LENGTH,
                range: ['min' => -10000.0, 'max' => 10000.0, 'units' => ['px', '%']],
                supportedTargets: ['*'], supportsAnimation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_LIGHT,
            ),
            new $D(
                type: 'resize', category: 'geometry', valueKind: OperationDefinition::KIND_LENGTH,
                range: ['min' => 0.0, 'max' => 10000.0, 'units' => ['px', '%']],
                supportedTargets: ['*'], supportsAnimation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_LIGHT,
            ),
            new $D(
                type: 'rotate', category: 'geometry', valueKind: OperationDefinition::KIND_ANGLE,
                range: ['min' => -360.0, 'max' => 360.0, 'units' => ['deg']],
                supportedTargets: ['*'], supportsAnimation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_LIGHT,
            ),
            new $D(
                type: 'align', category: 'geometry', valueKind: OperationDefinition::KIND_ENUM,
                enum: ['left', 'center', 'right', 'top', 'middle', 'bottom'],
                supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_LIGHT,
            ),

            // ---- LAYERS ----
            new $D(
                type: 'layer_order', category: 'layer', valueKind: OperationDefinition::KIND_ENUM,
                enum: ['front', 'back', 'forward', 'backward'], supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),
            new $D(
                type: 'layer_visibility', category: 'layer', valueKind: OperationDefinition::KIND_ENUM,
                enum: ['show', 'hide'], supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),
            new $D(
                type: 'layer_lock', category: 'layer', valueKind: OperationDefinition::KIND_ENUM,
                enum: ['lock', 'unlock'], collaborative: true, supportedTargets: ['*'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),

            // ---- MEDIA (media-agnostic; "Replace Media", not "Replace Image") ----
            new $D(
                type: 'replace_media', category: 'media', valueKind: OperationDefinition::KIND_MEDIA,
                supportedMedia: ['image'],            // video/audio become available when their plugins land
                supportedTargets: ['image', 'hero', 'logo', 'media'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_MEDIUM,
            ),
            new $D(
                type: 'crop_media', category: 'media', valueKind: OperationDefinition::KIND_ENUM,
                enum: ['square', '4:5', '16:9', '3:2', 'original'],
                supportedMedia: ['image'], supportedTargets: ['image', 'hero', 'media'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_MEDIUM,
            ),
            new $D(
                type: 'generate_image', category: 'media', valueKind: OperationDefinition::KIND_TEXT,
                supportedMedia: ['image'], supportedTargets: ['image', 'hero', 'media'],
                requiresCreative888: true, affectsBilling: true, requiresConfirmation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_EXPENSIVE,
            ),
            new $D(
                type: 'remove_background', category: 'media', valueKind: OperationDefinition::KIND_NONE,
                supportedMedia: ['image'], supportedTargets: ['image', 'logo', 'media'],
                affectsBilling: true, requiresConfirmation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_HEAVY,
            ),

            // ---- CANVAS / BRAND ----
            new $D(
                type: 'apply_palette', category: 'canvas', valueKind: OperationDefinition::KIND_NONE,
                supportedTargets: ['canvas'], requiresConfirmation: true, requiresCreative888: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_LIGHT,
            ),
            new $D(
                type: 'set_canvas_background', category: 'canvas',
                allowedProperties: ['background-color'], valueKind: OperationDefinition::KIND_COLOR,
                supportedTargets: ['canvas'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ),
            new $D(
                type: 'apply_brand', category: 'brand', valueKind: OperationDefinition::KIND_NONE,
                supportedTargets: ['canvas'], requiresCreative888: true, requiresConfirmation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_MEDIUM,
            ),

            // ---- DECLARED-BUT-UNAVAILABLE (discoverable, self-documenting, unplannable) ----
            new $D(
                type: 'generate_video', category: 'media', valueKind: OperationDefinition::KIND_TEXT,
                maturity: OperationDefinition::MATURITY_UNAVAILABLE, enabled: false,
                supportedMedia: ['video'], supportedTargets: ['media'],
                requiresCreative888: true, affectsBilling: true, supportsAnimation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_EXPENSIVE,
            ),
            new $D(
                type: 'trim_video', category: 'media', valueKind: OperationDefinition::KIND_LENGTH,
                maturity: OperationDefinition::MATURITY_UNAVAILABLE, enabled: false,
                range: ['min' => 0.0, 'max' => 86400.0, 'units' => ['s']],
                supportedMedia: ['video'], supportedTargets: ['media'], supportsAnimation: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_HEAVY,
            ),
            new $D(
                type: 'replace_audio', category: 'media', valueKind: OperationDefinition::KIND_MEDIA,
                maturity: OperationDefinition::MATURITY_UNAVAILABLE, enabled: false,
                supportedMedia: ['audio'], supportedTargets: ['media'],
                estimatedComplexity: OperationDefinition::COMPLEXITY_MEDIUM,
            ),
        ];
    }
}
