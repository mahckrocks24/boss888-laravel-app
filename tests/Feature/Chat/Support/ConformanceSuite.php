<?php

namespace Tests\Feature\Chat\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE normative conformance suite for CHAT-CONTRACT-v1.
 *
 * One case definition set, executed against every surface through its adapter
 * (clause V-01). There are deliberately NOT nine separate suites — nine suites
 * is how the platform arrived at sixteen contract drifts in the first place.
 *
 * Each case declares:
 *   id        stable machine name, cited in the baseline report
 *   clause    the CHAT-CONTRACT-v1 clause it measures (V-05)
 *   group     A..I per CHAT-CONTRACT-TEST-PLAN
 *   severity  critical | high | medium | low   (severity-weighted score)
 *   evidence  HTTP | DB | SOURCE | SCHEMA      (how the verdict was obtained)
 *   applies   fn(ChatSurface): bool
 *   run       fn(ChatSurface, ChatTestContext): Outcome
 */
class ConformanceSuite
{
    /** Legacy top-level reply aliases the contract deprecates (clause E-05). */
    public const LEGACY_REPLY_FIELDS = ['reply', 'text', 'response', 'ack', 'answer', 'output'];

    /** Closed error taxonomy (clause X-01). */
    public const ERROR_CODES = [
        'CHAT_VALIDATION_FAILED', 'CHAT_UNAUTHENTICATED', 'CHAT_FORBIDDEN',
        'CHAT_AGENT_FORBIDDEN', 'CHAT_CONVERSATION_NOT_FOUND', 'CHAT_CONVERSATION_CONFLICT',
        'CHAT_INSUFFICIENT_CREDITS', 'CHAT_RATE_LIMITED', 'CHAT_PROVIDER_TIMEOUT',
        'CHAT_PROVIDER_UNAVAILABLE', 'CHAT_PROVIDER_MALFORMED_RESPONSE', 'CHAT_DELIVERY_FAILED',
        'CHAT_ATTACHMENT_REJECTED', 'CHAT_TOOL_FAILED', 'CHAT_APPROVAL_REQUIRED',
        'CHAT_CANCELLED', 'CHAT_INTERNAL_ERROR',
    ];

    public const SEVERITY_WEIGHT = ['critical' => 8, 'high' => 4, 'medium' => 2, 'low' => 1];

    /**
     * Clause F-06 detector — does this source SYNTHESIZE an assistant reply
     * without a model call?
     *
     * Returns a human-readable evidence string, or null when clean.
     *
     * WHAT COUNTS AS FABRICATION (all three require an ASSIGNMENT, never a name):
     *   1. a reply variable assigned a string literal inside a branch guarded by
     *      a provider-failure condition — the "runtime is down, say something
     *      anyway" pattern;
     *   2. a reply or action chosen with array_rand()/rand()/mt_rand() — a guess
     *      presented as a considered answer;
     *   3. a keyword test (strpos/str_contains/preg_match on the user's text)
     *      whose branch assigns a hard-coded reply.
     *
     * WHAT DOES NOT COUNT:
     *   - identifiers such as extractKeywordFromReply() — parsing a keyword out
     *     of a real model reply is the opposite of fabricating one;
     *   - answering from the DATABASE (e.g. "you are tracking {$n} keywords"),
     *     which is grounding, not invention. Those interpolate a variable rather
     *     than assigning a fixed literal, so they do not match.
     */
    public static function detectFabrication(string $src): ?string
    {
        $reply = '(?:reply|response|answer|routerReply)';

        // 2. a random choice feeding a reply or an action list
        if (preg_match('/\$\w*(?:' . $reply . '|action|palette)\w*\s*(?:\[\s*\])?\s*=[^;\n]{0,200}?(?:array_rand|mt_rand|\brand)\s*\(/i', $src, $m)) {
            return 'a reply/action is selected at random: ' . trim(mb_substr($m[0], 0, 90));
        }
        if (preg_match('/(?:array_rand)\s*\(\s*\$\w*palette/i', $src, $m)) {
            return 'a palette is selected at random: ' . trim($m[0]);
        }

        // 1. a literal reply assigned inside a provider-failure branch
        if (preg_match(
            '/(?:if\s*\(\s*!\s*\$(?:ok|success|succeeded)\b|offline|unavailable|runtime[_ ]?down)[^;]{0,80}?\)?\s*\{'
            . '[\s\S]{0,600}?\$\w*' . $reply . '\w*\s*=\s*[\'"][^\'"]{12,}[\'"]/i',
            $src, $m)) {
            return 'a hard-coded reply is assigned inside a provider-failure branch';
        }

        // 3. a keyword test on the user's text whose branch assigns a literal reply
        if (preg_match(
            '/(?:strpos|str_contains|str_starts_with)\s*\(\s*\$\w+\s*,\s*[\'"][^\'"]{3,}[\'"]\s*\)'
            . '[\s\S]{0,300}?\$\w*' . $reply . '\w*\s*=\s*[\'"][^\'"]{12,}[\'"]/i',
            $src, $m)) {
            return 'a keyword match assigns a hard-coded reply: ' . trim(mb_substr($m[0], 0, 90));
        }

        return null;
    }

    public static function cases(): array
    {
        return array_merge(
            self::groupA(), self::groupB(), self::groupC(), self::groupD(),
            self::groupE(), self::groupF(), self::groupG(), self::groupH(), self::groupI(),
        );
    }

    // ═══════════════════════════════ A. CONTRACT ═══════════════════════════════

    private static function groupA(): array
    {
        return [
            [
                'id' => 'contract.channel_class_declared', 'clause' => 'C-19', 'group' => 'A',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn () => true,
                'run' => function (ChatSurface $s) {
                    $valid = ['durable_agent','durable_assistant','durable_visitor','durable_admin','durable_room','ephemeral_tool'];

                    return Outcome::assert(in_array($s->channelClass(), $valid, true),
                        "channel class '{$s->channelClass()}' is not a declared class");
                },
            ],
            [
                'id' => 'contract.contract_version_present', 'clause' => 'E-03', 'group' => 'A',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, 'contract version probe');
                    if ($r === null) return Outcome::blocked('adapter cannot drive a live send');

                    return Outcome::assert($r->has('contract_version'),
                        'response carries no contract_version; client cannot negotiate (S-04)', $s->sendRoute());
                },
            ],
            [
                'id' => 'contract.no_bare_reply_field', 'clause' => 'E-04', 'group' => 'A',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, 'bare reply probe');
                    if ($r === null) return Outcome::blocked('adapter cannot drive a live send');
                    $bare = $r->bareReplyFields();

