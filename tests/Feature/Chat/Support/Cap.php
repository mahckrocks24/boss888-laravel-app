<?php

namespace Tests\Feature\Chat\Support;

/** Capability keys a surface may declare. Clause V-03. */
final class Cap
{
    public const SEND              = 'send';
    public const PERSIST_USER      = 'persist_user_message';
    public const PERSIST_ASSISTANT = 'persist_assistant_message';
    public const HISTORY           = 'history';
    public const PAGINATION        = 'pagination';
    public const ACK               = 'acknowledgement';
    public const TWO_PHASE         = 'two_phase_delivery';
    public const BACKGROUND_REFRESH= 'background_refresh';
    public const READ_STATE        = 'read_state';
    public const READ_ALL          = 'mark_all_read';
    public const UNREAD_AGGREGATE  = 'unread_aggregate';
    public const CREDITS           = 'credit_metering';
    public const CREDIT_RESERVE    = 'credit_reserve_release';
    public const STRUCTURED_ERROR  = 'structured_error';
    public const IDEMPOTENCY       = 'idempotency';
    public const CORRELATION       = 'correlation_id';
    public const PROVIDER_ATTR     = 'provider_model_attribution';
    public const ATTACHMENTS       = 'attachments';
    public const TOOL_CALLS        = 'tool_calls';
    public const APPROVALS         = 'approvals';
    public const HTTP_PROBE        = 'http_probe';   // adapter can drive a real request
    public const TENANCY_COLUMN    = 'tenancy_column'; // store carries workspace_id
}
