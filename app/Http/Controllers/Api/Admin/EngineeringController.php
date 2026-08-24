<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Engineering\EngineeringListQuery;
use App\Core\Engineering\EngineeringReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ENTERPRISE888 E1 — Engineering Operations Dashboard (READ ONLY).
 *
 * M0 exposes exactly one endpoint: the section manifest.
 *
 * ⚠️ THIS CONTROLLER MUST NEVER GAIN A MUTATING METHOD.
 * E1 is read-only by approved scope. Execution, approvals and operational
 * actions belong to later phases with their own governance. If a future change
 * needs to act, it does not belong here — see
 * BELLA-ENGINEER888-IMPLEMENTATION-ROADMAP.md.
 *
 * Authorization is `auth.jwt` + AdminMiddleware at the route. `mfa.enrolled` is
 * deliberately NOT applied: no administrator is currently enrolled, so gating a
 * read-only dashboard behind it would make the section unreachable. Revisit when
 * capability-based authorization lands in E2.
 */
class EngineeringController
{
    public function __construct(
        private readonly EngineeringReadService $engineering,
    ) {}

    /**
     * GET /api/admin/engineering/overview
     *
     * Does anything need attention? Every figure carries its source; anything
     * that could not be measured is reported as blocked, never as zero.
     */
    public function overview(Request $request): JsonResponse
    {
        return response()->json(
            $this->engineering->overview(EngineeringListQuery::fromRequest($request))
        );
    }

    /**
     * GET /api/admin/engineering/health
     *
     * Current state of every probe. A probe that cannot run is BLOCKED — it is
     * never rendered green and never rendered as a healthy zero.
     */
    public function health(Request $request): JsonResponse
    {
        return response()->json(
            $this->engineering->health(EngineeringListQuery::fromRequest($request))
        );
    }
    /**
     * GET /api/admin/engineering/ownership
     *
     * Who holds what, and whether anything was written outside governed tooling.
     */
    public function ownership(Request $request): JsonResponse
    {
        return response()->json(
            $this->engineering->ownership(EngineeringListQuery::fromRequest($request))
        );
    }

    /**
     * GET /api/admin/engineering/backups
     *
     * Four separate backup systems, reported separately. No restore is possible
     * from here — E1 is read-only and restore is a Level-3 action in E5.
     */
    public function backups(Request $request): JsonResponse
    {
        return response()->json(
            $this->engineering->backups(EngineeringListQuery::fromRequest($request))
        );
    }
    /**
     * GET /api/admin/engineering/audit
     *
     * The structured audit trail. Reports what was recorded; it does not
     * interpret intent, and it states its own coverage limits.
     */
    public function audit(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: ['action', 'workspace_id', 'user_id', 'entity_type', 'since'],
        );

        return response()->json($this->engineering->audit($query));
    }

    /**
     * GET /api/admin/engineering/logs
     *
     * The application log tail. Test-channel entries are excluded by default
     * and always counted separately — the test suite writes into this same file.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: ['level', 'include_tests'],
        );

        return response()->json($this->engineering->logs($query));
    }
    /**
     * GET /api/admin/engineering/marketing-tasks
     *
     * Customer marketing work executed by the agent platform. Read-only: no
     * retry, no cancel, no approve. This is not engineering execution.
     */
    public function marketingTasks(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: ['status', 'category', 'workspace_id', 'source'],
        );

        return response()->json($this->engineering->marketingTasks($query));
    }

    /**
     * GET /api/admin/engineering/incidents
     *
     * Three incident sources, reported separately and never summed.
     */
    public function incidents(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: ['lifecycle_state', 'severity'],
        );

        return response()->json($this->engineering->incidents($query));
    }

    /**
     * GET /api/admin/engineering/costs
     *
     * Customer credits committed. Provider and token cost are not measured.
     */
    public function costs(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: [],
        );

        return response()->json($this->engineering->costs($query));
    }

    /**
     * GET /api/admin/engineering/documentation
     *
     * Operator guidance: what each screen measures, what it cannot prove, and
     * where its evidence comes from. Derived from the live screen definitions,
     * so it cannot drift away from the screens it describes.
     */
    public function documentation(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: ['screen'],
        );

        return response()->json($this->engineering->documentation($query));
    }

    /**
     * GET /api/admin/engineering/manifest
     *
     * The Engineering information architecture with each screen's true status,
     * data source, evidence source and owning phase.
     */
    public function manifest(Request $request): JsonResponse
    {
        $query = EngineeringListQuery::fromRequest(
            $request,
            allowedSorts: [],
            allowedFilters: ['status', 'phase'],
        );

        return response()->json($this->engineering->manifest($query));
    }
}
