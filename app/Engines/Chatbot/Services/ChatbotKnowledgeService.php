<?php

namespace App\Engines\Chatbot\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * CHATBOT888 — Knowledge base service.
 *
 * Extracts text from uploaded files (PDF / DOCX / TXT / MD) or accepts
 * pasted text, chunks it into ~800-char windows with 100-char overlap,
 * and persists chunks to chatbot_knowledge_chunks where a FULLTEXT index
 * drives retrieval (per Phase 0 design call D2 — no vector search in v1).
 *
 * Files live under storage/app/private/chatbot-kb/{wsId}/{source_id}-{slug}.
 * They are NEVER served from /public; download flows through an
 * authenticated admin endpoint.
 */
class ChatbotKnowledgeService
{
    public const ALLOWED_MIME = [
        'application/pdf'           => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'text/plain'                => 'txt',
        'text/markdown'             => 'md',
        'text/x-markdown'           => 'md',
    ];

    public const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB

    private const CHUNK_SIZE   = 800;
    private const CHUNK_OVERLAP = 100;

    /**
     * Ingest an uploaded file. Returns the chatbot_knowledge_sources id.
     *
     * Throws \InvalidArgumentException for MIME / size violations and
     * \RuntimeException for extraction failures.
     */
    public function ingestFile(int $workspaceId, UploadedFile $file, ?string $label = null, ?int $websiteId = null): int
    {
        $clientMime = $file->getClientMimeType();
        $serverMime = $file->getMimeType() ?: $clientMime;
        $kind = self::ALLOWED_MIME[$serverMime] ?? self::ALLOWED_MIME[$clientMime] ?? null;
        if ($kind === null) {
            throw new \InvalidArgumentException('Unsupported file type. Allowed: PDF, DOCX, TXT, MD.');
        }
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('File too large (max 10 MB).');
        }

