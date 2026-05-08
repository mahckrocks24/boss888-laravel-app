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
        $sections = json_decode($page->sections_json ?? '[]', true) ?: [];
        $currentJson = json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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

        // 4. Snapshot before.
        $this->snapshots->snapshot($pageId, 'arthur_edit_before');

        // 5. Apply actions atomically.
        $errors  = [];
        $applied = 0;
        $newSections = $sections;

        DB::transaction(function () use ($pageId, &$newSections, $actions, &$errors, &$applied) {
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
                'sections_json' => json_encode($newSections),
                'updated_at'    => now(),
            ]);
        });

        // 6. Snapshot after.
        $this->snapshots->snapshot($pageId, 'arthur_edit_after');

        // 7. Bust published cache.
        $this->bustPublishedCache($pageId);

        return [
            'success'         => true,
            'sections'        => $newSections,
            'reply'           => $reply,
            'actions_applied' => $applied,
            'errors'          => $errors,
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
