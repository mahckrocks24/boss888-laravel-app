<?php

namespace App\Core\Sarah888;

use App\Core\Orchestration\ToolSchemaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ARTHUR888 F-ARTHUR-B-COORD (2026-09-03): a Sarah->Arthur page edit / page add
 * request was never executed — the chat LLM won't emit builder.edit_page_with_arthur
 * because it needs a page_id from a prior builder.list_builder_pages (a 2-step chain
 * the single-round turn never completes), so it fell back to "I'll do it" + a bare
 * commitment with NO builder task/approval.
 *
 * This promotes a builder edit/add intent DETERMINISTICALLY (mirrors ReadToolPromotion):
 * detect intent -> resolve website -> resolve page_id -> executeToolCall the real builder
 * tool. The tool is review-gated, so it creates an APPROVAL and we reply truthfully
 * ("prepared, approve to apply") — an artifact the owner can act on, never a phantom.
 */
final class BuilderEditPromotion
{
    // An edit to an existing page: an edit verb + a page/section noun (NOT a blog article).
    private const EDIT = '/\b(change|update|edit|rewrite|revise|reword|replace|set|tweak|improve|shorten|lengthen|fix|make)\b[^.!?]*\b(headline|hero|sub-?title|tagline|title|heading|cta|call to action|button|copy|wording|text|section|paragraph|homepage|home page|landing page|about page|services page|contact page|pricing page|the page)\b/i';
    // Adding a new page: add/create + "page".
    private const ADD = '/\b(add|create|build|set up|make)\b[^.!?]*\bpage\b/i';
    // Exclusions — blog/article content is the WRITE engine, not a builder page edit.
    private const NOT_BUILDER = '/\b(blog|article|draft|newsletter|campaign|email|social|post)\b/i';

    /** @return array{type:string,command:string,page_hint:?string,page_template:?string}|null */
    public static function detect(string $message): ?array
    {
        $m = trim($message);
        if ($m === '') return null;
        if (preg_match(self::NOT_BUILDER, $m)) return null;

        if (preg_match(self::ADD, $m)) {
            return ['type' => 'add', 'command' => $m, 'page_hint' => null, 'page_template' => self::matchTemplate($m)];
        }
        if (preg_match(self::EDIT, $m)) {
            return ['type' => 'edit', 'command' => $m, 'page_hint' => self::pageHint($m), 'page_template' => null];
        }
        return null;
    }

    private static function pageHint(string $m): ?string
    {
        $l = mb_strtolower($m);
        foreach ([
            'home page' => 'home', 'homepage' => 'home', 'landing page' => 'home',
            'about page' => 'about', 'services page' => 'services', 'contact page' => 'contact',
            'pricing page' => 'pricing',
        ] as $needle => $slug) {
            if (strpos($l, $needle) !== false) return $slug;
        }
        return 'home'; // default: the homepage
    }

    private static function matchTemplate(string $m): string
    {
        $l = mb_strtolower($m);
        $map = [
            'contact' => 'contact', 'about' => 'about', 'service' => 'services', 'pricing' => 'pricing',
            'faq' => 'faq', 'menu' => 'menu', 'booking' => 'booking', 'book' => 'booking', 'event' => 'events',
            'portfolio' => 'portfolio', 'gallery' => 'portfolio', 'testimonial' => 'about', 'team' => 'about',
            'privacy' => 'legal', 'terms' => 'legal', 'blog' => 'blog', 'home' => 'home',
        ];
        foreach ($map as $needle => $tpl) if (strpos($l, $needle) !== false) return $tpl;
        return 'about';
    }

