<?php

namespace App\Engines\Chatbot\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

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
    /**
     * 2026-09-10 — the formats a customer actually has lying around. Everything here is converted to
     * MARKDOWN before it is chunked, so the knowledge base holds one predictable shape whatever was
     * uploaded, and a spreadsheet keeps its rows and columns instead of collapsing into a word soup.
     * No new composer dependency was taken: PDFs go through poppler's pdftotext, which is installed;
     * Word/ODT/RTF through the PhpWord readers already vendored; XLSX through ZipArchive + SimpleXML,
     * because an .xlsx IS a zip of XML.
     */
    public const ALLOWED_MIME = [
        'application/pdf'           => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword'        => 'doc',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/rtf'           => 'rtf',
        'text/rtf'                  => 'rtf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel'  => 'xls',
        'text/csv'                  => 'csv',
        'application/csv'           => 'csv',
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
            throw new \InvalidArgumentException('Unsupported file type. Allowed: PDF, Word (.docx/.doc), Excel (.xlsx), CSV, ODT, RTF, TXT and Markdown.');
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
        // 2026-09-10 — FAIL CLOSED WHEN THE WEBSITE IS UNKNOWN IN A MULTI-SITE BUSINESS.
        // The strict filter below only applies when $websiteId is truthy. A session that resolved to 0
        // dropped the website condition entirely and retrieved EVERY website's chunks in the workspace,
        // which is the opposite of the RISK-0118 intent stated just above.
        // The path is reachable: widget tokens issued before the website binding still exist (including
        // in live multi-site workspaces), and the Origin fallback returns website_id 0 whenever the
        // embedding host is not one of this business's known hosts — a foreign embed, or a stripped
        // Origin. Two shopfronts of one business would then answer from each other's documents.
        // WebsiteScope's rule is that an unknown website is never guessed; the same rule has to hold
        // here, so retrieval returns NOTHING rather than everything.
        // Single-site businesses are untouched: their sessions resolve to the only website, and their
        // historic NULL-website chunks were back-filled to it.
        if (! $websiteId) {
            $siteCount = DB::table('websites')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->count();
            if ($siteCount > 1) {
                Log::warning('[chatbot] retrieval refused: multi-site workspace with no website resolved', [
                    'workspace_id' => $workspaceId,
                    'websites'     => $siteCount,
                ]);
                return [];
            }
        }

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

    /**
     * Convert an uploaded document to MARKDOWN.
     *
     * Everything the customer can upload ends up as markdown before chunking, so retrieval sees one
     * shape and a spreadsheet's rows survive as a table rather than a run-on sentence.
     *
     * The PDF path deliberately uses poppler's pdftotext rather than a PHP parser: the class this file
     * used to import (Smalot\PdfParser) is in neither composer.json nor composer.lock, so every PDF
     * upload since this feature shipped failed with a class-not-found and was recorded as status
     * 'failed'. pdftotext is installed on the host, is the reference implementation, and -layout keeps
     * columns and tables readable.
     */
    private function extractText(string $path, string $kind): string
    {
        switch ($kind) {
            case 'pdf':
                return $this->pdfToMarkdown($path);
            case 'docx':
                return $this->wordToMarkdown($path, 'Word2007');
            case 'doc':
                return $this->wordToMarkdown($path, 'MsDoc');
            case 'odt':
                return $this->wordToMarkdown($path, 'ODText');
            case 'rtf':
                return $this->wordToMarkdown($path, 'RTF');
            case 'xlsx':
                return $this->xlsxToMarkdown($path);
            case 'xls':
                // The legacy binary format needs a reader this platform does not vendor. Say so plainly
                // rather than storing an empty document that quietly answers nothing.
                throw new \InvalidArgumentException('That is the old .xls format. Open it in Excel and "Save As" .xlsx or .csv, then upload again.');
            case 'csv':
                return $this->csvToMarkdown($path);
            case 'txt':
            case 'md':
                $raw = file_get_contents($path);
                return trim($raw === false ? '' : $raw);
        }
        throw new \RuntimeException("Unsupported extraction kind: {$kind}");
    }

    /** PDF → markdown via poppler. Page breaks become horizontal rules so chunking has a seam to use. */
    private function pdfToMarkdown(string $path): string
    {
        $out = [];
        $code = 0;
        @exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null', $out, $code);
        $text = trim(implode("\n", $out));
        if ($code !== 0 || $text === '') {
            @exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null', $out2, $code2);
            $text = trim(implode("\n", $out2 ?? []));
        }
        if ($text === '') {
            throw new \RuntimeException('No text could be read from this PDF. If it is a scan, it needs OCR first.');
        }
        $text = str_replace("\f", "\n\n---\n\n", $text);          // form feed = page break
        return $this->tidyMarkdown($text);
    }

    /** Word/ODT/RTF → markdown, keeping headings, list items and tables. */
    private function wordToMarkdown(string $path, string $reader): string
    {
        $doc = WordIOFactory::createReader($reader)->load($path);
        $md = '';
        foreach ($doc->getSections() as $section) {
            foreach ($section->getElements() as $el) {
                $md .= $this->wordElementToMarkdown($el);
            }
        }
        $md = trim($md);
        if ($md === '') {
            throw new \RuntimeException('No text could be read from this document.');
        }
        return $this->tidyMarkdown($md);
    }

    private function wordElementToMarkdown(object $el): string
    {
        $cls = (new \ReflectionClass($el))->getShortName();

        if ($cls === 'Title') {
            $depth = method_exists($el, 'getDepth') ? max(1, min(6, (int) $el->getDepth() + 1)) : 2;
            return "\n" . str_repeat('#', $depth) . ' ' . trim($this->extractWordElement($el)) . "\n\n";
        }
        if ($cls === 'ListItem' || $cls === 'ListItemRun') {
            $depth = method_exists($el, 'getDepth') ? (int) $el->getDepth() : 0;
            return str_repeat('  ', max(0, $depth)) . '- ' . trim($this->extractWordElement($el)) . "\n";
        }
        if ($cls === 'Table') {
            $rows = method_exists($el, 'getRows') ? $el->getRows() : [];
            if (! $rows) { return ''; }
            $out = "\n";
            foreach ($rows as $i => $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cells[] = str_replace('|', '\\|', trim(preg_replace('/\s+/', ' ', $this->extractWordElement($cell))));
                }
                $out .= '| ' . implode(' | ', $cells) . " |\n";
                if ($i === 0) {
                    $out .= '|' . str_repeat(' --- |', count($cells)) . "\n";
                }
            }
            return $out . "\n";
        }
        if ($cls === 'TextBreak' || $cls === 'PageBreak') { return "\n"; }

        $t = trim($this->extractWordElement($el));
        return $t === '' ? '' : $t . "\n\n";
    }

    /**
     * XLSX → one markdown table per sheet. An .xlsx is a zip of XML, so this reads sharedStrings and
     * each worksheet directly rather than pulling in a spreadsheet library for a text extraction.
     */
    private function xlsxToMarkdown(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('That .xlsx could not be opened.');
        }
        try {
            $shared = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml !== false && $ssXml !== '') {
                $x = @simplexml_load_string($ssXml);
                if ($x !== false) {
                    foreach ($x->si as $si) {
                        $shared[] = trim((string) (isset($si->t) ? $si->t : implode('', array_map(
                            static fn ($r) => (string) $r->t, iterator_to_array($si->r ?? [], false)))));
                    }
                }
            }

            // sheet name -> file, from the workbook relationships
            $names = [];
            $wb = $zip->getFromName('xl/workbook.xml');
            if ($wb !== false) {
                $x = @simplexml_load_string($wb);
                if ($x !== false) {
                    foreach ($x->sheets->sheet as $sh) { $names[] = (string) $sh['name']; }
                }
            }

            $md = '';
            for ($i = 1; $i <= 50; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXml === false) { continue; }
                $x = @simplexml_load_string($sheetXml);
                if ($x === false) { continue; }
                $title = $names[$i - 1] ?? ('Sheet ' . $i);
                $rows = [];
                foreach ($x->sheetData->row as $row) {
                    $cells = [];
                    foreach ($row->c as $c) {
                        $v = (string) $c->v;
                        if ((string) $c['t'] === 's') {
                            $v = $shared[(int) $v] ?? '';
                        } elseif (isset($c->is->t)) {
                            $v = (string) $c->is->t;
                        }
                        $cells[] = str_replace('|', '\\|', trim(preg_replace('/\s+/', ' ', $v)));
                    }
                    while ($cells && end($cells) === '') { array_pop($cells); }
                    if ($cells) { $rows[] = $cells; }
                }
                if (! $rows) { continue; }
                $width = max(array_map('count', $rows));
                $md .= "\n## " . $title . "\n\n";
                foreach ($rows as $n => $cells) {
                    $cells = array_pad($cells, $width, '');
                    $md .= '| ' . implode(' | ', $cells) . " |\n";
                    if ($n === 0) { $md .= '|' . str_repeat(' --- |', $width) . "\n"; }
                }
                $md .= "\n";
            }
            if (trim($md) === '') {
                throw new \RuntimeException('That spreadsheet has no readable cells.');
            }
            return $this->tidyMarkdown($md);
        } finally {
            $zip->close();
        }
    }

    /** CSV → a markdown table, delimiter sniffed from the header line. */
    private function csvToMarkdown(string $path): string
    {
        $fh = @fopen($path, 'r');
        if (! $fh) { throw new \RuntimeException('That CSV could not be read.'); }
        try {
            $first = fgets($fh) ?: '';
            $delim = ',';
            foreach ([',', ';', "\t", '|'] as $d) {
                if (substr_count($first, $d) > substr_count($first, $delim)) { $delim = $d; }
            }
            rewind($fh);
            $md = ''; $n = 0; $width = 0;
            while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
                if ($cells === [null] || $cells === false) { continue; }
                $cells = array_map(static fn ($c) => str_replace('|', '\\|', trim((string) $c)), $cells);
                if (! array_filter($cells, static fn ($c) => $c !== '')) { continue; }
                if ($n === 0) { $width = count($cells); }
                $cells = array_pad(array_slice($cells, 0, $width ?: count($cells)), $width ?: count($cells), '');
                $md .= '| ' . implode(' | ', $cells) . " |\n";
                if ($n === 0) { $md .= '|' . str_repeat(' --- |', count($cells)) . "\n"; }
                $n++;
                if ($n > 5000) { break; }   // a knowledge base is not a data warehouse
            }
            if ($n === 0) { throw new \RuntimeException('That CSV has no readable rows.'); }
            return $this->tidyMarkdown($md);
        } finally {
            fclose($fh);
        }
    }

    /** Collapse the runs of blank lines these converters produce, and normalise line endings. */
    private function tidyMarkdown(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $md = preg_replace("/[ \t]+\n/", "\n", $md);
        $md = preg_replace("/\n{3,}/", "\n\n", $md);
        return trim($md);
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
