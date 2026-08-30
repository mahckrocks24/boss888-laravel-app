<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ATTACH-2 (2026-08-30, Owner): files the owner attaches in Sarah's chat reach Sarah.
 *
 * The chat route only understood a base64 `image`; uploaded attachments (documents AND images) were dropped.
 * This reader validates the attachments against the workspace's media rows, extracts readable text from documents
 * (PDF, Word, text, Markdown, CSV, JSON) and returns (a) a sanitised list to persist on the message and (b) a
 * context block for the prompt. Images are returned for the existing vision path. Never throws.
 */
class AttachmentReader
{
    public const MAX_PER_DOC   = 12000;
    public const MAX_TOTAL     = 24000;

    /**
     * @param  array<int, array<string,mixed>> $input  what the SPA sent: [{media_id, kind, name, mime, url, size}]
     * @return array{meta: array<int,array<string,mixed>>, context: string, images: array<int,array<string,mixed>>}
     */
    public function read(array $input, int $wsId): array
    {
        $meta = []; $context = ''; $images = []; $total = 0;
        $ids = array_values(array_filter(array_map(static fn ($a) => (int) ($a['media_id'] ?? 0), $input)));
        if (!$ids || $wsId <= 0) return ['meta' => [], 'context' => '', 'images' => []];

        $rows = DB::table('media')->whereIn('id', $ids)->where('workspace_id', $wsId)
            ->get(['id', 'filename', 'path', 'url', 'mime_type', 'asset_type', 'size_bytes'])->keyBy('id');

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if (!$row) continue; // not this workspace's file — silently ignored
            $mime = strtolower((string) $row->mime_type);
            $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
            $item = ['media_id' => (int) $row->id, 'kind' => $kind, 'name' => (string) $row->filename, 'mime' => $mime, 'url' => (string) ($row->url ?: $row->file_url ?? ''), 'size' => (int) $row->size_bytes];
            $meta[] = $item;
            if ($kind === 'image') { $images[] = $item; continue; }
            if ($kind !== 'document') { $context .= "\n\n[The owner attached a {$kind} file \"{$item['name']}\" — Sarah cannot watch or listen to it yet; acknowledge it and ask what they want done with it.]"; continue; }

            $path = $this->diskPath((string) $row->path);
            $text = $path ? $this->extract($path, $mime, (string) $row->filename) : null;
            if ($text === null) {
                $context .= "\n\n[The owner attached a document \"{$item['name']}\" ({$mime}) that Sarah cannot read yet. Say so plainly and ask for the key points as text.]";
                continue;
            }
            $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R{3,}/', "\n\n", $text)));
            if ($text === '') {
                $context .= "\n\n[The owner attached a document \"{$item['name']}\" but no readable text was found in it (it may be a scan). Say so and ask for the content as text.]";
                continue;
            }
            $room = max(0, min(self::MAX_PER_DOC, self::MAX_TOTAL - $total));
            $clip = mb_substr($text, 0, $room);
            $total += mb_strlen($clip);
            $context .= "\n\n[The owner attached the document \"{$item['name']}\". Its contents" . (mb_strlen($text) > mb_strlen($clip) ? ' (first part)' : '') . ":]\n<<<\n{$clip}\n>>>";
        }
        return ['meta' => $meta, 'context' => $context, 'images' => $images];
    }

    private function diskPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        foreach ([storage_path('app/public/' . $path), storage_path('app/' . $path), public_path($path)] as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }

    /** Text or null when the type cannot be read. */
    private function extract(string $path, string $mime, string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            if ($mime === 'application/pdf' || $ext === 'pdf') {
                if (class_exists(\Smalot\PdfParser\Parser::class)) {
                    $t = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
                    if (trim($t) !== '') return $t;
                }
                $bin = trim((string) shell_exec('command -v pdftotext'));
                if ($bin !== '') { $t = (string) shell_exec(escapeshellcmd($bin) . ' -layout -q ' . escapeshellarg($path) . ' - 2>/dev/null'); return $t; }
                return null;
            }
            if ($ext === 'docx' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $doc = \PhpOffice\PhpWord\IOFactory::createReader('Word2007')->load($path);
                $out = '';
                foreach ($doc->getSections() as $s) { foreach ($s->getElements() as $el) { $out .= $this->wordText($el) . "\n"; } }
                return $out;
            }
            if ($ext === 'doc' || $mime === 'application/msword') {
                $doc = \PhpOffice\PhpWord\IOFactory::createReader('MsDoc')->load($path);
                $out = '';
                foreach ($doc->getSections() as $s) { foreach ($s->getElements() as $el) { $out .= $this->wordText($el) . "\n"; } }
                return $out;
            }
            if (in_array($ext, ['txt', 'md', 'csv', 'json', 'log'], true) || str_starts_with($mime, 'text/') || $mime === 'application/json') {
                $raw = file_get_contents($path);
                return $raw === false ? null : mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }
            if (in_array($ext, ['pptx'], true) && class_exists(\PhpOffice\PhpPresentation\IOFactory::class)) {
                $p = \PhpOffice\PhpPresentation\IOFactory::createReader('PowerPoint2007')->load($path);
                $out = '';
                foreach ($p->getAllSlides() as $i => $slide) { $out .= "Slide " . ($i + 1) . ":\n"; foreach ($slide->getShapeCollection() as $shape) { if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) { foreach ($shape->getParagraphs() as $para) { $out .= $para->getPlainText() . "\n"; } } } }
                return $out;
            }
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] AttachmentReader could not read a document', ['name' => $name, 'mime' => $mime, 'error' => $e->getMessage()]);
            return null;
        }
        return null; // xlsx/xls/zip and other binaries: acknowledged, not read
    }

    private function wordText(object $el): string
    {
        if (method_exists($el, 'getText')) { $t = $el->getText(); if (is_string($t)) return $t; if (is_object($t) && method_exists($t, 'getText')) return (string) $t->getText(); }
        $out = '';
        if (method_exists($el, 'getElements')) { foreach ($el->getElements() as $c) { $out .= $this->wordText($c); } }
        if (method_exists($el, 'getRows')) { foreach ($el->getRows() as $r) { foreach ($r->getCells() as $c) { $out .= $this->wordText($c) . "\t"; } $out .= "\n"; } }
        return $out;
    }
}
