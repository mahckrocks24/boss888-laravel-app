<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Engineer888\Chat\ActionCardService;
use App\Core\Engineer888\Chat\ChatAbsent;
use App\Core\Engineer888\Chat\ChatOwner;
use App\Core\Engineer888\Chat\ConversationService;
use App\Core\Engineer888\Chat\MessageService;
use App\Core\Engineer888\Chat\ProjectSelectionService;
use Illuminate\Http\Request;

/**
 * Engineer888 chat — the same controller for Admin and for the companion API.
 *
 * ONE CONTROLLER FOR BOTH SURFACES, DELIBERATELY. The requirement is that web
 * and mobile see the same conversation, the same messages, the same active
 * project and the same cards. Two controllers would satisfy that on the day
 * they were written and drift afterwards; one cannot drift from itself.
 *
 * THIN BY CONSTRUCTION. Everything here validates input, re-derives the owner,
 * calls a service and shapes a response. There is no workflow logic, no project
 * inference, no SQL, and nothing that reads authorisation out of a request body.
 * The route chain — auth.jwt → DenyApiKeyAuth → RequireEngineer888Access — has
 * already decided who may be here; ChatOwner::resolve() independently decides
 * whose data they get.
 *
 * NO EDIT AND NO DELETE ROUTES EXIST. Messages, and system messages in
 * particular, are append-only from every surface. The protection is the absence
 * of a path, not a flag on one.
 */
class Engineer888ChatController
{
    public function __construct(
        private ConversationService $conversations,
        private MessageService $messages,
        private ProjectSelectionService $projects,
        private ActionCardService $cards,
    ) {}

    /** Everything a surface needs to render itself from cold. */
    public function bootstrap(Request $request)
    {
        $this->owner($request);

        $conversation = $this->conversations->forOwner($request);
        $active = $this->projects->active($conversation);

        return response()->json([
            'contact' => [
                'key' => 'engineer888',
                'name' => 'Engineer888',
                'role' => 'AI Engineering Department',
                'description' => 'Plans, implements, verifies and documents engineering work across company projects.',
                'visibility' => 'private',
                'orb_type' => 'technical',
                'color' => '#7C5CFF',
                'status' => 'available',
            ],
            'conversation' => $this->conversationPayload($conversation, $active),
            'cards' => $this->liveCards($request, $conversation),
            'projects' => $this->projects->registry(),
            'unread' => $this->conversations->unreadCount($request),
            'capabilities' => [
                // What this surface may offer, answered by the policy rather
                // than assumed from the fact that the page loaded.
                'may_message' => (new \App\Core\Engineer888\Access\Engineer888Access())
                    ->mayMessage($request),
                'may_create_task' => (new \App\Core\Engineer888\Access\Engineer888Access())
                    ->mayCreateTask($request),
            ],
        ]);
    }

    public function conversation(Request $request)
    {
        $this->owner($request);

        $conversation = $this->conversations->forOwner($request);

        return response()->json([
            'conversation' => $this->conversationPayload(
                $conversation,
                $this->projects->active($conversation)
            ),
        ]);
    }

