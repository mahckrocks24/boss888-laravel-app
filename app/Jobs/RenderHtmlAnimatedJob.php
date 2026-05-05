<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Render an HTML animated template (template_type='html_animated') to MP4.
 *
 * Pipeline:
 *   design row → fetch template_html_path → expand to full URL →
 *   pass to tools/studio-record.cjs with {fields, paletteVars} →
 *   cjs seeks CSS animations frame-by-frame, screenshots, ffmpeg → MP4 →
 *   on success: exported_video_url + export_status=done
 *   on failure: export_status=failed + export_error
 */
class RenderHtmlAnimatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;   // 15 minutes — screen-record can be heavy
    public int $tries = 1;

    public function __construct(public int $designId) {}

    public function failed(?\Throwable $e): void
    {
        $msg = $e ? $e->getMessage() : 'job_killed_or_timed_out';
        try {
            // Don't clobber a successful render that already completed —
            // the worker's timeout vs. handle() runtime race can otherwise
            // leave export_status=done but export_error set to a stale string.
            $row = DB::table('studio_designs')->where('id', $this->designId)->first();
            if ($row && $row->export_status === 'done') return;

            DB::table('studio_designs')->where('id', $this->designId)->update([
                'export_status' => 'failed',
                'export_error'  => mb_substr($msg, 0, 600),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $_) {}
        Log::error('studio.html_animated.render job failed (terminal)', [
            'design_id' => $this->designId,
            'error'     => $msg,
        ]);
    }

    public function handle(): void
    {
        $row = DB::table('studio_designs')->where('id', $this->designId)->first();
        if (!$row) { $this->markFailed('design_not_found'); return; }

        DB::table('studio_designs')->where('id', $this->designId)->update([
            'export_status'        => 'processing',
            'export_progress_pct'  => 1,
            'export_error'         => null,
            'updated_at'           => now(),
        ]);

        try {
            $url = $this->render($row);
            DB::table('studio_designs')->where('id', $this->designId)->update([
                'export_status'       => 'done',
                'export_progress_pct' => 100,
                'exported_video_url'  => $url,
                'export_error'        => null,
                'status'              => 'exported',
                'updated_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('studio.html_animated.render failed', [
                'design_id' => $this->designId,
                'error'     => $e->getMessage(),
                'trace'     => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            $this->markFailed($e->getMessage());
        }
    }

    private function markFailed(string $err): void
    {
        DB::table('studio_designs')->where('id', $this->designId)->update([
            'export_status'       => 'failed',
            'export_error'        => mb_substr($err, 0, 600),
            'updated_at'          => now(),
        ]);
    }

    /**
     * Core renderer. Returns the public URL of the produced MP4.
     */
    private function render(object $design): string
    {
        $wsId = (int) $design->workspace_id;
        $data = json_decode($design->video_data ?? '{}', true) ?: [];

        // Resolve the linked template row.
        $tplSlug = $data['template_slug'] ?? $data['slug'] ?? null;
        $tpl = null;
        if ($tplSlug) {
            $tpl = DB::table('studio_video_templates')->where('slug', $tplSlug)->first();
        }
        if (!$tpl && isset($data['template_id'])) {
            $tpl = DB::table('studio_video_templates')->where('id', (int) $data['template_id'])->first();
        }
        if (!$tpl) {
            throw new \RuntimeException('template_not_linked (need template_slug in video_data)');
        }
        if ($tpl->template_type !== 'html_animated' || empty($tpl->template_html_path)) {
            throw new \RuntimeException('template_not_html_animated_or_missing_path');
        }

        $duration = (float) ($data['duration']       ?? $tpl->duration_seconds ?? 12);
        $width    = (int)   ($data['canvas_width']   ?? $tpl->canvas_width   ?? 1080);
        $height   = (int)   ($data['canvas_height']  ?? $tpl->canvas_height  ?? 1920);
        $fps      = (int)   ($data['fps']            ?? 30);

        // Build full template URL.
        $path = $tpl->template_html_path;
        $templateUrl = preg_match('#^https?://#', $path)
            ? $path
            : rtrim(config('app.url', 'https://staging.levelupgrowth.io'), '/') . $path;

        // Build field and palette overrides from the design's video_data.
        $fields = [];
        foreach (($data['fields'] ?? []) as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $fields[$k] = (string) $v;
            }
        }
        $paletteVars = [];
        foreach (($data['palette_vars'] ?? []) as $k => $v) {
            if (is_string($k) && is_string($v) && str_starts_with($k, '--')) {
                $paletteVars[$k] = $v;
            }
        }

        // Output path.
        $outDir = storage_path('app/public/exports/' . $wsId);
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
        $outName = $this->designId . '-animated-' . bin2hex(random_bytes(3)) . '.mp4';
        $outPath = $outDir . '/' . $outName;
        $publicUrl = '/storage/exports/' . $wsId . '/' . $outName;

        $args = [
            'templateUrl' => $templateUrl,
            'outputPath'  => $outPath,
            'duration'    => $duration,
            'fps'         => $fps,
            'width'       => $width,
            'height'      => $height,
            'fields'      => (object) $fields,       // always object, even if empty
            'paletteVars' => (object) $paletteVars,
        ];
        $argsJson = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $script = base_path('tools/studio-record.cjs');
        if (!is_file($script)) {
            throw new \RuntimeException('studio-record.cjs_missing');
        }

        DB::table('studio_designs')->where('id', $this->designId)->update([
            'export_progress_pct' => 10,
            'updated_at'          => now(),
        ]);

        $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($argsJson) . ' 2>&1';
        $env = [
            'HOME'                     => '/tmp',
            'PATH'                     => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'PUPPETEER_CACHE_DIR'      => base_path('.puppeteer-cache'),
            'LANG'                     => 'C.UTF-8',
        ];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open($cmd, $spec, $pipes, null, $env);
        if (!is_resource($p)) throw new \RuntimeException('proc_open_failed');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($p);

        Log::info('studio.html_animated.render done', [
            'design_id' => $this->designId,
            'status'    => $status,
            'stdout'    => mb_substr($stdout ?? '', 0, 500),
            'stderr'    => mb_substr($stderr ?? '', 0, 500),
        ]);

        if ($status !== 0 || !file_exists($outPath) || filesize($outPath) < 1000) {
            @unlink($outPath);
            $err = trim(($stderr ?: $stdout) ?: 'unknown_error');
            throw new \RuntimeException('render_error: ' . mb_substr($err, 0, 400));
        }

        DB::table('studio_designs')->where('id', $this->designId)->update([
            'export_progress_pct' => 90,
            'updated_at'          => now(),
        ]);

        return $publicUrl;
    }
}
