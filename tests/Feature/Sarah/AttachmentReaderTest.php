<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\AttachmentReader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ATTACH-2 (2026-08-30): documents the owner attaches are read into Sarah's context; other workspaces' files are ignored. */
class AttachmentReaderTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'att-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('workspaces')->insertGetId(['name' => 'Att', 'slug' => 'att-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function media(int $ws, string $name, string $mime, string $bytes): int
    {
        $rel = 'uploads/test-' . uniqid() . '-' . $name;
        @mkdir(storage_path('app/public/uploads'), 0775, true);
        file_put_contents(storage_path('app/public/' . $rel), $bytes);
        return (int) DB::table('media')->insertGetId(['workspace_id' => $ws, 'filename' => $name, 'path' => '/' . $rel, 'url' => '/storage/' . $rel, 'file_url' => '/storage/' . $rel, 'mime_type' => $mime, 'asset_type' => str_starts_with($mime, 'image/') ? 'image' : 'document', 'size_bytes' => strlen($bytes), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_text_word_and_pdf_documents_are_read_and_foreign_files_ignored(): void
    {
        $ws = $this->ws(); $other = $this->ws();
        $txt = $this->media($ws, 'brief.txt', 'text/plain', "Weekend sourdough launch.\nBudget 400.");
        $foreign = $this->media($other, 'secret.txt', 'text/plain', 'NOT FOR THIS WORKSPACE');

        $docx = tempnam(sys_get_temp_dir(), 'w') . '.docx';
        $pw = new \PhpOffice\PhpWord\PhpWord(); $pw->addSection()->addText('Croissant pricing memo for Brighton.');
        \PhpOffice\PhpWord\IOFactory::createWriter($pw, 'Word2007')->save($docx);
        $word = $this->media($ws, 'memo.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', file_get_contents($docx));

        $pdfBin = trim((string) shell_exec('command -v pdftotext'));
        $pdfId = null;
        if ($pdfBin !== '' && class_exists(\Dompdf\Dompdf::class)) {
            $d = new \Dompdf\Dompdf(); $d->loadHtml('<p>Opening hours: 7am to 3pm every weekend.</p>'); $d->render();
            $pdfId = $this->media($ws, 'hours.pdf', 'application/pdf', $d->output());
        }

        $in = [['media_id' => $txt], ['media_id' => $foreign], ['media_id' => $word]];
        if ($pdfId) $in[] = ['media_id' => $pdfId];
        $out = app(AttachmentReader::class)->read($in, $ws);

        $names = array_column($out['meta'], 'name');
        $this->assertContains('brief.txt', $names);
        $this->assertContains('memo.docx', $names);
        $this->assertNotContains('secret.txt', $names, 'another workspace\'s file never reaches Sarah');
        $this->assertStringContainsString('Weekend sourdough launch.', $out['context']);
        $this->assertStringContainsString('Croissant pricing memo for Brighton.', $out['context']);
        $this->assertStringNotContainsString('NOT FOR THIS WORKSPACE', $out['context']);
        if ($pdfId) $this->assertStringContainsString('Opening hours: 7am to 3pm', $out['context']);
        $this->assertSame([], $out['images']);
    }

    public function test_images_are_returned_for_vision_and_unreadable_types_are_acknowledged(): void
    {
        $ws = $this->ws();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $img = $this->media($ws, 'photo.png', 'image/png', $png);
        $xls = $this->media($ws, 'numbers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'PK-not-really');
        $out = app(AttachmentReader::class)->read([['media_id' => $img], ['media_id' => $xls]], $ws);
        $this->assertCount(1, $out['images']);
        $this->assertSame('photo.png', $out['images'][0]['name']);
        $this->assertStringContainsString('cannot read yet', $out['context']);
        $this->assertStringContainsString('numbers.xlsx', $out['context']);
    }
}
