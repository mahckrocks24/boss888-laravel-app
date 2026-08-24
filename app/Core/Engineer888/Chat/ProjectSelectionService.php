<?php

namespace App\Core\Engineer888\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Which repository does engineering work land in?
 *
 * THE ONLY ANSWER IS ONE SOMEBODY CHOSE. Not the first database row, not the
 * admin page the request came from, not a workspace, not a keyword in a
 * sentence. Every one of those is a guess, and a wrong guess writes code into
 * the wrong repository — which is discovered afterwards, by finding it there.
 *
 * NO NATURAL-LANGUAGE INFERENCE IN V1, DELIBERATELY. "Fix the builder" names no
 * project; it names a subject that could belong to several. A router confident
 * enough to pick one is a router confident enough to be wrong silently.
 */
final class ProjectSelectionService
{
    public const REQUIRED = 'PROJECT_SELECTION_REQUIRED';

    /**
     * Every project Engineer888 may work in.
     *
     * The registry is the authority. A project absent from it cannot be
     * selected however it is named.
     */
    public function registry(): array
    {
        return DB::table('engineering_projects')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'company', 'repository_path'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'key' => $p->key,
                'name' => $p->name,
                'company' => $p->company,
                'repository_path' => $p->repository_path,
            ])->all();
    }

    /**
     * The conversation's active project, or null.
     *
     * Falls back to a configured default ONLY when one was explicitly set. The
     * shipped value is null, so out of the box there is no project and task
     * creation refuses until somebody chooses.
     */
    public function active(object $conversation): ?array
    {
        if ($conversation->active_project_id !== null) {
            return $this->byId((int) $conversation->active_project_id);
        }

        $defaultKey = config('engineer888_chat.default_project_key');

        if (! is_string($defaultKey) || trim($defaultKey) === '') {
            return null;
        }

        // Resolved through the registry like any other selection — a
        // misconfigured key is no project, not an arbitrary one.
        return $this->byKey($defaultKey);
    }

    /**
     * Select a project by its registry key.
     *
     * Explicit, persisted, and recorded in the conversation itself as a system
     * message — so the switch is visible in the same history that shows the work
     * it affected, and cannot be reconstructed differently later.
     */
    public function select(?Request $request, object $conversation, string $projectKey): array
    {
        $owner = ChatOwner::resolve($request);

        if ($owner === null) {
            ChatAbsent::throw('project selection by a non-canonical identity');
        }

        $project = $this->byKey($projectKey);

        // An unknown key is answered exactly like an unauthorised one.
        if ($project === null) {
            ChatAbsent::throw('project key not in the Engineer888 registry');
        }

        $previous = $conversation->active_project_id !== null
            ? $this->byId((int) $conversation->active_project_id)
            : null;

        if (($previous['id'] ?? null) === $project['id']) {
            return $project;
        }

        DB::transaction(function () use ($conversation, $project, $previous) {
            ChatOwner::scope(
                DB::table('e888_conversations')->where('id', $conversation->id)
            )->update([
                'active_project_id' => $project['id'],
                'updated_at' => now(),
            ]);

            DB::table('e888_messages')->insert([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'owner_user_id' => ChatOwner::USER_ID,
                'role' => 'system',
                'body' => $previous === null
                    ? "Active project set to {$project['name']}."
                    : "Active project changed from {$previous['name']} to {$project['name']}.",
                'intent' => 'PROJECT_SELECTED',
                'metadata_json' => json_encode([
                    'kind' => 'project_selected',
                    'from' => $previous['key'] ?? null,
                    'to' => $project['key'],
                    'from_id' => $previous['id'] ?? null,
                    'to_id' => $project['id'],
                ]),
                'read_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Structured, and carrying no request body — the audit records the
        // decision, never the conversation.
        Log::info('engineer888.chat.project_selected', [
            'owner_user_id' => ChatOwner::USER_ID,
            'conversation_uuid' => $conversation->uuid,
            'from' => $previous['key'] ?? null,
            'to' => $project['key'],
        ]);

        return $project;
    }

    /**
     * The answer a task-creating intent gets when nothing is selected.
     *
     * It lists the registry rather than choosing from it.
     */
    public function selectionRequiredMessage(): string
    {
        $projects = $this->registry();

        if ($projects === []) {
            return "No project is registered with Engineer888, so I cannot create a task.";
        }

        $lines = implode("\n", array_map(
            fn ($p) => "• {$p['name']}  ({$p['key']})",
            $projects
        ));

        return "I have not created a task, because no active project is selected.\n\n"
            . "I will not choose one for you: picking the first registered project would "
            . "mean database order decides which repository receives engineering work, and "
            . "a wrong guess is discovered by finding code in the wrong place.\n\n"
            . "Available projects:\n\n{$lines}\n\n"
            . "Select one and repeat the request.";
    }

    public function byKey(string $key): ?array
    {
        $row = DB::table('engineering_projects')->where('key', $key)->first();

        return $row === null ? null : $this->present($row);
    }

    public function byId(int $id): ?array
    {
        $row = DB::table('engineering_projects')->where('id', $id)->first();

        return $row === null ? null : $this->present($row);
    }

    private function present(object $p): array
    {
        return [
            'id' => (int) $p->id,
            'key' => $p->key,
            'name' => $p->name,
            'company' => $p->company,
            'repository_path' => $p->repository_path,
        ];
    }
}
