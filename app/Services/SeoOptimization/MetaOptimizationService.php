<?php

namespace App\Services\SeoOptimization;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MetaOptimizationService
 *
 * Growth+ only. AI-first (RuntimeClient->chatJson). NO deterministic
 * fallback for AI output — free/AI-Lite tiers do not invoke this
 * service at all (they get manual edit only).
 *
 * Architecture: Laravel = brain. RuntimeClient routes the LLM call
 * via the existing chat_json task type. Never calls DeepSeek directly.
 *
 * AI judges per-field whether to replace or keep — single call returns
 * actions[] (one entry per field) with reasoning. Caller (orchestrator)
 * is responsible for applying replace actions via /save-meta and
 * persisting the credit charge.
 *
 * Response shape (success):
 *   {
 *     success: true,
 *     actions: [
 *       {field: 'meta_title',       action: 'replace', value: '...', reasoning: '...'},
 *       {field: 'meta_description', action: 'replace', value: '...', reasoning: '...'},
 *       {field: 'h1',               action: 'keep',                  reasoning: '...'}
 *     ],
 *     primary_keyword: 'luxury resort dubai',
 *     ai_model:        'deepseek-chat',
 *     credits_to_charge: 0.5
 *   }
 *
 * Response shape (failure — no credit charged):
 *   {
 *     success: false,
 *     error:   'ai_unreachable' | 'ai_returned_invalid_json' | 'ai_returned_no_actions',
 *     details: string
 *   }
 */
class MetaOptimizationService
{
    private const CREDIT_PER_PAGE = 0.5;

    private const TITLE_MIN_LEN = 30;
    private const TITLE_MAX_LEN = 60;
    private const DESC_MIN_LEN  = 120;
    private const DESC_MAX_LEN  = 160;
    private const H1_MIN_LEN    = 10;
    private const H1_MAX_LEN    = 200;

    public function __construct(
        private RuntimeClient $runtime,
    ) {}

