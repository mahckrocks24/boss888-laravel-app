<?php

namespace App\Engines\Write\Http\Controllers;

use App\Http\Controllers\Api\BaseEngineController;
use App\Engines\Write\Services\WriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WriteController extends BaseEngineController
{
    public function __construct(private WriteService $write) {}
    protected function engineSlug(): string { return 'write'; }

    // READS
    public function listArticles(Request $r): JsonResponse
    {
        $out = $this->write->listArticles($this->wsId($r), $r->all());
        // WRITE-1 (2026-08-29): the editor reads `items`; other callers read `articles`. Serve both.
        if (is_array($out) && isset($out['articles']) && !isset($out['items'])) {
            $out['items']   = collect($out['articles'])->map(fn($a) => $this->editorShape($a))->values()->all();
            $out['success'] = true;
        }
        return $this->readJson($out);
    }
    public function getArticle(Request $r, int $id): JsonResponse
    {
        $a = $this->write->getArticle($this->wsId($r), $id);
        if (!$a) {
            return $this->readJson(['success' => false, 'error' => 'not_found', 'message' => 'Article not found.'], 404);
        }
        // WRITE-1: {success,item} for the editor + the bare fields for older callers.
        $shaped = $this->editorShape($a);
        return $this->readJson($shaped + ['success' => true, 'item' => $shaped]);
    }

    /**
     * WRITE-1 — the shape the Write888 editor (write.js) renders: plain_text (the editable text),
     * content_json (outline source) and content_type, derived from the real columns.
     */
    private function editorShape($a): array
    {
        $a = is_object($a) ? (array) $a : (array) $a;
        $content = (string) ($a['content'] ?? '');
        if (!array_key_exists('plain_text', $a) || $a['plain_text'] === null || $a['plain_text'] === '') {
            $a['plain_text'] = $this->htmlToPlain($content);
        }
        if (empty($a['content_json'])) {
            $a['content_json'] = $content !== '' ? json_encode(['version' => 1, 'html' => true, 'blocks' => $this->htmlToBlocks($content)]) : null;
        }
        if (empty($a['content_type'])) {
            $a['content_type'] = $a['type'] ?? 'blog_article';
        }
        return $a;
    }

    private function htmlToPlain(string $html): string
    {
        if ($html === '') return '';
        $t = preg_replace('#<\s*(br|/p|/h[1-6]|/li|/div|/blockquote|/tr)\s*/?>#i', "$0\n", $html);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", (string) $t);
        $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
        return trim((string) $t);
    }

    private function htmlToBlocks(string $html): array
    {
        $blocks = [];
        if (preg_match_all('#<(h[1-6]|p|li)\b[^>]*>(.*?)</\1>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $text = trim(html_entity_decode(strip_tags($x[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') continue;
                $tag = strtolower($x[1]);
                $blocks[] = ['type' => $tag[0] === 'h' ? 'heading' : ($tag === 'li' ? 'list_item' : 'paragraph'), 'level' => $tag[0] === 'h' ? (int) $tag[1] : null, 'text' => $text];
            }
        }
        return $blocks;
    }

    /** Plain text from the editor → HTML paragraphs (lines starting with '# ' become headings). */
    private function plainToHtml(string $plain): string
    {
        $out = [];
        foreach (preg_split("/\n{2,}/", str_replace("\r", '', trim($plain))) as $para) {
            $para = trim($para);
            if ($para === '') continue;
            if (preg_match('/^(#{1,6})\s+(.+)$/s', $para, $h)) {
                $lvl = strlen($h[1]);
                $out[] = "<h{$lvl}>" . e(trim($h[2])) . "</h{$lvl}>";
                continue;
            }
            $out[] = '<p>' . nl2br(e($para)) . '</p>';
        }
        return implode("\n", $out);
    }
    public function getVersions(Request $r, int $id): JsonResponse { return $this->readJson(['versions' => $this->write->getVersions($id, $this->wsId($r))]); }
    public function dashboard(Request $r): JsonResponse { return $this->readJson($this->write->getDashboard($this->wsId($r))); }

    // WRITES — through pipeline
    public function createArticle(Request $r): JsonResponse { $r->validate(['title' => 'required|string']); return $this->executeAction($r, 'create_article', $r->all(), 'manual', 201); }
    public function updateArticle(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);
        $data = $r->all();
        // WRITE-1: the editor saves plain_text. Only rewrite `content` when the text actually changed —
        // an untouched HTML article must survive a status-only save intact.
        if (!array_key_exists('content', $data) && !array_key_exists('body', $data) && array_key_exists('plain_text', $data)) {
            $cur = $this->write->getArticle($wsId, $id);
            $curPlain = $cur ? $this->htmlToPlain((string) ($cur->content ?? '')) : '';
            $newPlain = trim(str_replace("\r", '', (string) $data['plain_text']));
            if ($newPlain !== '' && $newPlain !== $curPlain) {
                $data['content'] = $this->plainToHtml($newPlain);
            }
            unset($data['plain_text'], $data['content_json']);
        }
        $res  = $this->write->updateArticle($id, $data, $wsId);
        $item = $this->write->getArticle($wsId, $id);
        $out  = (is_array($res) ? $res : []) + ['success' => true, 'item' => $item ? $this->editorShape($item) : null];
        if (!empty($res['wordpress'])) {
            $wp = $res['wordpress'];
            $out['message'] = !empty($wp['ok'])
                ? 'Saved. Published to your WordPress site' . (!empty($wp['url']) ? ' at ' . $wp['url'] : '') . '.'
                : 'Saved, but the WordPress publish FAILED: ' . ($wp['error'] ?? 'unknown error');
        }
        return $this->readJson($out);
    }
    public function deleteArticle(Request $r, int $id): JsonResponse { $this->write->deleteArticle($id, $this->wsId($r)); return $this->readJson(['success' => true]); }
    public function restoreVersion(Request $r, int $id, int $versionId): JsonResponse { return $this->readJson($this->write->restoreVersion($id, $versionId, $this->wsId($r))); }
    public function writeArticle(Request $r): JsonResponse { return $this->executeAction($r, 'write_article', $r->all()); }
    public function improveDraft(Request $r): JsonResponse { return $this->executeAction($r, 'improve_draft', $r->all()); }
    public function generateOutline(Request $r): JsonResponse { return $this->executeAction($r, 'generate_outline', $r->all()); }
    public function generateHeadlines(Request $r): JsonResponse { return $this->executeAction($r, 'generate_headlines', $r->all()); }
    public function generateMeta(Request $r): JsonResponse { return $this->executeAction($r, 'generate_meta', $r->all()); }
}
