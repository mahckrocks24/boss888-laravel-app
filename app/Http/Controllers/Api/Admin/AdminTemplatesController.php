<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Engines\Builder\Services\TemplateService;

/**
 * Admin surface for template library management.
 *
 * Reads from storage/templates/{industry}/ (file-based — one folder per
 * industry, each with template.html + manifest.json). Toggles write
 * is_active flags back to the manifest. Clone duplicates a folder as
 * {industry}_commercial with the variation flipped. Upload accepts a
 * fresh template.html + manifest.json pair.
 *
 * Per the BOSS888 hands-vs-brain rule: no LLM calls, no runtime. Just
 * filesystem + DB reads.
 */
class AdminTemplatesController
{
    private const TEMPLATE_ROOT = 'templates';

    /**
     * Locked 2026-04-19 — every template must declare these blocks. Applied
     * on upload; index() reports which templates are currently missing them
     * so the admin view can flag them. Existing templates were swept to add
     * blog the same day, so all current ones pass.
     */
    private const REQUIRED_BLOCKS = ['hero', 'booking', 'blog', 'contact', 'footer'];

    public function index(Request $request)
    {
        try {
            $ts = new TemplateService();
            $all = $ts->listTemplates(true); // include inactive for admin

            // Usage table — how many websites use each template_industry
            $usage = DB::table('websites')
                ->whereNotNull('template_industry')
                ->whereNull('deleted_at')
                ->select('template_industry as industry', DB::raw('COUNT(*) as sites_built'))
                ->groupBy('template_industry')
                ->orderByDesc('sites_built')
                ->get();

            $usageMap = [];
            foreach ($usage as $row) $usageMap[$row->industry] = (int) $row->sites_built;

            // Attach usage count + REQUIRED_BLOCKS compliance to each row.
            foreach ($all as &$t) {
                $t['sites_built'] = $usageMap[$t['industry']] ?? 0;

                // Reload manifest to check required blocks (listTemplates
                // doesn't include the raw block ids — only counts).
                $mf = storage_path(self::TEMPLATE_ROOT . '/' . $t['industry'] . '/manifest.json');
                $blockIds = [];
                if (is_file($mf)) {
                    $manifest = json_decode(file_get_contents($mf), true);
                    if (is_array($manifest)) {
                        foreach (($manifest['blocks'] ?? []) as $b) {
                            if (isset($b['id'])) $blockIds[] = $b['id'];
                        }
                    }
                }
                $missingBlocks = array_values(array_diff(self::REQUIRED_BLOCKS, $blockIds));
                $t['required_blocks'] = self::REQUIRED_BLOCKS;
                $t['missing_blocks']  = $missingBlocks;
                $t['compliant']       = empty($missingBlocks);
            }
            unset($t);

            // Stats cards
            $totalTemplates = count($all);
            $activeTemplates = 0;
            foreach ($all as $t) if (!empty($t['is_active'])) $activeTemplates++;
            $industries = [];
            foreach ($all as $t) $industries[$t['industry']] = true;
            $totalIndustries = count($industries);

            $mostUsed = '—';
            $mostUsedCount = 0;
            foreach ($usage as $row) {
                if ($row->sites_built > $mostUsedCount) {
                    $mostUsed = $row->industry;
                    $mostUsedCount = (int) $row->sites_built;
                }
            }

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_templates'    => $totalTemplates,
                    'active_templates'   => $activeTemplates,
                    'inactive_templates' => $totalTemplates - $activeTemplates,
                    'total_industries'   => $totalIndustries,
                    'most_used'          => $mostUsed,
                    'most_used_count'    => $mostUsedCount,
                ],
                'templates' => $all,
                'usage'     => $usage,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AdminTemplates] index failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle a template's is_active flag in its manifest.json.
     */
    public function toggle(Request $request, string $industry)
    {
        $industry = $this->sanitizeIndustry($industry);
        if ($industry === null) {
            return response()->json(['success' => false, 'error' => 'Invalid industry key'], 400);
        }
        $manifestPath = $this->manifestPath($industry);
        if (!file_exists($manifestPath)) {
            return response()->json(['success' => false, 'error' => 'Template not found'], 404);
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return response()->json(['success' => false, 'error' => 'Manifest is invalid JSON'], 500);
        }

        $current = array_key_exists('is_active', $manifest) ? (bool) $manifest['is_active'] : true;
        $manifest['is_active'] = !$current;

        $written = file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        if ($written === false) {
            return response()->json(['success' => false, 'error' => 'Failed to write manifest'], 500);
        }

        return response()->json([
            'success'   => true,
            'industry'  => $industry,
            'is_active' => $manifest['is_active'],
        ]);
    }

