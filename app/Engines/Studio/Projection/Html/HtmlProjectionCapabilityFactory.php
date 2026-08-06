<?php

namespace App\Engines\Studio\Projection\Html;

use App\Engines\Studio\Projection\ProjectionCapability;

/**
 * STUDIO888 · Projection/Html — capability advertisements per document form.
 *
 * Raw HTML supports text + the six element-scoped style properties + hide/show.
 * Structured template documents support text only (see StructuredFieldsDocument).
 * No geometry, media, collaboration, or undo — Phase 4B is server-side text/style/state.
 */
final class HtmlProjectionCapabilityFactory
{
    private const STYLE_PROPERTIES = ['color', 'background-color', 'opacity', 'font-size', 'font-weight', 'text-align'];

    public static function forRaw(): ProjectionCapability
    {
        return new ProjectionCapability(
            targetTypes: ['*'],
            operations: ['replace_text', 'append_text', 'prepend_text', 'set_style', 'hide', 'show'],
            properties: self::STYLE_PROPERTIES,
            fields: ['text', 'visible', 'src'],
            mediaTypes: ['image'],
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

    public static function forStructured(): ProjectionCapability
    {
        return new ProjectionCapability(
            targetTypes: ['*'],
            operations: ['replace_text', 'append_text', 'prepend_text'],
            properties: [],
            fields: ['text'],
            mediaTypes: [],
            supportsGeometry: false,
            supportsStyling: false,
            supportsStateChange: false,
            supportsBatching: true,
            supportsTransactions: true,
            supportsVerification: true,
            supportsUndoIntegration: false,
            supportsCollaboration: false,
            supportsVersioning: true,
        );
    }
}
