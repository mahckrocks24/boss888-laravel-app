<?php

namespace App\Engines\Studio\Runtime\Events;

/**
 * STUDIO888 · Runtime — the runtime event vocabulary.
 *
 * The internal coordination signals a Studio session emits. Collaboration is
 * NOT implemented — only the event model. Listeners are in-process callables;
 * there is no transport, no browser, no persistence.
 */
final class RuntimeEventType
{
    public const DOCUMENT_LOADED     = 'document_loaded';
    public const RENDERER_ATTACHED   = 'renderer_attached';
    public const RENDERER_DETACHED   = 'renderer_detached';
    public const SELECTION_CHANGED   = 'selection_changed';
    public const VIEWPORT_CHANGED    = 'viewport_changed';
    public const TRANSACTION_STARTED = 'transaction_started';
    public const OPERATION_STARTED   = 'operation_started';
    public const PROJECTION_APPLIED  = 'projection_applied';
    public const PROJECTION_FAILED   = 'projection_failed';
    public const OPERATION_COMPLETED = 'operation_completed';
    public const HISTORY_COMMITTED   = 'history_committed';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::DOCUMENT_LOADED, self::RENDERER_ATTACHED, self::RENDERER_DETACHED,
            self::SELECTION_CHANGED, self::VIEWPORT_CHANGED, self::TRANSACTION_STARTED,
            self::OPERATION_STARTED, self::PROJECTION_APPLIED, self::PROJECTION_FAILED,
            self::OPERATION_COMPLETED, self::HISTORY_COMMITTED,
        ];
    }
}
