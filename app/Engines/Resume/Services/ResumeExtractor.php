<?php

namespace App\Engines\Resume\Services;

use App\Connectors\RuntimeClient;

/**
 * RESUME888 — uploaded CV → text, free first. PDF: pdftotext (scanned → pages to PNG → Tesseract);
 * DOCX/DOC: PhpWord; images: Tesseract (eng+fil) with a vision fallback only when OCR confidence is poor.
 * Then a rule-based pre-split before the single model extraction call (ResumeWriter::extract).
 */
class ResumeExtractor
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const OCR_MIN_CONF = 55; // tesseract mean confidence below this → vision fallback (if budget allows)

    private ?RuntimeClient $runtime = null;
    private function runtime(): RuntimeClient { return $this->runtime ??= app(RuntimeClient::class); }

    /** @return array{ok:bool, text:string, method:string, confidence:int, cost:int, vision_calls:int, error?:string} */
    public function extractText(string $path, string $kind, bool $allowVision = true): array
    {
        try {
            return match ($kind) {
                'pdf' => $this->fromPdf($path, $allowVision),
                'docx' => $this->fromDocx($path),
                'image' => $this->fromImage($path, $allowVision),
                default => ['ok' => false, 'text' => '', 'method' => 'none', 'confidence' => 0, 'cost' => 0, 'vision_calls' => 0, 'error' => 'unsupported'],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'method' => 'error', 'confidence' => 0, 'cost' => 0, 'vision_calls' => 0, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function fromPdf(string $path, bool $allowVision): array
    {
        $txt = '';
        if (is_executable('/usr/bin/pdftotext')) { $txt = (string) shell_exec('/usr/bin/pdftotext -layout -q ' . escapeshellarg($path) . ' - 2>/dev/null'); }
        $txt = self::normalise($txt);
        if (mb_strlen($txt) >= 200) return ['ok' => true, 'text' => $txt, 'method' => 'pdftotext', 'confidence' => 95, 'cost' => 0, 'vision_calls' => 0];
        // scanned PDF → rasterise up to 3 pages → OCR
        $tmp = sys_get_temp_dir() . '/rs_' . bin2hex(random_bytes(6));
        @mkdir($tmp, 0700, true);
        shell_exec('/usr/bin/convert -density 200 -colorspace Gray ' . escapeshellarg($path . '[0-2]') . ' -resize 1600x1600\> -quality 80 ' . escapeshellarg($tmp . '/p.png') . ' 2>/dev/null');
        $pages = glob($tmp . '/p*.png') ?: [];
        $all = ''; $confs = []; $cost = 0; $vis = 0; $method = 'tesseract';
        foreach ($pages as $p) { $r = $this->fromImage($p, $allowVision && $vis < 3); $all .= "\n" . $r['text']; $confs[] = $r['confidence']; $cost += $r['cost']; $vis += $r['vision_calls']; if ($r['method'] === 'vision') $method = 'vision'; }
        foreach ($pages as $p) @unlink($p); @rmdir($tmp);
        $all = self::normalise($all);
        return ['ok' => mb_strlen($all) >= 80, 'text' => $all, 'method' => $pages ? $method : 'pdftotext', 'confidence' => $confs ? (int) (array_sum($confs) / count($confs)) : 0, 'cost' => $cost, 'vision_calls' => $vis, 'error' => mb_strlen($all) < 80 ? 'unreadable' : null];
    }

    private function fromDocx(string $path): array
    {
        $text = '';
        try {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $reader = \PhpOffice\PhpWord\IOFactory::createReader($ext === 'doc' ? 'MsDoc' : 'Word2007');
            $doc = $reader->load($path);
            foreach ($doc->getSections() as $section) $text .= $this->walk($section->getElements()) . "\n";
        } catch (\Throwable $e) { return ['ok' => false, 'text' => '', 'method' => 'phpword', 'confidence' => 0, 'cost' => 0, 'vision_calls' => 0, 'error' => 'docx_unreadable']; }
        $text = self::normalise($text);
        return ['ok' => mb_strlen($text) >= 80, 'text' => $text, 'method' => 'phpword', 'confidence' => 95, 'cost' => 0, 'vision_calls' => 0];
    }
    private function walk(array $elements): string
    {
        $out = '';
        foreach ($elements as $el) {
            if (method_exists($el, 'getText')) { $t = $el->getText(); $out .= is_string($t) ? $t . "\n" : ''; }
            if (method_exists($el, 'getElements')) $out .= $this->walk($el->getElements());
            if ($el instanceof \PhpOffice\PhpWord\Element\Table) foreach ($el->getRows() as $row) foreach ($row->getCells() as $cell) $out .= $this->walk($cell->getElements()) . ' | ';
        }
        return $out;
    }

    private function fromImage(string $path, bool $allowVision): array
    {
        $txt = ''; $conf = 0;
        if (is_executable('/usr/bin/tesseract')) {
            $pre = sys_get_temp_dir() . '/rs_' . bin2hex(random_bytes(6)) . '.png';
            shell_exec('/usr/bin/convert ' . escapeshellarg($path) . ' -auto-orient -colorspace Gray -resize 2000x2000\> -normalize -sharpen 0x1 ' . escapeshellarg($pre) . ' 2>/dev/null');
            $src = file_exists($pre) ? $pre : $path;
            $tsv = (string) shell_exec('/usr/bin/tesseract ' . escapeshellarg($src) . ' - -l eng+fil --psm 4 tsv 2>/dev/null');
            $txt = (string) shell_exec('/usr/bin/tesseract ' . escapeshellarg($src) . ' - -l eng+fil --psm 4 2>/dev/null');
            @unlink($pre);
            $confs = []; foreach (explode("\n", $tsv) as $i => $line) { if ($i === 0) continue; $c = explode("\t", $line); if (count($c) >= 12 && is_numeric($c[10]) && (int) $c[10] >= 0 && trim($c[11]) !== '') $confs[] = (float) $c[10]; }
            $conf = $confs ? (int) round(array_sum($confs) / count($confs)) : 0;
            $txt = self::normalise($txt);
        }
        if ($conf >= self::OCR_MIN_CONF && mb_strlen($txt) >= 80) return ['ok' => true, 'text' => $txt, 'method' => 'tesseract', 'confidence' => $conf, 'cost' => 0, 'vision_calls' => 0];
        if (!$allowVision) return ['ok' => mb_strlen($txt) >= 80, 'text' => $txt, 'method' => 'tesseract', 'confidence' => $conf, 'cost' => 0, 'vision_calls' => 0, 'error' => mb_strlen($txt) < 80 ? 'ocr_poor' : null];
        // vision fallback (low detail): base64 of a downscaled copy
        $small = sys_get_temp_dir() . '/rs_' . bin2hex(random_bytes(6)) . '.jpg';
        shell_exec('/usr/bin/convert ' . escapeshellarg($path) . ' -auto-orient -resize 1200x1200\> -quality 70 ' . escapeshellarg($small) . ' 2>/dev/null');
        $b64 = file_exists($small) ? base64_encode((string) file_get_contents($small)) : ''; @unlink($small);
        if ($b64 === '') return ['ok' => false, 'text' => $txt, 'method' => 'tesseract', 'confidence' => $conf, 'cost' => 0, 'vision_calls' => 0, 'error' => 'image_unreadable'];
        try { $r = $this->runtime()->visionAnalyze('Transcribe this CV image exactly as text. Keep section headings and line breaks. Do not summarise, do not add anything. If a part is unreadable write [unreadable].', $b64); }
        catch (\Throwable $e) { $r = ['success' => false, 'error' => 'runtime_unavailable']; }
        $cost = ResumeWriter::estimateUsdMicro(1200, (int) (($r['tokens_used'] ?? 800) * 4), true);
        $vt = self::normalise((string) ($r['analysis'] ?? ''));
        if (!empty($r['success']) && mb_strlen($vt) >= 80) return ['ok' => true, 'text' => $vt, 'method' => 'vision', 'confidence' => 80, 'cost' => $cost, 'vision_calls' => 1];
        return ['ok' => mb_strlen($txt) >= 80, 'text' => $txt, 'method' => 'tesseract', 'confidence' => $conf, 'cost' => $cost, 'vision_calls' => 1, 'error' => 'vision_failed'];
    }

    public static function normalise(string $t): string
    {
        $t = preg_replace('/\r/', '', $t) ?? '';
        $t = preg_replace('/[ \t]+/', ' ', $t) ?? '';
        $t = preg_replace('/\n{3,}/', "\n\n", $t) ?? '';
        return trim($t);
    }

    /** Rule-based pre-extraction: contact details and obvious sections, so the model call is small and grounded. */
    public static function preSplit(string $text): array
    {
        $out = ['email' => null, 'phone' => null, 'sections' => []];
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) $out['email'] = strtolower($m[0]);
        if (preg_match('/(\+?\d[\d\s\-()]{7,}\d)/', $text, $m)) $out['phone'] = preg_replace('/\s+/', ' ', trim($m[1]));
        $heads = 'summary|profile|objective|experience|employment|work history|education|qualifications|skills|licen[cs]es?|certifications?|languages?|references?|personal (?:details|information)';
        $parts = preg_split('/\n(?=\s*(?:' . $heads . ')\s*:?\s*\n)/i', "\n" . $text) ?: [];
        foreach ($parts as $p) { $p = trim($p); if ($p === '') continue; $first = strtolower(trim(strtok($p, "\n"))); $out['sections'][] = ['head' => mb_substr($first, 0, 40), 'text' => mb_substr($p, 0, 3000)]; }
        return $out;
    }
}