    /**
     * Resolve website + page and execute the builder tool.
     * @return array{handled:bool,reply:string,executed:bool,ambiguous:bool}
     */
    public static function promote(ToolSchemaService $svc, int $wsId, string $ownerMessage, string $slug, array $intent): array
    {
        // 1. Resolve the target website.
        $siteId = null; $siteName = null;
        try {
            $named = $svc->websiteNamesMentioned($wsId, $ownerMessage);
            if (count($named) === 1) { $siteId = (int) $named[0]['id']; $siteName = (string) $named[0]['name']; }
        } catch (\Throwable) {}
        $sites = DB::table('websites')->where('workspace_id', $wsId)->get(['id', 'name']);
        if ($siteId === null) {
            if ($sites->count() === 1) { $siteId = (int) $sites[0]->id; $siteName = (string) $sites[0]->name; }
            elseif ($sites->count() > 1) {
                $names = $sites->pluck('name')->implode(', ');
                return ['handled' => true, 'executed' => false, 'ambiguous' => true,
                        'reply' => "You have more than one website ({$names}). Which one should I make this change on?"];
            } else {
                return ['handled' => true, 'executed' => false, 'ambiguous' => false,
                        'reply' => "You don't have a website yet to edit — say the word and I'll generate one first."];
            }
        }

        // 2. ADD a page.
        if ($intent['type'] === 'add') {
            $tpl = $intent['page_template'] ?: 'about';
            $r = $svc->executeToolCall('builder.add_page_from_template',
                    ['website_id' => $siteId, 'page_template' => $tpl], $wsId, $slug, ['workspace_id' => $wsId]);
            return self::replyFrom(is_array($r) ? $r : [], "add the {$tpl} page to {$siteName}");
        }

        // 3. EDIT an existing page — resolve page_id.
        $hint = $intent['page_hint'] ?: 'home';
        $pages = DB::table('pages')->where('website_id', $siteId)->get(['id', 'title', 'slug', 'is_homepage']);
        if ($pages->isEmpty()) {
            return ['handled' => true, 'executed' => false, 'ambiguous' => false,
                    'reply' => "{$siteName} has no pages yet — say the word and I'll generate the site first."];
        }
        $page = null;
        if ($hint === 'home') {
            foreach ($pages as $p) if ((int) ($p->is_homepage ?? 0) === 1) { $page = $p; break; }
            if (!$page) foreach ($pages as $p) if (in_array(strtolower((string) $p->slug), ['home', '', 'index'], true)) { $page = $p; break; }
        } else {
            foreach ($pages as $p) if (strtolower((string) $p->slug) === $hint || stripos((string) $p->title, $hint) !== false) { $page = $p; break; }
        }
        if (!$page) $page = $pages->first();

        $r = $svc->executeToolCall('builder.edit_page_with_arthur',
                ['page_id' => (int) $page->id, 'command' => $intent['command']], $wsId, $slug, ['workspace_id' => $wsId]);
        return self::replyFrom(is_array($r) ? $r : [], "update the {$page->title} page on {$siteName}");
    }

    private static function replyFrom(array $r, string $what): array
    {
        $ok = ($r['success'] ?? false) === true;
        $pending = !empty($r['pending_approval']) || ($r['code'] ?? '') === 'AWAITING_APPROVAL';
        if ($ok && $pending) {
            $aid = $r['approval_id'] ?? null;
            $reply = "I've prepared Arthur to {$what}. It's ready for your approval" . ($aid ? " (request #{$aid})" : '')
                   . " — approve it in your review queue and Arthur will apply the change with a before/after snapshot for undo.";
            return ['handled' => true, 'executed' => false, 'ambiguous' => false, 'reply' => $reply];
        }
        if ($ok) {
            return ['handled' => true, 'executed' => true, 'ambiguous' => false,
                    'reply' => "Done — Arthur applied the change: {$what}."];
        }
        $msg = (string) ($r['message'] ?? $r['error'] ?? 'it could not be prepared');
        Log::info('[Arthur888] builder-edit promotion did not execute', ['what' => $what, 'result' => mb_substr(json_encode($r), 0, 200)]);
        return ['handled' => true, 'executed' => false, 'ambiguous' => false,
                'reply' => "I couldn't {$what} right now — {$msg}. Tell me the exact page and change and I'll set it up."];
    }
}
