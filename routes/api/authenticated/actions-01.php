<?php

/*
 * APPROVE-BUTTONS-1 (Owner 2026-09-25: "Let's expand approval by incorporating execution buttons when Sarah asks for
 * ANY approval. Add it with the chat surfaces. Text should stay as an option").
 *
 * GET /api/agents/{slug}/pending-actions — what the chat surfaces render as buttons under Sarah's latest message:
 *   items[]  every approval still waiting in this workspace (a plan's gate, a held task, a strategy proposal), newest
 *            first, with the label the customer already sees under Needs attention, the credits, and the ids the
 *            ordinary endpoints take (POST /approvals/{id}/approve | /reject).
 *   offer    true when Sarah's latest message(s) asked for a go-ahead in words ("I can share… I need your approval
 *            first", "say yes", "whenever you're ready") — the surface shows Go / Not now chips that SEND TEXT, so
 *            typing stays an option and the platform's own turn rules decide, exactly as if the customer had typed it.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/agents/{slug}/pending-actions', function (Request $r, $slug) {
    $wsId = (int) $r->attributes->get('workspace_id');
    $slug = strtolower((string) $slug); if ($slug === 'dmm') $slug = 'sarah';
    $after = (int) $r->query('after_message_id', 0);
    $since = now()->subHours((int) max(1, min(168, (int) $r->query('hours', 48))));

    $rows = DB::table('approvals as a')->leftJoin('tasks as t', 't.id', '=', 'a.task_id')
        ->where('a.workspace_id', $wsId)->where('a.status', 'pending')->where('a.created_at', '>=', $since)
        ->orderByDesc('a.id')->limit(10)
        ->get(['a.id', 'a.task_id', 'a.proposal_id', 'a.engine as a_engine', 'a.action as a_action', 'a.created_at', 'a.data_json',
               't.engine', 't.action', 't.payload_json', 't.credit_cost', 't.progress_message', 't.mandate_id']);

    $human = function (string $engine, string $action, ?array $p): string {
        $phrases = [
            'write_article' => 'write an article', 'publish_article' => 'publish an article', 'improve_draft' => 'improve a draft',
            'generate_image' => 'create an image', 'create_post' => 'draft a social post', 'social_create_post' => 'post on social',
            'publish_post' => 'publish a social post', 'send_campaign' => 'send an email campaign', 'deep_audit' => 'run a technical SEO audit',
            'run_audit' => 'run an SEO audit', 'fix_orphans' => 'fix orphan pages', 'add_keyword' => 'track a keyword', 'ask_arthur' => 'update the website',
        ];
        $what = $phrases[$action] ?? lcfirst(str_replace('_', ' ', $action));
        $desc = is_array($p) ? trim((string) ($p['description'] ?? $p['title'] ?? $p['topic'] ?? '')) : '';
        return ucfirst($what) . ($desc !== '' ? ': ' . mb_substr($desc, 0, 90) : '');
    };

    $items = [];
    foreach ($rows as $row) {
        $p = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: null) : null;
        $engine = (string) ($row->engine ?: $row->a_engine ?: 'system'); $action = (string) ($row->action ?: $row->a_action ?: 'review');
        if ($engine === 'sarah' && $action === 'execute_plan') {
            $items[] = ['approval_id' => (int) $row->id, 'kind' => 'plan', 'task_id' => (int) $row->task_id,
                'label' => 'Approve the plan: ' . (string) ($p['title'] ?? 'Plan of action'),
                'description' => count($p['tasks'] ?? []) . ' task' . (count($p['tasks'] ?? []) === 1 ? '' : 's') . ' · up to ' . (int) ($p['spend_ceiling'] ?? 0) . ' credit' . ((int) ($p['spend_ceiling'] ?? 0) === 1 ? '' : 's') . ' · valid ' . (int) ($p['validity_days'] ?? 90) . ' days',
                'lines' => array_values(array_slice((array) ($p['task_lines'] ?? []), 0, 8)),
                'credits' => (int) ($p['spend_ceiling'] ?? 0), 'created_at' => (string) $row->created_at];
            continue;
        }
        if (! empty($row->proposal_id)) {
            $prop = DB::table('strategy_proposals')->where('id', (int) $row->proposal_id)->first(['title', 'description', 'total_credits']);
            $items[] = ['approval_id' => (int) $row->id, 'kind' => 'proposal', 'proposal_id' => (int) $row->proposal_id,
                'label' => (string) ($prop->title ?? 'A growth proposal'), 'description' => mb_substr((string) ($prop->description ?? ''), 0, 140), 'lines' => [],
                'credits' => (int) ($prop->total_credits ?? 0), 'created_at' => (string) $row->created_at];
            continue;
        }
        $items[] = ['approval_id' => (int) $row->id, 'kind' => 'task', 'task_id' => $row->task_id ? (int) $row->task_id : null,
            'label' => $human($engine, $action, $p), 'description' => '', 'lines' => [],
            'credits' => (int) ($row->credit_cost ?? 0), 'created_at' => (string) $row->created_at];
    }

    // Sarah asked in words? Her latest message(s) — the same shapes the turn classifier treats as her offer.
    $offer = false; $offerMessageId = null;
    try {
        $last = DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', $slug)->where('role', 'agent')
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))->orderByDesc('id')->limit(1)->first(['id', 'content']);
        if ($last && is_string($last->content) && preg_match(\App\Core\Sarah888\SpendPolicy::OFFER_SHAPE, $last->content)
            && ! preg_match('/\b(?:approved|done —|is running now|went up|are live now)\b/i', $last->content)) {
            $offer = true; $offerMessageId = (int) $last->id;
        }
    } catch (\Throwable $e) { $offer = false; }

    return response()->json(['success' => true, 'items' => $items, 'offer' => $offer, 'offer_message_id' => $offerMessageId,
        'quick_replies' => $offer ? [['label' => 'Go', 'text' => 'go'], ['label' => 'Not now', 'text' => 'not now']] : []]);
});
