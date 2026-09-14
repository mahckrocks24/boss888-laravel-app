<?php

namespace App\Engines\Builder\Services;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ARTHUR, LLM-FIRST (DEC-0050, 2026-09-14). Owner: "it must be asking clarifications and not just throwing message
 * replies or error messages. Arthur must be LLM first … and must understand full context. he is not a robot".
 *
 * Every chat request is read by the model FIRST, with the whole site in front of it (its text fields and their
 * values, its sections and pages, its catalogues and items, its colours, what can be added) and the recent
 * conversation, including a question Arthur asked a moment ago. The model returns ONE intent with the parameters
 * the deterministic executors need — or a clarifying question with concrete options — or a plain answer.
 * The executors stay deterministic (nothing is written by the model directly); the model decides, they act.
 */
class ArthurIntentService
{
    private const TURNS = 8;
    private const TTL = 1800;

    public function __construct(private RuntimeClient $runtime) {}

    private function key(int $wsId, int $websiteId, array $ctx): string
    {
        return 'arthur:conv:' . $wsId . ':' . $websiteId . ':' . (int) ($ctx['user_id'] ?? 0);
    }

    public function history(int $wsId, int $websiteId, array $ctx): array
    {
        $h = Cache::get($this->key($wsId, $websiteId, $ctx));
        return is_array($h) ? $h : ['turns' => [], 'pending' => null];
    }

    /** Keep the last turns and, when Arthur asked something, the question it is waiting on. */
    public function remember(int $wsId, int $websiteId, array $ctx, string $request, array $result, ?array $intent): void
    {
        $h = $this->history($wsId, $websiteId, $ctx);
        $h['turns'][] = ['customer' => mb_substr($request, 0, 400), 'arthur' => mb_substr((string) ($result['message'] ?? ''), 0, 400), 'intent' => (string) ($intent['intent'] ?? ''), 'done' => (bool) ($result['success'] ?? false)];
        $h['turns'] = array_slice($h['turns'], -self::TURNS);
        $h['pending'] = (($result['kind'] ?? '') === 'clarify') ? ['question' => (string) ($result['message'] ?? ''), 'options' => (array) ($result['options'] ?? []), 'about' => (string) ($intent['normalized'] ?? $request)] : null;
        Cache::put($this->key($wsId, $websiteId, $ctx), $h, self::TTL);
    }

    /**
     * @param array $siteCtx  name, industry, design, fields (key => value), images (keys), sections, pages, catalogues,
     *                        colours, addable_pages, addable_sections, abilities
     * @return array|null     the model's decision, or null when the model could not be reached (callers fall back)
     */
    public function interpret(int $wsId, int $websiteId, object $site, string $request, array $ctx, array $siteCtx): ?array
    {
        $h = $this->history($wsId, $websiteId, $ctx);
        $system = "You are Arthur, the website assistant of {$siteCtx['name']}, a {$siteCtx['industry']} business whose website was built with LevelUpGrowth. "
            . "You are talking with the business owner — a person, not a developer. Read their message IN CONTEXT: the whole site is in front of you, and so is your recent conversation with them. "
            . "Decide what they want and return one JSON object, nothing else.\n\n"
            . "PRINCIPLES\n"
            . "- Understand, don't pattern-match: 'change Browse Properties to Check Properties' is a text edit of the button that says Browse Properties; 'mark the bungalow as sold' is a catalogue change; 'make it blue' after you asked about the buttons means the buttons.\n"
            . "- When the target is genuinely ambiguous (two items or two texts fit, the words are not on the page, a colour or a part is missing, a follow-up you cannot tie to anything), ASK one short question with up to 4 concrete options taken from the site — never guess, never invent.\n"
            . "- When it is clear, act. A small typo in the customer's words is not ambiguity: 'Propeties' means 'Properties'.\n"
            . "- Never invent facts: prices, phone numbers, names, dates, outcomes come only from the customer or the site.\n"
            . "- Answer questions about the site from the context (how many listings, what the hero says, what you can do). Be brief, warm and specific. No jargon, no vendor names, no field keys in what you say to the customer.\n"
            . "- If something is not possible here, say plainly what is not and what you can do instead (intent 'unsupported').\n\n"
            . "INTENTS\n"
            . "copy_edit — change text. Fill copy: [{key, value}] using ONLY keys from FIELDS; change every field the request applies to; keep language, tone and length; keep inline <br>/<em>/<strong> when present.\n"
            . "style — colours, palette, gradients, darker/lighter, luxury/minimal moods, fonts, bigger/smaller text, logo colour or visibility. Fill normalized with an explicit sentence (e.g. 'Make the buttons navy', 'Make the hero text bigger', 'Make the hero a gradient from navy to gold').\n"
            . "catalogue — add / price / status / rename / remove / restore / toggle an item of a CATALOGUE. Fill catalogue: {kind, action, item (exact title of an existing item), title, price (number), currency, period, status, note, summary, attrs}. Only what the customer stated. For remove or a status change, when more than one item shares the customer's words, ASK which (list them) — never pick for them. 'restore' brings back an item removed earlier ('undo that', 'put the Craftsman back').\n"
            . "undo — 'undo' of a colour, text or section change is the Undo button in the editor (say so, intent 'answer'); a removed catalogue item is restored with catalogue action 'restore'.\n"
            . "blog — Arthur does not add blog pages by hand: articles the customer publishes from Write appear under /blog/ on the site automatically, with a Blog link in the menu (intent 'answer', say exactly that).\n"
            . "outcomes — when the customer states a sale outcome ('it went for $540,000', 'sold in 5 days, over asking'), put it in catalogue.note as a short phrase ('Sold for $540,000'); never invent one.\n"
            . "section_add / section_remove / page_add — from ADDABLE_SECTIONS / ADDABLE_PAGES; fill normalized ('Add an FAQ section', 'Add a pricing page'). Arthur removes only sections it added; template sections are removed in the editor.\n"
            . "image — generate a new photo (normalized: 'Generate a hero image of …'). video — generate a short video. overlay — write text over the hero/about photo. image_edit — remove the background or an object from a photo. logo — logo visibility/contrast. Fill normalized for each.\n"
            . "answer — the customer asked something; put the answer in reply.\n"
            . "clarify — fill question and options (2–4 short options, each a complete choice the customer can tap).\n"
            . "unsupported — fill reply.\n\n"
            . "OUTPUT (JSON only)\n"
            . '{"intent":"copy_edit|style|catalogue|section_add|section_remove|page_add|image|video|overlay|image_edit|logo|answer|clarify|unsupported","confidence":0.0-1.0,"normalized":"the request as one explicit self-contained sentence","reply":"one or two sentences to the customer (answer/unsupported)","question":"the clarifying question","options":["…"],"copy":[{"key":"…","value":"…"}],"catalogue":{"kind":"…","action":"add|price|status|rename|remove|toggle","item":"…","title":"…","price":0,"currency":"USD","period":"","status":"…","note":"","summary":"","attrs":{}}}';
        $user = $this->contextBlock($siteCtx, $h) . "\nMESSAGE FROM THE CUSTOMER: " . $request;
        $result = $this->runtime->chatJson($system, $user, ['task' => 'arthur_intent', 'workspace_id' => $wsId, 'reasoning_budget' => 2000], 900);
        $parsed = ($result['success'] ?? false) && is_array($result['parsed'] ?? null)
            ? \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($result['parsed']) : null;
        if (! is_array($parsed) || empty($parsed['intent'])) {
            Log::warning('[Arthur] intent: no parseable decision', ['website' => $websiteId, 'error' => $result['error'] ?? null]);
            return null;
        }
        $parsed['intent'] = strtolower(trim((string) $parsed['intent']));
        $parsed['confidence'] = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.8;
        $parsed['options'] = array_values(array_filter(array_map(fn($o) => trim((string) $o), (array) ($parsed['options'] ?? []))));
        Log::info('[Arthur] intent', ['website' => $websiteId, 'intent' => $parsed['intent'], 'confidence' => $parsed['confidence'], 'normalized' => mb_substr((string) ($parsed['normalized'] ?? ''), 0, 160), 'pending' => $h['pending'] !== null]);
        // a low-confidence action becomes a question — with the model's own question when it gave one
        if ($parsed['confidence'] < 0.55 && ! in_array($parsed['intent'], ['clarify', 'answer', 'unsupported'], true)) {
            $parsed['intent'] = 'clarify';
            if (empty($parsed['question'])) { $parsed['question'] = "I want to get this right — what exactly should change on {$siteCtx['name']}?"; }
        }
        return $parsed;
    }

