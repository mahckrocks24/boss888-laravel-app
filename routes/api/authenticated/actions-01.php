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

    // PREVIEW-1: drafts waiting to be posted — the preview card in the chat (Post it / Edit / Not now)
    $drafts = [];
    try {
        $rows = DB::table('social_posts as p')->leftJoin('articles as a', 'a.id', '=', 'p.article_id')->leftJoin('websites as w', 'w.id', '=', \Illuminate\Support\Facades\DB::raw('COALESCE(p.website_id, a.website_id)'))
            ->where('p.workspace_id', $wsId)->whereNull('p.deleted_at')->where('p.status', 'draft')->whereNull('p.preview_dismissed_at')->where('p.created_at', '>=', $since)   /* PREVIEW-DISMISS-1 */
            ->orderByDesc('p.id')->limit(6)
            ->get(['p.id', 'p.platform', 'p.content', 'p.media_json', 'p.hashtags_json', 'p.canonical_url', 'p.article_id', 'p.business_id', 'p.website_id', 'p.social_account_id', 'p.created_at', 'p.execution_status', 'p.failure_class',
                   'a.title as article_title', 'a.meta_title as article_meta_title', 'a.featured_image_url', 'a.slug as article_slug', 'a.website_id as article_website_id', 'a.meta_description', 'a.excerpt', 'w.custom_domain', 'w.subdomain', 'w.business_id as site_business_id']);
        $resolver = app(\App\Engines\Social\Services\SocialAccountResolver::class);
        foreach ($rows as $d) {
            $media = json_decode((string) ($d->media_json ?? '[]'), true) ?: [];
            $first = is_array($media) && $media ? (is_array($media[0]) ? ($media[0]['url'] ?? $media[0]['src'] ?? null) : $media[0]) : null;
            $host = $d->custom_domain ?: $d->subdomain;
            $link = $d->canonical_url ?: (($host && $d->article_slug) ? 'https://' . preg_replace('#^https?://#', '', rtrim((string) $host, '/')) . '/blog/' . ltrim((string) $d->article_slug, '/') : null);
            $account = null;
            try {
                $res = $resolver->resolve($wsId, (string) $d->platform, $d->business_id ? (int) $d->business_id : ($d->site_business_id ? (int) $d->site_business_id : null),
                    $d->website_id ? (int) $d->website_id : ($d->article_website_id ? (int) $d->article_website_id : null), $d->article_id ? (int) $d->article_id : null, $d->social_account_id ? (int) $d->social_account_id : null);
                if (! empty($res['ok']) && ! empty($res['account'])) {
                    $acc = $res['account'];
                    $biz = ! empty($acc->business_id) ? DB::table('businesses')->where('id', (int) $acc->business_id)->value('name') : null;
                    $__stats = json_decode((string) ($acc->stats_json ?? ''), true) ?: [];
                    $account = ['id' => (int) $acc->id, 'name' => (string) $acc->account_name, 'business' => $biz ? (string) $biz : null,
                        'avatar' => (is_array($__stats) && ! empty($__stats['picture_url']) && preg_match('#^https://#', (string) $__stats['picture_url'])) ? (string) $__stats['picture_url'] : null];   // PREVIEW-3: the Page's real picture
                } else {
                    $account = ['id' => null, 'name' => null, 'business' => null, 'problem' => (string) ($res['message'] ?? 'No connected account for this platform yet.')];
                }
            } catch (\Throwable $e) { $account = null; }
            $drafts[] = ['post_id' => (int) $d->id, 'platform' => (string) $d->platform,
                'account' => $account, 'caption' => (string) $d->content, 'hashtags' => array_values(array_filter((array) (json_decode((string) ($d->hashtags_json ?? '[]'), true) ?: []))),
                'link' => $link, 'image' => $first ?: ($d->featured_image_url ?: null), 'article_id' => $d->article_id ? (int) $d->article_id : null,
                // POST-MEDIA-1: the post's own media — a media post goes out as a photo/video, not as a link card
                'media_url' => $first ?: null, 'media_kind' => $first ? ((is_array($media[0]) && ($media[0]['type'] ?? '') === 'video') || preg_match('#\.(mp4|mov|m4v|webm)(\?|$)#i', (string) $first) ? 'video' : 'image') : null,
                'article_title' => ($d->article_meta_title ?: $d->article_title) ? (string) ($d->article_meta_title ?: $d->article_title) : null,   // OG-URL-1c: the title Facebook shows (og:title)
                'description' => mb_substr(trim((string) ($d->meta_description ?: $d->excerpt ?: '')), 0, 160) ?: null,   // PREVIEW-2: the link card's blurb
                'domain' => $link ? strtoupper((string) preg_replace('#^https?://(www\.)?([^/]+).*$#', '$2', $link)) : null,
                'ready' => trim((string) $d->content) !== '' && ! empty($account['name']),
                'execution_status' => $d->execution_status ? (string) $d->execution_status : null, 'failure_class' => $d->failure_class ? (string) $d->failure_class : null,   // PREVIEW-3: a draft that already went through a dry run says so on load
                'created_at' => (string) $d->created_at];
        }
    } catch (\Throwable $e) { $drafts = []; }

    return response()->json(['success' => true, 'items' => $items, 'drafts' => $drafts, 'offer' => $offer, 'offer_message_id' => $offerMessageId,
        'quick_replies' => $offer ? [['label' => 'Go', 'text' => 'go'], ['label' => 'Not now', 'text' => 'not now']] : []]);
});
