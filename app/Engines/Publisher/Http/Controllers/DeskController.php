<?php

namespace App\Engines\Publisher\Http\Controllers;

use App\Engines\Publisher\Services\DeskAudit;
use App\Engines\Publisher\Services\DeskService;
use App\Engines\Publisher\Services\DeskValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PUBLISHER888 — /api/desk/* . Requires auth.jwt + desk.context (website + role on the request).
 * Unit 2: every action is guarded (no stack traces leave the box), validated, audited where it mutates,
 * and POST creates honour an Idempotency-Key header.
 */
class DeskController
{
    public function __construct(private DeskService $desk, private DeskAudit $audit) {}

    private function site(Request $r): object { return $r->attributes->get('desk_website'); }
    private function ws(Request $r): int { return (int) $r->attributes->get('workspace_id'); }
    private function wid(Request $r): int { return (int) $this->site($r)->id; }
    private function uid(Request $r): int { return (int) ($r->user()?->id ?? $r->attributes->get('user_id') ?? 0); }
    private function role(Request $r): string { return (string) $r->attributes->get('desk_role'); }
    private function rid(Request $r): string { return (string) $r->attributes->get('desk_request_id'); }

    private function need(Request $r, string $ability): ?JsonResponse
    {
        return DeskService::can($this->role($r), $ability) ? null
            : response()->json(['success' => false, 'error' => 'FORBIDDEN', 'message' => "Your desk role ({$this->role($r)}) cannot do this.", 'ability' => $ability, 'request_id' => $this->rid($r)], 403);
    }

    private function out(array $res, Request $r): JsonResponse
    {
        $code = 200;
        if (empty($res['success'])) $code = match ($res['error'] ?? '') { 'NOT_FOUND' => 404, 'FORBIDDEN' => 403, 'STALE' => 409, 'VALIDATION', 'NOT_PUBLISHABLE', 'DUPLICATE', 'IN_USE', 'OWNER_LOCKED', 'NOT_A_MEMBER' => 422, 'RATE_LIMITED' => 429, default => 400 };
        if ($code >= 400) $res['request_id'] = $this->rid($r);
        return response()->json($res, $code);
    }