    private function contextBlock(array $c, array $h): string
    {
        $b = "SITE: {$c['name']} — industry {$c['industry']}, design {$c['design']}\n";
        if (! empty($c['pages'])) $b .= "PAGES ON THE SITE: " . implode(', ', $c['pages']) . "\n";
        if (! empty($c['sections'])) $b .= "SECTIONS ON THE HOME PAGE: " . implode(', ', $c['sections']) . "\n";
        if (! empty($c['colours'])) $b .= "COLOURS: " . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($c['colours']), $c['colours'])) . "\n";
        $b .= "FIELDS (key: current text — the editable text of the site):\n";
        foreach ($c['fields'] as $k => $v) $b .= "  $k: " . json_encode(mb_substr((string) $v, 0, 110), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (! empty($c['images'])) $b .= "IMAGE SLOTS: " . implode(', ', $c['images']) . "\n";
        foreach ((array) ($c['catalogues'] ?? []) as $kind => $cat) {
            $b .= "CATALOGUE '{$kind}' ({$cat['label']}, statuses: " . implode('/', $cat['statuses']) . ")" . ($cat['enabled'] ? '' : ' — switched off') . ":\n";
            foreach ($cat['items'] as $it) $b .= "  - {$it}\n";
            if ($cat['items'] === []) $b .= "  (no items yet)\n";
        }
        if (! empty($c['addable_sections'])) $b .= "ADDABLE_SECTIONS: " . implode(', ', $c['addable_sections']) . "\n";
        if (! empty($c['addable_pages'])) $b .= "ADDABLE_PAGES: " . implode(', ', $c['addable_pages']) . "\n";
        if (! empty($c['abilities'])) $b .= "OTHER THINGS ARTHUR CAN DO: " . implode('; ', $c['abilities']) . "\n";
        if (($h['turns'] ?? []) !== []) {
            $b .= "RECENT CONVERSATION (oldest first):\n";
            foreach ($h['turns'] as $t) $b .= "  Customer: {$t['customer']}\n  Arthur: {$t['arthur']}" . ($t['done'] ? '' : ' (nothing changed)') . "\n";
        }
        if (! empty($h['pending'])) {
            $b .= "YOU ARE WAITING FOR AN ANSWER TO YOUR QUESTION: \"{$h['pending']['question']}\" (options: " . implode(' | ', $h['pending']['options']) . ") about: {$h['pending']['about']}. If the message answers it, combine both into one complete intent.\n";
        }
        return $b;
    }
}