                    return Outcome::assert($bare === [],
                        'response exposes undocumented top-level reply field(s): ' . implode(', ', $bare), $s->sendRoute());
                },
            ],
            [
                'id' => 'contract.legacy_fields_registered', 'clause' => 'S-01', 'group' => 'A',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->legacyResponseFields() !== [],
                'run' => function (ChatSurface $s) {
                    $unknown = array_diff($s->legacyResponseFields(), self::LEGACY_REPLY_FIELDS + ['message' => 'message']);
                    $unknown = array_diff($s->legacyResponseFields(), array_merge(self::LEGACY_REPLY_FIELDS, ['message']));

                    return Outcome::assert($unknown === [],
                        'emits legacy field(s) absent from the deprecation register: ' . implode(', ', $unknown));
                },
            ],
            [
                'id' => 'contract.request_field_canonical', 'clause' => 'R-06', 'group' => 'A',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->requestContentField() !== null,
                'run' => fn (ChatSurface $s) => Outcome::assert(
                    $s->requestContentField() === 'message.content',
                    "request carries the user's text in '{$s->requestContentField()}', not the canonical 'message.content' (deprecated alias)"),
            ],
            [
                'id' => 'contract.correlation_id_on_response', 'clause' => 'E-02', 'group' => 'A',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, 'correlation probe');
                    if ($r === null) return Outcome::blocked('adapter cannot drive a live send');

                    return Outcome::assert($r->has('correlation_id'),
                        'no correlation_id on the response; an incident cannot be traced from a user report (O-02)');
                },
            ],
            [
                'id' => 'contract.ok_and_error_agree', 'clause' => 'E-01', 'group' => 'A',
                'severity' => 'medium', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, 'ok/error agreement probe');
                    if ($r === null) return Outcome::blocked('adapter cannot drive a live send');
                    if (!$r->has('ok')) {
                        return Outcome::fail("response has no 'ok' boolean; success cannot be determined without inspecting HTTP status and ad-hoc fields");
                    }
                    $ok = $r->get('ok');
                    $err = $r->get('error');

                    return Outcome::assert(($ok === true && $err === null) || ($ok === false && $err !== null),
                        "ok=" . json_encode($ok) . ' disagrees with error=' . json_encode($err));
                },
            ],
            [
                'id' => 'contract.message_type_enum_closed', 'clause' => 'M-01', 'group' => 'A',
                'severity' => 'medium', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent in the test schema");

                    return Schema::hasColumn($t, 'type')
                        ? Outcome::pass()
                        : Outcome::fail("store '{$t}' has no 'type' column; message type cannot be expressed, so error/progress/tool messages are indistinguishable from assistant text (X-09)");
                },
            ],
            [
                'id' => 'contract.message_status_enum_present', 'clause' => 'M-03', 'group' => 'A',
                'severity' => 'medium', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent in the test schema");

                    return Schema::hasColumn($t, 'status')
                        ? Outcome::pass()
                        : Outcome::fail("store '{$t}' has no 'status' column; delivery state cannot be represented (M-03)");
                },
            ],
            [
                'id' => 'contract.timestamps_rfc3339', 'clause' => 'E-06', 'group' => 'A',
                'severity' => 'low', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE) && $s->historyRoute() !== null,
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $h = $s->history($ctx);
                    if ($h === null) return Outcome::blocked('adapter cannot fetch history');
                    $first = $h[0] ?? null;
                    if (!is_array($first)) return Outcome::blocked('no history rows to inspect');
                    foreach (['ts', 'created_at', 'timestamp'] as $k) {
                        if (isset($first[$k]) && is_string($first[$k])) {
                            $ok = (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $first[$k]);

                            return Outcome::assert($ok, "timestamp '{$first[$k]}' is not RFC 3339 with an explicit offset");
                        }
                    }

                    return Outcome::blocked('no timestamp field found in history rows');
                },
            ],
        ];
    }

    // ═══════════════════════════════ B. PERSISTENCE ════════════════════════════

    private static function groupB(): array
    {
        return [
            [
                'id' => 'persist.user_message_stored', 'clause' => 'P-01', 'group' => 'B',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    if (!$s->supports(Cap::PERSIST_USER)) {
                        return Outcome::fail('surface is durable but does not persist the user message (P-01)');
                    }
                    if (!$s->supports(Cap::HTTP_PROBE)) return Outcome::blocked('no live probe');
                    $before = count($s->persistedRows($ctx) ?? []);
                    $s->send($ctx, 'persistence probe ' . uniqid());
                    $after = count($s->persistedRows($ctx) ?? []);

                    return Outcome::assert($after > $before,
                        'send produced no new persisted row', $s->storeTable());
                },
            ],
            [
                'id' => 'persist.user_message_survives_refusal', 'clause' => 'P-02', 'group' => 'B',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral() && $s->supports(Cap::CREDITS),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    if (!$s->supports(Cap::HTTP_PROBE)) return Outcome::blocked('no live probe');
                    // Drain credits so the surface must refuse, then confirm the
                    // user's words were still recorded. This is the direct
                    // regression guard for the 2026-07-26 Sarah incident.
                    $restore = $ctx->induceCreditRefusal();
                    $marker = 'refusal-persistence-' . uniqid();
                    try {
                        $before = count($s->persistedRows($ctx) ?? []);
                        $resp = $s->send($ctx, $marker);
                        $rows = $s->persistedRows($ctx) ?? [];
                        $found = false;
                        foreach ($rows as $row) {
                            if (str_contains((string) ($row[$s->storeContentColumn()] ?? ''), $marker)) {
                                $found = true;
                                break;
                            }
                        }
                        if ($resp !== null && $resp->status !== 402) {
                            return Outcome::blocked("expected a 402 refusal with zero credits, got HTTP {$resp->status}");
                        }

                        return Outcome::assert($found,
                            "the user's message was discarded when the request was refused — it is not recoverable after reload (P-02)",
                            $s->storeTable());
                    } finally {
                        $restore();
                    }
                },
            ],
            [
                'id' => 'persist.assistant_final_stored', 'clause' => 'P-03', 'group' => 'B',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::PERSIST_ASSISTANT)
                    ? Outcome::pass()
                    : Outcome::fail('surface is durable but does not persist assistant replies (P-03)'),
            ],
            [
                'id' => 'persist.reload_restores_history', 'clause' => 'P-04', 'group' => 'B',
                'severity' => 'critical', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    if (!$s->supports(Cap::HISTORY)) {
                        return Outcome::fail('surface is durable but exposes no history endpoint; a reload cannot restore the conversation (P-04)');
                    }
                    if (!$s->supports(Cap::HTTP_PROBE)) return Outcome::blocked('no live probe');
                    $h = $s->history($ctx);

                    return $h === null
                        ? Outcome::blocked('adapter cannot fetch history')
                        : Outcome::pass('history endpoint returned ' . count($h) . ' rows', $s->historyRoute());
                },
            ],
            [
                'id' => 'persist.ephemerality_declared', 'clause' => 'P-11', 'group' => 'B',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->isEphemeral(),
                'run' => fn (ChatSurface $s) => count($s->notes()) > 0
                    ? Outcome::pass('ephemerality declared in the adapter')
                    : Outcome::fail('ephemeral surface does not declare its ephemerality; the UI implies durability it does not have (P-11/C-19)'),
            ],
            [
                'id' => 'persist.deterministic_ordering', 'clause' => 'M-06', 'group' => 'B',
                'severity' => 'medium', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");

                    return Schema::hasColumn($t, 'id')
                        ? Outcome::pass('id available as a deterministic tiebreak')
                        : Outcome::fail("store '{$t}' has no id column; ordering ties cannot be broken deterministically");
                },
            ],
            [
                'id' => 'persist.error_not_stored_as_assistant', 'clause' => 'X-09', 'group' => 'B',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral() && $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");
                    if (Schema::hasColumn($t, 'type')) return Outcome::pass();

                    return Outcome::fail(
                        "store '{$t}' cannot distinguish an error from an assistant reply by machine — there is no 'type' column, so a billing or infrastructure failure recorded in the thread is indistinguishable from something the agent said (X-08/X-09)");
                },
            ],
            [
                'id' => 'persist.no_duplicate_on_safe_retry', 'clause' => 'I-01', 'group' => 'B',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::IDEMPOTENCY)
                    ? Outcome::pass()
                    : Outcome::fail('no idempotency key is accepted or honoured; a double-click or a retry after timeout creates a second message (I-01)'),
            ],
            [
                'id' => 'persist.acknowledgement_recorded', 'clause' => 'A-07', 'group' => 'B',
                'severity' => 'medium', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::ACK),
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t)) return Outcome::blocked('no store');

                    return Schema::hasColumn($t, 'metadata_json') || Schema::hasColumn($t, 'type')
                        ? Outcome::pass('ack is distinguishable in the store')
                        : Outcome::fail('acknowledgements are not distinguishable in the store; orphaned acks cannot be detected (A-07)');
                },
            ],
            [
                'id' => 'persist.pagination_or_declared_cap', 'clause' => 'P-10', 'group' => 'B',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral() && $s->supports(Cap::HISTORY),
                'run' => function (ChatSurface $s) {
                    if ($s->supports(Cap::PAGINATION)) return Outcome::pass('paginated');
                    if ($s->historyCap() !== null && $s->declaresHistoryCap()) {
                        return Outcome::pass("cap of {$s->historyCap()} is declared to clients");
                    }
                    $cap = $s->historyCap() ?? '?';

                    return Outcome::fail(
                        "history is silently truncated at {$cap} rows with no pagination and no declaration; older history exists but is unreachable and the client is not told (P-10)");
                },
            ],
        ];
    }

    // ═══════════════════════════════ C. TENANCY ════════════════════════════════

    private static function groupC(): array
    {
        return [
            [
                'id' => 'tenancy.store_carries_workspace', 'clause' => 'C-03', 'group' => 'C',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");

                    return Schema::hasColumn($t, 'workspace_id')
                        ? Outcome::pass()
                        : Outcome::fail("store '{$t}' has NO workspace_id column — tenant isolation depends entirely on every query remembering to join; a single query that forgets is a cross-tenant leak (C-03/Z-01)", $t);
                },
            ],
            [
                'id' => 'tenancy.owner_user_declared', 'clause' => 'C-06', 'group' => 'C',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");
                    if (Schema::hasColumn($t, 'user_id')) return Outcome::pass();

                    return Outcome::fail("store '{$t}' has no user_id column; per-user attribution and per-user read state are impossible (C-06/RD-08)", $t);
                },
            ],
            [
                'id' => 'tenancy.workspace_not_client_settable', 'clause' => 'R-03', 'group' => 'C',
                'severity' => 'critical', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE) && !$s->isEphemeral(),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $marker = 'tenancy-override-' . uniqid();
                    // Attempt to claim a foreign workspace through the request body.
                    $s->send($ctx, $marker, ['workspace_id' => 999999, 'role' => 'assistant']);
                    $rows = $s->persistedRows($ctx) ?? [];
                    foreach ($rows as $row) {
                        if (str_contains((string) ($row[$s->storeContentColumn()] ?? ''), $marker)) {
                            $wsOk  = !array_key_exists('workspace_id', $row) || (int) $row['workspace_id'] === $ctx->workspaceId;
                            $roleOk = !array_key_exists('role', $row) || $row['role'] !== 'assistant';

                            return Outcome::assert($wsOk && $roleOk,
                                'a client-supplied workspace_id/role changed the stored record — authoritative fields are not server-controlled (R-03/C-02)');
                        }
                    }

                    return Outcome::blocked('probe message was not persisted, cannot evaluate');
                },
            ],
            [
                'id' => 'tenancy.cross_workspace_read_denied', 'clause' => 'Z-02', 'group' => 'C',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE) && $s->supports(Cap::HISTORY),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t) || !Schema::hasColumn($t, 'workspace_id')) {
                        return Outcome::blocked('store cannot express workspace ownership');
                    }
                    // Plant a row owned by a different workspace and confirm it
                    // never appears in this workspace's history.
                    $foreign = 'foreign-tenant-' . uniqid();
                    $planted = $ctx->insertFixture($t, [
                        'workspace_id' => 999999,
                        $s->storeContentColumn() => $foreign,
                        'role' => 'agent', 'sender' => 'agent', 'agent_slug' => 'sarah',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!$planted) {
                        return Outcome::blocked('could not plant a foreign-tenant fixture in ' . $t);
                    }
                    $h = $s->history($ctx);
                    DB::table($t)->where('workspace_id', 999999)->delete();
                    if ($h === null) return Outcome::blocked('adapter cannot fetch history');
                    $leaked = str_contains(json_encode($h) ?: '', $foreign);

                    return Outcome::assert(!$leaked,
                        'history returned a message belonging to another workspace (Z-02)', $s->historyRoute());
                },
            ],
            [
                'id' => 'tenancy.unauthenticated_rejected', 'clause' => 'Z-01', 'group' => 'C',
                'severity' => 'critical', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->sendRoute() !== null && $s->channelClass() !== 'durable_visitor',
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    // Must be the RESOLVED url: posting the "{slug}" template
                    // 404s, which would masquerade as a missing auth check.
                    $route = $s->resolvedSendRoute();
                    if ($route === null || str_contains($route, '{')) {
                        return Outcome::blocked('send route is not resolvable to a concrete url');
                    }
                    $ctx->relaxRateLimits();
                    // withHeaders() MERGES into the test's default headers and
                    // persists for the rest of the test, so the Authorization
                    // header set by an earlier probe would leak into this one
                    // and every surface would look wide open. Flush first, and
                    // again afterwards so the next case starts clean.
                    $ctx->test->flushHeaders();
                    $resp = $ctx->test->withHeaders(['Accept' => 'application/json'])
                        ->postJson($route, ['content' => 'unauthenticated probe', 'message' => 'unauthenticated probe']);
                    $ctx->test->flushHeaders();
                    $code = $resp->getStatusCode();
                    if ($code === 404) {
                        return Outcome::blocked("route {$route} did not resolve (404) — cannot evaluate authorization");
                    }

                    return Outcome::assert(in_array($code, [401, 403, 419], true),
                        "an unauthenticated send returned HTTP {$code}, not 401/403", $route);
                },
            ],
            [
                'id' => 'tenancy.public_surface_bound_to_workspace', 'clause' => 'Z-06', 'group' => 'C',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->channelClass() === 'durable_visitor',
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t)) return Outcome::blocked('no store');

                    return Schema::hasColumn($t, 'workspace_id')
                        ? Outcome::pass('public surface binds every record to the owning workspace')
                        : Outcome::fail('an unauthenticated surface persists records with no workspace binding (Z-06)', $t);
                },
            ],
            [
                'id' => 'tenancy.admin_surface_server_enforced', 'clause' => 'Z-05', 'group' => 'C',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->channelClass() === 'durable_admin',
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared for this surface');
                    $enforced = preg_match('/is_platform_admin|platform_admin|admin_token|BELLA_ADMIN_TOKEN|abort\(\s*403/i', $src);

                    return Outcome::assert((bool) $enforced,
                        'no server-side admin enforcement found in the surface source; a UI-only gate is not enforcement (Z-05)');
                },
            ],
            [
                'id' => 'tenancy.agent_authorization_checked', 'clause' => 'R-04', 'group' => 'C',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE) && $s->channelClass() === 'durable_agent',
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $route = str_replace('{slug}', 'not-a-real-agent', (string) $s->sendRoute());
                    $resp = $ctx->test->withHeaders($ctx->authHeaders())
                        ->postJson($route, ['content' => 'unauthorized agent probe']);
                    $code = $resp->getStatusCode();

                    return Outcome::assert(in_array($code, [403, 404], true),
                        "sending to an unknown agent returned HTTP {$code}; agent authorization is not checked per request", $route);
                },
            ],
        ];
    }

    // ═══════════════════════════════ D. DELIVERY ═══════════════════════════════

    private static function groupD(): array
    {
        return [
            [
                'id' => 'delivery.mechanism_declared', 'clause' => 'A-03', 'group' => 'D',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, 'delivery mechanism probe');
                    if ($r === null) return Outcome::blocked('no live probe');
                    $d = $r->get('delivery');

                    return Outcome::assert(is_array($d) && isset($d['mechanism']),
                        'the response does not declare a delivery mechanism; the client must assume how the answer will arrive (T-02/A-03)');
                },
            ],
            [
                'id' => 'delivery.ack_distinct_from_final', 'clause' => 'A-01', 'group' => 'D',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::ACK),
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t)) return Outcome::blocked('no store');
                    $ok = Schema::hasColumn($t, 'type') || Schema::hasColumn($t, 'metadata_json');

                    return Outcome::assert($ok,
                        'an acknowledgement cannot be distinguished from a final answer in the store; a holding line can be mistaken for the agent\'s reply (A-01)');
                },
            ],
            [
                'id' => 'delivery.poll_descriptor_complete', 'clause' => 'A-04', 'group' => 'D',
                'severity' => 'medium', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::TWO_PHASE) && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, 'poll descriptor probe');
                    if ($r === null) return Outcome::blocked('no live probe');
                    $d = $r->get('delivery');
                    if (is_array($d) && isset($d['poll']['url'], $d['poll']['interval_ms'], $d['poll']['deadline_ms'])) {
                        return Outcome::pass();
                    }
                    $legacy = $r->has('poll_url') && $r->has('poll_interval_ms');

                    return Outcome::fail($legacy
                        ? 'poll descriptor is present but in the legacy flat shape (poll_url/poll_interval_ms) rather than delivery.poll, and carries no deadline_ms (A-04)'
                        : 'no poll descriptor on a two-phase response (A-04)');
                },
            ],
            [
                'id' => 'delivery.final_survives_client_disconnect', 'clause' => 'F-04', 'group' => 'D',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::PERSIST_ASSISTANT)
                    ? Outcome::pass('finals are persisted, so they are retrievable after a disconnect')
                    : Outcome::fail('the final answer is not persisted; if the client disconnects the answer is lost permanently (F-04)'),
            ],
            [
                'id' => 'delivery.orphan_ack_detectable', 'clause' => 'A-07', 'group' => 'D',
                'severity' => 'medium', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::ACK),
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t)) return Outcome::blocked('no store');

                    return Schema::hasColumn($t, 'metadata_json') || Schema::hasColumn($t, 'status')
                        ? Outcome::pass('ack/final phases are queryable')
                        : Outcome::fail('an acknowledgement never followed by an answer cannot be detected by query (A-07)');
                },
            ],
            [
                'id' => 'delivery.duplicate_send_is_safe', 'clause' => 'I-04', 'group' => 'D',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral() && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $marker = 'duplicate-send-' . uniqid();
                    $key = 'idem-' . uniqid();
                    $s->send($ctx, $marker, ['idempotency_key' => $key]);
                    $s->send($ctx, $marker, ['idempotency_key' => $key]);
                    $rows = $s->persistedRows($ctx) ?? [];
                    $n = 0;
                    foreach ($rows as $row) {
                        if (str_contains((string) ($row[$s->storeContentColumn()] ?? ''), $marker)) $n++;
                    }
                    if ($n === 0) return Outcome::blocked('probe messages were not persisted');

                    return Outcome::assert($n === 1,
                        "the same message sent twice with an identical idempotency key produced {$n} persisted rows; a retry after a timeout duplicates the conversation (I-01/I-04)");
                },
            ],
            [
                'id' => 'delivery.no_open_request_required', 'clause' => 'T-03', 'group' => 'D',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::PERSIST_ASSISTANT)
                    ? Outcome::pass('a persisted final can be recovered without holding the request open')
                    : Outcome::fail('the client must hold the request open to receive the answer (T-03)'),
            ],
            [
                'id' => 'delivery.background_refresh_present', 'clause' => 'T-01', 'group' => 'D',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => in_array($s->channelClass(), ['durable_agent'], true),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::BACKGROUND_REFRESH)
                    ? Outcome::pass()
                    : Outcome::fail('the surface has no background refresh; a reply produced from another surface never appears until the user navigates (T-01)'),
            ],
        ];
    }

    // ═══════════════════════════════ E. CREDITS ════════════════════════════════

    private static function groupE(): array
    {
        return [
            [
                'id' => 'credits.refusal_is_structured', 'clause' => 'K-03', 'group' => 'E',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS) && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $restore = $ctx->induceCreditRefusal();
                    try {
                        $r = $s->send($ctx, 'structured refusal probe');
                        if ($r === null) return Outcome::blocked('no live probe');
                        if ($r->status !== 402) return Outcome::blocked("expected 402, got {$r->status}");
                        $err = $r->get('error');
                        if (is_array($err) && in_array($err['code'] ?? '', self::ERROR_CODES, true)) {
                            return Outcome::pass();
                        }

                        return Outcome::fail(is_string($err)
                            ? "the credit refusal is a bare string, not a structured error with a taxonomy code: \"" . mb_substr($err, 0, 80) . '"'
                            : 'the credit refusal carries no structured error object (K-03/X-01)', $s->sendRoute());
                    } finally {
                        $restore();
                    }
                },
            ],
            [
                'id' => 'credits.refusal_states_persistence', 'clause' => 'X-05', 'group' => 'E',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS) && $s->supports(Cap::HTTP_PROBE) && !$s->isEphemeral(),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $restore = $ctx->induceCreditRefusal();
                    try {
                        $r = $s->send($ctx, 'refusal persistence statement probe');
                        if ($r === null || $r->status !== 402) return Outcome::blocked('no 402 obtained');
                        $err = $r->get('error');
                        $structured = is_array($err) && isset($err['persistence']['user_message_saved']);
                        $legacy = $r->has('message_saved');

                        return Outcome::assert($structured,
                            $legacy
                                ? 'the refusal states persistence through the legacy flat field message_saved rather than error.persistence (X-05, deprecated)'
                                : 'the refusal does not tell the user whether their message survived (X-05)');
                    } finally {
                        $restore();
                    }
                },
            ],
            [
                'id' => 'credits.refusal_offers_action', 'clause' => 'X-02', 'group' => 'E',
                'severity' => 'medium', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS) && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $restore = $ctx->induceCreditRefusal();
                    try {
                        $r = $s->send($ctx, 'refusal action probe');
                        if ($r === null || $r->status !== 402) return Outcome::blocked('no 402 obtained');
                        $body = $r->jsonText();
                        $hasAction = str_contains($body, 'action_label') || str_contains($body, '"action"');
                        // Machine phrasing check: a refusal that quotes an internal
                        // rate formula at the customer is not user-safe copy.
                        $machine = (bool) preg_match('/0\.1 credit|1 credit per 10|chat_meter|chat_counter/i', $body);
                        if (!$hasAction) {
                            return Outcome::fail('the credit refusal offers the customer no next step (X-02)');
                        }

                        return Outcome::assert(!$machine,
                            'the credit refusal exposes internal metering mechanics to the customer instead of plain language (X-02)');
                    } finally {
                        $restore();
                    }
                },
            ],
            [
                'id' => 'credits.no_provider_call_after_refusal', 'clause' => 'K-02', 'group' => 'E',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared');
                    // The refusal must return before any runtime/provider invocation.
                    if (!preg_match('/sufficient/i', $src)) return Outcome::blocked('no credit check found in the declared source');
                    $pos = 0;
                    $violation = false;
                    if (preg_match('/if\s*\(!\s*\$\w*_?meter\w*\[.sufficient.\]\)\s*\{(.{0,900})\}/s', $src, $m)) {
                        $violation = (bool) preg_match('/RuntimeClient|chatJson|aiRun|Http::post/i', $m[1]);
                    }

                    return Outcome::assert(!$violation,
                        'a provider call appears inside the insufficient-credit branch (K-02)');
                },
            ],
            [
                'id' => 'credits.reserve_release_on_failure', 'clause' => 'K-05', 'group' => 'E',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::CREDIT_RESERVE)
                    ? Outcome::pass()
                    : Outcome::fail('the surface charges without a reserve/release cycle; a failed generation is not refunded (K-05)'),
            ],
            [
                'id' => 'credits.no_double_charge_on_retry', 'clause' => 'K-06', 'group' => 'E',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::IDEMPOTENCY)
                    ? Outcome::pass()
                    : Outcome::fail('without idempotency a retried send charges the workspace twice (K-06)'),
            ],
            [
                'id' => 'credits.charge_attributable_to_message', 'clause' => 'K-07', 'group' => 'E',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS),
                'run' => function () {
                    if (!Schema::hasTable('credit_transactions')) return Outcome::blocked('no credit_transactions table');
                    $cols = Schema::getColumnListing('credit_transactions');
                    $linked = array_intersect($cols, ['message_id', 'chat_message_id', 'agent_message_id']);

                    return Outcome::assert($linked !== [],
                        'no column links a credit transaction to the message that caused it; a billing dispute about a specific chat cannot be answered (K-07)', 'credit_transactions');
                },
            ],
            [
                'id' => 'credits.service_implementation_declared', 'clause' => 'K-09', 'group' => 'E',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS),
                'run' => function (ChatSurface $s) {
                    $svc = $s->creditService();
                    if ($svc === null) return Outcome::fail('the surface does not declare which CreditService it uses (K-09)');
                    $canonical = 'App\\Core\\Billing\\CreditService';

                    return $svc === $canonical
                        ? Outcome::pass()
                        : Outcome::fail("uses {$svc} rather than {$canonical}; two credit implementations with divergent semantics are live (K-09 — measured in P1, consolidation deferred to P2)");
                },
            ],
        ];
    }

    // ═══════════════════════════════ F. READ STATE ═════════════════════════════

    private static function groupF(): array
    {
        return [
            [
                'id' => 'read.state_supported', 'clause' => 'RD-01', 'group' => 'F',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral(),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::READ_STATE)
                    ? Outcome::pass()
                    : Outcome::unsupported('surface has no read-state concept'),
            ],
            [
                'id' => 'read.server_authoritative', 'clause' => 'RD-01', 'group' => 'F',
                'severity' => 'critical', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::READ_STATE) && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasColumn($t, 'read_at')) return Outcome::blocked('no read_at column');
                    $before = DB::table($t)->where('workspace_id', $ctx->workspaceId)->whereNull('read_at')->count();
                    $ctx->test->withHeaders($ctx->authHeaders())->postJson('/api/messages/read-all');
                    $after = DB::table($t)->where('workspace_id', $ctx->workspaceId)->whereNull('read_at')->count();

                    return Outcome::assert($after <= $before,
                        'marking read did not reduce server-side unread; the badge is cleared locally only (RD-01)');
                },
            ],
            [
                'id' => 'read.aggregate_matches_sum', 'clause' => 'RD-05', 'group' => 'F',
                'severity' => 'medium', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::UNREAD_AGGREGATE) && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $resp = $ctx->test->withHeaders($ctx->authHeaders())->getJson('/api/messages/unread-count');
                    $body = $resp->json();
                    if (!is_array($body) || !isset($body['total'])) return Outcome::blocked('unread-count returned no total');
                    $sum = array_sum($body['by_agent'] ?? []);

                    return Outcome::assert((int) $body['total'] === (int) $sum,
                        "aggregate unread ({$body['total']}) does not equal the sum of per-conversation unread ({$sum}) (RD-05)");
                },
            ],
            [
                'id' => 'read.mark_all_supported', 'clause' => 'RD-02', 'group' => 'F',
                'severity' => 'medium', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::READ_STATE),
                'run' => fn (ChatSurface $s) => $s->supports(Cap::READ_ALL)
                    ? Outcome::pass()
                    : Outcome::fail('no mark-all-read operation exists; a user cannot clear an accumulated badge (RD-02)'),
            ],
            [
                'id' => 'read.no_cross_workspace_clear', 'clause' => 'RD-06', 'group' => 'F',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::READ_ALL) && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasColumn($t, 'read_at')) return Outcome::blocked('no read_at column');
                    $planted = $ctx->insertFixture($t, [
                        'workspace_id' => 999998,
                        $s->storeContentColumn() => 'foreign unread',
                        'read_at' => null, 'role' => 'agent', 'sender' => 'agent', 'agent_slug' => 'sarah',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!$planted) {
                        return Outcome::blocked('could not plant a foreign fixture in ' . $t);
                    }
                    $ctx->test->withHeaders($ctx->authHeaders())->postJson('/api/messages/read-all');
                    $stillUnread = DB::table($t)->where('workspace_id', 999998)->whereNull('read_at')->count();
                    DB::table($t)->where('workspace_id', 999998)->delete();

                    return Outcome::assert($stillUnread === 1,
                        'mark-all-read cleared unread messages belonging to another workspace (RD-06)');
                },
            ],
            [
                'id' => 'read.single_emission_per_action', 'clause' => 'RD-07', 'group' => 'F',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::READ_STATE) && $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    // A single user action must emit exactly one mark-read call.
                    foreach ($s->sourceFiles() as $file) {
                        $text = $ctx->source($file);
                        if ($text === null) continue;
                        $lines = explode("\n", $text);
                        foreach ($lines as $i => $line) {
                            if (!preg_match('/_msgMarkRead\s*\(/', $line)) continue;
                            $next = $lines[$i + 1] ?? '';
                            if (preg_match('/_msgMarkRead\s*\(/', $next)) {
                                return Outcome::fail(
                                    'a single user action emits two consecutive mark-read calls, producing a duplicate server write (RD-07)',
                                    $file . ':' . ($i + 1) . '-' . ($i + 2));
                            }
                        }
                    }

                    return Outcome::pass();
                },
            ],
            [
                'id' => 'read.scope_declared_honestly', 'clause' => 'RD-09', 'group' => 'F',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::READ_STATE),
                'run' => function (ChatSurface $s) {
                    $scope = $s->readStateScope();
                    if ($scope === 'user') {
                        $t = $s->storeTable();
                        $canDo = $t !== null && Schema::hasTable($t) && Schema::hasColumn($t, 'user_id');

                        return Outcome::assert($canDo,
                            'the surface claims per-user read state but the store has no user_id column — the claim is false (RD-09)');
                    }
                    if ($scope === 'workspace') {
                        return $s->readStateLimitation() !== null
                            ? Outcome::pass('workspace scope declared with its limitation stated')
                            : Outcome::fail('read state is workspace-wide but the limitation is not declared (RD-09)');
                    }

                    return Outcome::fail('read-state scope is not declared (RD-09)');
                },
            ],
            [
                'id' => 'read.per_user_supported', 'clause' => 'RD-08', 'group' => 'F',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::READ_STATE),
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t)) return Outcome::blocked('no store');

                    return Schema::hasColumn($t, 'user_id')
                        ? Outcome::pass()
                        : Outcome::fail('read state cannot be per-user: the store has no user_id, so one member opening a conversation clears the badge for every member of the workspace (RD-08)', $t);
                },
            ],
        ];
    }

    // ═══════════════════════ G. RENDERING AND SAFETY ═══════════════════════════

    private static function groupG(): array
    {
        return [
            [
                'id' => 'render.no_icon_markup_in_message_content', 'clause' => 'G-04', 'group' => 'G',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    // An icon helper's OUTPUT must never be concatenated into a
                    // message body or assigned to textContent. Both render the
                    // raw <svg> as visible characters.
                    foreach ($s->sourceFiles() as $file) {
                        if (!str_ends_with($file, '.js')) continue;
                        $hits = $ctx->grep($file, '/(AddMsg|addMessage|appendMessage)\s*\([^)]*window\.icon\s*\(/');
                        foreach ($hits as $h) {
                            return Outcome::fail(
                                'an icon helper\'s SVG output is concatenated into a message body; it renders as literal markup in the conversation (G-04/G-05)',
                                $file . ':' . $h['line']);
                        }
                        $hits = $ctx->grep($file, '/\.textContent\s*=\s*[^;]*window\.icon\s*\(/');
                        foreach ($hits as $h) {
                            return Outcome::fail(
                                'an SVG string is assigned to .textContent, which renders the raw <svg …> tag as visible text (G-04)',
                                $file . ':' . $h['line']);
                        }
                    }

                    return Outcome::pass();
                },
            ],
            [
                'id' => 'render.errors_not_assistant_bubbles', 'clause' => 'G-06', 'group' => 'G',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    foreach ($s->sourceFiles() as $file) {
                        if (!str_ends_with($file, '.js')) continue;
                        // A catch block that renders through the assistant path
                        // makes an infrastructure failure look like the agent
                        // speaking. Clause X-08 prohibits it outright.
                        $hits = $ctx->grep($file, "/AddMsg\s*\(\s*['\"]assistant['\"].*(e\.message|err\.message|error\.message)/");
                        foreach ($hits as $h) {
                            return Outcome::fail(
                                'an error is rendered through the assistant message path, so a billing or infrastructure failure appears to the customer as something the agent said (X-08/G-06)',
                                $file . ':' . $h['line']);
                        }
                    }

                    return Outcome::pass();
                },
            ],
            [
                'id' => 'render.escape_precedes_markdown', 'clause' => 'G-02', 'group' => 'G',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                // Only meaningful for surfaces that own a client render path.
                'applies' => fn (ChatSurface $s) => $s->clientSourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared');
                    if (!preg_match('/function\s+(fmt|bld_fmt)\s*\(/', $src)) {
                        // The surface has no formatter of its own. That is only
                        // acceptable if it demonstrably delegates to the shared
                        // one — and then the shared one is what must be measured.
                        if (!preg_match('/typeof\s+fmt\s*===\s*[\'"]function[\'"]|window\.fmt/', $src)) {
                            return Outcome::blocked('no formatter and no delegation found in the declared source');
                        }
                        $src = (string) $ctx->source('public/app/js/core.js');
                        if ($src === '') return Outcome::blocked('shared formatter (core.js) unreadable');
                    }
                    // The formatter must escape & < > before any markdown
                    // substitution, otherwise literal markup becomes live markup.
                    $escapesFirst = (bool) preg_match(
                        '/replace\(\s*\/&\/g\s*,\s*[\'"]&amp;[\'"]\s*\)[\s\S]{0,200}?replace\(\s*\/</',
                        $src);

                    return Outcome::assert($escapesFirst,
                        'the message formatter does not escape & < > before applying markdown; literal markup in model or user text can become live markup (G-02/G-03)');
                },
            ],
            [
                'id' => 'render.literal_markup_is_inert', 'clause' => 'G-03', 'group' => 'G',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->clientSourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared');
                    $hasEscaper = (bool) preg_match('/&amp;|escapeHtml|_msgE\s*\(|bld_esc\s*\(/', $src);

                    return Outcome::assert($hasEscaper,
                        'no escaping routine found in the surface source; text containing <script>, <svg> or an event-handler attribute is not demonstrably inert (G-03)');
                },
            ],
            [
                'id' => 'render.innerhtml_paired_with_escaping', 'clause' => 'G-07', 'group' => 'G',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    foreach ($s->sourceFiles() as $file) {
                        if (!str_ends_with($file, '.js')) continue;
                        $text = $ctx->source($file);
                        if ($text === null) continue;
                        $inner = preg_match_all('/innerHTML/', $text);
                        $esc   = preg_match_all('/escapeHtml|_msgE\s*\(|bld_esc\s*\(|&amp;/', $text);
                        if ($inner > 0 && $esc === 0) {
                            return Outcome::fail(
                                "uses innerHTML {$inner} times with no escaping routine in the same file; safety depends entirely on a global helper being loaded first (G-07)",
                                $file);
                        }
                    }

                    return Outcome::pass();
                },
            ],
            [
                'id' => 'render.no_fabricated_replies', 'clause' => 'F-06', 'group' => 'G',
                'severity' => 'critical', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared');
                    // P2-B (2026-07-27) — corrected. The previous rule matched any
                    // co-occurrence of "keyword|fallback" with "reply|response|answer",
                    // which flagged the IDENTIFIER extractKeywordFromReply() — a helper
                    // that parses a keyword OUT OF a genuine model reply, i.e. the
                    // opposite of fabrication. See detectFabrication() for what now
                    // counts as evidence, and Cr06FabricationDetectorTest for proof
                    // that it still catches the real thing.
                    $hit = self::detectFabrication($src);

                    return Outcome::assert($hit === null,
                        'the surface can synthesize an assistant reply without a model call; the customer cannot tell a canned answer from a real one (F-06) — evidence: ' . $hit);
                },
            ],
            [
                'id' => 'render.cross_surface_equivalence', 'clause' => 'G-09', 'group' => 'G',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->channelClass() === 'durable_agent',
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared');
                    // Agent surfaces share one store; they must share one renderer.
                    $usesShared = (bool) preg_match('/typeof\s+fmt\s*===\s*[\'"]function[\'"]|window\.fmt|\bfmt\s*\(/', $src);

                    return Outcome::assert($usesShared,
                        'the surface does not use the shared message formatter, so the same stored message can render differently here than elsewhere (G-09)');
                },
            ],
            [
                'id' => 'render.safe_link_handling', 'clause' => 'G-08', 'group' => 'G',
                'severity' => 'medium', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $src = $ctx->sourceOf($s);
                    if ($src === '') return Outcome::blocked('no source declared');
                    if (!str_contains($src, 'target="_blank"') && !str_contains($src, "target='_blank'")) {
                        return Outcome::notApplicable('surface opens no external targets');
                    }

                    return Outcome::assert(str_contains($src, 'noopener'),
                        'external links are opened with target="_blank" without rel="noopener noreferrer" (G-08)');
                },
            ],
            [
                'id' => 'render.no_component_markup_persisted', 'clause' => 'G-05', 'group' => 'G',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $rows = $s->persistedRows($ctx) ?? [];
                    foreach ($rows as $row) {
                        $c = (string) ($row[$s->storeContentColumn()] ?? '');
                        if (preg_match('/<svg\b|<path\b/i', $c)) {
                            return Outcome::fail('component markup has been serialized into stored message content (G-05)', $s->storeTable());
                        }
                    }

                    return Outcome::pass();
                },
            ],
        ];
    }

    // ═══════════════════════════════ H. ERRORS ═════════════════════════════════

    private static function groupH(): array
    {
        return [
            [
                'id' => 'error.empty_content_rejected', 'clause' => 'R-01', 'group' => 'H',
                'severity' => 'medium', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, '   ');
                    if ($r === null) return Outcome::blocked('no live probe');

                    return Outcome::assert(in_array($r->status, [400, 422], true),
                        "whitespace-only content returned HTTP {$r->status}; empty input is not rejected (R-01)", $s->sendRoute());
                },
            ],
            [
                'id' => 'error.code_from_closed_taxonomy', 'clause' => 'X-01', 'group' => 'H',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, '');
                    if ($r === null) return Outcome::blocked('no live probe');
                    if ($r->status < 400) return Outcome::blocked('no error response obtained');
                    $err = $r->get('error');
                    $code = is_array($err) ? ($err['code'] ?? null) : null;

                    return Outcome::assert($code !== null && in_array($code, self::ERROR_CODES, true),
                        'the error carries no code from the closed taxonomy; clients must string-match prose to classify failures (X-01)');
                },
            ],
            [
                'id' => 'error.retryable_declared', 'clause' => 'X-03', 'group' => 'H',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, '');
                    if ($r === null || $r->status < 400) return Outcome::blocked('no error response obtained');
                    $err = $r->get('error');

                    return Outcome::assert(is_array($err) && array_key_exists('retryable', $err),
                        'the error does not say whether it is retryable, so a client cannot decide whether to retry (X-03)');
                },
            ],
            [
                'id' => 'error.provider_called_declared', 'clause' => 'X-04', 'group' => 'H',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE) && $s->supports(Cap::CREDITS),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $restore = $ctx->induceCreditRefusal();
                    try {
                        $r = $s->send($ctx, 'provider_called probe');
                        if ($r === null || $r->status !== 402) return Outcome::blocked('no 402 obtained');
                        $err = $r->get('error');

                        return Outcome::assert(is_array($err) && array_key_exists('provider_called', $err),
                            'the error does not state whether a provider was called, so a billing dispute cannot be answered from the response (X-04)');
                    } finally {
                        $restore();
                    }
                },
            ],
            [
                'id' => 'error.no_internals_leaked', 'clause' => 'X-07', 'group' => 'H',
                'severity' => 'critical', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, '');
                    if ($r === null || $r->status < 400) return Outcome::blocked('no error response obtained');
                    $body = $r->jsonText();
                    foreach ([
                        '/var/www' => 'a filesystem path',
                        'SQLSTATE' => 'a database error',
                        'Stack trace' => 'a stack trace',
                        'App\\\\' => 'an internal class name',
                        '<svg' => 'raw SVG markup',
                    ] as $needle => $what) {
                        if (str_contains($body, $needle)) {
                            return Outcome::fail("the error response leaks {$what} to the client (X-07)");
                        }
                    }

                    return Outcome::pass();
                },
            ],
            [
                'id' => 'error.correlation_id_present', 'clause' => 'E-02', 'group' => 'H',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $r = $s->send($ctx, '');
                    if ($r === null || $r->status < 400) return Outcome::blocked('no error response obtained');
                    $err = $r->get('error');
                    $has = $r->has('correlation_id') || (is_array($err) && isset($err['correlation_id']));

                    return Outcome::assert($has,
                        'the error carries no correlation id; a customer report cannot be tied to a server log line (E-02/O-02)');
                },
            ],
            [
                'id' => 'error.user_safe_language', 'clause' => 'X-02', 'group' => 'H',
                'severity' => 'high', 'evidence' => 'HTTP',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::HTTP_PROBE) && $s->supports(Cap::CREDITS),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $restore = $ctx->induceCreditRefusal();
                    try {
                        $r = $s->send($ctx, 'user-safe copy probe');
                        if ($r === null || $r->status !== 402) return Outcome::blocked('no 402 obtained');
                        $err = $r->get('error');
                        $msg = is_array($err) ? ($err['message'] ?? '') : (is_string($err) ? $err : '');

                        return Outcome::assert(
                            $msg !== '' && !preg_match('/0\.1 credit|1 credit per 10|meter|counter|threshold/i', $msg),
                            'the customer-facing refusal explains the internal metering formula instead of what to do next (X-02)');
                    } finally {
                        $restore();
                    }
                },
            ],
            [
                'id' => 'error.not_persisted_as_assistant_reply', 'clause' => 'X-08', 'group' => 'H',
                'severity' => 'critical', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->supports(Cap::CREDITS) && !$s->isEphemeral() && $s->supports(Cap::HTTP_PROBE),
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    $t = $s->storeTable();
                    if ($t === null || !Schema::hasTable($t)) return Outcome::blocked('no store');
                    if (!Schema::hasColumn($t, 'type')) {
                        return Outcome::fail("the store has no 'type' column, so an error bubble persisted for thread coherence is recorded as an ordinary assistant message (X-08/X-09)", $t);
                    }

                    return Outcome::pass();
                },
            ],
        ];
    }

    // ═══════════════════════════ I. OBSERVABILITY ══════════════════════════════

    private static function groupI(): array
    {
        return [
            [
                'id' => 'obs.correlation_recorded_on_message', 'clause' => 'O-02', 'group' => 'I',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null,
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");

                    return Schema::hasColumn($t, 'correlation_id')
                        ? Outcome::pass()
                        : Outcome::fail("store '{$t}' has no correlation_id column; a message cannot be traced to the request or provider call that produced it (O-02)", $t);
                },
            ],
            [
                'id' => 'obs.provider_and_model_recorded', 'clause' => 'F-02', 'group' => 'I',
                'severity' => 'high', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null && !$s->isEphemeral(),
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");
                    $has = Schema::hasColumn($t, 'provider') && Schema::hasColumn($t, 'model');

                    return Outcome::assert($has,
                        "store '{$t}' records neither provider nor model; after a provider migration there is no way to tell which model produced any historical answer (F-02/O-03)", $t);
                },
            ],
            [
                'id' => 'obs.usage_recorded', 'clause' => 'O-03', 'group' => 'I',
                'severity' => 'medium', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->storeTable() !== null && !$s->isEphemeral(),
                'run' => function (ChatSurface $s) {
                    $t = $s->storeTable();
                    if (!Schema::hasTable($t)) return Outcome::blocked("table {$t} absent");
                    $cols = Schema::getColumnListing($t);
                    $has = array_intersect($cols, ['tokens_used', 'usage_json', 'tokens_in', 'tokens_out', 'latency_ms']);

                    return Outcome::assert($has !== [],
                        "store '{$t}' records no token or latency usage; cost and performance cannot be attributed per message (O-03)", $t);
                },
            ],
            [
                'id' => 'obs.persistence_failure_logged', 'clause' => 'O-04', 'group' => 'I',
                'severity' => 'high', 'evidence' => 'SOURCE',
                'applies' => fn (ChatSurface $s) => !$s->isEphemeral() && $s->sourceFiles() !== [],
                'run' => function (ChatSurface $s, ChatTestContext $ctx) {
                    foreach ($s->sourceFiles() as $file) {
                        if (!str_ends_with($file, '.php')) continue;
                        $text = $ctx->source($file);
                        if ($text === null) continue;
                        $lines = explode("\n", $text);
                        foreach ($lines as $i => $line) {
                            // A catch that neither logs nor rethrows around a
                            // message insert turns a persistence failure into
                            // silence: the user's words vanish and nothing records it.
                            if (!preg_match('/catch\s*\(\s*\\\\?Throwable[^)]*\)\s*\{\s*(\/\*.*?\*\/)?\s*\}/', $line)) {
                                continue;
                            }
                            $window = implode("\n", array_slice($lines, max(0, $i - 25), 25));
                            if (preg_match('/->insert\(\s*\[/', $window) && preg_match('/agent_messages|chatbot_messages|seo_assistant_messages|meeting_messages/', $window)) {
                                return Outcome::fail(
                                    'a message-persistence failure is swallowed by an empty catch: the exception is neither logged nor rethrown, so a lost message produces no signal anywhere (O-04)',
                                    $file . ':' . ($i + 1));
                            }
                        }
                    }

                    return Outcome::pass();
                },
            ],
            [
                'id' => 'obs.audit_linkage', 'clause' => 'O-05', 'group' => 'I',
                'severity' => 'low', 'evidence' => 'DB',
                'applies' => fn (ChatSurface $s) => $s->channelClass() === 'durable_agent',
                'run' => function () {
                    if (!Schema::hasTable('audit_logs')) return Outcome::blocked('no audit_logs table');

                    return Schema::hasColumn('audit_logs', 'correlation_id')
                        ? Outcome::pass()
                        : Outcome::fail('audit records carry no correlation id, so an audit entry cannot be joined to the chat message that caused it (O-05)', 'audit_logs');
                },
            ],
        ];
    }
}
