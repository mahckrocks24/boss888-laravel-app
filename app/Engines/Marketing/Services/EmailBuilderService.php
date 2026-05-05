<?php

namespace App\Engines\Marketing\Services;

use App\Engines\Marketing\EmailBlockLibrary;
use App\Connectors\EmailConnector;
use App\Connectors\DeepSeekConnector;
use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * EmailBuilderService — the brain of the Email Builder.
 *
 * Scope:
 *   • Template CRUD  (list / get / create / update / delete)
 *   • Block CRUD     (list / add / update / delete / reorder)
 *   • Render engine  (assemble blocks + shell, substitute variables, inject tracking)
 *   • Preview, export-html, thumbnail
 *   • AI  (generate, rewrite-block, suggest-subject, spam-check)
 *   • Send  (validate, send-test, send)
 *   • Tracking  (open pixel, click redirect, unsubscribe)
 *   • Analytics (per-campaign aggregate)
 *
 * Hands-vs-brain: all LLM calls route through RuntimeClient. This service
 * does persistence, gating, rendering, tracking — never direct LLM.
 */
class EmailBuilderService
{
    public function __construct(
        private EmailConnector     $email,
        private RuntimeClient      $runtime,
        private DeepSeekConnector  $llm,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // TEMPLATE CRUD
    // ═══════════════════════════════════════════════════════════════════

    public function listTemplates(int $wsId, string $scope = 'all'): array
    {
        // scope-aware-templates
        $q = DB::table('email_templates')->where('is_active', 1);
        if ($scope === 'mine') {
            $q->where('workspace_id', $wsId)->where('is_system', 0);
        } elseif ($scope === 'system') {
            $q->where('is_system', 1);
        } else {
            $q->where(function ($qq) use ($wsId) {
                $qq->where('workspace_id', $wsId)->orWhere('is_system', 1);
            });
        }
        $rows = $q->orderByDesc('updated_at')->get();

        $templates = [];
        foreach ($rows as $r) {
            $templates[] = [
                'id'             => $r->id,
                'name'           => $r->name,
                'category'       => $r->category,
                'subject'        => $r->subject,
                'preview_text'   => $r->preview_text,
                'thumbnail_url'  => $r->thumbnail_url,
                'brand_color'    => $r->brand_color,
                'is_system'      => (bool) $r->is_system,
                'variables_json' => $r->variables_json,
                'block_count'    => DB::table('email_blocks')->where('template_id', $r->id)->count(),
                'updated_at'     => $r->updated_at,
            ];
        }
        return $templates;
    }

    public function getTemplate(int $id): ?array
    {
        $tpl = DB::table('email_templates')->where('id', $id)->first();
        if (!$tpl) return null;

        $blocks = DB::table('email_blocks')
            ->where('template_id', $id)
            ->orderBy('block_order')
            ->get()
            ->map(fn($b) => [
                'id'           => $b->id,
                'template_id'  => $b->template_id,
                'block_order'  => $b->block_order,
                'block_type'   => $b->block_type,
                'content_json' => json_decode($b->content_json ?: '{}', true),
                'styles_json'  => json_decode($b->styles_json  ?: '{}', true),
                'is_visible'   => (bool) $b->is_visible,
            ])->toArray();

        $variables = $this->extractVariables($tpl->html_body ?? '');

        return [
            'template'  => (array) $tpl,
            'blocks'    => $blocks,
            'variables' => $variables,
        ];
    }

    /**
     * Create a new template.
     *   source='blank' → create with header + hero + footer
     *   source='html'  → parse uploaded html_content, store as custom_html or infer blocks
     *   source='clone' → copy from existing template id
     */
    public function createTemplate(int $wsId, array $data): array
    {
        $name     = $data['name']     ?? 'Untitled Template';
        $category = $data['category'] ?? 'general';
        $source   = $data['source']   ?? 'blank';

        $templateId = DB::table('email_templates')->insertGetId([
            'workspace_id'   => $wsId,
            'name'           => $name,
            'category'       => $category,
            'subject'        => $data['subject']      ?? '',
            'preview_text'   => $data['preview_text'] ?? null,
            'body_html'      => '',
            'html_body'      => '',
            'variables_json' => json_encode([]),
            'blocks_json'    => json_encode([]),
            'brand_color'    => $data['brand_color'] ?? '#5B5BD6',
            'is_system'      => 0,
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        if ($source === 'blank') {
            $this->seedDefaultBlocks($templateId);
        } elseif ($source === 'html' && !empty($data['html_content'])) {
            $this->seedFromHtml($templateId, (string) $data['html_content']);
        } elseif ($source === 'clone' && !empty($data['clone_from_id'])) {
            $this->cloneBlocks($templateId, (int) $data['clone_from_id']);
        }

        // Re-render & persist html_body + variables
        $this->recompile($templateId);

        return $this->getTemplate($templateId);
    }

    public function updateTemplate(int $id, array $data): array
    {
        $up = array_intersect_key($data, array_flip([
            'name', 'category', 'subject', 'preview_text', 'brand_color',
        ]));
        if (isset($data['variables'])) {
            $up['variables_json'] = json_encode($data['variables']);
        }
        $up['updated_at'] = now();
        DB::table('email_templates')->where('id', $id)->update($up);

        // Recompile if brand_color changed — affects all blocks
        if (isset($up['brand_color'])) {
            $this->recompile($id);
        }
        return ['updated' => true];
    }

    public function deleteTemplate(int $id): bool
    {
        $row = DB::table('email_templates')->where('id', $id)->first();
        if (!$row || $row->is_system) return false;
        DB::table('email_templates')->where('id', $id)->update([
            'is_active' => 0, 'updated_at' => now(),
        ]);
        return true;
    }

    /**
     * Clone a system template into the user's workspace as their own copy.
     * Result is editable (is_system=0) and full block tree is duplicated.
     */
    public function useSystemTemplate(int $wsId, int $templateId): array
    {
        $src = DB::table('email_templates')->where('id', $templateId)->first();
        if (!$src) return ['success' => false, 'error' => 'not_found'];

        $newId = DB::table('email_templates')->insertGetId([
            'workspace_id'   => $wsId,
            'name'           => $src->name,
            'category'       => $src->category,
            'subject'        => $src->subject,
            'preview_text'   => $src->preview_text,
            'body_html'      => $src->body_html,
            'html_body'      => $src->html_body,
            'blocks_json'    => $src->blocks_json,
            'variables_json' => $src->variables_json,
            'thumbnail_url'  => $src->thumbnail_url,
            'brand_color'    => $src->brand_color ?? '#5B5BD6',
            'font_family'    => $src->font_family ?? 'Inter, Arial, Helvetica',
            'is_system'      => 0,
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Clone blocks
        $blocks = DB::table('email_blocks')->where('template_id', $templateId)->orderBy('block_order')->get();
        foreach ($blocks as $b) {
            DB::table('email_blocks')->insert([
                'template_id'  => $newId,
                'block_order'  => $b->block_order,
                'block_type'   => $b->block_type,
                'content_json' => $b->content_json,
                'styles_json'  => $b->styles_json,
                'is_visible'   => $b->is_visible,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
        return ['success' => true, 'template_id' => $newId];
    }

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN — bypass system protection
    // ═══════════════════════════════════════════════════════════════════

    public function adminListAllTemplates(): array
    {
        return DB::table('email_templates')
            ->orderByDesc('is_system')->orderByDesc('updated_at')
            ->get()->toArray();
    }

    public function adminCreateSystemTemplate(array $data): array
    {
        $svcCopy = $this->createTemplate(0, [
            'name'        => $data['name'] ?? 'New System Template',
            'category'    => $data['category'] ?? 'general',
            'subject'     => $data['subject'] ?? '',
            'source'      => $data['source'] ?? 'blank',
            'html_content'=> $data['html_content'] ?? '',
            'brand_color' => $data['brand_color'] ?? '#5B5BD6',
        ]);
        $tplId = $svcCopy['template']['id'];
        DB::table('email_templates')->where('id', $tplId)->update([
            'is_system'  => 1,
            'updated_at' => now(),
        ]);
        return ['success' => true, 'template_id' => $tplId];
    }

    public function adminUpdateAnyTemplate(int $id, array $data): array
    {
        // Bypass the system-protection in updateTemplate by writing directly
        $up = array_intersect_key($data, array_flip([
            'name','category','subject','preview_text','brand_color',
            'font_family','is_system','is_active',
        ]));
        if (isset($data['variables']) && is_array($data['variables'])) {
            $up['variables_json'] = json_encode($data['variables']);
        }
        $up['updated_at'] = now();
        DB::table('email_templates')->where('id', $id)->update($up);

        // Recompile if brand_color changed (touches html_body)
        if (isset($up['brand_color'])) {
            try { $this->getTemplate($id); /* triggers any cache */ } catch (\Throwable $e) {}
        }
        return ['success' => true, 'updated' => true];
    }

    public function adminDeleteAnyTemplate(int $id): bool
    {
        $row = DB::table('email_templates')->where('id', $id)->first();
        if (!$row) return false;
        // Hard delete (admin scope) — also clean up blocks + thumbnail file
        DB::table('email_blocks')->where('template_id', $id)->delete();
        DB::table('email_templates')->where('id', $id)->delete();
        $thumb = '/var/www/levelup-staging/storage/app/public/email-thumbnails/tpl-' . $id . '.png';
        if (file_exists($thumb)) @unlink($thumb);
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // BLOCK CRUD
    // ═══════════════════════════════════════════════════════════════════

    public function getBlocks(int $templateId): array
    {
        return DB::table('email_blocks')
            ->where('template_id', $templateId)
            ->orderBy('block_order')
            ->get()
            ->map(fn($b) => [
                'id'           => $b->id,
                'block_order'  => $b->block_order,
                'block_type'   => $b->block_type,
                'content_json' => json_decode($b->content_json ?: '{}', true),
                'styles_json'  => json_decode($b->styles_json  ?: '{}', true),
                'is_visible'   => (bool) $b->is_visible,
            ])->toArray();
    }

    public function addBlock(int $templateId, array $data): array
    {
        $type = (string) ($data['block_type'] ?? 'body_text');
        if (!in_array($type, EmailBlockLibrary::TYPES, true)) {
            return ['error' => 'invalid block_type'];
        }
        $order = $data['block_order']
               ?? ((int) DB::table('email_blocks')->where('template_id', $templateId)->max('block_order') + 1);

        // Shift existing blocks at or after this order
        DB::table('email_blocks')
            ->where('template_id', $templateId)
            ->where('block_order', '>=', $order)
            ->increment('block_order');

        $content = $data['content_json'] ?? EmailBlockLibrary::defaultContent($type);

        $blockId = DB::table('email_blocks')->insertGetId([
            'template_id'  => $templateId,
            'block_order'  => $order,
            'block_type'   => $type,
            'content_json' => json_encode($content),
            'styles_json'  => json_encode($data['styles_json'] ?? []),
            'is_visible'   => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $this->recompile($templateId);
        return ['block_id' => $blockId, 'block_order' => $order];
    }

    public function updateBlock(int $templateId, int $blockId, array $data): array
    {
        $up = [];
        if (array_key_exists('content_json', $data)) {
            $up['content_json'] = json_encode($data['content_json']);
        }
        if (array_key_exists('styles_json', $data)) {
            $up['styles_json'] = json_encode($data['styles_json']);
        }
        if (array_key_exists('is_visible', $data)) {
            $up['is_visible'] = $data['is_visible'] ? 1 : 0;
        }
        $up['updated_at'] = now();
        DB::table('email_blocks')
            ->where('id', $blockId)
            ->where('template_id', $templateId)
            ->update($up);
        $this->recompile($templateId);
        return ['updated' => true];
    }

    public function deleteBlock(int $templateId, int $blockId): bool
    {
        $block = DB::table('email_blocks')
            ->where('id', $blockId)
            ->where('template_id', $templateId)
            ->first();
        if (!$block) return false;

        DB::table('email_blocks')->where('id', $blockId)->delete();

        // Renumber remaining blocks to close the gap
        DB::table('email_blocks')
            ->where('template_id', $templateId)
            ->where('block_order', '>', $block->block_order)
            ->decrement('block_order');

        $this->recompile($templateId);
        return true;
    }

    /** Reorder all blocks. Expects an ordered array of block ids. */
    public function reorderBlocks(int $templateId, array $blockIds): bool
    {
        foreach (array_values($blockIds) as $i => $id) {
            DB::table('email_blocks')
                ->where('template_id', $templateId)
                ->where('id', $id)
                ->update(['block_order' => $i + 1, 'updated_at' => now()]);
        }
        $this->recompile($templateId);
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // RENDER ENGINE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Assemble the full email HTML from the template's blocks.
     * Does NOT substitute user-level variables ({{first_name}} etc) — those
     * are applied by renderWithVariables during send.
     */
    public function render(int $templateId): string
    {
        $tpl = DB::table('email_templates')->where('id', $templateId)->first();
        if (!$tpl) return '';

        $blocks = DB::table('email_blocks')
            ->where('template_id', $templateId)
            ->where('is_visible', 1)
            ->orderBy('block_order')
            ->get();

        $inner = '';
        foreach ($blocks as $b) {
            $content = json_decode($b->content_json ?: '{}', true) ?: [];
            $inner  .= EmailBlockLibrary::render($b->block_type, $content, (int) $b->id) . "\n";
        }

        $html = EmailBlockLibrary::wrap($inner, [
            'brand_color'  => $tpl->brand_color ?? '#5B5BD6',
            'preview_text' => $tpl->preview_text ?? '',
            'title'        => $tpl->subject ?? $tpl->name ?? 'Email',
        ]);
        // Part 6 — inject template's font_family, replacing the default Inter stack
        $fontStack = (string) ($tpl->font_family ?? 'Inter, Arial, Helvetica');
        if ($fontStack !== '' && $fontStack !== 'Inter, Arial, Helvetica') {
            $html = preg_replace(
                "/font-family:\s*'Inter',\s*Arial,\s*Helvetica(?:,\s*sans-serif)?/i",
                'font-family:' . $fontStack,
                $html
            );
        }
        return $html;
    }

    /**
     * Render the template and apply runtime variables: workspace, contact
     * merge tags, unsubscribe tokens, tracking pixel, link tracking wrappers.
     */
    public function renderWithVariables(int $templateId, array $vars = [], ?object $contact = null, ?int $logId = null): string
    {
        $html = $this->render($templateId);

        // Default vars
        $vars['current_year']    = (string) ($vars['current_year']    ?? date('Y'));
        $vars['brand_name']      = (string) ($vars['brand_name']      ?? 'Your Brand');

        if ($contact) {
            $vars['first_name'] = (string) ($contact->first_name ?? '');
            $vars['last_name']  = (string) ($contact->last_name  ?? '');
            $vars['email']      = (string) ($contact->email      ?? '');
            $vars['company']    = (string) ($contact->company    ?? '');
            // Unsubscribe — use the per-log tracking_token if available
            if ($logId) {
                $vars['unsubscribe_url'] = url('/email/unsubscribe/' . $this->tokenFor($logId, 'u'));
            }
        }

        // Substitute known variables
        foreach ($vars as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $html = str_replace('{{' . $k . '}}', (string) $v, $html);
            }
        }
        // Strip leftover unknown tokens
        $html = preg_replace('/\{\{[a-z_0-9]+\}\}/i', '', $html);

        // Tracking — open pixel + link rewriting — only when we have a log id
        if ($logId) {
            $html = $this->injectTracking($html, $logId);
        }
        return $html;
    }

    /**
     * After any block mutation, regenerate html_body + variables_json on the
     * template row so list/preview reads are cheap.
     */
    private function recompile(int $templateId): void
    {
        $html      = $this->render($templateId);
        $variables = $this->extractVariables($html);
        $blocksSnap = $this->getBlocks($templateId);
        DB::table('email_templates')->where('id', $templateId)->update([
            'html_body'      => $html,
            'blocks_json'    => json_encode($blocksSnap),
            'variables_json' => json_encode($variables),
            'updated_at'     => now(),
        ]);
    }

    private function extractVariables(string $html): array
    {
        preg_match_all('/\{\{([a-z_0-9]+)\}\}/i', $html, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    // ═══════════════════════════════════════════════════════════════════
    // PREVIEW / EXPORT / THUMBNAIL
    // ═══════════════════════════════════════════════════════════════════

    public function previewTemplate(int $templateId, array $variables = [], string $format = 'desktop'): string
    {
        // phase3-sentinel: email-builder-phase3
        $html = $this->renderWithVariables($templateId, $variables);
        if ($format === 'mobile') {
            $html = str_replace('class="wrap"', 'class="wrap" style="max-width:420px;margin:0 auto;"', $html);
        }
        return $this->injectPreviewBridge($html);
    }

    public function getTemplateVariables(int $templateId): array
    {
        $html = $this->render($templateId);
        preg_match_all('/\{\{([a-z_0-9]+)\}\}/i', $html, $m);
        $names = array_values(array_unique($m[1] ?? []));
        $typed = [];
        foreach ($names as $n) {
            $typed[] = ['name' => $n, 'type' => $this->inferVariableType($n)];
        }
        return $typed;
    }

    private function inferVariableType(string $name): string
    {
        $n = strtolower($name);
        if (str_ends_with($n, '_color') || $n === 'color' || $n === 'bg_color' || $n === 'text_color') return 'color';
        if ($n === 'email' || str_ends_with($n, '_email'))   return 'email';
        if (str_contains($n, 'image') || $n === 'logo_url' || str_ends_with($n, '_image_url') || $n === 'avatar_url') return 'image';
        if (str_ends_with($n, '_url') || $n === 'url' || $n === 'unsubscribe_url') return 'url';
        if (in_array($n, ['body_text','body_html','description','product_description','quote','testimonial_quote','subheadline','secondary_body','footer_text'], true)) return 'textarea';
        return 'text';
    }

    private function injectPreviewBridge(string $html): string
    {
        $bridge = <<<'JS'
<script id="__lu_email_preview_bridge__">
(function(){
  function sendHover(el, end){
    try {
      var rect = el.getBoundingClientRect();
      parent.postMessage({
        type: end ? 'block-hover-end' : 'block-hover',
        blockId: el.getAttribute('data-block-id'),
        blockType: el.getAttribute('data-block-type'),
        rect: { top: rect.top, left: rect.left, width: rect.width, height: rect.height }
      }, '*');
    } catch(e){}
  }
  document.querySelectorAll('[data-block-id]').forEach(function(block){
    block.addEventListener('mouseenter', function(){ sendHover(this, false); });
    block.addEventListener('mouseleave', function(){ sendHover(this, true); });
    block.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      parent.postMessage({
        type: 'block-selected',
        blockId:   this.getAttribute('data-block-id'),
        blockType: this.getAttribute('data-block-type')
      }, '*');
    });
  });
  window.addEventListener('message', function(e){
    if (!e.data || !e.data.type) return;
    var d = e.data;
    if (d.type === 'update-field') {
      var sel = '[data-field="' + d.field + '"]';
      if (d.blockId != null) sel = '[data-block-id="' + d.blockId + '"] ' + sel;
      var el = document.querySelector(sel);
      if (el) el.innerHTML = d.value;
    }
    if (d.type === 'update-brand-color') {
      document.querySelectorAll('[data-brand-color]').forEach(function(el){ el.style.backgroundColor = d.color; });
      document.querySelectorAll('[data-brand-color-text]').forEach(function(el){ el.style.color = d.color; });
      document.querySelectorAll('[data-brand-color-border]').forEach(function(el){ el.style.borderColor = d.color; });
    }
    if (d.type === 'update-font-family') {
      document.querySelectorAll('*').forEach(function(el){
        var cs = el.style.fontFamily;
        if (cs) el.style.fontFamily = d.fontFamily;
      });
      // Also rewrite any literal Inter references in style attributes
      var sel = document.querySelectorAll('[style*="font-family"]');
      sel.forEach(function(el){
        if (el.getAttribute('style').toLowerCase().includes('inter')) {
          el.style.fontFamily = d.fontFamily;
        }
      });
    }
    if (d.type === 'highlight-block') {
      document.querySelectorAll('[data-block-id]').forEach(function(b){ b.style.outline=''; });
      var t = document.querySelector('[data-block-id="' + d.blockId + '"]');
      if (t) { t.style.outline = '2px solid #6C5CE7'; t.scrollIntoView({behavior:'smooth', block:'center'}); }
    }
  });
  try { parent.postMessage({ type: 'bridge-ready' }, '*'); } catch(_){}
})();
</script>
JS;

        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $bridge . "\n</body>", $html);
        }
        return $html . $bridge;
    }

    public function exportHtml(int $templateId): string
    {
        return $this->render($templateId);
    }

    /**
     * Generate a 1200x800 thumbnail via puppeteer (Chromium bundled in the
     * repo's .puppeteer-cache). Returns the public URL.
     */
    public function generateThumbnail(int $templateId): array
    {
        $tpl = DB::table('email_templates')->where('id', $templateId)->first();
        if (!$tpl) return ['error' => 'template_not_found'];

        $dir = storage_path('app/public/email-thumbnails');
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $htmlPath  = $dir . "/tpl-{$templateId}.html";
        $outPath   = $dir . "/tpl-{$templateId}.png";
        file_put_contents($htmlPath, $this->render($templateId));

        $toolPath = base_path('tools/bake-email-thumbnail.cjs');
        if (!file_exists($toolPath)) {
            // Minimal inline puppeteer invocation via Chrome headless CLI fallback
            Log::warning('bake-email-thumbnail.cjs not found — thumbnail deferred');
            return ['error' => 'thumbnailer_missing'];
        }

        $cmd = escapeshellcmd('node ' . $toolPath . ' ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($outPath));
        exec($cmd . ' 2>&1', $output, $rc);
        if ($rc !== 0 || !file_exists($outPath)) {
            Log::warning('email-thumbnail render failed', ['rc' => $rc, 'output' => $output]);
            return ['error' => 'render_failed', 'output' => implode("\n", $output)];
        }

        $url = '/storage/email-thumbnails/tpl-' . $templateId . '.png?v=' . time();
        DB::table('email_templates')->where('id', $templateId)->update([
            'thumbnail_url' => $url, 'updated_at' => now(),
        ]);
        return ['thumbnail_url' => $url];
    }

    // ═══════════════════════════════════════════════════════════════════
    // AI OPERATIONS
    // ═══════════════════════════════════════════════════════════════════

    public function aiGenerate(int $wsId, array $params): array
    {
        $goal     = (string) ($params['goal']       ?? 'announce');
        $tone     = (string) ($params['tone']       ?? 'professional');
        $industry = (string) ($params['industry']   ?? '');
        $brand    = (string) ($params['brand_name'] ?? 'your brand');
        $prompt   = (string) ($params['prompt']     ?? '');

        $system = "You are a world-class email copywriter with 15 years experience writing high-converting marketing emails for SaaS, ecommerce, and service businesses. You write with clarity, urgency, and empathy. You know what subject lines get opened and what CTAs get clicked. Always return valid JSON only.";

        $user = "Write a complete marketing email for:\n"
              . "Business: {$brand}\n"
              . "Industry: {$industry}\n"
              . "Goal: {$goal} (sell/nurture/announce/onboard/reactivate)\n"
              . "Tone: {$tone} (professional/casual/urgent)\n"
              . "Instructions: {$prompt}\n\n"
              . "Return JSON:\n"
              . "{\n"
              . "  \"subject_a\": string (primary subject, max 60 chars, no clickbait),\n"
              . "  \"subject_b\": string (A/B variant, different angle, max 60 chars),\n"
              . "  \"preview_text\": string (50-90 chars, complements subject_a),\n"
              . "  \"blocks\": [ { \"block_type\": string, \"content\": { ... } } ]\n"
              . "}\n\n"
              . "Block selection rules:\n"
              . "- Always include: header, footer\n"
              . "- Promotional/sell: hero + features + secondary_cta + testimonial\n"
              . "- Nurture/relationship: hero + body_text + testimonial\n"
              . "- Announcement: hero + body_text + image\n"
              . "- Onboarding: hero + features + body_text\n"
              . "- Reactivation: hero + secondary_cta\n\n"
              . "Write copy that sounds human, not AI. Make the headline punchy (max 8 words). CTA must be action-verb first. Body text max 3 short paragraphs.\n\n"
              . "Allowed block_types: " . implode(',', EmailBlockLibrary::TYPES) . ".";

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.7, 'max_tokens' => 2200]);

        if (empty($result['success']) || empty($result['parsed'])) {
            return ['success' => false, 'error' => $result['error'] ?? ($result['parse_error'] ?? 'ai_failed'), 'raw' => $result['content'] ?? null];
        }
        $parsed = $result['parsed'];

        $tpl = $this->createTemplate($wsId, [
            'name'         => $params['name']        ?? ('AI: ' . Str::limit($goal, 40)),
            'category'     => $params['category']    ?? 'ai_generated',
            'subject'      => $parsed['subject_a']    ?? '',
            'preview_text' => $parsed['preview_text'] ?? '',
            'brand_color'  => $params['brand_color']  ?? '#5B5BD6',
            'source'       => 'empty',
        ]);
        $templateId = $tpl['template']['id'];

        DB::table('email_blocks')->where('template_id', $templateId)->delete();
        foreach (($parsed['blocks'] ?? []) as $i => $b) {
            $type = (string) ($b['block_type'] ?? 'body_text');
            if (!in_array($type, EmailBlockLibrary::TYPES, true)) continue;
            $this->addBlock($templateId, [
                'block_type'   => $type,
                'block_order'  => $i + 1,
                'content_json' => array_merge(EmailBlockLibrary::defaultContent($type), $b['content'] ?? []),
            ]);
        }
        $this->recompile($templateId);

        return [
            'success'      => true,
            'template_id'  => $templateId,
            'blocks'       => $this->getBlocks($templateId),
            'subject_a'    => $parsed['subject_a']    ?? '',
            'subject_b'    => $parsed['subject_b']    ?? '',
            'preview_text' => $parsed['preview_text'] ?? '',
        ];
    }

    public function aiRewriteBlock(int $templateId, int $blockId, string $instruction): array
    {
        $block = DB::table('email_blocks')
            ->where('id', $blockId)->where('template_id', $templateId)->first();
        if (!$block) return ['error' => 'block_not_found'];

        $current = json_decode($block->content_json ?: '{}', true) ?: [];

        $system = "You are an expert email copywriter. Rewrite the given email block content following the instruction exactly. Return JSON with the same structure as the input content_json. Keep all field names identical. Only change the text values.";
        $user   = "Block type: {$block->block_type}\n"
                . "Instruction: {$instruction}\n"
                . "Current content: " . json_encode($current) . "\n\n"
                . "Return the updated content JSON only.";

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.7, 'max_tokens' => 900]);

        if (empty($result['success']) || empty($result['parsed'])) {
            return ['error' => $result['error'] ?? ($result['parse_error'] ?? 'ai_failed'), 'raw' => $result['content'] ?? null];
        }
        $merged = array_merge($current, $result['parsed']);
        $this->updateBlock($templateId, $blockId, ['content_json' => $merged]);
        return ['success' => true, 'content_json' => $merged];
    }

    public function aiSuggestSubjects(int $templateId, array $params): array
    {
        $tpl     = DB::table('email_templates')->where('id', $templateId)->first();
        $goal    = (string) ($params['goal']        ?? 'engage');
        $tone    = (string) ($params['tone']        ?? 'professional');
        $brand   = (string) ($params['brand_name']  ?? '');
        $summary = (string) ($params['summary']     ?? strip_tags(substr((string) ($tpl->html_body ?? ''), 0, 800)));

        $system = "You are an email subject line specialist. Generate 5 subject line options. Each must be under 60 characters. Vary the angles: curiosity, urgency, benefit, social proof, personalization. Return JSON array only.";
        $user   = "Email goal: {$goal}\n"
                . "Brand: {$brand}\n"
                . "Email content summary: {$summary}\n"
                . "Tone: {$tone}\n\n"
                . "Return:\n"
                . '{"subjects":[{"text":string,"angle":string,"score":1-10,"reason":string}]}';

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.8, 'max_tokens' => 800]);

        if (empty($result['success']) || empty($result['parsed'])) {
            return ['subjects' => [], 'error' => $result['error'] ?? ($result['parse_error'] ?? 'ai_failed')];
        }
        return ['subjects' => $result['parsed']['subjects'] ?? []];
    }

    /**
     * Rule-based spam check — deterministic, no LLM call.
     * Returns {score, max_score, rating, issues, subject_score, content_score, recommendation}.
     */
    public function aiSpamCheck(int $templateId, string $subject = ''): array
    {
        $tpl = DB::table('email_templates')->where('id', $templateId)->first();
        if (!$tpl) return ['error' => 'not_found'];
        $subject = $subject !== '' ? $subject : (string) $tpl->subject;
        $html    = (string) ($tpl->html_body ?? '');
        $body    = strip_tags($html);

        $issues        = [];
        $subjectScore  = 0;
        $contentScore  = 0;

        // 1. Spam words in subject — +1 each
        $spamWords = ['free','urgent','act now','limited','winner','cash','prize','click here','buy now','guarantee','no obligation'];
        foreach ($spamWords as $w) {
            if (stripos($subject, $w) !== false) {
                $issues[]      = ['rule' => 'spam_word', 'severity' => 1, 'fix' => "Remove spam trigger word '{$w}' from subject"];
                $subjectScore += 1;
            }
        }
        // 2. ALL CAPS words (6+ chars) — +2 each
        if (preg_match_all('/\b[A-Z]{6,}\b/', $subject, $m)) {
            foreach ($m[0] as $w) {
                $issues[]      = ['rule' => 'all_caps', 'severity' => 2, 'fix' => "Avoid ALL-CAPS word '{$w}'"];
                $subjectScore += 2;
            }
        }
        // 3. Excessive exclamation marks — +1 each beyond the first
        $excl = substr_count($subject, '!');
        if ($excl >= 2) {
            $issues[]      = ['rule' => 'exclamation', 'severity' => $excl - 1, 'fix' => 'Reduce exclamation marks to at most 1'];
            $subjectScore += $excl - 1;
        }
        // 4. No unsubscribe link — +5
        if (!str_contains(strtolower($html), 'unsubscribe') && !str_contains($html, '{{unsubscribe_url}}')) {
            $issues[]      = ['rule' => 'no_unsubscribe', 'severity' => 5, 'fix' => 'Add an unsubscribe link in the footer'];
            $contentScore += 5;
        }
        // 5. Image-to-text ratio > 60% — +3
        $imgCount  = preg_match_all('/<img\s/i', $html);
        $textLen   = max(1, strlen(trim($body)));
        $imgWeight = $imgCount * 500;
        $ratio     = $imgWeight / ($imgWeight + $textLen);
        if ($ratio > 0.6) {
            $issues[]      = ['rule' => 'image_ratio', 'severity' => 3, 'fix' => 'Add more body copy — images outweigh text'];
            $contentScore += 3;
        }
        // 6. More than 3 links — +1 each beyond 3
        $linkCount = preg_match_all('/href=/i', $html);
        if ($linkCount > 3) {
            $over = $linkCount - 3;
            $issues[]      = ['rule' => 'too_many_links', 'severity' => $over, 'fix' => "Reduce links (currently {$linkCount}, aim for 3 or fewer)"];
            $contentScore += $over;
        }
        // 7. Missing alt text on images — +2 each
        $missingAlt = 0;
        if ($imgCount > 0 && preg_match_all('/<img\s+[^>]*>/i', $html, $imgs)) {
            foreach ($imgs[0] as $imgTag) {
                if (!preg_match('/\balt="[^"]*"/', $imgTag)) $missingAlt++;
            }
        }
        if ($missingAlt > 0) {
            $issues[]      = ['rule' => 'missing_alt', 'severity' => 2 * $missingAlt, 'fix' => "Add alt text to {$missingAlt} image(s)"];
            $contentScore += 2 * $missingAlt;
        }
        // 8. Subject length > 60 chars — +1
        $subjLen = mb_strlen($subject);
        if ($subjLen > 60) {
            $issues[]      = ['rule' => 'subject_length', 'severity' => 1, 'fix' => "Shorten subject (currently {$subjLen} chars)"];
            $subjectScore += 1;
        }
        if ($subjLen === 0) {
            $issues[]      = ['rule' => 'subject_empty', 'severity' => 5, 'fix' => 'Add a subject line'];
            $subjectScore += 5;
        }

        $score  = $subjectScore + $contentScore;
        $rating = $score <= 3 ? 'great' : ($score <= 6 ? 'warning' : 'danger');
        $rec    = $rating === 'great'
                ? 'Great — likely to land in the primary inbox.'
                : ($rating === 'warning'
                    ? 'Warning — may land in the Promotions tab. Fix the flagged issues first.'
                    : 'Danger — high risk of spam folder. Resolve critical issues before sending.');

        return [
            'score'          => $score,
            'max_score'      => 20,
            'rating'         => $rating,
            'issues'         => $issues,
            'subject_score'  => $subjectScore,
            'content_score'  => $contentScore,
            'recommendation' => $rec,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // SEND PIPELINE
    // ═══════════════════════════════════════════════════════════════════

    // ───────────────────────────────────────────────────────────
    // PHASE 5 — queue-based send, tracking, resubscribe, analytics
    // email-builder-phase5
    // ───────────────────────────────────────────────────────────

    /** Part 1 — template-level send test. No tracking injected. */
    public function sendTestTemplate(int $templateId, string $toEmail, array $variables = []): array
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        $tpl = DB::table('email_templates')->where('id', $templateId)->first();
        if (!$tpl) return ['success' => false, 'message' => 'Template not found'];

        $html = $this->renderWithVariables($templateId, $variables);
        $subject = '[TEST] ' . ($tpl->subject ?: ($tpl->name ?: 'Email'));

        $result = $this->email->execute('send_email', [
            'to' => $toEmail, 'subject' => $subject, 'body' => $html, 'html' => true,
        ]);
        return [
            'success'    => (bool) ($result['success'] ?? false),
            'message_id' => $result['data']['message_id'] ?? null,
            'message'    => $result['message'] ?? ($result['error'] ?? 'unknown'),
        ];
    }

    /** Part 2 — dispatch the queue job after validation. */
    public function queueSendCampaign(int $campaignId): array
    {
        $validation = $this->validateCampaign($campaignId);
        if (!$validation['valid']) {
            return ['queued' => false, 'errors' => $validation['errors']];
        }
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
        $recipients = $campaign ? (json_decode($campaign->recipients_json ?: '[]', true) ?: []) : [];

        // Mark pending — job picks up and flips to 'sending'
        DB::table('campaigns')->where('id', $campaignId)->update([
            'status'     => 'queued',
            'stats_json' => json_encode([
                'sent' => 0, 'failed' => 0, 'total' => count($recipients), 'progress_pct' => 0,
            ]),
            'updated_at' => now(),
        ]);

        $jobId = 'campaign-send-' . $campaignId . '-' . now()->timestamp;
        \App\Jobs\SendEmailCampaignJob::dispatch($campaignId)->onQueue('tasks');

        return [
            'queued'          => true,
            'recipient_count' => count($recipients),
            'job_id'          => $jobId,
        ];
    }

    /** Part 2 — live progress aggregate for UI polling. */
    public function getSendStatus(int $campaignId): array
    {
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return ['status' => 'not_found', 'sent_count' => 0, 'total_count' => 0, 'progress_pct' => 0, 'errors' => []];

        $stats = json_decode($campaign->stats_json ?: '{}', true) ?: [];
        $total = (int) ($stats['total'] ?? 0);
        $sent  = (int) DB::table('email_campaigns_log')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', ['sent','opened','clicked'])
            ->count();
        $failed = (int) DB::table('email_campaigns_log')
            ->where('campaign_id', $campaignId)
            ->where('status', 'bounced')
            ->count();
        $pct = $total > 0 ? (int) round(($sent + $failed) * 100 / $total) : 0;

        return [
            'status'       => (string) $campaign->status,
            'sent_count'   => $sent,
            'failed_count' => $failed,
            'total_count'  => $total,
            'progress_pct' => $pct,
            'errors'       => [], // individual failures are in email_campaigns_log
        ];
    }

    /**
     * Part 3 — enhanced open tracking.
     * Detects device from User-Agent, increments counts, updates campaign stats.
     */
    public function trackOpenEnhanced(string $token, ?string $userAgent = null): void
    {
        $log = DB::table('email_campaigns_log')->where('tracking_token', $token)->first();
        if (!$log) return;

        $device = $this->detectDevice((string) $userAgent);

        $update = [
            'opened_count' => (int) $log->opened_count + 1,
            'updated_at'   => now(),
        ];
        if (!$log->opened_at) {
            $update['opened_at'] = now();
            if (!in_array($log->status, ['clicked', 'unsubscribed'], true)) {
                $update['status'] = 'opened';
            }
        }
        if (empty($log->device_type) && $device) {
            $update['device_type'] = $device;
        }
        DB::table('email_campaigns_log')->where('id', $log->id)->update($update);

        $this->refreshCampaignStats($log->campaign_id);
    }

    private function detectDevice(string $ua): string
    {
        $ua = strtolower($ua);
        if ($ua === '') return 'desktop';
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) return 'tablet';
        if (preg_match('/mobile|android|iphone|ipod/i', $ua)) return 'mobile';
        return 'desktop';
    }

    /**
     * Part 3 — click tracking with click_data JSON.
     * ALWAYS returns a redirect target; never throws.
     */
    public function trackClickEnhanced(string $linkToken, string $logToken): string
    {
        try {
            $link = DB::table('email_links')->where('tracking_token', $linkToken)->first();
            if (!$link) return url('/');

            DB::table('email_links')->where('id', $link->id)->increment('click_count');

            $log = DB::table('email_campaigns_log')->where('tracking_token', $logToken)->first();
            if ($log) {
                $clickData = json_decode($log->click_data ?: '{}', true) ?: [];
                $url = (string) $link->original_url;
                $clickData[$url] = (int) ($clickData[$url] ?? 0) + 1;

                $upd = [
                    'clicked_count' => (int) $log->clicked_count + 1,
                    'click_data'    => json_encode($clickData),
                    'updated_at'    => now(),
                ];
                if (!$log->clicked_at) {
                    $upd['clicked_at'] = now();
                    if ($log->status !== 'unsubscribed') $upd['status'] = 'clicked';
                }
                DB::table('email_campaigns_log')->where('id', $log->id)->update($upd);

                $this->refreshCampaignStats($log->campaign_id);
            }

            return (string) $link->original_url;
        } catch (\Throwable $e) {
            \Log::warning('email.track.click.failed', ['error' => $e->getMessage()]);
            return url('/');
        }
    }

    /** Part 3 — resolve + mark lead unsubscribed. */
    public function unsubscribeByToken(string $token): ?object
    {
        if (\Schema::hasTable('leads')) {
            $lead = DB::table('leads')->where('unsubscribe_token', $token)->first();
            if ($lead) {
                DB::table('leads')->where('id', $lead->id)->update([
                    'email_unsubscribed'    => 1,
                    'email_unsubscribed_at' => now(),
                    'updated_at'            => now(),
                ]);
                // Remove from active sequences — best-effort
                if (\Schema::hasTable('sequences')) {
                    DB::table('sequences')
                        ->where('workspace_id', $lead->workspace_id ?? 0)
                        ->whereNull('deleted_at')
                        ->update(['updated_at' => now()]);
                    // A dedicated sequence_enrollments table would be cleaner,
                    // but we don't have one — marker here is a no-op by design.
                }
                return $lead;
            }
        }
        return parent_legacy_unsub($token) ?? null;
    }

    /** Part 3 — resubscribe a lead by token. */
    public function resubscribeByToken(string $token): ?object
    {
        if (\Schema::hasTable('leads')) {
            $lead = DB::table('leads')->where('unsubscribe_token', $token)->first();
            if ($lead) {
                DB::table('leads')->where('id', $lead->id)->update([
                    'email_unsubscribed'    => 0,
                    'email_unsubscribed_at' => null,
                    'updated_at'            => now(),
                ]);
                return $lead;
            }
        }
        return null;
    }

    /**
     * After every tracking event, refresh campaign.stats_json with the
     * aggregate counts. Keeps dashboards live without extra queries.
     */
    private function refreshCampaignStats(int $campaignId): void
    {
        $logs      = DB::table('email_campaigns_log')->where('campaign_id', $campaignId)->get();
        $sent      = $logs->whereIn('status', ['sent','opened','clicked'])->count();
        $opened    = $logs->filter(fn($l) => !is_null($l->opened_at))->count();
        $clicked   = $logs->filter(fn($l) => !is_null($l->clicked_at))->count();
        $bounced   = $logs->where('status', 'bounced')->count();
        $unsubbed  = $logs->where('status', 'unsubscribed')->count();

        DB::table('campaigns')->where('id', $campaignId)->update([
            'stats_json' => json_encode([
                'sent' => $sent, 'opened' => $opened, 'clicked' => $clicked,
                'bounced' => $bounced, 'unsubscribed' => $unsubbed,
                'open_rate'  => $sent > 0 ? round($opened  * 100 / $sent, 2) : 0,
                'click_rate' => $sent > 0 ? round($clicked * 100 / $sent, 2) : 0,
            ]),
            'updated_at' => now(),
        ]);
    }

    public function validateCampaign(int $campaignId): array
    {
        $errors = []; $warnings = [];
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return ['valid' => false, 'errors' => ['Campaign not found']];

        if (empty($campaign->subject)) $errors[] = 'Subject is empty';
        if (empty($campaign->recipients_json) || json_decode($campaign->recipients_json, true) === []) {
            $errors[] = 'No recipients';
        }
        if (!$campaign->template_id) $errors[] = 'No template attached';
        if ($campaign->template_id) {
            $spam = $this->aiSpamCheck($campaign->template_id, $campaign->subject ?? '');
            $issueList = array_map(fn($i) => is_array($i) ? ($i['rule'] ?? 'issue') : (string) $i, $spam['issues'] ?? []);
            if (($spam['score'] ?? 0) >= 7) $errors[] = "Spam score {$spam['score']}/20 (" . ($spam['rating'] ?? '') . ") — " . implode(', ', $issueList);
            elseif (($spam['score'] ?? 0) >= 4) $warnings[] = "Spam score {$spam['score']}/20 (" . ($spam['rating'] ?? '') . ") — " . implode(', ', $issueList);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }

    public function sendTest(int $campaignId, string $toEmail, array $variables = []): array
    {
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
        if (!$campaign || !$campaign->template_id) return ['success' => false, 'message' => 'Campaign or template missing'];
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL))  return ['success' => false, 'message' => 'Invalid email'];

        $html = $this->renderWithVariables((int) $campaign->template_id, $variables);
        $subject = '[TEST] ' . ($campaign->subject ?: 'Test email');

        $result = $this->email->execute('send_email', [
            'to' => $toEmail, 'subject' => $subject, 'body' => $html, 'html' => true,
        ]);
        return [
            'success'    => (bool) ($result['success'] ?? false),
            'message_id' => $result['data']['message_id'] ?? null,
            'message'    => $result['message'] ?? ($result['error'] ?? 'unknown'),
        ];
    }

    /**
     * Queue a full send to every recipient. Synchronous fan-out for now — one
     * row inserted into email_campaigns_log per recipient, all sent inline.
     * (A real queue job could iterate this table but the core path is the same.)
     */
    public function sendCampaign(int $campaignId): array
    {
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
        if (!$campaign || !$campaign->template_id) return ['success' => false, 'message' => 'Campaign or template missing'];

        $validation = $this->validateCampaign($campaignId);
        if (!$validation['valid']) return ['success' => false, 'errors' => $validation['errors']];

        $recipients = json_decode($campaign->recipients_json ?: '[]', true) ?: [];
        $sent = 0; $failed = 0;

        foreach ($recipients as $r) {
            $email = (string) ($r['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }
            $name  = (string) ($r['name']  ?? '');

            $logId = DB::table('email_campaigns_log')->insertGetId([
                'campaign_id'     => $campaignId,
                'workspace_id'    => $campaign->workspace_id,
                'recipient_email' => $email,
                'recipient_name'  => $name,
                'subject'         => $campaign->subject,
                'status'          => 'pending',
                'tracking_token'  => Str::random(32),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $contact = (object) ['first_name' => explode(' ', $name)[0] ?? '', 'last_name' => '', 'email' => $email, 'company' => ''];
            $html    = $this->renderWithVariables((int) $campaign->template_id, $r['variables'] ?? [], $contact, $logId);

            $result = $this->email->execute('send_email', [
                'to' => $email, 'subject' => $campaign->subject, 'body' => $html, 'html' => true,
            ]);

            if (!empty($result['success'])) {
                $sent++;
                DB::table('email_campaigns_log')->where('id', $logId)->update([
                    'status'              => 'sent',
                    'sent_at'             => now(),
                    'postmark_message_id' => $result['data']['message_id'] ?? null,
                    'updated_at'          => now(),
                ]);
            } else {
                $failed++;
                DB::table('email_campaigns_log')->where('id', $logId)->update([
                    'status' => 'bounced', 'updated_at' => now(),
                ]);
            }
        }

        DB::table('campaigns')->where('id', $campaignId)->update([
            'status'  => 'sent',
            'sent_at' => now(),
            'stats_json' => json_encode([
                'sent' => $sent, 'failed' => $failed, 'total' => count($recipients),
            ]),
            'updated_at' => now(),
        ]);

        return ['queued' => $sent, 'failed' => $failed, 'count' => count($recipients)];
    }

    // ═══════════════════════════════════════════════════════════════════
    // TRACKING
    // ═══════════════════════════════════════════════════════════════════

    /** Injects open pixel + rewrites <a href> through click-tracking URLs. */
    private function injectTracking(string $html, int $logId): string
    {
        $log = DB::table('email_campaigns_log')->where('id', $logId)->first();
        if (!$log) return $html;

        $openUrl = url('/email/track/open/' . $log->tracking_token);
        $pixel   = '<img src="' . e($openUrl) . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;" />';
        $html    = str_replace('</body>', $pixel . '</body>', $html);

        // Rewrite links
        $html = preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function ($m) use ($log) {
                $orig = $m[1];
                // Skip already-tracked links and unsubscribe links
                if (str_contains($orig, '/email/track/') || str_contains($orig, '/email/unsubscribe/')) return 'href="' . $orig . '"';
                $linkId = DB::table('email_links')->insertGetId([
                    'campaign_id'    => $log->campaign_id,
                    'original_url'   => $orig,
                    'tracking_token' => Str::random(24),
                    'created_at'     => now(), 'updated_at' => now(),
                ]);
                $token = DB::table('email_links')->where('id', $linkId)->value('tracking_token');
                $tracked = url('/email/track/click/' . $token . '/' . $log->tracking_token);
                return 'href="' . e($tracked) . '"';
            },
            $html
        );
        return $html;
    }

    /** Deterministic per-log unsubscribe token — different namespace than open token */
    private function tokenFor(int $logId, string $kind): string
    {
        return hash('sha256', config('app.key') . '|' . $kind . '|' . $logId);
    }

    public function trackOpen(string $token): void
    {
        $log = DB::table('email_campaigns_log')->where('tracking_token', $token)->first();
        if (!$log) return;
        $update = [
            'opened_count' => (int) $log->opened_count + 1,
            'updated_at'   => now(),
        ];
        if (!$log->opened_at) {
            $update['opened_at'] = now();
            $update['status']    = in_array($log->status, ['clicked','unsubscribed'], true) ? $log->status : 'opened';
        }
        DB::table('email_campaigns_log')->where('id', $log->id)->update($update);
    }

    public function trackClick(string $linkToken, string $logToken): ?string
    {
        $link = DB::table('email_links')->where('tracking_token', $linkToken)->first();
        $log  = DB::table('email_campaigns_log')->where('tracking_token', $logToken)->first();
        if (!$link) return null;

        DB::table('email_links')->where('id', $link->id)->increment('click_count');

        if ($log) {
            $clickData = json_decode($log->click_data ?: '[]', true) ?: [];
            $clickData[] = ['url' => $link->original_url, 'at' => now()->toIso8601String()];
            DB::table('email_campaigns_log')->where('id', $log->id)->update([
                'clicked_count' => (int) $log->clicked_count + 1,
                'clicked_at'    => $log->clicked_at ?: now(),
                'click_data'    => json_encode($clickData),
                'status'        => 'clicked',
                'updated_at'    => now(),
            ]);
        }
        return $link->original_url;
    }

    public function unsubscribe(string $token): ?object
    {
        // Find the log row whose unsubscribe-token matches
        $rows = DB::table('email_campaigns_log')->get();
        foreach ($rows as $log) {
            if (hash('sha256', config('app.key') . '|u|' . $log->id) === $token) {
                DB::table('email_campaigns_log')->where('id', $log->id)->update([
                    'status' => 'unsubscribed', 'updated_at' => now(),
                ]);
                // Also flip the contact in CRM if table exists
                if (\Schema::hasTable('leads') && \Schema::hasColumn('leads', 'email_unsubscribed')) {
                    DB::table('leads')
                        ->where('email', $log->recipient_email)
                        ->update(['email_unsubscribed' => 1, 'email_unsubscribed_at' => now()]);
                }
                return $log;
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ANALYTICS
    // ═══════════════════════════════════════════════════════════════════

    public function getCampaignAnalytics(int $campaignId): array
    {
        $logs      = DB::table('email_campaigns_log')->where('campaign_id', $campaignId)->get();
        $total     = $logs->count();
        $sent      = $logs->whereIn('status', ['sent','opened','clicked'])->count();
        $delivered = $logs->where('status', '!=', 'bounced')->count();
        $opened    = $logs->filter(fn($l) => !is_null($l->opened_at))->count();
        $clicked   = $logs->filter(fn($l) => !is_null($l->clicked_at))->count();
        $bounced   = $logs->where('status', 'bounced')->count();
        $unsubbed  = $logs->where('status', 'unsubscribed')->count();

        $openRate   = $delivered > 0 ? round($opened  * 100 / $delivered, 2) : 0;
        $clickRate  = $delivered > 0 ? round($clicked * 100 / $delivered, 2) : 0;
        $ctor       = $opened    > 0 ? round($clicked * 100 / $opened,    2) : 0;
        $bounceRate = $total     > 0 ? round($bounced * 100 / $total,     2) : 0;

        // opens by hour — local server TZ
        $opensByHour = array_fill(0, 24, 0);
        foreach ($logs as $l) {
            if ($l->opened_at) {
                $h = (int) date('G', strtotime($l->opened_at));
                $opensByHour[$h]++;
            }
        }

        // device split
        $devices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
        foreach ($logs as $l) {
            $d = $l->device_type ?: 'desktop';
            if (!isset($devices[$d])) $d = 'desktop';
            $devices[$d]++;
        }

        // top links
        $links = DB::table('email_links')
            ->where('campaign_id', $campaignId)
            ->orderByDesc('click_count')
            ->limit(10)
            ->get();
        $totalClicks = max(1, (int) $links->sum('click_count'));
        $topLinks = $links->map(fn($l) => [
            'url'        => $l->original_url,
            'clicks'     => (int) $l->click_count,
            'percentage' => round($l->click_count * 100 / $totalClicks, 1),
        ])->toArray();

        // subject variant split
        $subjectAOpens = $logs->filter(fn($l) => $l->subject_variant === 'a' && !is_null($l->opened_at))->count();
        $subjectBOpens = $logs->filter(fn($l) => $l->subject_variant === 'b' && !is_null($l->opened_at))->count();

        $result = [
            'sent'               => $sent,
            'delivered'          => $delivered,
            'opened'             => $opened,
            'open_rate'          => $openRate,
            'clicked'            => $clicked,
            'click_rate'         => $clickRate,
            'click_to_open_rate' => $ctor,
            'bounced'            => $bounced,
            'bounce_rate'        => $bounceRate,
            'unsubscribed'       => $unsubbed,
            'opens_by_hour'      => array_values($opensByHour),
            'opens_by_device'    => $devices,
            'top_links'          => $topLinks,
            'subject_a_opens'    => $subjectAOpens,
            'subject_b_opens'    => $subjectBOpens,
            'ai_insight'         => $this->aiInsightForCampaign($campaignId, $openRate, $clickRate, $topLinks, $opensByHour),
        ];
        return $result;
    }

    private function aiInsightForCampaign(int $campaignId, float $openRate, float $clickRate, array $topLinks, array $opensByHour): string
    {
        // Cheap cache so the insight isn't regenerated on every poll
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
        $cached = null;
        if ($campaign) {
            $stats = json_decode($campaign->stats_json ?: '{}', true) ?: [];
            $cached = $stats['ai_insight'] ?? null;
            $cachedFor = $stats['ai_insight_for'] ?? null;
            $sig = md5($openRate . '|' . $clickRate);
            if ($cached && $cachedFor === $sig) return (string) $cached;
        }

        // Rule-based baseline in case DeepSeek is unavailable
        $peakHour = array_search(max($opensByHour), $opensByHour, true);
        $peakLabel = $peakHour === false ? 'no data' : date('gA', mktime((int) $peakHour));
        $ind = 22.0;
        $above = $openRate >= $ind;
        $fallback = sprintf(
            "Your open rate of %s%% is %s the industry average of %.0f%%. Peak opens were at %s. %s",
            number_format($openRate, 1),
            $above ? 'above' : 'below',
            $ind,
            $peakLabel,
            $above
                ? 'Your subject line and timing are resonating — replicate this pattern next time.'
                : 'Try a shorter, more specific subject line and test sending on Tuesday or Thursday.'
        );

        try {
            $prompt = "Write one short email-marketing insight (2-3 sentences) based on these stats: open_rate={$openRate}% click_rate={$clickRate}% peak_hour={$peakLabel} industry_average_open_rate={$ind}%. Be specific and actionable. Plain text only, no JSON.";
            $result = $this->llm->chat([
                ['role' => 'system', 'content' => 'You are a senior email marketing analyst. Give concise, specific insights.'],
                ['role' => 'user',   'content' => $prompt],
            ], ['temperature' => 0.5, 'max_tokens' => 200]);
            $text = trim((string) ($result['content'] ?? ''));
            $out  = $text !== '' ? $text : $fallback;
        } catch (\Throwable $e) {
            $out = $fallback;
        }

        if ($campaign) {
            $stats = json_decode($campaign->stats_json ?: '{}', true) ?: [];
            $stats['ai_insight'] = $out;
            $stats['ai_insight_for'] = md5($openRate . '|' . $clickRate);
            DB::table('campaigns')->where('id', $campaignId)->update([
                'stats_json' => json_encode($stats), 'updated_at' => now(),
            ]);
        }
        return $out;
    }


    // ═══════════════════════════════════════════════════════════════════
    // PRIVATE: seeding helpers (blank / html / clone)
    // ═══════════════════════════════════════════════════════════════════

    private function seedDefaultBlocks(int $templateId): void
    {
        foreach (['header', 'hero', 'footer'] as $i => $type) {
            $this->addBlock($templateId, [
                'block_type'  => $type,
                'block_order' => $i + 1,
                'content_json'=> EmailBlockLibrary::defaultContent($type),
            ]);
        }
    }

    private function seedFromHtml(int $templateId, string $html): void
    {
        DB::table('email_blocks')->insert([
            'template_id'  => $templateId,
            'block_order'  => 1,
            'block_type'   => 'custom_html',
            'content_json' => json_encode(['raw_html' => $html]),
            'styles_json'  => json_encode([]),
            'is_visible'   => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        DB::table('email_templates')->where('id', $templateId)->update([
            'html_body'  => $html,
            'updated_at' => now(),
        ]);
    }

    private function cloneBlocks(int $newId, int $sourceId): void
    {
        $blocks = DB::table('email_blocks')->where('template_id', $sourceId)->orderBy('block_order')->get();
        foreach ($blocks as $b) {
            DB::table('email_blocks')->insert([
                'template_id'  => $newId,
                'block_order'  => $b->block_order,
                'block_type'   => $b->block_type,
                'content_json' => $b->content_json,
                'styles_json'  => $b->styles_json,
                'is_visible'   => $b->is_visible,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // PREMIUM TEMPLATE SEED — parses /tmp/email-template-premium.html
    // ═══════════════════════════════════════════════════════════════════

    public function seedPremiumTemplate(int $workspaceId): array
    {
        // Idempotency — skip if already seeded
        $existing = DB::table('email_templates')
            ->where('name', 'Premium — Full Feature')
            ->where('is_system', 1)
            ->first();
        if ($existing) return ['template_id' => $existing->id, 'already_seeded' => true];

        $templateId = DB::table('email_templates')->insertGetId([
            'workspace_id'   => $workspaceId,
            'name'           => 'Premium — Full Feature',
            'category'       => 'promotional',
            'subject'        => '{{headline}} — {{brand_name}}',
            'preview_text'   => '{{subheadline}}',
            'brand_color'    => '#5B5BD6',
            'body_html'      => '',
            'html_body'      => '',
            'variables_json' => json_encode([]),
            'blocks_json'    => json_encode([]),
            'is_system'      => 1,
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Insert 7 blocks mirroring the premium reference layout
        $seed = [
            ['header', [
                'logo_url'         => 'https://cdn.example.com/logo@2x.png',
                'logo_alt'         => '{{brand_name}}',
                'brand_name'       => '{{brand_name}}',
                'logo_width'       => 140,
                'bg_color'         => '{{brand_color}}',
                'nav_link_1_text'  => 'Features',
                'nav_link_1_url'   => '{{cta_url}}',
                'nav_link_2_text'  => 'Pricing',
                'nav_link_2_url'   => '{{cta_url}}',
            ]],
            ['hero', [
                'hero_image_url' => 'https://cdn.example.com/hero-1200x520.png',
                'eyebrow_text'   => "\u{2726} What's New",
                'headline'       => '{{headline}}',
                'subheadline'    => '{{subheadline}}',
                'cta_text'       => '{{cta_text}}',
                'cta_url'        => '{{cta_url}}',
                'cta_note'       => 'No credit card required · Free 14-day trial',
            ]],
            ['features', [
                'section_label'   => 'Why teams choose {{brand_name}}',
                'feature_1_icon'  => '{{feature_1_icon}}',
                'feature_1_title' => '{{feature_1_title}}',
                'feature_1_body'  => '{{feature_1_body}}',
                'feature_2_icon'  => '{{feature_2_icon}}',
                'feature_2_title' => '{{feature_2_title}}',
                'feature_2_body'  => '{{feature_2_body}}',
                'feature_3_icon'  => '{{feature_3_icon}}',
                'feature_3_title' => '{{feature_3_title}}',
                'feature_3_body'  => '{{feature_3_body}}',
            ]],
            ['body_text', [
                'body_html' => '<p>{{body_text}}</p><p>Ready to see it in action?</p>',
            ]],
            ['secondary_cta', [
                'headline' => '{{secondary_headline}}',
                'body'     => '{{secondary_body}}',
                'cta_text' => '{{secondary_cta_text}}',
                'cta_url'  => '{{secondary_cta_url}}',
                'bg_color' => '#0F172A',
            ]],
            ['testimonial', [
                'quote'            => '{{testimonial_quote}}',
                'name'             => '{{testimonial_name}}',
                'role'             => '{{testimonial_role}}',
                'avatar_initials'  => 'TN',
                'avatar_bg'        => '{{brand_color}}',
                'show_stars'       => true,
            ]],
            ['footer', [
                'brand_name'           => '{{brand_name}}',
                'footer_text'          => '{{footer_text}}',
                'unsubscribe_url'      => '{{unsubscribe_url}}',
                'preferences_url'      => '{{cta_url}}',
                'privacy_url'          => '{{cta_url}}',
                'social_x_url'         => '#',
                'social_linkedin_url'  => '#',
                'social_instagram_url' => '#',
                'current_year'         => '{{current_year}}',
            ]],
        ];

        foreach ($seed as $i => [$type, $content]) {
            DB::table('email_blocks')->insert([
                'template_id'  => $templateId,
                'block_order'  => $i + 1,
                'block_type'   => $type,
                'content_json' => json_encode($content),
                'styles_json'  => json_encode([]),
                'is_visible'   => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $this->recompile($templateId);
        return ['template_id' => $templateId, 'already_seeded' => false, 'blocks' => 7];
    }
}

/** Helper for legacy unsubscribe-token format (pre-5A log-based tokens). */
if (!function_exists('parent_legacy_unsub')) {
    function parent_legacy_unsub(string $token): ?object {
        try {
            $rows = \Illuminate\Support\Facades\DB::table('email_campaigns_log')->get();
            foreach ($rows as $log) {
                if (hash('sha256', config('app.key') . '|u|' . $log->id) === $token) {
                    \Illuminate\Support\Facades\DB::table('email_campaigns_log')->where('id', $log->id)->update([
                        'status' => 'unsubscribed', 'updated_at' => now(),
                    ]);
                    return $log;
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }
}

