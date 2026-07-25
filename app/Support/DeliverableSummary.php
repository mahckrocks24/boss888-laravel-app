<?php

namespace App\Support;

use App\Models\Task;

/**
 * Builds a friendly human-readable deliverable summary for a completed task,
 * given its engine/action. Used by /api/projects/tasks/{id} so the drawer
 * shows "Article #176 created" instead of dumping the runtime JSON envelope.
 *
 * Returns null for non-completed tasks.
 * Returns ['summary' => HTML, 'deliverable' => raw result_json] for completed.
 */
class DeliverableSummary
{
    public static function for(Task $t): ?array
    {
        if ($t->status !== 'completed' || !$t->result_json) return null;

        $raw  = is_array($t->result_json) ? $t->result_json : (json_decode((string)$t->result_json, true) ?: []);
        $data = (is_array($raw) && isset($raw['data'])) ? $raw['data'] : $raw;
        if (!is_array($data)) $data = [];

        $key = $t->engine . '/' . $t->action;
        $summary = self::summaryHtmlFor($key, $data, $raw);

        // 2026-05-27 Phase 3 — append a Sources section for research tasks
        // listing every URL the agent touched via AgentBrowser.
        if (($t->category ?? null) === 'research') {
            $summary .= self::sourcesHtmlFor($t);
        }

        return [
            'summary'     => $summary,
            'deliverable' => $raw,
        ];
    }

    /**
     * Build a "Sources" block citing every fetch/search the agent performed
     * during this research task. Empty string when there's no web activity.
     */
    private static function sourcesHtmlFor(Task $t): string
    {
        try {
            $rows = app(\App\Engines\Web\Services\WebActivityService::class)->forTask($t->id, 25);
        } catch (\Throwable $e) {
            return '';
        }
        if (empty($rows)) return '';

        $items = '';
        foreach ($rows as $r) {
            $action = $r['action'] ?? '';
            $target = $r['url_or_query'] ?? '';
            $title  = $r['title'] ?? '';
            $statusOk = ($r['status'] ?? '') === 'ok';
            $icon = $action === 'search' ? '&#128269;' : '&#127760;';  // 🔍 or 🌐
            $row = '<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border-top:1px solid var(--bd);font-size:11px">'
                 . '  <span style="font-size:14px;flex-shrink:0">' . $icon . '</span>'
                 . '  <div style="flex:1;min-width:0">'
                 . '    <div style="color:var(--t1);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . htmlspecialchars($title !== '' ? $title : $target) . '</div>'
                 . '    <div style="color:var(--t3);font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px">' . htmlspecialchars(mb_substr($target, 0, 200)) . '</div>'
                 . '  </div>'
                 . '  <span style="font-size:10px;color:' . ($statusOk ? 'var(--ac)' : 'var(--rd)') . ';flex-shrink:0">' . htmlspecialchars($r['status'] ?? '') . '</span>'
                 . '</div>';
            $items .= $row;
        }
        return '<div style="margin-top:14px;padding:10px 0;border-top:1px solid var(--bd)">'
             . '  <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh);margin-bottom:6px;padding:0 10px">Sources (' . count($rows) . ')</div>'
             . $items
             . '</div>';
    }

