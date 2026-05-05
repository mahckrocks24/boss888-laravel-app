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
    public function ingestFile(int $workspaceId, UploadedFile $file, ?string $label = null): int
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
            ->update(['file_path' => $storedPath, 'updated_at' => now()]);

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
    public function ingestText(int $workspaceId, string $label, string $text): int
    {
        $text = trim($text);
        $hash = hash('sha256', $text);
        $sourceId = DB::table('chatbot_knowledge_sources')->insertGetId([
            'workspace_id' => $workspaceId,
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

    public function deleteSource(int $workspaceId, int $sourceId): bool
    {
        $row = DB::table('chatbot_knowledge_sources')
            ->where('id', $sourceId)->where('workspace_id', $workspaceId)->first();
        if (! $row) return false;

        if ($row->file_path && Storage::disk('local')->exists($row->file_path)) {
            Storage::disk('local')->delete($row->file_path);
        }
        // Cascade delete chunks.
        DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->delete();
        return true;
    }

    /**
     * Retrieve top N chunks for a query. FULLTEXT first, fallback to LIKE.
     * Always scoped to workspace_id — workspace isolation invariant.
     */
    public function retrieveChunks(int $workspaceId, string $query, int $topN = 5): array
    {
        $query = trim($query);
        if ($query === '') return [];

        // FULLTEXT NATURAL LANGUAGE
        try {
            $rows = DB::select(
                'SELECT id, source_id, chunk_text, MATCH(chunk_text) AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                 FROM chatbot_knowledge_chunks
                 WHERE workspace_id = ?
                   AND MATCH(chunk_text) AGAINST(? IN NATURAL LANGUAGE MODE)
                 ORDER BY score DESC LIMIT ?',
                [$query, $workspaceId, $query, $topN]
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
        $chunks = $this->chunkText($text);
        $rows = [];
        foreach ($chunks as $i => $chunk) {
            $rows[] = [
                'source_id'    => $sourceId,
                'workspace_id' => $workspaceId,
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
