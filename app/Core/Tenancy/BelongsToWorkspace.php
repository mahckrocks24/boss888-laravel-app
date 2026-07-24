<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * INFRA888 — automatic workspace scoping (control C2 of the 0.1-F design).
 *
 * Applies a global scope constraining every query to WorkspaceContext::id(), and
 * auto-populates workspace_id on create.
 *
 * IMPORTANT — what this does NOT do:
 *   - It does not protect raw DB::table() queries. That was precisely the gap that
 *     made the 2026-07-15 IDOR grep miss the dominant vulnerability pattern.
 *     Hence policies (C4) and service-level assertions (C5) exist alongside it.
 *   - It is not an authorization control. It is a blast-radius reducer.
 *
 * Escaping the scope is deliberately verbose and greppable:
 *     Model::withoutWorkspaceScope()   // requires a justification comment
 *
 * A CI rule fails the build on withoutGlobalScopes() inside the Infrastructure
 * engine, so the only escape hatch is the auditable one.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope());

        static::creating(function (Model $model) {
            if ($model->getAttribute('workspace_id') === null) {
                // Fails closed: no ambient workspace means no silent insert.
                $model->setAttribute('workspace_id', WorkspaceContext::id());
            }
        });

        static::saving(function (Model $model) {
            // A workspace_id may never be REASSIGNED after creation. Silent
            // re-parenting is a cross-tenant write by another name.
            if ($model->exists && $model->isDirty('workspace_id')) {
                throw new RuntimeException(
                    static::class . ': workspace_id is immutable once set.'
                );
            }
        });
    }

    /**
     * Explicit, greppable scope bypass. Use only for platform-admin surfaces and
     * always alongside an audit event naming the real administrator.
     */
    public static function withoutWorkspaceScope(): Builder
    {
        return static::query()->withoutGlobalScope(WorkspaceScope::class);
    }
}

/**
 * The scope itself. Separate class so it can be named in withoutGlobalScope()
 * and matched by the CI drift rule.
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->getTable() . '.workspace_id',
            WorkspaceContext::id()
        );
    }
}