    public function optimize(int $wsId, int $pageId, string $primaryKeyword, array $alternatives = []): array
    {
        $page = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('id', $pageId)
            ->first(['id', 'url', 'title', 'meta_title', 'meta_description', 'h1', 'intent', 'content_score']);

        if (! $page) {
            return ['success' => false, 'error' => 'page_not_found', 'details' => "page id={$pageId}"];
        }

        if (trim($primaryKeyword) === '') {
            return ['success' => false, 'error' => 'no_focus_keyword', 'details' => 'Focus keyword required'];
        }

        $workspace = DB::table('workspaces')
            ->where('id', $wsId)
            ->first(['business_name', 'industry']);

        $businessName = $workspace?->business_name ?: 'the business';
        $industry     = $workspace?->industry      ?: 'unknown industry';

        $currentTitle = (string) ($page->meta_title ?? $page->title ?? '');
        $currentDesc  = (string) ($page->meta_description ?? '');
        $currentH1    = (string) ($page->h1 ?? '');
        $intent       = (string) ($page->intent ?? 'unknown');

        $altList = empty($alternatives) ? '(none)' : implode(', ', array_slice($alternatives, 0, 4));

        $systemPrompt = <<<'SYS'
You are the SEO specialist on Sarah's marketing team for a multi-tenant marketing platform.
Your job: given a page and its focus keyword, decide which on-page metadata fields need
replacement to better serve the keyword + brand, and which are already optimal.

For each field (meta_title, meta_description, h1), output one entry in the actions array:
  - action: "replace" if the field should change, with a "value" and short "reasoning"
  - action: "keep" if the field is already strong, with a short "reasoning"

REPLACEMENT CONSTRAINTS:
  meta_title:       {$titleMin}-{$titleMax} chars total; focus keyword in the first 30 chars; brand at end
  meta_description: {$descMin}-{$descMax} chars; action-oriented; includes focus keyword + a soft CTA
  h1:               {$h1Min}-{$h1Max} chars; primary keyword phrasing; distinct from title

KEEP CRITERIA (do not replace if):
  - Current value is within the length range AND already contains the focus keyword
  - Current value is clearly stronger than what generic rules would produce

Respond with VALID JSON only. No prose. No markdown fences. Shape:
{
  "actions": [
    {"field": "meta_title", "action": "replace"|"keep", "value": "...", "reasoning": "..."},
    {"field": "meta_description", "action": "replace"|"keep", "value": "...", "reasoning": "..."},
    {"field": "h1", "action": "replace"|"keep", "value": "...", "reasoning": "..."}
  ]
}
For "keep" actions, the value field may be omitted or echo the current value.
SYS;

        $systemPrompt = strtr($systemPrompt, [
            '{$titleMin}' => self::TITLE_MIN_LEN,
            '{$titleMax}' => self::TITLE_MAX_LEN,
            '{$descMin}'  => self::DESC_MIN_LEN,
            '{$descMax}'  => self::DESC_MAX_LEN,
            '{$h1Min}'    => self::H1_MIN_LEN,
            '{$h1Max}'    => self::H1_MAX_LEN,
        ]);

        $userPrompt = "Business: {$businessName}\n"
            . "Industry: {$industry}\n"
            . "Page URL: {$page->url}\n"
            . "Page intent: {$intent}\n"
            . "Focus keyword: {$primaryKeyword}\n"
            . "Supporting keywords: {$altList}\n\n"
            . "Current metadata:\n"
            . "  meta_title       (" . mb_strlen($currentTitle) . " chars): \"{$currentTitle}\"\n"
            . "  meta_description (" . mb_strlen($currentDesc)  . " chars): \"{$currentDesc}\"\n"
            . "  h1               (" . mb_strlen($currentH1)    . " chars): \"{$currentH1}\"\n\n"
            . "Return the actions JSON.";

        // Single AI call via RuntimeClient (chat_json task for structured output)
        $resp = $this->runtime->chatJson($systemPrompt, $userPrompt, [], 800);

        if (empty($resp['success'])) {
            Log::warning('[SEO][meta-opt] runtime call failed', [
                'ws_id' => $wsId, 'page_id' => $pageId,
                'error' => $resp['error'] ?? 'unknown',
            ]);
            return [
                'success' => false,
                'error'   => 'ai_unreachable',
                'details' => (string) ($resp['error'] ?? 'runtime returned non-success'),
            ];
        }

        $parsed = $resp['parsed'] ?? null;
        if (! is_array($parsed)) {
            // chat_json failed to parse — try raw text manual parse as last-ditch
            $raw = trim((string) ($resp['text'] ?? ''));
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $parsed = json_decode((string) $raw, true);
        }

        if (! is_array($parsed) || empty($parsed['actions']) || ! is_array($parsed['actions'])) {
            Log::warning('[SEO][meta-opt] AI returned invalid JSON', [
                'ws_id' => $wsId, 'page_id' => $pageId,
                'raw'   => mb_substr((string) ($resp['text'] ?? ''), 0, 400),
            ]);
            return [
                'success' => false,
                'error'   => 'ai_returned_invalid_json',
                'details' => 'Response did not contain a valid actions[] array',
            ];
        }

        // Validate + sanitize each action
        $validated = [];
        foreach ($parsed['actions'] as $a) {
            if (! is_array($a)) continue;
            $field  = (string) ($a['field'] ?? '');
            $action = (string) ($a['action'] ?? '');
            $value  = isset($a['value']) ? trim((string) $a['value']) : '';
            $reason = isset($a['reasoning']) ? mb_substr((string) $a['reasoning'], 0, 300) : '';

            if (! in_array($field,  ['meta_title', 'meta_description', 'h1'], true)) continue;
            if (! in_array($action, ['replace', 'keep'], true))                       continue;

            if ($action === 'replace') {
                // Strip surrounding quotes / fences
                $value = trim($value, "\"'`");
                $value = preg_replace('/^```[a-z]*\s*/i', '', $value);
                $value = preg_replace('/\s*```$/', '', $value);

                // Length guards — refuse replacement that's wildly out of range
                $len = mb_strlen($value);
                $ok  = true;
                if ($field === 'meta_title'       && ($len < 20  || $len > 80))  $ok = false;
                if ($field === 'meta_description' && ($len < 80  || $len > 200)) $ok = false;
                if ($field === 'h1'               && ($len < 5   || $len > 250)) $ok = false;
                if ($value === '') $ok = false;
                if (! $ok) {
                    // Demote to keep — refuse to write garbage
                    $validated[] = [
                        'field'     => $field,
                        'action'    => 'keep',
                        'reasoning' => 'AI proposed value out of range — refused replacement',
                    ];
                    continue;
                }

                $validated[] = [
                    'field'     => $field,
                    'action'    => 'replace',
                    'value'     => $value,
                    'reasoning' => $reason !== '' ? $reason : 'AI suggested replacement',
                ];
            } else {
                $validated[] = [
                    'field'     => $field,
                    'action'    => 'keep',
                    'reasoning' => $reason !== '' ? $reason : 'Already optimal',
                ];
            }
        }

        if (empty($validated)) {
            return [
                'success' => false,
                'error'   => 'ai_returned_no_actions',
                'details' => 'AI response contained no valid actions after validation',
            ];
        }

        return [
            'success'           => true,
            'actions'           => $validated,
            'primary_keyword'   => $primaryKeyword,
            'ai_model'          => 'runtime/chat_json',
            'credits_to_charge' => self::CREDIT_PER_PAGE,
        ];
    }

    public function creditPerPage(): float
    {
        return self::CREDIT_PER_PAGE;
    }
}
