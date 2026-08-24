<?php

namespace App\Core\Sarah888;

/**
 * SARAH888 Phase 1E slice 1E.2 — request-scoped spend authorization context.
 *
 * Slice 1E.1 put the cost gate in the chat route's create_tasks loop and it was
 * not enough: tracing every paid creation path showed three of them, and the
 * one that produced F1-D07 in the first place — WriteService::fillMissingImages,
 * reached from the deterministic router — never touches that loop. A gate each
 * call site has to remember is a gate that will be forgotten by the next one.
 *
 * So the authorization travels with the REQUEST instead, and the decision moves
 * to TaskService::create, the single funnel all five callers pass through and
 * where the launch-scope refusal already lives.
 *
 * Bound as a singleton, which in Laravel is per-request. It defaults to
 * UNAUTHORIZED: a path that never sets it — a queue worker, a scheduled job, a
 * caller that does not know about this — gets the strict treatment rather than
 * a free pass.
 */
class SpendContext
{
    private ?array $turn = null;
    private ?int $workspaceId = null;

    public function setTurn(array $turn, ?int $workspaceId = null): void
    {
        $this->turn = $turn;
        $this->workspaceId = $workspaceId;

        // Register THIS instance for the rest of the request.
        //
        // Laravel auto-resolves an unbound concrete class to a NEW instance on
        // every app() call, so without this the assessment set here is invisible
        // to TaskService — which is exactly what the first run of the 1E.2 test
        // caught: the gate held authorised work and recorded no holds, because
        // it was reading a different, empty object. Binding here rather than in
        // a service provider keeps the change out of AppServiceProvider, which
        // another session has uncommitted work in.
        try { app()->instance(self::class, $this); } catch (\Throwable $e) { /* non-fatal */ }
    }

    /** Unset means unauthorized, deliberately. */
    public function turn(): array
    {
        return $this->turn ?? [
            'authorized' => false,
            'reason' => 'no turn context — spend was not authorized by a user request',
            'classification' => 'none',
        ];
    }

    public function isAuthorized(): bool
    {
        return (bool) ($this->turn()['authorized'] ?? false);
    }

    public function workspaceId(): ?int
    {
        return $this->workspaceId;
    }

    /** Tasks held during this request, for disclosure in the reply. */
    private array $held = [];

    public function recordHeld(string $action, int $creditCost): void
    {
        $this->held[] = ['action' => $action, 'credit_cost' => $creditCost];
    }

    public function held(): array
    {
        return $this->held;
    }

    public function clearHeld(): void
    {
        $this->held = [];
    }
}