    /**
     * Clone {industry}/ to {industry}_commercial/ with variation flipped.
     * If {industry}_commercial already exists returns 409 instead of
     * overwriting — admin must delete or pick a new suffix manually.
     */
    public function clone(Request $request, string $industry)
    {
        $industry = $this->sanitizeIndustry($industry);
        if ($industry === null) {
            return response()->json(['success' => false, 'error' => 'Invalid industry key'], 400);
        }
        $srcDir = storage_path(self::TEMPLATE_ROOT . '/' . $industry);
        if (!is_dir($srcDir)) {
            return response()->json(['success' => false, 'error' => 'Source template not found'], 404);
        }

        $dstIndustry = $industry . '_commercial';
        $dstDir = storage_path(self::TEMPLATE_ROOT . '/' . $dstIndustry);
        if (is_dir($dstDir)) {
            return response()->json([
                'success' => false,
                'error'   => "Clone target already exists at {$dstIndustry}. Delete it first or rename manually.",
            ], 409);
        }

        if (!mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
            return response()->json(['success' => false, 'error' => 'Failed to create clone directory'], 500);
        }

        // Copy every file in the source folder, keeping permissions.
        foreach (scandir($srcDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!is_file($srcDir . '/' . $f)) continue;
            if (!copy($srcDir . '/' . $f, $dstDir . '/' . $f)) {
                return response()->json([
                    'success' => false,
                    'error' => "Copy failed for {$f}",
                ], 500);
            }
        }

        // Rewrite manifest with new industry/id + variation=commercial.
        $manifestPath = $dstDir . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (is_array($manifest)) {
                $originalName = $manifest['name'] ?? ucwords(str_replace('_', ' ', $industry));
                $manifest['id']        = $dstIndustry;
                $manifest['industry']  = $dstIndustry;
                $manifest['name']      = $originalName . ' — Commercial';
                $manifest['variation'] = 'commercial';
                $manifest['is_active'] = true;
                file_put_contents(
                    $manifestPath,
                    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }
        }

        return response()->json([
            'success'       => true,
            'cloned_from'   => $industry,
            'cloned_to'     => $dstIndustry,
            'variation'     => 'commercial',
        ]);
    }

    /**
     * Upload a new template: industry_key + name + variation + files.
     * Accepts multipart with two files: template_html + manifest_json.
     */
    public function upload(Request $request)
    {
        $industry = $this->sanitizeIndustry((string) $request->input('industry'));
        $name     = trim((string) $request->input('name'));
        $variation = in_array((string) $request->input('variation'), ['luxury', 'commercial'], true)
            ? (string) $request->input('variation')
            : 'luxury';

        if ($industry === null || $industry === '' || strlen($industry) < 2) {
            return response()->json(['success' => false, 'error' => 'industry key is required (letters, numbers, underscore)'], 400);
        }
        if ($name === '') {
            return response()->json(['success' => false, 'error' => 'Display name is required'], 400);
        }

        $templateFile = $request->file('template_html');
        $manifestFile = $request->file('manifest_json');
        if (!$templateFile || !$manifestFile) {
            return response()->json(['success' => false, 'error' => 'Both template_html and manifest_json files are required'], 400);
        }
        if ($templateFile->getSize() > 2 * 1024 * 1024 || $manifestFile->getSize() > 1 * 1024 * 1024) {
            return response()->json(['success' => false, 'error' => 'File too large (max 2 MB template, 1 MB manifest)'], 400);
        }

        // Validate manifest JSON before writing anything.
        $manifestRaw = file_get_contents($manifestFile->getRealPath());
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) {
            return response()->json(['success' => false, 'error' => 'manifest.json is not valid JSON'], 400);
        }

        // Enforce required blocks — gallery contract guarantees every site
        // generated via Arthur has hero/booking/blog/contact/footer.
        $providedBlocks = [];
        foreach (($manifest['blocks'] ?? []) as $b) {
            if (isset($b['id'])) $providedBlocks[] = $b['id'];
        }
        $missing = array_values(array_diff(self::REQUIRED_BLOCKS, $providedBlocks));
        if (!empty($missing)) {
            return response()->json([
                'success' => false,
                'error'   => 'Manifest is missing required blocks: ' . implode(', ', $missing)
                           . '. Every template must declare: ' . implode(', ', self::REQUIRED_BLOCKS) . '.',
            ], 422);
        }

        $dstDir = storage_path(self::TEMPLATE_ROOT . '/' . $industry);
        if (is_dir($dstDir)) {
            return response()->json([
                'success' => false,
                'error'   => "A template folder for '{$industry}' already exists. Delete it first or pick a different key.",
            ], 409);
        }
        if (!mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
            return response()->json(['success' => false, 'error' => 'Failed to create template directory'], 500);
        }

        // Normalize the manifest so admin-provided values win where relevant,
        // and is_active defaults to true.
        $manifest['id']        = $industry;
        $manifest['industry']  = $industry;
        $manifest['name']      = $name;
        $manifest['variation'] = $variation;
        if (!array_key_exists('is_active', $manifest)) $manifest['is_active'] = true;

        $ok1 = file_put_contents($dstDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $ok2 = move_uploaded_file($templateFile->getRealPath(), $dstDir . '/template.html');
        if ($ok1 === false || !$ok2) {
            return response()->json(['success' => false, 'error' => 'Failed to write template files'], 500);
        }

        return response()->json([
            'success'    => true,
            'industry'   => $industry,
            'name'       => $name,
            'variation'  => $variation,
            'path'       => '/storage/templates/' . $industry,
        ], 201);
    }

    /**
     * Industry key must be safe for a filesystem path. Returns null if
     * the candidate contains anything outside [a-z0-9_]. Rejects
     * absolute paths, dot-segments, and reserved names.
     */
    private function sanitizeIndustry(?string $industry): ?string
    {
        if ($industry === null) return null;
        $industry = strtolower(trim($industry));
        if ($industry === '' || strlen($industry) > 64) return null;
        if (!preg_match('/^[a-z0-9_]+$/', $industry)) return null;
        if (in_array($industry, ['.', '..'], true)) return null;
        return $industry;
    }

    private function manifestPath(string $industry): string
    {
        return storage_path(self::TEMPLATE_ROOT . '/' . $industry . '/manifest.json');
    }
}
