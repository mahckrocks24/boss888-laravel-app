<?php

namespace Tests\Feature\Chat\Support;

/**
 * Test-only adapter describing one production chat surface.
 *
 * An adapter is TEST INFRASTRUCTURE. It does not replace, wrap, or modify any
 * production code path. Its job is to translate a surface's real shape into the
 * canonical vocabulary of CHAT-CONTRACT-v1 so that ONE normative suite can be
 * executed against ALL surfaces.
 *
 * HONESTY RULES (clause V-03/V-04)
 *  - An adapter MUST NOT synthesize a capability the surface does not have.
 *  - Declaring a capability false yields `unsupported`, never `pass`.
 *  - Where the harness cannot obtain evidence, the case is `blocked`.
 */
abstract class ChatSurface
{
    /** Stable machine key, e.g. 's1_agent_drawer'. */
    abstract public function key(): string;

    /** Human name used in the report. */
    abstract public function name(): string;

    /** Clause C-19 channel class. Determines which clauses apply. */
    abstract public function channelClass(): string;

    /**
     * Capability declarations: Cap::* => true | false.
     * Absent key means "not declared" and is treated as false.
     */
    abstract public function capabilities(): array;

    public function supports(string $cap): bool
    {
        return ($this->capabilities()[$cap] ?? false) === true;
    }

    public function isEphemeral(): bool
    {
        return $this->channelClass() === 'ephemeral_tool';
    }

    // ── Declared structural facts ────────────────────────────────────────────

    /** Production source files this surface owns (repo-relative). */
    public function sourceFiles(): array { return []; }

    /** Canonical send endpoint, or null when the surface cannot be exercised. */
    public function sendRoute(): ?string { return null; }

    /** Canonical history endpoint. */
    public function historyRoute(): ?string { return null; }

    /**
     * sendRoute() with path placeholders substituted for a real, resolvable URL.
     * Posting the literal "{slug}" template yields a 404, which would look like
     * a missing authorization check rather than a bad test.
     */
    public function resolvedSendRoute(): ?string
    {
        return $this->sendRoute();
    }

    /** Backing table, or null when the surface persists nothing. */
    public function storeTable(): ?string { return null; }

    /** Column in the store that carries the message body. */
    public function storeContentColumn(): string { return 'content'; }

    /** Request field that carries the user's text (clause R-06 drift). */
    public function requestContentField(): ?string { return null; }

    /** Legacy top-level response fields this surface still emits (clause E-05). */
    public function legacyResponseFields(): array { return []; }

    /** 'workspace' | 'user' | null — clause RD-08/RD-09. */
    public function readStateScope(): ?string { return null; }

    /** Declared limitation string when readStateScope() is not 'user'. */
    public function readStateLimitation(): ?string { return null; }

    /** Fully-qualified CreditService class in use (clause K-09). */
    public function creditService(): ?string { return null; }

    /** Declared history cap, or null when paginated (clause P-10). */
    public function historyCap(): ?int { return null; }

    /** True when the surface declares its cap to clients rather than truncating silently. */
    public function declaresHistoryCap(): bool { return false; }

    /** Free-form notes surfaced in the report. */
    public function notes(): array { return []; }

    /** Client-side source files (the render path), as opposed to server source. */
    public function clientSourceFiles(): array
    {
        return array_values(array_filter($this->sourceFiles(), fn ($f) => str_ends_with($f, '.js')));
    }

    // ── Live probes (optional) ───────────────────────────────────────────────

    /**
     * Called before every live probe.
     *
     * The platform rate-limits chat endpoints, and a conformance run issues far
     * more requests per minute than a human ever would. Without this the suite
     * measures the throttle instead of the contract: cases that expect a 402
     * receive a 429 and record `blocked`, which looks like missing evidence
     * rather than a harness artefact.
     */
    protected function beginProbe(ChatTestContext $ctx): void
    {
        $ctx->relaxRateLimits();
    }

    /**
     * Perform a real send. Return null when this surface cannot be exercised
     * from the harness — the case is then recorded `blocked`, never `pass`.
     */
    public function send(ChatTestContext $ctx, string $content, array $opts = []): ?ProbeResponse
    {
        return null;
    }

    /** Fetch history as a raw array, or null when not exercisable. */
    public function history(ChatTestContext $ctx, array $opts = []): ?array
    {
        return null;
    }

    /** Rows persisted for the current test workspace, newest last. */
    public function persistedRows(ChatTestContext $ctx): ?array
    {
        $table = $this->storeTable();
        if ($table === null) {
            return null;
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            return null;
        }
        $q = \Illuminate\Support\Facades\DB::table($table);
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'workspace_id')) {
            $q->where('workspace_id', $ctx->workspaceId);
        }

        return $q->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }
}
