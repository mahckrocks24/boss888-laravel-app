<?php

namespace App\Engines\Studio\Transform;

/**
 * STUDIO888 · AI Transformation Engine — Operation Definition.
 *
 * The immutable, single-source-of-truth descriptor for one operation type.
 * ALL operation metadata lives here and nowhere else: the Capability Registry
 * projects its advertisement from these fields, and the Validator reads its
 * value rules from these fields. Nothing is duplicated or hand-mirrored.
 *
 * Deliberately future-proof: the media / target / animation / collaboration
 * fields already model Images, Video, Audio, Animation, Subtitles, 3D assets,
 * collaborative editing, automation, mobile and voice editing, so adding those
 * capabilities later means registering new definitions — never a redesign.
 */
final class OperationDefinition
{
    /** Maturity levels, ordered from "not exposed" to "fully exposed". */
    public const MATURITY_UNAVAILABLE  = 'unavailable';
    public const MATURITY_EXPERIMENTAL = 'experimental';
    public const MATURITY_BETA         = 'beta';
    public const MATURITY_STABLE       = 'stable';

    /**
     * Estimated execution complexity (NOT billing). Drives later execution
     * scheduling, progress UI, batch optimization, background queues, mobile
     * streaming, and future distributed execution. Declared in metadata now,
     * before operations proliferate.
     */
    public const COMPLEXITY_INSTANT   = 'instant';
    public const COMPLEXITY_LIGHT     = 'light';
    public const COMPLEXITY_MEDIUM    = 'medium';
    public const COMPLEXITY_HEAVY     = 'heavy';
    public const COMPLEXITY_EXPENSIVE = 'expensive';

    /** Value kinds the Validator knows how to check. */
    public const KIND_NONE    = 'none';
    public const KIND_COLOR   = 'color';
    public const KIND_LENGTH  = 'length';   // number + unit (px/%/rem/em)
    public const KIND_NUMBER  = 'number';   // unitless number
    public const KIND_PERCENT = 'percent';  // 0..100 (%)
    public const KIND_ANGLE   = 'angle';    // number + deg
    public const KIND_TEXT    = 'text';     // free text (sanitised)
    public const KIND_ENUM    = 'enum';     // one of $enum
    public const KIND_MEDIA   = 'media';    // media reference (asset id / studio-hosted ref)

    /**
     * @param string   $type                 canonical operation id (e.g. "set_style")
     * @param string   $category             text|style|geometry|layer|media|canvas|brand
     * @param string[] $allowedProperties    for property-bearing ops (e.g. set_style); [] otherwise
     * @param string   $valueKind            one of KIND_*
     * @param string[] $enum                 permitted values when $valueKind === KIND_ENUM
     * @param array|null $range              ['min'=>float,'max'=>float,'units'=>string[]] for numeric kinds
     * @param string   $maturity             one of MATURITY_*
     * @param bool     $enabled              master on/off independent of maturity
     * @param string[] $supportedMedia       none|image|video|audio|animation|subtitles|model3d
     * @param string[] $supportedTargets     node roles/types this op may target, or ['*']
     * @param string[] $requiredCapabilities other operation ids this op depends on
     * @param bool     $requiresCreative888  needs Creative888 creative direction to plan
     * @param bool     $requiresConfirmation destructive/bulk — needs explicit user confirmation
     * @param bool     $affectsBilling       consumes credits (e.g. generation)
     * @param bool     $reversible           can be undone
     * @param bool     $historySafe          safe to record in grouped history
     * @param bool     $supportsBatching     may appear in a multi-op batch
     * @param bool     $collaborative        safe under concurrent multiplayer editing
     * @param bool     $supportsAnimation    can participate in an animation timeline
     * @param string   $estimatedComplexity  one of COMPLEXITY_* — execution cost hint, not billing
     * @param int      $schemaVersion        op-contract schema this definition targets
     * @param string   $futureCompat         forward-compat marker (e.g. "v1")
     */
    public function __construct(
        public readonly string $type,
        public readonly string $category,
        public readonly array  $allowedProperties = [],
        public readonly string $valueKind = self::KIND_NONE,
        public readonly array  $enum = [],
        public readonly ?array $range = null,
        public readonly string $maturity = self::MATURITY_STABLE,
        public readonly bool   $enabled = true,
        public readonly array  $supportedMedia = ['none'],
        public readonly array  $supportedTargets = ['*'],
        public readonly array  $requiredCapabilities = [],
        public readonly bool   $requiresCreative888 = false,
        public readonly bool   $requiresConfirmation = false,
        public readonly bool   $affectsBilling = false,
        public readonly bool   $reversible = true,
        public readonly bool   $historySafe = true,
        public readonly bool   $supportsBatching = true,
        public readonly bool   $collaborative = true,
        public readonly bool   $supportsAnimation = false,
        public readonly string $estimatedComplexity = self::COMPLEXITY_LIGHT,
        public readonly int    $schemaVersion = 1,
        public readonly string $futureCompat = 'v1',
    ) {
    }

    /** Available = a real, exposed capability the AI may plan with. */
    public function isAvailable(): bool
    {
        return $this->enabled && $this->maturity !== self::MATURITY_UNAVAILABLE;
    }

    /** Property-bearing operations constrain which properties may be set. */
    public function hasPropertyConstraint(): bool
    {
        return $this->allowedProperties !== [];
    }

    public function allowsProperty(string $property): bool
    {
        return in_array($property, $this->allowedProperties, true);
    }
}
