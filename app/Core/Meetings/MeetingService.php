<?php

namespace App\Core\Meetings;

use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Core\Audit\AuditLogService;

class MeetingService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function create(int $workspaceId, int $userId, array $data): Meeting
    {
        $meeting = Meeting::create([
            'workspace_id' => $workspaceId,
            'title' => $data['title'],
            'created_by' => $userId,
        ]);

        // Add creator as participant
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'participant_type' => 'user',
            'participant_id' => $userId,
        ]);

        // Add agents if specified
        foreach ($data['agent_ids'] ?? [] as $agentId) {
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'participant_type' => 'agent',
                'participant_id' => $agentId,
            ]);
        }

        $this->auditLog->log($workspaceId, $userId, 'meeting.created', 'Meeting', $meeting->id);

        return $meeting->load(['participants', 'messages']);
    }

    public function listForWorkspace(int $workspaceId): \Illuminate\Database\Eloquent\Collection
    {
        return Meeting::where('workspace_id', $workspaceId)
            ->with('creator')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function find(int $meetingId): ?Meeting
    {
        return Meeting::with(['messages', 'participants', 'tasks'])->find($meetingId);
    }

    public function addMessage(int $meetingId, string $senderType, int $senderId, string $message, ?array $attachments = null): MeetingMessage
    {
        return MeetingMessage::create([
            'meeting_id' => $meetingId,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'message' => $message,
            'attachments_json' => $attachments,
        ]);
    }
}