    /** Run an action with validation, optional idempotency, and error containment. */
    private function run(Request $r, ?string $ability, ?string $validate, callable $fn, bool $idempotent = false): JsonResponse
    {
        if ($ability && ($e = $this->need($r, $ability))) return $e;
        if ($validate && ($v = DeskValidation::check($validate, $r->all()))) return $this->out($v, $r);
        $idem = $idempotent ? trim((string) $r->header('Idempotency-Key')) : '';
        if ($idem !== '' && preg_match('/^[A-Za-z0-9_\-:.]{8,80}$/', $idem)) {
            $prev = DB::table('desk_idempotency')->where('website_id', $this->wid($r))->where('user_id', $this->uid($r))->where('idem_key', $idem)->first();
            if ($prev) return response()->json(json_decode($prev->response_json, true), (int) $prev->status_code)->header('Idempotent-Replayed', 'true');
        } else $idem = '';
        try {
            $res = $fn();
            $resp = $res instanceof JsonResponse ? $res : $this->out($res, $r);
            if ($idem !== '' && $resp->getStatusCode() < 500) {
                try { DB::table('desk_idempotency')->insert(['website_id' => $this->wid($r), 'user_id' => $this->uid($r), 'idem_key' => $idem, 'route' => mb_substr($r->method() . ' ' . $r->path(), 0, 120), 'status_code' => $resp->getStatusCode(), 'response_json' => $resp->getContent(), 'created_at' => now()]); } catch (\Throwable) {}
            }
            return $resp;
        } catch (\Throwable $e) {
            Log::error('desk.action.failed', ['request_id' => $this->rid($r), 'path' => $r->path(), 'user_id' => $this->uid($r), 'website_id' => $this->wid($r), 'error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            return response()->json(['success' => false, 'error' => 'INTERNAL', 'message' => 'Something went wrong on our side. Quote the request id if it persists.', 'request_id' => $this->rid($r)], 500);
        }
    }

    // context / health / audit / sessions
    public function context(Request $r): JsonResponse
    {
        return $this->run($r, null, null, function () use ($r) {
            $u = $r->user();
            if ($u && !empty($u->email)) $this->desk->claimPreassignedRole($this->ws($r), $this->wid($r), (int) $u->id, (string) $u->email);
            $role = $this->desk->resolveRole($this->ws($r), $this->wid($r), $this->uid($r), $r->attributes->get('workspace_role')) ?: $this->role($r);
            return response()->json($this->desk->context($this->site($r), $u ?: (object) ['id' => $this->uid($r)], $role) + ['request_id' => $this->rid($r)]);
        });
    }
    public function health(Request $r): JsonResponse { return $this->run($r, null, null, fn () => $this->desk->health($this->ws($r), $this->wid($r))); }
    public function audit(Request $r): JsonResponse { return $this->run($r, 'audit.read', null, fn () => $this->audit->list($this->wid($r), $r->query())); }
    public function sessions(Request $r): JsonResponse { return $this->run($r, null, null, fn () => $this->desk->sessions($this->uid($r), (int) $r->attributes->get('session_id'))); }
    public function revokeOtherSessions(Request $r): JsonResponse { return $this->run($r, null, null, fn () => $this->desk->revokeOtherSessions($this->uid($r), (int) $r->attributes->get('session_id'))); }

    // stories
    public function stories(Request $r): JsonResponse { return $this->run($r, 'stories.read', null, fn () => $this->desk->listStories($this->ws($r), $this->wid($r), $r->query())); }
    public function story(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.read', null, function () use ($r, $id) { $s = $this->desk->getStory($this->ws($r), $this->wid($r), $id); return $s ? ['success' => true, 'story' => $s] : ['success' => false, 'error' => 'NOT_FOUND']; }); }
    public function createStory(Request $r): JsonResponse { return $this->run($r, 'stories.write', 'story.create', fn () => $this->desk->createStory($this->ws($r), $this->wid($r), $this->uid($r), $r->all()), true); }
    public function updateStory(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.write', 'story.update', fn () => $this->desk->updateStory($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->all())); }
    public function publishStory(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.publish', null, fn () => $this->desk->publishStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function scheduleStory(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.publish', 'story.schedule', fn () => $this->desk->scheduleStory($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->input('at'))); }
    public function unpublishStory(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.publish', null, fn () => $this->desk->unpublishStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function deleteStory(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.write', null, fn () => $this->desk->deleteStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function restoreStory(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.write', null, fn () => $this->desk->restoreStory($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function storyVersions(Request $r, int $id): JsonResponse { return $this->run($r, 'stories.read', null, fn () => $this->desk->storyVersions($this->ws($r), $this->wid($r), $id)); }
    public function restoreStoryVersion(Request $r, int $id, int $vid): JsonResponse { return $this->run($r, 'stories.write', null, fn () => $this->desk->restoreStoryVersion($this->ws($r), $this->wid($r), $this->uid($r), $id, $vid)); }

    // sections
    public function sections(Request $r): JsonResponse { return $this->run($r, null, null, fn () => ['success' => true, 'sections' => $this->desk->listSections($this->ws($r), $this->wid($r))]); }
    public function createSection(Request $r): JsonResponse { return $this->run($r, 'sections.write', 'section.create', fn () => $this->desk->createSection($this->ws($r), $r->all()), true); }
    public function updateSection(Request $r, int $id): JsonResponse { return $this->run($r, 'sections.write', 'section.update', fn () => $this->desk->updateSection($this->ws($r), $this->wid($r), $id, $r->all())); }
    public function deleteSection(Request $r, int $id): JsonResponse { return $this->run($r, 'sections.write', null, fn () => $this->desk->deleteSection($this->ws($r), $this->wid($r), $id)); }

    // commissions
    public function commissions(Request $r): JsonResponse { return $this->run($r, 'stories.read', null, fn () => $this->desk->listCommissions($this->ws($r), $this->wid($r))); }
    public function commission(Request $r): JsonResponse { return $this->run($r, 'commission', 'commission', fn () => $this->desk->commission($this->ws($r), $this->wid($r), $this->uid($r), $r->all()), true); }

    // jobs
    public function jobs(Request $r): JsonResponse { return $this->run($r, 'jobs.read', null, fn () => $this->desk->listJobs($this->ws($r), $this->wid($r), $r->query())); }
    public function job(Request $r, int $id): JsonResponse { return $this->run($r, 'jobs.read', null, function () use ($r, $id) { $j = $this->desk->getJob($this->ws($r), $this->wid($r), $id); return $j ? ['success' => true, 'job' => $j] : ['success' => false, 'error' => 'NOT_FOUND']; }); }
    public function createJob(Request $r): JsonResponse { return $this->run($r, 'jobs.write', 'job.create', fn () => $this->desk->createJob($this->ws($r), $this->wid($r), $this->uid($r), $r->all()), true); }
    public function updateJob(Request $r, int $id): JsonResponse { return $this->run($r, 'jobs.write', 'job.update', fn () => $this->desk->updateJob($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->all())); }
    public function publishJob(Request $r, int $id): JsonResponse { return $this->run($r, 'jobs.publish', null, fn () => $this->desk->publishJob($this->ws($r), $this->wid($r), $this->uid($r), $id)); }
    public function jobStatus(Request $r, int $id): JsonResponse { return $this->run($r, 'jobs.publish', 'job.status', fn () => $this->desk->setJobStatus($this->ws($r), $this->wid($r), $this->uid($r), $id, (string) $r->input('status'))); }

    // inbox
    public function inbox(Request $r): JsonResponse { return $this->run($r, 'inbox.read', null, fn () => $this->desk->listInbox($this->ws($r), $this->wid($r), $r->query())); }
    public function inboxItem(Request $r, int $id): JsonResponse { return $this->run($r, 'inbox.read', null, function () use ($r, $id) { $i = $this->desk->getInboxItem($this->ws($r), $this->wid($r), $id); return $i ? ['success' => true, 'item' => $i] : ['success' => false, 'error' => 'NOT_FOUND']; }); }
    public function updateInbox(Request $r, int $id): JsonResponse { return $this->run($r, 'inbox.write', 'inbox.update', fn () => $this->desk->updateInboxItem($this->ws($r), $this->wid($r), $this->uid($r), $id, $r->all())); }
    public function jobFromInbox(Request $r, int $id): JsonResponse { return $this->run($r, 'jobs.write', null, fn () => $this->desk->jobFromInbox($this->ws($r), $this->wid($r), $this->uid($r), $id), true); }

    // members
    public function members(Request $r): JsonResponse { return $this->run($r, 'members.read', null, fn () => $this->desk->listMembers($this->ws($r), $this->wid($r))); }
    public function setMemberRole(Request $r, int $userId): JsonResponse { return $this->run($r, 'members.write', 'member.role', fn () => $this->desk->setMemberRole($this->ws($r), $this->wid($r), $this->uid($r), $userId, (string) $r->input('role'))); }
    public function invite(Request $r): JsonResponse { return $this->run($r, 'members.write', 'member.invite', fn () => $this->desk->invite($this->ws($r), $this->wid($r), $this->uid($r), (string) $r->input('email'), (string) $r->input('role', 'editor')), true); }
}
