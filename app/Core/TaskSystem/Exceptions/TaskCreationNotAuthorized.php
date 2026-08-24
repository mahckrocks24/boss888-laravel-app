<?php

namespace App\Core\TaskSystem\Exceptions;

/**
 * TASK_CREATION_NOT_AUTHORIZED — an EXPECTED governance denial.
 *
 * TaskService refusing to create work is a correct outcome, not a fault. It was
 * being signalled with a generic RuntimeException, which left callers with two
 * bad options: catch \Throwable and swallow real faults alongside it, or match
 * on the message string. Both were in use, and one call site caught nothing at
 * all — so a refusal that protected the owner's data became a 500 on the chat
 * surface instead.
 *
 * A distinct type lets a caller handle the denial precisely and let genuine
 * failures keep propagating.
 *
 * It extends RuntimeException deliberately: existing `catch (\Throwable)`
 * handlers keep working unchanged, so introducing this cannot regress the
 * callers that were already safe.
 *
 * The public message is deliberately plain. The structured context is for logs
 * and tests, never for the customer.
 */
class TaskCreationNotAuthorized extends \RuntimeException
{
    /** Denials are never retryable — retrying cannot manufacture authority. */
    public const RETRYABLE = false;

    public function __construct(
        public readonly string $reason,
        public readonly ?string $action = null,
        public readonly array $context = [],
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }

    /** Structured context for logging. Never returned to a customer verbatim. */
    public function toLog(): array
    {
        return array_merge([
            'code'             => 'TASK_CREATION_NOT_AUTHORIZED',
            'reason'           => $this->reason,
            'action'           => $this->action,
            'retryable'        => self::RETRYABLE,
        ], $this->context);
    }
}