<?php

namespace App\Engines\Publisher\Http\Controllers;

use App\Engines\Publisher\Services\DeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUBLISHER888 Unit 1 — /api/desk/* . Requires auth.jwt + desk.context (website + role on the request).
 */
class DeskController
{
    public function __construct(private DeskService $desk) {}

    private function site(Request $r): object { return $r->attributes->get('desk_website'); }
    private function ws(Request $r): int { return (int) $r->attributes->get('workspace_id'); }
    private function wid(Request $r): int { return (int) $this->site($r)->id; }
    private function uid(Request $r): int { return (int) ($r->user()?->id ?? $r->attributes->get('user_id') ?? 0); }
    private function role(Request $r): string { return (string) $r->attributes->get('desk_role'); }

    private function need(Request $r, string $ability): ?JsonResponse
    {
        return DeskService::can($this->role($r), $ability) ? null
            : response()->json(['success' => false, 'error' => 'FORBIDDEN', 'message' => "Your desk role ({$this->role($r)}) cannot do this.", 'ability' => $ability], 403);
    }

    private function out(array $res): JsonResponse
    {
        $code = 200;
        if (empty($res['success'])) $code = match ($res['error'] ?? '') { 'NOT_FOUND' => 404, 'FORBIDDEN' => 403, 'VALIDATION', 'NOT_PUBLISHABLE', 'DUPLICATE', 'IN_USE', 'OWNER_LOCKED', 'NOT_A_MEMBER' => 422, default => 400 };
        return response()->json($res, $code);
    }

    // context
    public function context(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u && !empty($u->email)) $this->desk->claimPreassignedRole($this->ws($r), $this->wid($r), (int) $u->id, (string) $u->email);
        $role = $this->desk->resolveRole($this->ws($r), $this->wid($r), $this->uid($r), $r->attributes->get('workspace_role')) ?: $this->role($r);
        return response()->json($this->desk->context($this->site($r), $u ?: (object) ['id' => $this->uid($r)], $role));
    }

    // stories
    public function stories(Request $r): JsonResponse { if ($e = $this->need($r, 'stories.read')) return $e; return $this->out($this->desk->listStories($this->ws($r), $this->wid($r), $r->query())); }
    public function story(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'stories.read')) return $e; $s = $this->desk->getStory($this->ws($r), $this->wid($r), $id); return $s ? response()->json(['success' => true, 'story' => $s]) : $this->out(['success' => false, 'error' => 'NOT_FOUND']); }
    public function createStory(Request $r): JsonResponse { if ($e = $this->need($r, 'stories.write')) return $e; return $this->out($this->desk->createStory($this->ws($r), $this->wid($r), $this->uid($r), $r->all())); }
    public function updateStory(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'stories.write')) return $e; return $this->out($this->desk->updateStory($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->all())); }
    public function publishStory(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'stories.publish')) return $e; return $this->out($this->desk->publishStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function scheduleStory(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'stories.publish')) return $e; return $this->out($this->desk->scheduleStory($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->input('at'))); }
    public function unpublishStory(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'stories.publish')) return $e; return $this->out($this->desk->unpublishStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function deleteStory(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'stories.write')) return $e; return $this->out($this->desk->deleteStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }

    // sections
    public function sections(Request $r): JsonResponse { return response()->json(['success' => true, 'sections' => $this->desk->listSections($this->ws($r), $this->wid($r))]); }
    public function createSection(Request $r): JsonResponse { if ($e = $this->need($r, 'sections.write')) return $e; return $this->out($this->desk->createSection($this->ws($r), $r->all())); }
    public function updateSection(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'sections.write')) return $e; return $this->out($this->desk->updateSection($this->ws($r), $this->wid($r), $id, $r->all())); }
    public function deleteSection(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'sections.write')) return $e; return $this->out($this->desk->deleteSection($this->ws($r), $this->wid($r), $id)); }

    // commissions
    public function commissions(Request $r): JsonResponse { if ($e = $this->need($r, 'stories.read')) return $e; return $this->out($this->desk->listCommissions($this->ws($r), $this->wid($r))); }
    public function commission(Request $r): JsonResponse { if ($e = $this->need($r, 'commission')) return $e; return $this->out($this->desk->commission($this->ws($r), $this->wid($r), $this->uid($r), $r->all())); }

    // jobs
    public function jobs(Request $r): JsonResponse { if ($e = $this->need($r, 'jobs.read')) return $e; return $this->out($this->desk->listJobs($this->ws($r), $this->wid($r), $r->query())); }
    public function job(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'jobs.read')) return $e; $j = $this->desk->getJob($this->ws($r), $this->wid($r), $id); return $j ? response()->json(['success' => true, 'job' => $j]) : $this->out(['success' => false, 'error' => 'NOT_FOUND']); }
    public function createJob(Request $r): JsonResponse { if ($e = $this->need($r, 'jobs.write')) return $e; return $this->out($this->desk->createJob($this->ws($r), $this->wid($r), $this->uid($r), $r->all())); }
    public function updateJob(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'jobs.write')) return $e; return $this->out($this->desk->updateJob($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->all())); }
    public function publishJob(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'jobs.publish')) return $e; return $this->out($this->desk->publishJob($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function jobStatus(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'jobs.publish')) return $e; return $this->out($this->desk->setJobStatus($this->ws($r), $this->wid($r), $this->uid($r), $id, (string) $r->input('status'))); }

    // inbox
    public function inbox(Request $r): JsonResponse { if ($e = $this->need($r, 'inbox.read')) return $e; return $this->out($this->desk->listInbox($this->ws($r), $this->wid($r), $r->query())); }
    public function inboxItem(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'inbox.read')) return $e; $i = $this->desk->getInboxItem($this->ws($r), $this->wid($r), $id); return $i ? response()->json(['success' => true, 'item' => $i]) : $this->out(['success' => false, 'error' => 'NOT_FOUND']); }
    public function updateInbox(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'inbox.write')) return $e; return $this->out($this->desk->updateInboxItem($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->all())); }
    public function jobFromInbox(Request $r, int $id): JsonResponse { if ($e = $this->need($r, 'jobs.write')) return $e; return $this->out($this->desk->jobFromInbox($this->ws($r), $this->wid($r), $this->uid($r), $id)); }

    // members
    public function members(Request $r): JsonResponse { if ($e = $this->need($r, 'members.read')) return $e; return $this->out($this->desk->listMembers($this->ws($r), $this->wid($r))); }
    public function setMemberRole(Request $r, int $userId): JsonResponse { if ($e = $this->need($r, 'members.write')) return $e; return $this->out($this->desk->setMemberRole($this->ws($r), $this->wid($r), $this->uid($r), $userId, (string) $r->input('role'))); }
    public function invite(Request $r): JsonResponse { if ($e = $this->need($r, 'members.write')) return $e; return $this->out($this->desk->invite($this->ws($r), $this->wid($r), $this->uid($r), (string) $r->input('email'), (string) $r->input('role', 'editor'))); }
}