    private static function summaryHtmlFor(string $key, array $data, array $raw): string
    {
        $msg     = $raw['message'] ?? '';
        $tokens  = $data['tokens_used'] ?? null;
        $tokenLine = $tokens ? '<div style="margin-top:6px;font-size:11px;color:var(--t3)">Tokens used: ' . number_format((int)$tokens) . '</div>' : '';

        switch ($key) {
            case 'write/write_article':
            case 'seo/write_article':
                $id     = $data['article_id'] ?? null;
                $status = $data['status'] ?? 'draft';
                if ($id) {
                    return '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">📝 Article draft #' . (int)$id . ' generated</div>'
                        . '<div style="font-size:12px;color:var(--t2)">Status: <span style="color:var(--am)">' . htmlspecialchars($status) . '</span></div>'
                        . '<div style="margin-top:10px"><a href="/app/#write" onclick="if(window.nav){nav(\'write\');return false;}" style="display:inline-block;padding:6px 12px;background:var(--p);color:#fff;border-radius:6px;text-decoration:none;font-size:11px;font-weight:600">Open in Write Engine →</a></div>'
                        . $tokenLine;
                }
                return '<div style="font-size:12px;color:var(--t1)">' . htmlspecialchars($msg ?: 'Article generated.') . '</div>' . $tokenLine;

            case 'seo/deep_audit':
                $issues = $data['issues_count'] ?? (isset($data['issues']) && is_array($data['issues']) ? count($data['issues']) : null);
                $url    = $data['url'] ?? null;
                $head = '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">🔍 SEO audit complete' . ($url ? ' — <span style="color:var(--bl)">' . htmlspecialchars($url) . '</span>' : '') . '</div>';
                $body = $issues !== null
                    ? '<div style="font-size:12px;color:var(--t2)"><b>' . (int)$issues . '</b> ' . ($issues === 1 ? 'issue' : 'issues') . ' found</div>'
                    : '<div style="font-size:12px;color:var(--t2)">Audit completed.</div>';
                return $head . $body . $tokenLine;

            case 'social/create_post':
            case 'social/social_create_post':
                $id = $data['post_id'] ?? $data['entity_id'] ?? null;
                return '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">📱 Social post drafted' . ($id ? ' #' . (int)$id : '') . '</div>'
                    . '<div style="margin-top:8px"><a href="#" onclick="if(window.nav){nav(\'social\');return false;}" style="display:inline-block;padding:6px 12px;background:var(--p);color:#fff;border-radius:6px;text-decoration:none;font-size:11px;font-weight:600">Open in Social →</a></div>'
                    . $tokenLine;

            case 'marketing/create_campaign':
                $id = $data['entity_id'] ?? $data['campaign_id'] ?? null;
                return '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">📣 Campaign created' . ($id ? ' #' . (int)$id : '') . '</div>'
                    . '<div style="margin-top:8px"><a href="#" onclick="if(window.nav){nav(\'marketing\');return false;}" style="display:inline-block;padding:6px 12px;background:var(--p);color:#fff;border-radius:6px;text-decoration:none;font-size:11px;font-weight:600">Open in Marketing →</a></div>'
                    . $tokenLine;

            case 'crm/list_leads':
                $count = $data['count'] ?? (isset($data['leads']) && is_array($data['leads']) ? count($data['leads']) : null);
                return '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">🎯 Lead list returned</div>'
                    . ($count !== null ? '<div style="font-size:12px;color:var(--t2)"><b>' . (int)$count . '</b> ' . ($count === 1 ? 'lead' : 'leads') . '</div>' : '')
                    . '<div style="margin-top:8px"><a href="#" onclick="if(window.nav){nav(\'crm\');return false;}" style="display:inline-block;padding:6px 12px;background:var(--p);color:#fff;border-radius:6px;text-decoration:none;font-size:11px;font-weight:600">Open CRM →</a></div>';

            case 'write/generate_meta':
            case 'seo/generate_meta':
                $mt = $data['meta_title'] ?? null;
                $md = $data['meta_description'] ?? null;
                $out = '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">🏷 Meta generated</div>';
                if ($mt) $out .= '<div style="font-size:12px;color:var(--t2);margin-top:6px"><b>Title:</b> ' . htmlspecialchars($mt) . '</div>';
                if ($md) $out .= '<div style="font-size:12px;color:var(--t2);margin-top:4px"><b>Description:</b> ' . htmlspecialchars($md) . '</div>';
                return $out . $tokenLine;

            case 'write/aeo_enrich':
                return '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">✨ AEO enrichment complete</div>'
                    . '<div style="font-size:12px;color:var(--t2)">TLDR + FAQ + JSON-LD added to the article.</div>' . $tokenLine;

            case 'creative/generate_image_mini':
            case 'creative/generate_image':
            case 'creative/generate_image_high':
                $url = $data['url'] ?? $data['image_url'] ?? null;
                $out = '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">🎨 Image generated</div>';
                if ($url) $out .= '<div style="margin-top:8px"><img src="' . htmlspecialchars($url) . '" alt="Generated image" style="max-width:100%;border-radius:8px;border:1px solid var(--bd)"></div>';
                return $out . $tokenLine;

            case 'seo/insert_link':
                return '<div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:6px">🔗 Internal link inserted</div>'
                    . '<div style="font-size:12px;color:var(--t2)">' . htmlspecialchars($msg ?: 'Link added to article.') . '</div>' . $tokenLine;

            default:
                // Generic fallback — humanized key/value pairs, not raw JSON.
                if (!empty($data)) {
                    $rows = [];
                    foreach ($data as $k => $v) {
                        if (is_scalar($v) || $v === null) {
                            $rows[] = '<div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0;border-bottom:1px solid var(--bd)"><span style="color:var(--t3);text-transform:uppercase;letter-spacing:.05em">' . htmlspecialchars(str_replace('_', ' ', (string)$k)) . '</span><span style="color:var(--t1);font-weight:600">' . htmlspecialchars((string)$v) . '</span></div>';
                        }
                    }
                    if ($rows) {
                        return '<div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:8px">' . htmlspecialchars($msg ?: 'Task completed') . '</div>'
                            . implode('', $rows) . $tokenLine;
                    }
                }
                return '<div style="font-size:13px;color:var(--t1)">' . htmlspecialchars($msg ?: 'Action completed.') . '</div>' . $tokenLine;
        }
    }
}
