<?php

namespace App\Engines\Builder\Services;

use App\Connectors\RuntimeClient;
use App\Engines\Builder\Schema\SectionSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ArthurEditService — structured-JSON Arthur edits (Patch 8.5 Tier 1).
 *
 * Replaces the legacy regex-on-static-HTML closure at routes/api.php:6973
 * with a deterministic mutation pipeline that operates ONLY on
 * pages.sections_json:
 *
 *   1. Load pages.sections_json
 *   2. Snapshot before
 *   3. Ask runtime for {actions: [...], reply: "..."}
 *   4. Apply actions atomically (DB transaction)
 *   5. Validate each resulting section against SectionSchema
 *   6. Snapshot after
 *   7. Bust published-site cache
 *   8. Return new sections + reply
 *
 * Section data is FLAT — fields live directly on the section object,
 * not nested under a `data` key. This matches the canonical sections_json
 * shape at page id=2 and what BuilderRenderer reads.
 *
 * Max 5 actions per response (enforced).
 */
class ArthurEditService
{
    private const MAX_ACTIONS = 5;
    private const MAX_TOKENS = 1500;

    public function __construct(
        protected RuntimeClient $runtime,
        protected BuilderSnapshotService $snapshots,
    ) {}

    /**
     * Apply an Arthur edit to a page's sections_json.
     * Returns success/sections/reply/applied/errors.
     */
    public function editPage(
        int $pageId,
        string $userMessage,
        ?int $sectionIndex = null,
        array $context = []
    ): array {
        // 1. Load page + parse current sections.
        $page = DB::table('pages')->where('id', $pageId)->first();
        if (! $page) {
            throw new \RuntimeException("Page {$pageId} not found");
        }
        // TENANCY (B2/B7 IDOR): when a workspace context is supplied the page MUST
        // belong to it. Mirrors BuilderService::updatePage + the RISK-0092 sibling
        // guards (publish_website/generate_page/update_page). Without this the
        // ai_builder_action capability would LLM-edit + save a FOREIGN workspace's
        // page. Conditional on workspace_id so callers that legitimately omit it
        // (and pre-check ownership themselves, e.g. ArthurEditController) still work.
        $wsCtx = (int) ($context['workspace_id'] ?? 0);
        if ($wsCtx > 0 && ! DB::table('pages')
                ->join('websites', 'websites.id', '=', 'pages.website_id')
                ->where('pages.id', $pageId)
                ->where('websites.workspace_id', $wsCtx)
                ->exists()) {
            throw new \RuntimeException("Page {$pageId} not found");
        }
        $raw = json_decode($page->sections_json ?? '[]', true) ?: [];
        // BUILDER888: sections_json may be stored wrapped ({schemaVersion,sections}) by
        // createPage/updatePage, or as a flat list. Edit the flat list and re-wrap on
        // save so the validate loop never receives the schemaVersion int (was a
        // TypeError into SectionSchema::validate) and the stored shape is preserved.
        $wrapped = is_array($raw) && isset($raw['sections']) && is_array($raw['sections']);
        $schemaVersion = $wrapped ? ($raw['schemaVersion'] ?? 1) : 1;
        $sections = $wrapped ? $raw['sections'] : (is_array($raw) ? $raw : []);
        $currentJson = json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $websiteId = (int) ($page->website_id ?? 0);
        $styleVarsHint = $this->availableStyleVars($websiteId);

        // 2. Build runtime prompt.
        $allowedTypes = implode(', ', SectionSchema::allowedTypes());
        $allowedOps   = implode(', ', SectionSchema::allowedOps());
        $maxActions   = self::MAX_ACTIONS;

        $sectionFocusHint = $sectionIndex !== null
            ? "User is focused on section_index={$sectionIndex}. Prefer edits to that section unless the user explicitly references another."
            : "No specific section selected — apply the edit where it makes the most sense.";

        $systemPrompt = <<<PROMPT
You are Arthur, an AI website-builder assistant. You edit website pages
represented as a flat JSON array of sections. Each section has a "type"
plus inline fields like "heading", "body", "cta_text" — NOT nested under
a "data" key. Match this shape exactly when you propose changes.

Current sections (flat shape — fields live directly on the section object):
{$currentJson}

{$sectionFocusHint}

Rules:
- Respond ONLY with valid JSON matching the response schema below.
- Maximum {$maxActions} actions per response.
- section_index is 0-based.
- Allowed ops: {$allowedOps}
- Allowed section types: {$allowedTypes}
- For update_text / update_field: provide section_index, field, value.
  Field must be a known field for that section's type.
- For update_image: provide section_index, field (default background_image), value (URL).
- For add_section: provide type and (optional) flat fields plus optional insert_at index.
- For remove_section: provide section_index.
- For reorder_section: provide from_index and to_index.
- For update_style: provide a "colors" object mapping colour names to #hex values.{$styleVarsHint}
- Never invent new top-level keys other than the response schema below.

Response schema (return ONLY this JSON object):
{
  "actions": [
    { "op": "update_text", "section_index": 0, "field": "heading", "value": "New heading" }
  ],
  "reply": "I've updated the hero heading to ..."
}
PROMPT;

        // 3. Call RuntimeClient — real signature is (system, user, context, maxTokens).
        $resp = $this->runtime->chatJson($systemPrompt, $userMessage, [], self::MAX_TOKENS);
        if (empty($resp['success']) || ! is_array($resp['parsed'] ?? null)) {
            throw new \RuntimeException('Runtime returned non-JSON or failed: ' . ($resp['error'] ?? 'unknown'));
        }
        $parsed = $resp['parsed'];

        $actions = is_array($parsed['actions'] ?? null) ? $parsed['actions'] : [];
        $reply   = (string) ($parsed['reply'] ?? 'Changes applied.');

        if (count($actions) > self::MAX_ACTIONS) {
            $actions = array_slice($actions, 0, self::MAX_ACTIONS);
            Log::warning('ArthurEditService: actions truncated to MAX_ACTIONS', [
                'page_id'    => $pageId,
                'requested'  => count($parsed['actions'] ?? []),
                'kept'       => self::MAX_ACTIONS,
            ]);
        }

        // OPEN-1 (2026-09-02): site-level colour changes are not per-section edits. Split them
        // out so the transaction/section loop and syncStaticHtml stay untouched; apply them to
        // the served output (static :root vars, or renderer settings_json) after the sync.
        $styleColors    = [];
        $sectionActions = [];
        foreach ($actions as $__a) {
            if ((string) ($__a['op'] ?? '') === 'update_style' && is_array($__a['colors'] ?? null)) {
                foreach ($__a['colors'] as $__k => $__v) { $styleColors[(string) $__k] = $__v; }
            } else {
                $sectionActions[] = $__a;
            }
        }
        $actions = $sectionActions;

        // 4. Snapshot before.
        $this->snapshots->snapshot($pageId, 'arthur_edit_before');

        // 5. Apply actions atomically.
        $errors  = [];
        $applied = 0;
        $newSections = $sections;

        DB::transaction(function () use ($pageId, &$newSections, $actions, &$errors, &$applied, $wrapped, $schemaVersion) {
            foreach ($actions as $action) {
                try {
                    $newSections = $this->applyAction($newSections, $action);
                    $applied++;
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    Log::warning('ArthurEditService action failed', [
                        'page_id' => $pageId,
                        'op'      => $action['op'] ?? '?',
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Schema validation pass (warnings only — don't block writes).
            foreach ($newSections as $i => $section) {
                $v = SectionSchema::validate($section);
                if (! ($v['ok'] ?? false)) {
                    Log::warning('Section validation warning', [
                        'page_id'  => $pageId,
                        'index'    => $i,
                        'errors'   => $v['errors'] ?? [],
                    ]);
                }
            }

            DB::table('pages')->where('id', $pageId)->update([
                'sections_json' => json_encode($wrapped ? ['schemaVersion' => $schemaVersion, 'sections' => $newSections] : $newSections),
                'updated_at'    => now(),
            ]);
        });

        // 6. Snapshot after.
        $this->snapshots->snapshot($pageId, 'arthur_edit_after');

        // 6b. (2026-08-03) MIRROR THE EDIT ONTO THE SERVED PAGE.
        //
        // Every Arthur-built site is written to
        // storage/app/public/sites/{id}/index.html, and PublishedSiteMiddleware
        // serves that file in preference to BuilderRenderer. Writing only
        // sections_json therefore changed nothing the customer could see, while
        // this method still returned success and the endpoint still charged a
        // credit. Legacy renderer-backed sites (no static file) are unaffected:
        // syncStaticHtml() no-ops when the file is absent.
        $sync = $this->syncStaticHtml($websiteId, $newSections, $actions);

        // Never claim a change the visitor cannot see.
        if ($sync['is_static']) {
            if ($applied > 0 && $sync['applied'] === 0) {
                $reply = 'I saved those changes, but I could not apply them to your published page — '
                       . 'the fields involved have no editable slot in this template. '
                       . 'Your live site is unchanged.';
            } elseif (! empty($sync['missed'])) {
                $reply .= ' (' . count($sync['missed']) . ' of those changes could not be applied to the published page.)';
            }
        }

        // OPEN-1: apply colour changes to the served page (static :root vars or renderer settings).
        $style = ['applied' => 0, 'missed' => [], 'is_static' => false, 'files' => []];
        if ($styleColors !== []) {
            $style = $this->applyStyleColors($websiteId, $styleColors);
            if ($style['applied'] > 0) {
                $reply .= ' I updated ' . $style['applied'] . ' site colour'
                        . ($style['applied'] === 1 ? '' : 's') . ' on your published page.';
            }
            if (! empty($style['missed'])) {
                $reply .= ' I could not change: ' . implode('; ', $style['missed']) . '.';
            }
        }

        // 7. Bust published cache.
        $this->bustPublishedCache($pageId);

        return [
            'success'         => true,
            'sections'        => $newSections,
            'reply'           => $reply,
            'actions_applied' => $applied,
            'errors'          => $errors,
            'static_applied'  => $sync['applied'],
            'static_missed'   => $sync['missed'],
            'visible_on_site' => $sync['is_static'] ? ($sync['applied'] > 0) : true,
            'style_applied'   => $style['applied'],
            'style_missed'    => $style['missed'],
        ];
    }

    /**
     * Dispatch a single action to its op handler.
     */
    private function applyAction(array $sections, array $action): array
    {
        $op  = (string) ($action['op'] ?? '');
        $idx = isset($action['section_index']) ? (int) $action['section_index'] : null;

        return match ($op) {
            'update_text', 'update_field' => $this->opUpdateField(
                $sections,
                $idx,
                (string) ($action['field'] ?? ''),
                $action['value'] ?? ''
            ),
            'update_image' => $this->opUpdateField(
                $sections,
                $idx,
                (string) ($action['field'] ?? 'background_image'),
                $action['value'] ?? ''
            ),
            'add_section' => $this->opAddSection(
                $sections,
                (string) ($action['type'] ?? 'generic'),
                $action,
                isset($action['insert_at']) ? (int) $action['insert_at'] : null
            ),
            'remove_section' => $this->opRemoveSection($sections, $idx),
            'reorder_section' => $this->opReorderSection(
                $sections,
                isset($action['from_index']) ? (int) $action['from_index'] : $idx,
                isset($action['to_index']) ? (int) $action['to_index'] : null
            ),
            default => throw new \RuntimeException("Unknown op: {$op}"),
        };
    }

    /**
     * Update a flat field on a section. Validates that the field is
     * allowed for that section's type (generic accepts anything).
     */
    private function opUpdateField(array $sections, ?int $idx, string $field, mixed $value): array
    {
        if ($idx === null || ! array_key_exists($idx, $sections)) {
            throw new \RuntimeException("Invalid section_index for update: " . ($idx ?? 'null'));
        }
        if ($field === '') {
            throw new \RuntimeException('Field name required for update');
        }
        $type = (string) ($sections[$idx]['type'] ?? 'generic');
        if ($type !== 'generic' && SectionSchema::isKnownType($type)) {
            $allowed = SectionSchema::allowedFieldsFor($type);
            if ($allowed && ! in_array($field, $allowed, true)) {
                throw new \RuntimeException("Field '{$field}' not allowed on type '{$type}'");
            }
        }
        $sections[$idx][$field] = $value;
        return $sections;
    }

    /**
     * Add a new section (append or insert-at).
     */
    private function opAddSection(array $sections, string $type, array $action, ?int $insertAt): array
    {
        if (! SectionSchema::isKnownType($type)) {
            $type = 'generic';
        }
        $newSection = ['type' => $type];
        // Pull only the schema-allowed fields off the action payload.
        $allowed = SectionSchema::allowedFieldsFor($type) ?: ['heading', 'body', 'content'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $action)) $newSection[$f] = $action[$f];
        }
        if ($insertAt !== null && $insertAt >= 0 && $insertAt < count($sections)) {
            array_splice($sections, $insertAt, 0, [$newSection]);
        } else {
            $sections[] = $newSection;
        }
        return array_values($sections);
    }

    private function opRemoveSection(array $sections, ?int $idx): array
    {
        if ($idx === null || ! array_key_exists($idx, $sections)) {
            throw new \RuntimeException("Invalid section_index for remove: " . ($idx ?? 'null'));
        }
        array_splice($sections, $idx, 1);
        return array_values($sections);
    }

    private function opReorderSection(array $sections, ?int $from, ?int $to): array
    {
        $count = count($sections);
        if ($from === null || $to === null
            || ! array_key_exists($from, $sections)
            || $to < 0 || $to > $count) {
            throw new \RuntimeException("Invalid reorder indices (from={$from} to={$to})");
        }
        $section = array_splice($sections, $from, 1)[0];
        // After splice, count is decremented by 1; adjust to_index if past from.
        $insertAt = ($to > $from) ? $to - 1 : $to;
        array_splice($sections, $insertAt, 0, [$section]);
        return array_values($sections);
    }

    /**
     * sections_json field names ("hero.heading") and the static templates'
     * data-field ids ("hero_title") are different vocabularies, and the id
     * varies BY TEMPLATE — a features heading is `process_title` on one
     * template, `services_title` on another. So each pair maps to an ORDERED
     * CANDIDATE LIST and we take the first id that actually exists in that
     * site's DOM (updateField reports whether it matched). That degrades
     * correctly across all 31 templates instead of guessing one name.
     */
    private const STATIC_FIELD_MAP = [
        'hero' => [
            'heading'            => ['hero_title'],
            'subheading'         => ['hero_subtitle', 'hero_eyebrow'],
            'eyebrow'            => ['hero_eyebrow'],
            'body'               => ['hero_subtitle'],
            'cta_text'           => ['hero_cta', 'hero_cta_primary'],
            'cta_secondary_text' => ['hero_cta_secondary'],
            'background_image'   => ['hero_image'],
            'image'              => ['hero_image'],
        ],
        'features' => [
            'heading'    => ['features_title', 'services_title', 'process_title', 'why_title', 'why_us_title', 'specialties_title', 'amenities_title', 'programs_title'],
            'subheading' => ['features_intro', 'services_intro', 'process_intro', 'why_intro', 'why_us_intro'],
            'body'       => ['features_intro', 'services_intro', 'process_intro', 'why_intro', 'why_us_intro'],
        ],
        'services' => [
            'heading'    => ['services_title', 'features_title', 'process_title', 'menu_title', 'programs_title', 'specialties_title'],
            'subheading' => ['services_intro', 'features_intro', 'process_intro', 'menu_intro'],
            'body'       => ['services_intro', 'features_intro', 'process_intro', 'menu_intro'],
        ],
        'cta' => [
            'heading'    => ['cta_banner_title'],
            'subheading' => ['cta_banner_subtitle'],
            'body'       => ['cta_banner_subtitle'],
            'cta_text'   => ['cta_banner_cta'],
        ],
        'contact_form' => [
            'heading'      => ['contact_form_title', 'contact_title', 'booking_title'],
            'subheading'   => ['contact_intro', 'booking_intro'],
            'body'         => ['contact_intro', 'booking_intro'],
            'submit_label' => ['form_submit_text', 'booking_submit'],
        ],
        'booking_form' => [
            'heading'      => ['booking_title', 'contact_form_title', 'contact_title'],
            'subheading'   => ['booking_intro', 'contact_intro'],
            'body'         => ['booking_intro', 'contact_intro'],
            'submit_label' => ['booking_submit', 'form_submit_text'],
        ],
        'gallery'      => ['heading' => ['gallery_title'], 'subheading' => ['gallery_intro'], 'body' => ['gallery_intro']],
        'testimonials' => ['heading' => ['testimonials_title'], 'body' => ['testimonials_intro']],
        'team'         => ['heading' => ['team_title', 'staff_title', 'doctors_title', 'trainers_title', 'faculty_title'], 'body' => ['team_intro', 'staff_intro', 'doctors_intro']],
        'faq'          => ['heading' => ['faq_title'], 'body' => ['faq_intro']],
        'pricing'      => ['heading' => ['pricing_title', 'plans_title'], 'body' => ['pricing_intro']],
        'stats'        => ['heading' => ['stats_title'], 'body' => ['stats_intro']],
        'blog_list'    => ['heading' => ['blog_title'], 'subheading' => ['blog_intro'], 'body' => ['blog_intro']],
        'header'       => ['logo_text' => ['business_name', 'logo'], 'cta_text' => ['nav_cta']],
        'footer'       => ['copyright' => ['footer_copyright'], 'body' => ['footer_text', 'footer_about', 'footer_tagline']],
    ];

    /**
     * Mirror applied actions onto the site's served static HTML.
     * No-ops (is_static=false) for renderer-backed sites, which read
     * sections_json directly and were never affected.
     *
     * @return array{is_static:bool, applied:int, missed:array<int,string>}
     */
    /**
     * RISK-0097 — reflect a direct sections save (BuilderService::updatePage) on the
     * static export IN PLACE (template polish preserved). Diffs changed top-level
     * scalar fields between old and new sections into update_text/update_image actions
     * and reuses syncStaticHtml/TemplateService::updateField. Returns
     * {is_static,applied,missed}; is_static=false (no static export) or a non-empty
     * missed[] tells the caller to fall back to a dynamic re-serve for those changes.
     */
    public function syncStaticFromSections(int $websiteId, array $oldSections, array $newSections): array
    {
        $actions = [];
        foreach ($newSections as $idx => $sec) {
            if (! is_array($sec)) {
                continue;
            }
            $old = (is_array($oldSections) && isset($oldSections[$idx]) && is_array($oldSections[$idx]))
                ? $oldSections[$idx] : [];
            foreach ($sec as $field => $val) {
                if (! is_scalar($val) || $val === '') {
                    continue;
                }
                $oldVal = $old[$field] ?? null;
                if (is_scalar($oldVal) && (string) $oldVal === (string) $val) {
                    continue;
                }
                $op = (is_string($field) && stripos($field, 'image') !== false) ? 'update_image' : 'update_text';
                $actions[] = ['op' => $op, 'section_index' => (int) $idx, 'field' => (string) $field, 'value' => $val];
            }
        }
        // Also treat added/removed sections as a structural change the in-place patcher
        // cannot represent -> force a dynamic fallback.
        if (count($newSections) !== count($oldSections)) {
            return ['is_static' => true, 'applied' => 0, 'missed' => ['structural: section count changed']];
        }
        if (empty($actions)) {
            return ['is_static' => false, 'applied' => 0, 'missed' => []];
        }
        return $this->syncStaticHtml($websiteId, $newSections, $actions);
    }

    /**
     * OPEN-1: a prompt hint telling Arthur which colour keys THIS site accepts. Static-template
     * sites expose named CSS custom properties (read from the export's :root block, with current
     * values); renderer/section sites accept the semantic roles.
     */
    private function availableStyleVars(int $websiteId): string
    {
        if ($websiteId <= 0) {
            return '';
        }
        $index = storage_path("app/public/sites/{$websiteId}/index.html");
        if (is_file($index)) {
            $html = (string) @file_get_contents($index);
            $vars = [];
            if (preg_match('/:root\s*\{([^}]*)\}/', $html, $m)
                && preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+)/i', $m[1], $mm, PREG_SET_ORDER)) {
                foreach ($mm as $pair) {
                    $vars[] = trim($pair[1]) . ' (now ' . trim($pair[2]) . ')';
                    if (count($vars) >= 16) {
                        break;
                    }
                }
            }
            if ($vars !== []) {
                return ' Available colour variables for this site: ' . implode(', ', $vars)
                     . '. Use ONLY these variable names as the keys.';
            }
        }
        return ' Use the roles "primary", "secondary" or "accent" as the keys (e.g. {"primary":"#1E5CFF"}).';
    }

    /**
     * OPEN-1 (2026-09-02): change site colours so the visitor actually sees them.
     * Static-template sites (a baked index.html is served in preference to the renderer) are
     * recoloured by rewriting the values in the export's :root custom-property block — every
     * var(--x) reference then follows. Renderer/section sites read colours live from settings_json,
     * so we merge there. Deterministic, hex-validated, reversible (a timestamped .bak per file).
     *
     * @param array<string,mixed> $colors  key => hex; key is a --css-var (static) or a role (renderer)
     * @return array{is_static:bool,applied:int,missed:array<int,string>,files:array<int,string>}
     */
    public function applyStyleColors(int $websiteId, array $colors): array
    {
        $out = ['is_static' => false, 'applied' => 0, 'missed' => [], 'files' => []];
        if ($websiteId <= 0 || $colors === []) {
            return $out;
        }

        // Validate: only #RGB / #RRGGBB survive. Anything else is reported, never written.
        $clean = [];
        foreach ($colors as $key => $val) {
            $k = trim((string) $key);
            $v = trim((string) $val);
            if ($k === '') {
                continue;
            }
            if (! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) {
                $out['missed'][] = "{$k} (\"{$v}\" is not a hex colour)";
                continue;
            }
            $clean[$k] = $v;
        }
        if ($clean === []) {
            return $out;
        }

        $siteRoot = storage_path("app/public/sites/{$websiteId}");

        if (is_file("{$siteRoot}/index.html")) {
            // STATIC: rewrite :root variable values across every exported HTML file.
            $out['is_static'] = true;

            $files = glob("{$siteRoot}/*.html") ?: [];
            foreach ((glob("{$siteRoot}/*/index.html") ?: []) as $nested) {
                $files[] = $nested;
            }
            $files = array_values(array_unique($files));

            $appliedVars = [];
            $stamp = date('YmdHis');
            foreach ($files as $file) {
                $html = @file_get_contents($file);
                if ($html === false || stripos($html, ':root') === false) {
                    continue;
                }
                $orig = $html;
                $html = preg_replace_callback('/:root\s*\{([^}]*)\}/', function (array $m) use ($clean, &$appliedVars) {
                    $body = $m[1];
                    foreach ($clean as $k => $v) {
                        if (strncmp($k, '--', 2) !== 0) {
                            continue; // role keys are for renderer sites, not a baked :root
                        }
                        $n = 0;
                        $body = preg_replace(
                            '/(' . preg_quote($k, '/') . '\s*:\s*)[^;}]+/',
                            '${1}' . $v,
                            $body,
                            -1,
                            $n
                        );
                        if ($n > 0) {
                            $appliedVars[$k] = true;
                        }
                    }
                    return ':root{' . $body . '}';
                }, $html);

                if (is_string($html) && $html !== $orig) {
                    @copy($file, "{$file}.bak-{$stamp}");
                    file_put_contents($file, $html);
                    $out['files'][] = ltrim(str_replace($siteRoot, '', $file), '/\\');
                }
            }

            $out['applied'] = count($appliedVars);
            foreach ($clean as $k => $v) {
                if (strncmp($k, '--', 2) !== 0) {
                    $out['missed'][] = "{$k} (this template needs a --css-variable name, not a role)";
                } elseif (empty($appliedVars[$k])) {
                    $out['missed'][] = "{$k} (no such colour variable in this template)";
                }
            }

            $this->rememberStyleColors($websiteId, $clean);

            return $out;
        }

        // RENDERER/SECTION: BuilderRenderer reads primary/secondary/accent from settings_json live.
        $ws = DB::table('websites')->where('id', $websiteId)->first();
        if (! $ws) {
            return $out;
        }
        $settings = json_decode($ws->settings_json ?? '{}', true);
        if (! is_array($settings)) {
            $settings = [];
        }
        $roleMap = ['primary' => 'primary_color', 'secondary' => 'secondary_color', 'accent' => 'accent_color'];
        foreach ($clean as $k => $v) {
            $role = str_replace('_color', '', strtolower(ltrim($k, '-')));
            if (isset($roleMap[$role])) {
                $settings[$roleMap[$role]] = $v;
                $out['applied']++;
            } else {
                $out['missed'][] = "{$k} (use primary, secondary or accent for this site)";
            }
        }
        if ($out['applied'] > 0) {
            DB::table('websites')->where('id', $websiteId)->update([
                'settings_json' => json_encode($settings),
                'updated_at'    => now(),
            ]);
        }

        return $out;
    }

    /**
     * OPEN-1: mirror a static site's chosen colours into template_variables so a later rebuild
     * (TemplateService::render) starts from them. Presentation store only — never touches
     * sections_json or any workspace/billing state.
     */
    private function rememberStyleColors(int $websiteId, array $vars): void
    {
        try {
            $ws = DB::table('websites')->where('id', $websiteId)->first();
            if (! $ws) {
                return;
            }
            $tv = json_decode($ws->template_variables ?? '{}', true);
            if (! is_array($tv)) {
                $tv = [];
            }
            foreach ($vars as $k => $v) {
                $tv[ltrim($k, '-')] = $v; // bare var name matches the manifest colour keys
            }
            DB::table('websites')->where('id', $websiteId)->update([
                'template_variables' => json_encode($tv),
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ArthurEditService: rememberStyleColors failed', [
                'website_id' => $websiteId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncStaticHtml(int $websiteId, array $sections, array $actions): array
    {
        $out = ['is_static' => false, 'applied' => 0, 'missed' => []];
        if ($websiteId <= 0) {
            return $out;
        }
        if (! file_exists(storage_path("app/public/sites/{$websiteId}/index.html"))) {
            return $out;   // renderer-backed site — sections_json IS the source of truth
        }
        $out['is_static'] = true;

        $templates = app(TemplateService::class);

        foreach ($actions as $action) {
            $op = (string) ($action['op'] ?? '');
            if (! in_array($op, ['update_text', 'update_field', 'update_image'], true)) {
                // Structural ops (add/remove/reorder) cannot be expressed as a
                // data-field patch. Report them rather than silently dropping.
                if ($op !== '') {
                    $out['missed'][] = $op . ' (structural change — not supported on a published template)';
                }
                continue;
            }

            $idx   = isset($action['section_index']) ? (int) $action['section_index'] : null;
            $field = (string) ($action['field'] ?? ($op === 'update_image' ? 'background_image' : ''));
            $value = $action['value'] ?? '';
            if ($idx === null || $field === '' || ! is_scalar($value)) {
                continue;
            }

            $type = (string) ($sections[$idx]['type'] ?? 'generic');
            $done = false;
            foreach ($this->candidateFieldIds($type, $field) as $candidate) {
                try {
                    if ($templates->updateField($websiteId, $candidate, (string) $value)) {
                        $out['applied']++;
                        $done = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::warning('ArthurEditService: static field patch threw', [
                        'website_id' => $websiteId, 'candidate' => $candidate, 'error' => $e->getMessage(),
                    ]);
                }
            }
            if (! $done) {
                $out['missed'][] = "{$type}.{$field}";
                Log::info('ArthurEditService: no data-field slot for edit', [
                    'website_id' => $websiteId, 'type' => $type, 'field' => $field,
                ]);
            }
        }

        return $out;
    }

    /**
     * Ordered data-field candidates for a (section type, field) pair.
     * Explicit map first, then conventional guesses, then the raw field name
     * (templates occasionally use the sections_json name verbatim).
     */
    private function candidateFieldIds(string $type, string $field): array
    {
        $ids = self::STATIC_FIELD_MAP[$type][$field] ?? [];

        $suffix = match ($field) {
            'heading'      => '_title',
            'subheading',
            'body'         => '_intro',
            'cta_text'     => '_cta',
            default        => '_' . $field,
        };
        $ids[] = $type . $suffix;
        $ids[] = $type . '_' . $field;
        $ids[] = $field;

        return array_values(array_unique(array_filter($ids)));
    }

    private function bustPublishedCache(int $pageId): void
    {
        $page = DB::table('pages')->where('id', $pageId)->first();
        if (! $page) return;
        $website = DB::table('websites')->where('id', $page->website_id)->first();
        if (! $website || ! $website->subdomain) return;

        $sub = explode('.', (string) $website->subdomain)[0];
        Cache::forget("published_site:{$sub}");
        Cache::forget("published_site:{$sub}:home");
        Cache::forget("published_site:{$sub}:" . ($page->slug ?? 'home'));
    }
}