    public function messages(Request $request)
    {
        $this->owner($request);

        $data = $request->validate([
            'after_id' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $conversation = $this->conversations->forOwner($request);

        return response()->json($this->messages->history(
            $request,
            $conversation->uuid,
            isset($data['after_id']) ? (int) $data['after_id'] : null,
            (int) ($data['limit'] ?? 200)
        ));
    }

    /**
     * Send a message.
     *
     * ONLY `body` IS ACCEPTED. No role, no sender type, no intent, no metadata,
     * no task or project reference. A client that submits them is not refused
     * with an error that teaches it the field names — they are simply not read,
     * because the validator does not name them and the service does not take
     * them. `role` is set to 'user' by the service; there is no code path from
     * a request to a 'system' message.
     */
    public function send(Request $request)
    {
        $this->owner($request);

        $data = $request->validate([
            'body' => 'required|string|min:1|max:20000',
        ]);

        $conversation = $this->conversations->forOwner($request);
        $result = $this->messages->post($request, $conversation->uuid, $data['body']);

        $fresh = $this->conversations->forOwner($request);

        // Cards are resolved AFTER the message transaction has committed and
        // live workflow state has been re-read, so a card can never appear
        // without the message and target record that justify it.
        return response()->json([
            'intent' => $result['intent'],
            'blocked_action' => $result['blocked_action'],
            'messages' => $result['messages'],
            'cards' => $this->liveCards($request, $fresh),
            'conversation' => $this->conversationPayload($fresh, $this->projects->active($fresh)),
        ]);
    }

    /**
     * Select the active project, explicitly.
     *
     * The key is resolved through the Engineer888 project registry. An
     * unregistered key produces the module's uniform 404 — the same answer a
     * guessed conversation UUID gets.
     */
    public function selectProject(Request $request)
    {
        $this->owner($request);

        $data = $request->validate([
            'project_key' => 'required|string|max:64',
        ]);

        $conversation = $this->conversations->forOwner($request);
        $project = $this->projects->select($request, $conversation, $data['project_key']);

        $fresh = $this->conversations->forOwner($request);

        return response()->json([
            'active_project' => $project,
            'conversation' => $this->conversationPayload($fresh, $project),
        ]);
    }

    /** Cursor poll. The same shape both surfaces consume. */
    public function events(Request $request)
    {
        $this->owner($request);

        $data = $request->validate(['cursor' => 'nullable|integer|min:0']);

        $conversation = $this->conversations->forOwner($request);
        $history = $this->messages->history(
            $request,
            $conversation->uuid,
            isset($data['cursor']) ? (int) $data['cursor'] : null,
            200
        );

        return response()->json([
            'messages' => $history['messages'],
            'cursor' => $history['cursor'],
            'unread' => $this->conversations->unreadCount($request),
            'active_project' => $this->projects->active($conversation),
            'cards' => $this->liveCards($request, $conversation),
        ]);
    }

    /*
     * THERE IS NO GENERIC CARD-READ ENDPOINT, DELIBERATELY.
     *
     * A single GET gated on review_candidate would turn that capability into a
     * universal permission to read every card type — including recovery and
     * migration cards, whose authorities are separate on purpose. Cards are
     * returned with the conversation instead, filtered per card by the
     * capability that card's own action requires (see MessageService).
     *
     * One method per action below. The endpoint carries the authority; the
     * service then proves the stored card actually is that action.
     */

    public function approveCandidate(Request $request, string $uuid)
    {
        return $this->press($request, $uuid, ActionCardService::APPROVE_CANDIDATE);
    }

    public function executeTask(Request $request, string $uuid)
    {
        return $this->press($request, $uuid, ActionCardService::EXECUTE_TASK);
    }

    public function approveRecovery(Request $request, string $uuid)
    {
        return $this->press($request, $uuid, ActionCardService::APPROVE_RECOVERY);
    }

    public function approveMigration(Request $request, string $uuid)
    {
        return $this->press($request, $uuid, ActionCardService::APPROVE_MIGRATION);
    }

    public function rejectCandidate(Request $request, string $uuid)
    {
        return $this->press($request, $uuid, ActionCardService::REJECT_CANDIDATE);
    }

    /**
     * Withdraw an approval.
     *
     * NOT routed through press(): revoke is not an action of one type, it is an
     * operation ON a card of any type. The service resolves the stored card's
     * own authority and re-asks for exactly that capability, so approve_candidate
     * cannot revoke a recovery or a migration approval.
     */
    public function revokeApproval(Request $request, string $uuid)
    {
        $this->owner($request);

        $card = $this->cards->revoke($request, $uuid);

        return response()->json([
            'uuid' => $card->uuid,
            'action_type' => $card->action_type,
            'revoked_at' => $card->revoked_at,
        ]);
    }

    /**
     * Press a card of an exact type.
     *
     * The expected type comes from the ROUTE, never from the request. Every
     * binding — owner, session, device, conversation, task, target, fingerprint,
     * payload hashes, workflow state, expiry, revocation, prior consumption and
     * current capability — is recomputed inside the service, in one transaction,
     * with a conditional claim so exactly one concurrent press can win.
     */
    private function press(Request $request, string $uuid, string $expectedActionType)
    {
        $this->owner($request);

        // Domain input the action needs. Validated here, never invented by the
        // executor: a rejection with no stated reason is an engineering
        // decision with no record of why.
        $input = $request->validate([
            'instruction' => 'nullable|string|max:2000',
        ]);

        $card = $this->cards->consume($request, $uuid, $expectedActionType, 'executed', $input);

        return response()->json([
            'uuid' => $card->uuid,
            'action_type' => $card->action_type,
            'consumed_at' => $card->consumed_at,
            'result' => $card->consumed_result,
            'task_uuid' => $card->task_uuid,
            'candidate_uuid' => $card->candidate_uuid,
        ]);
    }

    /**
     * The owner, re-derived here as well as in every service.
     *
     * The middleware already refused everybody else. This is the second
     * independent check the brief requires: middleware attributes are evidence,
     * never authorisation.
     */
    private function owner(Request $request): void
    {
        if (! ChatOwner::is($request)) {
            ChatAbsent::throw('chat controller reached by a non-canonical identity');
        }
    }

    /**
     * Reconcile live workflow state, then return what this actor may see.
     *
     * RECONCILIATION IS WHAT ISSUES CARDS. Before this, the surfaces called
     * visibleFor() alone, which lists cards but never creates them — so the
     * conversation could only ever show an empty list.
     *
     * It runs here, at a service boundary that already holds the canonical
     * owner, the conversation and the capability context. Never from Blade,
     * never from JavaScript: a card is an authority, and the browser must not
     * be able to ask for one into existence.
     *
     * visibleFor() then filters per card by the capability that card's own
     * action requires, so a card this actor may not press is absent rather
     * than disabled.
     */
    private function liveCards(Request $request, object $conversation): array
    {
        app(\App\Core\Engineer888\Chat\CardIssuanceService::class)
            ->reconcile($request, $conversation);

        return $this->cards->visibleFor($request, $conversation);
    }

    private function conversationPayload(object $conversation, ?array $activeProject): array
    {
        return [
            'uuid' => $conversation->uuid,
            'title' => $conversation->title,
            'active_project' => $activeProject,
            'project_selection_required' => $activeProject === null,
            'last_message_at' => $conversation->last_message_at,
        ];
    }
}
