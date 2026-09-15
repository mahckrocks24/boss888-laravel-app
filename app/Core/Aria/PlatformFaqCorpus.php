<?php

namespace App\Core\Aria;

use App\Engines\Builder\Support\BuilderCapabilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ARIA888 (2026-09-15, Owner: "put Aria as an FAQ of the entire platform, no longer an executive assistant").
 *
 * The knowledge Aria answers from. One corpus, three sources, all read-only:
 *   1. resources/knowledge/aria/*.md — curated question/answer entries (## heading = the question, body = the
 *      answer, an optional "Links:" line = marketing pages to cite). Version-controlled with the code.
 *   2. The plans table, read live — prices, credits, sites, seats, chatbot limits — so a price change on the
 *      plans table reaches Aria without a redeploy.
 *   3. BuilderCapabilities::describe() — the live list of what Arthur can add and what it costs.
 *
 * Retrieval is lexical (token overlap weighted by inverse document frequency, title matches count double, a
 * phrase bonus for adjacent query words). The corpus is ~150 entries, so scoring in PHP is a few milliseconds
 * and there is nothing to index, embed or keep in sync.
 */
class PlatformFaqCorpus
{
    private const CACHE_KEY = 'aria:corpus:v1';
    private const CACHE_TTL = 600;

    private const STOP = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'is', 'are', 'it', 'i', 'my', 'me', 'do', 'does', 'can', 'how', 'what', 'where', 'which', 'with', 'for', 'this', 'that', 'be', 'you', 'your', 'we', 'our', 'have', 'has', 'was', 'will', 'there', 'from', 'at', 'as', 'by', 'not', 'if', 'so', 'any', 'get', 'use', 'when', 'why', 'who', 'about', 'into', 'up', 'out', 'am', 'want', 'need', 'please', 'tell', 'know'];

    /** @return array<int, array{id:string, doc:string, title:string, text:string, links:array<int,string>}> */
    public function entries(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && ($cached['sig'] ?? '') === $this->signature()) {
                return $cached['entries'];
            }
        } catch (\Throwable $e) { /* cache is a convenience */ }

        $entries = array_merge($this->fileEntries(), $this->liveEntries());
        try { Cache::put(self::CACHE_KEY, ['sig' => $this->signature(), 'entries' => $entries], self::CACHE_TTL); } catch (\Throwable $e) {}
        return $entries;
    }

    /** Files changed or plans changed → a new signature → the cache is rebuilt. */
    private function signature(): string
    {
        $sig = [];
        foreach ($this->files() as $f) { $sig[] = basename($f) . ':' . (string) @filemtime($f); }
        try { $sig[] = 'plans:' . (string) DB::table('plans')->max('updated_at'); } catch (\Throwable $e) { $sig[] = 'plans:?'; }
        return md5(implode('|', $sig));
    }

    /** @return string[] */
    private function files(): array
    {
        $dir = resource_path('knowledge/aria');
        $files = is_dir($dir) ? glob($dir . '/*.md') : [];
        sort($files);
        return $files ?: [];
    }

    private function fileEntries(): array
    {
        $out = [];
        foreach ($this->files() as $file) {
            $raw = (string) @file_get_contents($file);
            if ($raw === '') continue;
            $doc = '';
            if (preg_match('/^#\s+(.+)$/m', $raw, $m)) $doc = trim($m[1]);
            $parts = preg_split('/^##\s+/m', $raw);
            array_shift($parts); // the preamble before the first question
            foreach ($parts as $i => $part) {
                $lines = preg_split('/\r?\n/', trim($part));
                $title = trim((string) array_shift($lines));
                $links = [];
                $body = [];
                foreach ($lines as $line) {
                    if (preg_match('/^Links:\s*(.+)$/i', trim($line), $lm)) {
                        foreach (preg_split('/\s*,\s*/', trim($lm[1])) as $u) { if ($u !== '') $links[] = $u; }
                        continue;
                    }
                    $body[] = $line;
                }
                $text = trim(implode("\n", $body));
                if ($title === '' || $text === '') continue;
                $out[] = ['id' => basename($file, '.md') . '#' . ($i + 1), 'doc' => $doc, 'title' => $title, 'text' => $text, 'links' => $links];
            }
        }
        return $out;
    }

    private function liveEntries(): array
    {
        $out = [];
        try {
            $plans = DB::table('plans')->where('is_public', 1)->orderBy('price')->get();
            $lines = [];
            foreach ($plans as $p) {
                $f = json_decode((string) $p->features_json, true) ?: [];
                $seats = (int) $p->max_team_members >= 999 ? 'unlimited team members' : ((int) $p->max_team_members . ' team member' . ((int) $p->max_team_members === 1 ? '' : 's'));
                $wf = (int) $p->includes_dmm ? ('Sarah plus ' . (int) $p->agent_count . ' specialists') : 'no AI workforce';
                $bits = [
                    $p->name . ': $' . number_format((float) $p->price, 0) . ' a month',
                    number_format((int) $p->credit_limit) . ' credits a month',
                    (int) $p->max_websites . ' website' . ((int) $p->max_websites === 1 ? '' : 's'),
                    $seats,
                    $wf,
                ];
                $inc = [];
                foreach (['website_builder' => 'website builder', 'custom_domain' => 'custom domain', 'crm' => 'CRM', 'calendar' => 'calendar and booking', 'seo_suite' => 'SEO suite', 'content_writing' => 'content writing', 'image_generation' => 'AI images', 'video_generation' => 'AI video', 'chatbot_included' => 'website chatbot', 'social' => 'social', 'automation' => 'automations', 'meeting_room' => 'Strategy Room', 'hosting_access' => 'hosting access'] as $key => $label) {
                    if (!empty($f[$key])) $inc[] = $label;
                }
                if ($inc) $bits[] = 'includes ' . implode(', ', $inc);
                if (!empty($f['max_tracked_keywords'])) $bits[] = (int) $f['max_tracked_keywords'] . ' tracked keywords';
                if (!empty($f['chatbot_messages_per_month'])) $bits[] = ((int) $f['chatbot_messages_per_month'] >= 999999 ? 'unlimited' : number_format((int) $f['chatbot_messages_per_month'])) . ' chatbot messages a month';
                if (!empty($f['chatbot_kb_max_docs'])) $bits[] = ((int) $f['chatbot_kb_max_docs'] >= 999999 ? 'unlimited' : (int) $f['chatbot_kb_max_docs']) . ' chatbot knowledge documents';
                if ((int) $p->companion_app) $bits[] = 'companion app';
                if ((int) $p->white_label) $bits[] = 'white-label';
                if ((int) $p->priority_processing) $bits[] = 'priority processing';
                if ($p->agent_addon_price !== null && (float) $p->agent_addon_price > 0) $bits[] = 'extra specialist $' . number_format((float) $p->agent_addon_price, 0) . ' a month';
                $lines[] = '- ' . implode('; ', $bits) . '.';
            }
            if ($lines) {
                $out[] = ['id' => 'live#plans', 'doc' => 'Plans, credits and billing', 'title' => 'Plan table: prices, credits, websites, team members, features (live)', 'text' => "The plans as the platform enforces them right now:\n" . implode("\n", $lines), 'links' => ['/pricing/']];
            }
        } catch (\Throwable $e) {
            Log::warning('[aria] plans entry unavailable', ['error' => $e->getMessage()]);
        }
        try {
            $out[] = ['id' => 'live#builder', 'doc' => 'Website and hosting', 'title' => 'What Arthur can add to a website and what each change costs (live)', 'text' => BuilderCapabilities::describe(), 'links' => ['/product/website-builder/']];
        } catch (\Throwable $e) {
            Log::warning('[aria] builder entry unavailable', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Top-N entries for a question, best first. Each carries a `score`. */
    public function retrieve(string $question, int $topN = 6): array
    {
        $q = $this->tokens($question);
        if ($q === []) return [];
        $entries = $this->entries();
        $n = max(1, count($entries));
        $df = [];
        $tok = [];
        foreach ($entries as $i => $e) {
            $t = $this->tokens($e['title'] . ' ' . $e['text']);
            $tok[$i] = ['title' => array_flip($this->tokens($e['title'])), 'all' => array_count_values($t)];
            foreach (array_unique($t) as $w) { $df[$w] = ($df[$w] ?? 0) + 1; }
        }
        $scored = [];
        $qUnique = array_values(array_unique($q));
        foreach ($entries as $i => $e) {
            $s = 0.0;
            $hits = 0;
            foreach ($qUnique as $w) {
                if (!isset($tok[$i]['all'][$w])) continue;
                $hits++;
                $idf = log(1 + $n / (1 + ($df[$w] ?? 0)));
                $s += $idf * (1 + min(3, $tok[$i]['all'][$w]) * 0.15);
                if (isset($tok[$i]['title'][$w])) $s += $idf;
            }
            if ($hits === 0) continue;
            // phrase bonus: adjacent query words that also appear adjacent in the entry text
            $hay = ' ' . implode(' ', $this->tokens($e['title'] . ' ' . $e['text'])) . ' ';
            for ($k = 0; $k + 1 < count($q); $k++) {
                if (strpos($hay, ' ' . $q[$k] . ' ' . $q[$k + 1] . ' ') !== false) $s += 1.2;
            }
            // coverage: entries that answer most of the question win over long entries that mention one word
            $s *= (0.6 + 0.4 * $hits / max(1, count($qUnique)));
            $e['score'] = round($s, 3);
            $scored[] = $e;
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $topN);
    }

    /** @return string[] */
    public function tokens(string $text): array
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[^\p{L}\p{N}\s\$]+/u', ' ', $t) ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($t)) ?: [] as $w) {
            if ($w === '' || mb_strlen($w) < 2) continue;
            if (in_array($w, self::STOP, true)) continue;
            $out[] = $this->stem($w);
        }
        return $out;
    }

    private function stem(string $w): string
    {
        $map = ['websites' => 'website', 'sites' => 'site', 'credits' => 'credit', 'plans' => 'plan', 'images' => 'image', 'videos' => 'video', 'pages' => 'page', 'sections' => 'section', 'leads' => 'lead', 'domains' => 'domain', 'prices' => 'price', 'pricing' => 'price', 'costs' => 'cost', 'costing' => 'cost', 'members' => 'member', 'agents' => 'agent', 'specialists' => 'specialist', 'articles' => 'article', 'posts' => 'post', 'bookings' => 'booking', 'emails' => 'email', 'colours' => 'colour', 'colors' => 'colour', 'color' => 'colour', 'logos' => 'logo', 'edits' => 'edit', 'editing' => 'edit', 'edited' => 'edit', 'building' => 'build', 'builds' => 'build', 'built' => 'build', 'cancelling' => 'cancel', 'canceling' => 'cancel', 'cancellation' => 'cancel', 'refunds' => 'refund', 'refunded' => 'refund', 'upgrades' => 'upgrade', 'upgrading' => 'upgrade', 'exports' => 'export', 'exporting' => 'export', 'publishing' => 'publish', 'published' => 'publish', 'publishes' => 'publish', 'included' => 'include', 'includes' => 'include', 'including' => 'include', 'hosted' => 'host', 'hosting' => 'host', 'generated' => 'generate', 'generating' => 'generate', 'generation' => 'generate', 'connecting' => 'connect', 'connected' => 'connect', 'trials' => 'trial', 'chatbots' => 'chatbot', 'keywords' => 'keyword', 'documents' => 'document', 'messages' => 'message', 'seats' => 'seat', 'teams' => 'team', 'apps' => 'app', 'views' => 'view', 'modes' => 'mode', 'settings' => 'setting', 'passwords' => 'password', 'businesses' => 'business', 'customers' => 'customer', 'clients' => 'client', 'tools' => 'tool', 'features' => 'feature', 'rules' => 'rule', 'automations' => 'automation', 'reminders' => 'reminder', 'invoices' => 'invoice', 'payments' => 'payment', 'photos' => 'photo', 'pictures' => 'picture', 'forms' => 'form', 'listings' => 'listing', 'services' => 'service', 'menus' => 'menu', 'buttons' => 'button', 'elements' => 'element', 'moves' => 'move', 'moving' => 'move', 'moved' => 'move', 'resize' => 'size', 'resizing' => 'size', 'resized' => 'size', 'bigger' => 'size', 'smaller' => 'size', 'aligned' => 'align', 'aligning' => 'align', 'shadows' => 'shadow', 'gradients' => 'gradient', 'fonts' => 'font'];
        if (isset($map[$w])) return $map[$w];
        if (mb_strlen($w) > 5 && str_ends_with($w, 'ies')) return mb_substr($w, 0, -3) . 'y';
        if (mb_strlen($w) > 4 && str_ends_with($w, 'es') && !str_ends_with($w, 'ses')) return mb_substr($w, 0, -2);
        if (mb_strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) return mb_substr($w, 0, -1);
        return $w;
    }

    /** Topic groups for the panel's starter questions: [doc => [titles…]]. */
    public function topics(): array
    {
        $out = [];
        foreach ($this->entries() as $e) {
            if (str_starts_with($e['id'], 'live#')) continue;
            $out[$e['doc']][] = $e['title'];
        }
        return $out;
    }
}