        // Hash for de-dup before any IO.
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $workspaceId)
            ->where('content_hash', $hash)
            ->first();
        if ($existing) {
            return (int) $existing->id;
        }

        // Persist row first so the source_id is available for the path.
        $sourceId = DB::table('chatbot_knowledge_sources')->insertGetId([
            'workspace_id' => $workspaceId,
            'website_id'   => $websiteId,
            'source_type'  => 'file',
            'label'        => $label ?: $file->getClientOriginalName(),
            'mime_type'    => $serverMime,
            'size_bytes'   => $file->getSize(),
            'content_hash' => $hash,
            'status'       => 'processing',
            'created_at'   => now(), 'updated_at' => now(),
        ]);

        // Move file to private storage with safe name.
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $extension = $kind;
        $storedPath = sprintf('chatbot-kb/%d/%d-%s.%s', $workspaceId, $sourceId, $safeName ?: 'doc', $extension);
        Storage::disk('local')->putFileAs(
            dirname($storedPath),
            $file,
            basename($storedPath)
        );
        DB::table('chatbot_knowledge_sources')->where('id', $sourceId)
            ->update(['stored_path' => $storedPath, 'updated_at' => now()]); // 2026-06-10 fix: column is stored_path, not file_path (was 500 on every file upload)

        // Extract + chunk + index.
        try {
            $absPath = Storage::disk('local')->path($storedPath);
            $rawText = $this->extractText($absPath, $kind);
            $this->indexChunks($workspaceId, $sourceId, $rawText);
            DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->update([
                'raw_text' => $rawText, 'status' => 'ready', 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[chatbot] ingestFile extraction failed', [
                'source_id' => $sourceId, 'error' => $e->getMessage(),
            ]);
            DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->update([
                'status' => 'failed', 'error_message' => substr($e->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
        }

        return $sourceId;
    }

    /**
     * Ingest pasted text. Returns the source id. Always succeeds (text
     * extraction is a no-op).
     */
    public function ingestText(int $workspaceId, string $label, string $text, ?int $websiteId = null): int
    {
        $text = trim($text);
        $hash = hash('sha256', $text);
        $sourceId = DB::table('chatbot_knowledge_sources')->insertGetId([
            'workspace_id' => $workspaceId,
            'website_id'   => $websiteId,
            'source_type'  => 'text',
            'label'        => $label ?: 'Manual text',
            'size_bytes'   => strlen($text),
            'raw_text'     => $text,
            'content_hash' => $hash,
            'status'       => 'processing',
            'created_at'   => now(), 'updated_at' => now(),
        ]);

        $this->indexChunks($workspaceId, $sourceId, $text);
        DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->update([
            'status' => 'ready', 'updated_at' => now(),
        ]);
        return $sourceId;
    }

    /**
     * 2026-07-02 — Ground a website's chatbot KB on its OWN pages.sections_json
     * (platform-native content), tagged with website_id. Fixes the two G3 gaps at
     * once: the KB is derived from real page content (not just manual text / an
     * external crawl), AND every chunk carries website_id → real per-website
     * separation. Reuses ingestText → indexChunks (FULLTEXT-searchable). Idempotent:
     * replaces the prior "Website content:" source for this website on each run.
     */
    public function syncWebsiteContent(int $websiteId): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->first();
        if (! $site) {
            return ['success' => false, 'error' => 'website not found', 'website_id' => $websiteId];
        }
        $wsId = (int) $site->workspace_id;

        $pages = DB::table('pages')->where('website_id', $websiteId)
            ->get(['id', 'title', 'slug', 'sections_json']);

        $parts = [];
        $pageCount = 0;
        foreach ($pages as $p) {
            $decoded = json_decode((string) $p->sections_json, true);
            if (! is_array($decoded)) {
                continue;
            }
            // Accept both the raw top-level array and the {schemaVersion,sections} wrapper.
            $sections = $decoded['sections'] ?? $decoded;
            $text = is_array($sections) ? $this->extractSectionsText($sections) : '';
            if (trim($text) !== '') {
                $parts[] = '# ' . (string) ($p->title ?: $p->slug ?: 'Page') . "\n" . $text;
                $pageCount++;
            }
        }

        $full = trim(implode("\n\n", $parts));
        if ($full === '') {
            return ['success' => false, 'error' => 'no extractable content', 'website_id' => $websiteId];
        }

        // Idempotency — drop the prior synced source (+ its chunks) for this website.
        $priorIds = DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $wsId)
            ->where('website_id', $websiteId)
            ->where('source_type', 'text')
            ->where('label', 'like', 'Website content:%')
            ->pluck('id');
        foreach ($priorIds as $pid) {
            DB::table('chatbot_knowledge_chunks')->where('source_id', $pid)->delete();
            DB::table('chatbot_knowledge_sources')->where('id', $pid)->delete();
        }

        $label = 'Website content: ' . (string) ($site->name ?: ('site ' . $websiteId));
        $sourceId = $this->ingestText($wsId, $label, $full, $websiteId);
        $chunks = (int) DB::table('chatbot_knowledge_chunks')->where('source_id', $sourceId)->count();

        return [
            'success'      => true,
            'website_id'   => $websiteId,
            'workspace_id' => $wsId,
            'source_id'    => $sourceId,
            'pages'        => $pageCount,
            'chars'        => strlen($full),
            'chunks'       => $chunks,
        ];
    }

    /**
     * Pull human-readable content out of a sections_json array (headings, body,
     * item/tier/member text, FAQ Q&A, stats labels/values...) while skipping
     * structural/asset fields (type/style/urls/colors/images). Deterministic —
     * no LLM. De-duplicates repeated strings, preserves order.
     */
    private function extractSectionsText(array $sections): string
    {
        $skipKeys = [
            'type', 'style', 'icon', 'logo_image', 'image', 'background_image', 'photo',
            'src', 'url', 'href', 'cta_url', 'link', 'columns', 'variant', 'tone',
            'schemaversion', 'logo_url', 'is_homepage',
        ];
        $out = [];
        $walk = function ($node) use (&$walk, &$out, $skipKeys) {
            if (is_string($node)) {
                $s = trim($node);
                if ($s === '' || mb_strlen($s) < 2) {
                    return;
                }
                if (preg_match('#^(https?://|/|mailto:|tel:|\#|\{|\[)#i', $s)) {
                    return; // urls / anchors / raw json
                }
                if (preg_match('/^#?[0-9a-fA-F]{3,8}$/', $s)) {
                    return; // hex colour
                }
                $out[] = $s;
            } elseif (is_array($node)) {
                foreach ($node as $k => $v) {
                    if (is_string($k) && in_array(strtolower($k), $skipKeys, true)) {
                        continue;
                    }
                    $walk($v);
                }
            }
        };
        $walk($sections);

        $seen = [];
        $res = [];
        foreach ($out as $s) {
            $k = mb_strtolower($s);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $res[] = $s;
        }
        return implode("\n", $res);
    }

    public function deleteSource(int $workspaceId, int $sourceId): bool
    {
        $row = DB::table('chatbot_knowledge_sources')
            ->where('id', $sourceId)->where('workspace_id', $workspaceId)->first();
        if (! $row) return false;

        if ($row->stored_path && Storage::disk('local')->exists($row->stored_path)) {
            Storage::disk('local')->delete($row->stored_path);
        }
        // Cascade delete chunks.
        DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->delete();
        return true;
    }

    /**
     * Retrieve top N chunks for a query. FULLTEXT first, fallback to LIKE.
     * Always scoped to workspace_id — workspace isolation invariant.
     */
    public function retrieveChunks(int $workspaceId, string $query, int $topN = 5, ?int $websiteId = null): array
    {
        $query = trim($query);
        if ($query === '') return [];

        // B1 (2026-06-23) — per-website KB scoping. When a websiteId is given,
        // match THAT website's chunks plus legacy workspace-level chunks
        // (website_id NULL) as a fallback — so each website's chatbot answers
        // from its own knowledge base without breaking pre-migration data.
        // RISK-0118 (2026-08-29) — STRICT: a website-scoped session reads only its own chunks.
        // Workspace-level (NULL) chunks were back-filled to the single website of single-site
        // workspaces; in a multi-site workspace an unassigned source is not shared by accident.
        $webSql = $websiteId ? ' AND website_id = ?' : '';

        // FULLTEXT NATURAL LANGUAGE
        try {
            $params = [$query, $workspaceId];
            if ($websiteId) { $params[] = $websiteId; }
            $params[] = $query;
            $params[] = $topN;
            $rows = DB::select(
                'SELECT id, source_id, chunk_text, MATCH(chunk_text) AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                 FROM chatbot_knowledge_chunks
                 WHERE workspace_id = ?' . $webSql . '
                   AND MATCH(chunk_text) AGAINST(? IN NATURAL LANGUAGE MODE)
                 ORDER BY score DESC LIMIT ?',
                $params
            );
            if (! empty($rows)) {
                return array_map(fn($r) => (array) $r, $rows);
            }
        } catch (\Throwable $e) {
            Log::warning('[chatbot] FULLTEXT retrieval failed, falling back', ['error' => $e->getMessage()]);
        }

        // Fallback LIKE on the top tokens (3+ chars each, max 5 tokens).
        $tokens = array_slice(array_filter(
            preg_split('/\s+/', strtolower($query)),
            fn($t) => strlen($t) >= 3
        ), 0, 5);
        if (empty($tokens)) return [];

        $q = DB::table('chatbot_knowledge_chunks')
            ->where('workspace_id', $workspaceId);
        if ($websiteId) {
            $q->where('website_id', $websiteId);
        }
        foreach ($tokens as $tok) {
            $q->where('chunk_text', 'like', '%' . $tok . '%');
        }
        return $q->limit($topN)->get(['id', 'source_id', 'chunk_text'])->map(fn($r) => (array) $r)->all();
    }

    // ── Private ──────────────────────────────────────────────

    private function extractText(string $path, string $kind): string
    {
        switch ($kind) {
            case 'pdf':
                $parser = new PdfParser();
                $pdf = $parser->parseFile($path);
                return trim($pdf->getText());
            case 'docx':
                $reader = WordIOFactory::createReader('Word2007');
                $doc = $reader->load($path);
                $text = '';
                foreach ($doc->getSections() as $section) {
                    foreach ($section->getElements() as $el) {
                        $text .= $this->extractWordElement($el) . "\n";
                    }
                }
                return trim($text);
            case 'txt':
            case 'md':
                $raw = file_get_contents($path);
                return trim($raw === false ? '' : $raw);
        }
        throw new \RuntimeException("Unsupported extraction kind: {$kind}");
    }

    private function extractWordElement(object $el): string
    {
        // Recursive flatten of PhpWord element tree → plaintext.
        if (method_exists($el, 'getText')) {
            $val = $el->getText();
            if (is_string($val)) return $val;
        }
        if (method_exists($el, 'getElements')) {
            $out = '';
            foreach ($el->getElements() as $child) {
                $out .= $this->extractWordElement($child) . ' ';
            }
            return trim($out);
        }
        return '';
    }

    private function indexChunks(int $workspaceId, int $sourceId, string $text): void
    {
        DB::table('chatbot_knowledge_chunks')->where('source_id', $sourceId)->delete();
        // B1 (2026-06-23) — chunks inherit website_id from their source so
        // retrieval can scope the KB per website (NULL = workspace-level).
        $websiteId = DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->value('website_id');
        $chunks = $this->chunkText($text);
        $rows = [];
        foreach ($chunks as $i => $chunk) {
            $rows[] = [
                'source_id'    => $sourceId,
                'workspace_id' => $workspaceId,
                'website_id'   => $websiteId,
                'chunk_index'  => $i,
                'chunk_text'   => $chunk,
                'char_count'   => strlen($chunk),
                'created_at'   => now(),
            ];
        }
        if (! empty($rows)) {
            // Insert in batches to avoid placeholder overflow.
            foreach (array_chunk($rows, 200) as $batch) {
                DB::table('chatbot_knowledge_chunks')->insert($batch);
            }
        }
        DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->update([
            'chunk_count' => count($chunks),
            'updated_at'  => now(),
        ]);
    }

    private function chunkText(string $text): array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);
        if ($text === '') return [];
        $chunks = [];
        $offset = 0;
        $len = strlen($text);
        while ($offset < $len) {
            $chunk = substr($text, $offset, self::CHUNK_SIZE);
            $chunks[] = $chunk;
            $offset += self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        }
        return $chunks;
    }
}
