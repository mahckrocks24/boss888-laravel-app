<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Decisions\DecisionPresentationResolver;
use App\Core\Engineer888\Decisions\DecisionProjection;
use App\Core\Engineer888\Decisions\DecisionState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The canonical Decisions surface.
 *
 * ── ONE SOURCE, OR THE NUMBERS DISAGREE AGAIN ───────────────────────
 *
 * This endpoint reads DecisionProjection and nothing else. It runs no query of
 * its own against approvals, candidates or cards, and it does not decide what
 * is executable — that answer comes from ApprovalLedger through the projection,
 * which is the same answer the chat header, the conversation and the execution
 * gate all get.
 *
 * The reason is on the record. Before the projection existed these surfaces
 * each approximated the count separately and disagreed: 52 live cards, a header
 * saying 25, a per-task view saying 24, and a model telling Boss "30". Adding a
 * page that computed a fifth number would have been repeating the mistake the
 * page was built to end.
 *
 * ── NO AUTHORITY IS CREATED HERE ────────────────────────────────────
 *
 * Read-only. Approving, rejecting and executing stay on their own capability-
 * gated card endpoints, and the card uuids in this payload are resolved live by
 * DecisionPresentationResolver, which withholds any card the decision's state
 * does not permit. An expired approval therefore arrives with an explanation
 * and no card at all, which is the entire point.
 */
class Engineer888DecisionsController
{
    public function index(Request $request, DecisionProjection $projection, DecisionPresentationResolver $resolver)
    {
        $ctx = Engineer888AccessContext::fromRequest($request);

        // SCOPE FOLLOWS THE CONVERSATION unless Boss asks otherwise, so the
        // page and the chat header cannot be looking at different projects and
        // both be right. `?project=all` is the deliberate way to see everything.
        $scope = (string) $request->query('project', '');
        $projectId = match (true) {
            $scope === 'all'      => null,
            ctype_digit($scope)   => (int) $scope,
            default               => $this->activeProjectId($request),
        };

        $attention = $projection->needingAttention($ctx, $projectId);

        // Hydrated through the SAME resolver the conversation uses, so a card
        // drawn here and a card drawn in chat are the same object, subject to
        // the same withholding rules.
        $refs = array_map(fn ($i) => [
            'type' => DecisionPresentationResolver::TYPE_DECISION,
            'logical_key' => $i['work_key'],
        ], $attention);

        $hydrated = [];
        foreach ($resolver->hydrate($ctx, $refs, $projectId) as $h) {
            $hydrated[$h['logical_key']] = $h['decision'];
        }

        $sections = [
            DecisionState::REVIEW_REQUIRED   => [],
            DecisionState::READY_TO_EXECUTE  => [],
            DecisionState::APPROVAL_EXPIRED  => [],
        ];

        foreach ($attention as $item) {
            $sections[$item['state']][] = $hydrated[$item['work_key']]
                ?? $this->fallback($item);   // access already checked; a miss means the card lookup found nothing
        }

        return response()->json([
            'scope' => [
                'project_id' => $projectId,
                'project'    => $projectId === null ? 'All projects' : $this->projectName($projectId),
            ],
            'needs_attention' => [
                'total'            => count($attention),
                'review_required'  => $sections[DecisionState::REVIEW_REQUIRED],
                'ready_to_execute' => $sections[DecisionState::READY_TO_EXECUTE],
                'approval_expired' => $sections[DecisionState::APPROVAL_EXPIRED],
            ],
            'in_progress' => $projection->inProgress($ctx, $projectId),
            'history'     => $projection->history($ctx, $projectId),
            // The header reads this same number. It is count(), not a length
            // computed here, so the two cannot drift.
            'count'       => $projection->count($ctx, $projectId),
        ]);
    }

    /**
     * What to draw when the projection has an item the resolver did not hydrate.
     *
     * Only reachable if the live card lookup returned nothing for a state that
     * wants one. Drawing the decision without its card is correct — the work is
     * real — and `stale` says why the buttons are missing rather than leaving a
     * dead control on the page.
     */
    private function fallback(array $item): array
    {
        return [
            'kind'           => $item['kind'],
            'state'          => $item['state'],
            'executable'     => $item['executable'],
            'actions'        => $item['actions'],
            'explanation'    => $item['explanation'],
            'approved_by'    => $item['approved_by'] ?? null,
            'expires_at'     => $item['expires_at'] ?? null,
            'title'          => $item['title'],
            'project'        => $item['project'],
            'file_count'     => $item['file_count'],
            'confidence'     => $item['confidence'],
            'attempts'       => $item['attempts'],
            'earlier'        => count($item['history']),
            'task_uuid'      => $item['task_uuid'],
            'candidate_uuid' => $item['candidate_uuid'],
            'review_card'    => null,
            'reject_card'    => null,
            'execute_card'   => null,
            'stale'          => true,
        ];
    }

    private function activeProjectId(Request $request): ?int
    {
        $id = DB::table('e888_conversations')
            ->where('owner_user_id', (int) ($request->user()->id ?? 0))
            ->value('active_project_id');

        return $id === null ? null : (int) $id;
    }

    private function projectName(int $id): string
    {
        return (string) (DB::table('engineering_projects')->where('id', $id)->value('name') ?? 'Unknown project');
    }
}
