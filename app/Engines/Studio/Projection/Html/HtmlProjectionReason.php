<?php

namespace App\Engines\Studio\Projection\Html;

/**
 * STUDIO888 · Projection/Html — HTML-adapter-specific failure reason codes.
 *
 * Complements the shared App\Engines\Studio\Projection\ProjectionResult::R_*
 * codes with reasons unique to markup projection. Stable identifiers, not copy.
 */
final class HtmlProjectionReason
{
    public const AMBIGUOUS_TARGET   = 'ambiguous_target';   // data-field appears more than once
    public const NON_LEAF_TARGET    = 'non_leaf_target';    // text op on an element containing child markup
    public const MALFORMED_DOCUMENT = 'malformed_document';
    public const STRUCTURED_STYLE_UNSUPPORTED = 'structured_style_unsupported';
}
