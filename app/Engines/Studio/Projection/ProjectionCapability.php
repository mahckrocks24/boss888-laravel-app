<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — renderer capability advertisement.
 *
 * A live renderer declares what it can project. The orchestration layer negotiates
 * against this BEFORE projecting, so unsupported operations are rejected up front
 * rather than blindly attempted. No renderer is assumed to support every Studio
 * operation.
 */
final class ProjectionCapability
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param string[] $targetTypes    element roles the renderer can target, or ['*']
     * @param string[] $operations     operation types it can project (replace_text, set_style, hide, ...)
     * @param string[] $properties     style properties it can set (color, opacity, ...)
     * @param string[] $fields         non-style field paths it can set (text, visible, locked, ...)
     * @param string[] $mediaTypes     media types it can project (image, video, ...)
     */
    public function __construct(
        public readonly array $targetTypes = ['*'],
        public readonly array $operations = [],
        public readonly array $properties = [],
        public readonly array $fields = [],
        public readonly array $mediaTypes = [],
        public readonly bool  $supportsGeometry = false,
        public readonly bool  $supportsStyling = false,
        public readonly bool  $supportsStateChange = false,
        public readonly bool  $supportsBatching = false,
        public readonly bool  $supportsTransactions = false,
        public readonly bool  $supportsVerification = false,
        public readonly bool  $supportsUndoIntegration = false,
        public readonly bool  $supportsCollaboration = false,
        public readonly bool  $supportsVersioning = false,
    ) {
    }

    public function supportsTarget(string $role): bool
    {
        return $this->targetTypes === ['*'] || in_array($role, $this->targetTypes, true);
    }

    public function supportsOperation(string $type): bool
    {
        return in_array($type, $this->operations, true);
    }

    public function supportsProperty(string $property): bool
    {
        return $this->supportsStyling && in_array($property, $this->properties, true);
    }

    /** Whether a full field path ('text', 'visible', 'style.color') can be projected. */
    public function supportsField(string $path): bool
    {
        if (str_starts_with($path, 'style.')) {
            return $this->supportsProperty(substr($path, 6));
        }
        if ($path === 'visible' || $path === 'locked') {
            return $this->supportsStateChange && in_array($path, $this->fields, true);
        }

        return in_array($path, $this->fields, true);
    }

    public function toArray(): array
    {
        return [
            'schema_version'            => self::SCHEMA_VERSION,
            'target_types'              => $this->targetTypes,
            'operations'                => $this->operations,
            'properties'                => $this->properties,
            'fields'                    => $this->fields,
            'media_types'               => $this->mediaTypes,
            'supports_geometry'         => $this->supportsGeometry,
            'supports_styling'          => $this->supportsStyling,
            'supports_state_change'     => $this->supportsStateChange,
            'supports_batching'         => $this->supportsBatching,
            'supports_transactions'     => $this->supportsTransactions,
            'supports_verification'     => $this->supportsVerification,
            'supports_undo_integration' => $this->supportsUndoIntegration,
            'supports_collaboration'    => $this->supportsCollaboration,
            'supports_versioning'       => $this->supportsVersioning,
        ];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(
            targetTypes: ArrayGuard::strList($a, 'target_types'),
            operations: ArrayGuard::strList($a, 'operations'),
            properties: ArrayGuard::strList($a, 'properties'),
            fields: ArrayGuard::strList($a, 'fields'),
            mediaTypes: ArrayGuard::strList($a, 'media_types'),
            supportsGeometry: ArrayGuard::bool($a, 'supports_geometry'),
            supportsStyling: ArrayGuard::bool($a, 'supports_styling'),
            supportsStateChange: ArrayGuard::bool($a, 'supports_state_change'),
            supportsBatching: ArrayGuard::bool($a, 'supports_batching'),
            supportsTransactions: ArrayGuard::bool($a, 'supports_transactions'),
            supportsVerification: ArrayGuard::bool($a, 'supports_verification'),
            supportsUndoIntegration: ArrayGuard::bool($a, 'supports_undo_integration'),
            supportsCollaboration: ArrayGuard::bool($a, 'supports_collaboration'),
            supportsVersioning: ArrayGuard::bool($a, 'supports_versioning'),
        );
    }

    /** A reasonable default for the reference adapter: safe text/style/state. */
    public static function referenceDefault(): self
    {
        return new self(
            targetTypes: ['*'],
            operations: ['replace_text', 'append_text', 'prepend_text', 'set_style', 'hide', 'show', 'lock', 'unlock'],
            properties: ['color', 'background-color', 'opacity', 'font-size', 'font-weight', 'text-align'],
            fields: ['text', 'visible', 'locked'],
            mediaTypes: [],
            supportsGeometry: false,
            supportsStyling: true,
            supportsStateChange: true,
            supportsBatching: true,
            supportsTransactions: true,
            supportsVerification: true,
            supportsUndoIntegration: false,
            supportsCollaboration: false,
            supportsVersioning: true,
        );
    }
}
