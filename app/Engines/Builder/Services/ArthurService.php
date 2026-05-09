<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Arthur AI Website Builder — smart extraction + template generation.
 * Single detailed message → builds immediately.
 * Vague message → asks follow-ups conversationally.
 */
class ArthurService
{
    private \App\Connectors\RuntimeClient $runtime;
    private TemplateService $templates;

    // FIX 1 — named color → hex map used by applyBrandColors()
    private const COLOR_MAP = [
        'black'     => '#0A0A0A', 'white'   => '#FFFFFF', 'red'     => '#DC2626',
        'blue'      => '#2563EB', 'navy'    => '#1A2744', 'gold'    => '#C9943A',
        'green'     => '#16A34A', 'purple'  => '#7C3AED', 'violet'  => '#7C3AED',
        'orange'    => '#F97316', 'pink'    => '#EC4899', 'grey'    => '#6B7280',
        'gray'      => '#6B7280', 'brown'   => '#78350F', 'teal'    => '#0D9488',
        'yellow'    => '#EAB308', 'cyan'    => '#06B6D4', 'rose'    => '#E11D48',
        'emerald'   => '#059669', 'indigo'  => '#4F46E5', 'silver'  => '#9CA3AF',
        'cream'     => '#F5F1EC', 'beige'   => '#D4C5A0', 'ivory'   => '#FFFFF0',
        'charcoal'  => '#1F2937', 'maroon'  => '#7F1D1D', 'crimson' => '#B91C1C',
        'bronze'    => '#8B6F3E', 'copper'  => '#B45309', 'mint'    => '#6EE7B7',
        'coral'     => '#FB7185', 'magenta' => '#C026D3', 'turquoise' => '#06B6D4',
        'olive'     => '#65733C', 'lime'    => '#84CC16', 'peach'   => '#FDBA74',
        'sand'      => '#D4C5A0',
    ];

    // FIX 1 — every accent/brand var name known across the 10 templates.
    // applyBrandColors() walks the manifest and sets only vars that exist.
    // Sourced from: beauty/events/fashion/fitness/healthcare/interior_design/
    // legal/real_estate/restaurant/technology manifests (color-typed vars).
    private const ACCENT_VAR_NAMES = [
        // Canonical trio (restaurant + generic)
        'primary_color', 'secondary_color', 'accent_color',
        'primary_light', 'primary_deep',
        'primary', 'accent',
        // Paired accent + deep variants per industry
        'gold', 'gold_deep',
        'rose', 'rose_deep',
        'terracotta', 'terracotta_deep',
        'orange', 'orange_deep',
        'bronze', 'bronze_deep',
        'brass',  'brass_deep',
        'forest', 'forest_deep',
        'medical_blue', 'medical_deep',
        'cyan', 'violet',
        'sage', 'sage_soft',
        'navy', 'ember', 'volt',
    ];

    // FIX 4 — industry keyword deny-list. If a generated service title
    // contains a keyword from a DIFFERENT industry, we fall back to the
    // manifest default. Keys are the site's industry.
    private const INDUSTRY_DENY = [
        'fitness'      => ['dining','restaurant','cuisine','menu','chef','bakery','tasting','wedding','venue','clinic','attorney','law firm','property','listing','couture','atelier','saas','api','endpoint'],
        'restaurant'   => ['workout','fitness','gym','hiit','yoga','pilates','strength training','clinic','attorney','property','listing','couture','saas','api','endpoint'],
        'healthcare'   => ['dining','cuisine','menu','workout','hiit','yoga','couture','atelier','property','listing','saas','api','endpoint'],
        'legal'        => ['dining','cuisine','menu','workout','hiit','yoga','clinic','salon','couture','saas','api'],
        'beauty'       => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','property','saas','api','endpoint'],
        'real_estate'  => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'interior_design' => ['dining','cuisine','menu','workout','hiit','clinic','attorney','property listing','saas','api'],
        'fashion'      => ['dining','cuisine','menu','workout','hiit','clinic','attorney','property listing','saas','api'],
        'technology'   => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','atelier'],
        'events'       => ['dining menu','cuisine','workout','hiit','yoga','clinic','attorney','saas','api'],
    ];

    // BUG 2 FIX — keyword → canonical industry map. Order of keys matters:
    // applyIndustryMap() iterates by descending key length so longer phrases
    // ("digital marketing") match before shorter substrings ("digital").
    private const INDUSTRY_MAP = [
        // Marketing agency (new industry) — digital marketing agencies are NOT SaaS
        'digital marketing'        => 'marketing_agency',
        'marketing agency'         => 'marketing_agency',
        'seo agency'               => 'marketing_agency',
        'seo'                      => 'marketing_agency',
        'social media agency'      => 'marketing_agency',
        'social media'             => 'marketing_agency',
        'advertising agency'       => 'marketing_agency',
        'advertising'              => 'marketing_agency',
        'ppc'                      => 'marketing_agency',
        'paid ads'                 => 'marketing_agency',
        'growth agency'            => 'marketing_agency',
        'media buying'             => 'marketing_agency',
        'web design'               => 'marketing_agency',
        // Technology (SaaS / dev tools / software only)
        'web development'          => 'technology',
        'it company'               => 'technology',
        'it services'              => 'technology',
        'software'                 => 'technology',
        'app development'          => 'technology',
        'app'                      => 'technology',
        'saas'                     => 'technology',
        'tech'                     => 'technology',
        'digital'                  => 'technology',
        // Education (new)
        'education'                => 'education',
        'school'                   => 'education',
        'university'               => 'education',
        'college'                  => 'education',
        'institute'                => 'education',
        'academy'                  => 'education',
        'tutoring'                 => 'education',
        'training center'          => 'education',
        'online course'            => 'education',
        // Automotive (new)
        'automotive'               => 'automotive',
        'car dealership'           => 'automotive',
        'auto'                     => 'automotive',
        'vehicle'                  => 'automotive',
        'garage'                   => 'automotive',
        'car service'              => 'automotive',
        'auto repair'              => 'automotive',
        'car rental'               => 'automotive',
        'detailing'                => 'automotive',
        // Hospitality (new)
        'hotel'                    => 'hospitality',
        'hospitality'              => 'hospitality',
        'resort'                   => 'hospitality',
        'serviced apartment'       => 'hospitality',
        'guesthouse'               => 'hospitality',
        'boutique hotel'           => 'hospitality',
        'vacation rental'          => 'hospitality',
        // Cafe (new — split from restaurant)
        'cafe'                     => 'cafe',
        'coffee shop'              => 'cafe',
        'bakery'                   => 'cafe',
        'juice bar'                => 'cafe',
        'patisserie'               => 'cafe',
        'tea house'                => 'cafe',
        'brunch'                   => 'cafe',
        'breakfast'                => 'cafe',
        'dessert shop'             => 'cafe',
        // Cleaning (new)
        'cleaning services'        => 'cleaning',
        'cleaning'                 => 'cleaning',
        'maid service'             => 'cleaning',
        'housekeeping'             => 'cleaning',
        'office cleaning'          => 'cleaning',
        'deep cleaning'            => 'cleaning',
        'move-out cleaning'        => 'cleaning',
        'post construction cleaning' => 'cleaning',
        'sanitization'             => 'cleaning',
        'disinfection'             => 'cleaning',
        // Construction (new)
        'construction'             => 'construction',
        'contractor'               => 'construction',
        'builder'                  => 'construction',
        'civil engineering'        => 'construction',
        'mep'                      => 'construction',
        'fit-out'                  => 'construction',
        'renovation'               => 'construction',
        'infrastructure'           => 'construction',
        'building contractor'      => 'construction',
        'joinery'                  => 'construction',
        // Photography (new)
        'photography'              => 'photography',
        'photographer'             => 'photography',
        'photo studio'             => 'photography',
        'videography'              => 'photography',
        'videographer'             => 'photography',
        'wedding photographer'     => 'photography',
        'portrait studio'          => 'photography',
        'commercial photography'   => 'photography',
        // Childcare (new)
        'childcare'                => 'childcare',
        'nursery'                  => 'childcare',
        'daycare'                  => 'childcare',
        'kindergarten'             => 'childcare',
        'preschool'                => 'childcare',
        'early childhood'          => 'childcare',
        'kids activities'          => 'childcare',
        'after school'             => 'childcare',
        'child development center' => 'childcare',
        // Consulting (new — split from legal)
        'management consulting'    => 'consulting',
        'business consulting'      => 'consulting',
        'hr consulting'            => 'consulting',
        'financial consulting'     => 'consulting',
        'consultancy'              => 'consulting',
        'consulting'               => 'consulting',
        'strategy'                 => 'consulting',
        'advisory'                 => 'consulting',
        // Finance (new)
        'finance'                  => 'finance',
        'accounting'               => 'finance',
        'accountant'               => 'finance',
        'financial advisor'        => 'finance',
        'tax'                      => 'finance',
        'audit'                    => 'finance',
        'bookkeeping'              => 'finance',
        'payroll'                  => 'finance',
        'vat'                      => 'finance',
        'cfo services'             => 'finance',
        'wealth management'        => 'finance',
        // Wellness (new)
        'wellness'                 => 'wellness',
        'nutrition'                => 'wellness',
        'nutritionist'             => 'wellness',
        'life coach'               => 'wellness',
        'mental health'            => 'wellness',
        'therapist'                => 'wellness',
        'meditation'               => 'wellness',
        'holistic'                 => 'wellness',
        'naturopath'               => 'wellness',
        'health coach'             => 'wellness',
        'mindfulness'              => 'wellness',
        // Pet services (new)
        'pet'                      => 'pet_services',
        'veterinary'               => 'pet_services',
        'vet clinic'               => 'pet_services',
        'pet grooming'             => 'pet_services',
        'pet boarding'             => 'pet_services',
        'pet training'             => 'pet_services',
        'dog grooming'             => 'pet_services',
        'cat clinic'               => 'pet_services',
        'pet shop'                 => 'pet_services',
        'animal hospital'          => 'pet_services',
        'pet daycare'              => 'pet_services',
        // Logistics (new)
        'logistics'                => 'logistics',
        'freight'                  => 'logistics',
        'shipping'                 => 'logistics',
        'courier'                  => 'logistics',
        'delivery'                 => 'logistics',
        'warehousing'              => 'logistics',
        'moving company'           => 'logistics',
        'relocation'               => 'logistics',
        'cargo'                    => 'logistics',
        'supply chain'             => 'logistics',
        'last mile'                => 'logistics',
        'movers'                   => 'logistics',
        // Architecture (new)
        'architecture'             => 'architecture',
        'architect'                => 'architecture',
        'urban planning'           => 'architecture',
        'landscape architecture'   => 'architecture',
        'structural engineering'   => 'architecture',
        'masterplan'               => 'architecture',
        'urban design'             => 'architecture',
        'building design'          => 'architecture',
        // Real estate broker — personal brand (not a company site) — must
        // beat 'real estate' / 'property' which go to the company-site
        // 'real_estate' template. Keys ordered so the more-specific phrase
        // matches first via normalizeIndustry()'s longest-key-wins rule.
        'real estate broker'       => 'real_estate_broker',
        'property broker'          => 'real_estate_broker',
        'real estate agent'        => 'real_estate_broker',
        'property agent'           => 'real_estate_broker',
        'property consultant'      => 'real_estate_broker',
        'realtor'                  => 'real_estate_broker',
        'broker'                   => 'real_estate_broker',
        // Legacy / existing industries
        'law firm'                 => 'legal',
        'lawyer'                   => 'legal',
        'attorney'                 => 'legal',
        'clinic'                   => 'healthcare',
        'hospital'                 => 'healthcare',
        'doctor'                   => 'healthcare',
        'dental'                   => 'healthcare',
        'gym'                      => 'fitness',
        'yoga'                     => 'fitness',
        'pilates'                  => 'fitness',
        'personal trainer'         => 'fitness',
        'salon'                    => 'beauty',
        'spa'                      => 'beauty',
        'barbershop'               => 'beauty',
        'restaurant'               => 'restaurant',
        'bistro'                   => 'restaurant',
        'food'                     => 'restaurant',
        'interior'                 => 'interior_design',
        'real estate'              => 'real_estate',
        'property'                 => 'real_estate',
        'fashion'                  => 'fashion',
        'clothing'                 => 'fashion',
        'boutique'                 => 'fashion',
        'wedding'                  => 'events',
        'events'                   => 'events',
    ];

    // Canonical industry slugs the template system understands.
    private const VALID_INDUSTRIES = [
        // Original 10
        'restaurant','interior_design','fitness','healthcare','legal',
        'real_estate','fashion','technology','events','beauty',
        // Added 2026-04-19
        'marketing_agency','education','automotive','hospitality','cafe',
        'cleaning','construction','photography','childcare','consulting',
        'finance','wellness','pet_services','logistics','architecture',
        'real_estate_broker',
    ];

    public function __construct()
    {
        $this->runtime = app(\App\Connectors\RuntimeClient::class);
        $this->templates = new TemplateService();
    }

    // FIX 1 — normalise a color token (name or hex) to a 6-digit hex string.
    private function normalizeHex(?string $val): ?string
    {
        if (!$val) return null;
        $val = strtolower(trim($val));
        if (isset(self::COLOR_MAP[$val])) return self::COLOR_MAP[$val];
        if (preg_match('/^#([0-9a-f]{3})$/', $val, $m)) {
            $c = $m[1];
            return '#' . $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $val)) return strtoupper($val);
        return null;
    }

    // FIX 1 — darken a hex colour by a percentage (0–100).
    private function darkenHex(string $hex, int $pct): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#' . $hex;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $factor = max(0, min(100, 100 - $pct)) / 100;
        $r = max(0, (int) round($r * $factor));
        $g = max(0, (int) round($g * $factor));
        $b = max(0, (int) round($b * $factor));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    // FIX 1 — apply the user's brand colors across the manifest's known
    // accent variables. Rule:
    //   - primary_color / primary → user's primary
    //   - every other industry-specific accent name → user's accent
    //     (accent defaults to secondary → primary if secondary not given)
    //   - *_deep / *_dark variants → darkened shade of whatever the base
    //     would be for that name
    // Soft/muted variants are left alone to avoid inverted tonal ranges.
    private function applyBrandColors(array &$variables, array $manifest, ?array $colors): void
    {
        if (empty($colors) || !is_array($colors)) return;
        $primary   = $this->normalizeHex($colors['primary']   ?? null);
        $secondary = $this->normalizeHex($colors['secondary'] ?? null);
        $accent    = $this->normalizeHex($colors['accent']    ?? null) ?? $secondary ?? $primary;
        if (!$primary && !$secondary && !$accent) return;
        $primaryDeep = $primary ? $this->darkenHex($primary, 12) : null;
        $accentDeep  = $accent  ? $this->darkenHex($accent,  12) : null;

        $manifestVars = $manifest['variables'] ?? [];
        foreach (self::ACCENT_VAR_NAMES as $name) {
            if (!array_key_exists($name, $manifestVars)) continue;

            $isDeep = (str_contains($name, '_deep') || str_contains($name, '-deep') || str_contains($name, '_dark'));
            $isSoft = (str_contains($name, '_soft') || str_contains($name, '_light') || str_contains($name, '_muted'));
            if ($isSoft) continue; // leave soft/muted tints untouched

            if (in_array($name, ['primary_color','primary'], true)) {
                $variables[$name] = $isDeep ? ($primaryDeep ?? $primary) : $primary;
            } else {
                // Every industry-specific accent var (gold, rose, orange,
                // terracotta, cyan, bronze, brass, forest, medical_blue,
                // violet, sage, etc.) takes the user's accent color.
                $variables[$name] = $isDeep ? ($accentDeep ?? $accent) : $accent;
            }
        }
        // Ensure the canonical trio is explicitly set for downstream CSS
        // regardless of whether the template's manifest declares them.
        if ($primary)       $variables['primary_color']   = $primary;
        if ($secondary)     $variables['secondary_color'] = $secondary;
        if ($accent)        $variables['accent_color']    = $accent;
        if ($primaryDeep)   $variables['primary_deep']    = $primaryDeep;
    }

    // FIX 1 — server-side color scanner (mirrors _arthurExtractColors in JS).
    // Returns partial ['primary' => ..., 'secondary' => ..., 'accent' => ...].
    private function scanColorsServerSide(string $msg): array
    {
        if ($msg === '') return [];
        $words = implode('|', array_keys(self::COLOR_MAP));
        $out   = [];

        // Role-explicit phrases first
        if (preg_match_all('/(?:use|make|set)\s+(#(?:[0-9a-f]{3}|[0-9a-f]{6})|[a-z]+)\s+(?:as|for)(?:\s+the)?\s+(primary|main|secondary|accent|brand)/i', $msg, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $v = strtolower($m[1]);
                $r = strtolower($m[2]);
                if ($r === 'main' || $r === 'brand') $r = 'primary';
                if (isset(self::COLOR_MAP[$v]) || preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/', $v)) {
                    $out[$r] = $v;
                }
            }
        }

        // Positional flow: hex codes + remaining named colors into unfilled slots
        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $msg, $hex);
        preg_match_all('/\b(' . $words . ')\b/i', $msg, $named);
        $pool = array_merge(array_map('strtolower', $hex[0] ?? []), array_map('strtolower', $named[1] ?? []));
        $slots = ['primary','secondary','accent'];
        foreach ($pool as $c) {
            foreach ($slots as $role) {
                if (empty($out[$role])) { $out[$role] = $c; break; }
            }
        }
        return $out;
    }

    // FIX 4 — returns true if $text contains a keyword from a different
    // industry's deny-list for $industry.
    private function isCrossIndustryLeak(string $text, string $industry): bool
    {
        $deny = self::INDUSTRY_DENY[$industry] ?? [];
        if (empty($deny)) return false;
        $lower = strtolower($text);
        foreach ($deny as $kw) {
            if (strpos($lower, $kw) !== false) return true;
        }
        return false;
    }

    // BUG 2 FIX — normalize an industry string to a canonical slug.
    // Preference order: explicit keyword from INDUSTRY_MAP (longest match
    // wins) > already-valid slug > raw input.
    private function normalizeIndustry(?string $raw, string $message): ?string
    {
        $haystack = strtolower(trim(($raw ?? '') . ' ' . $message));
        if ($haystack === '') return $raw;
        $keys = array_keys(self::INDUSTRY_MAP);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (strpos($haystack, $kw) !== false) {
                return self::INDUSTRY_MAP[$kw];
            }
        }
        if ($raw && in_array(strtolower($raw), self::VALID_INDUSTRIES, true)) {
            return strtolower($raw);
        }
        return $raw;
    }

    // BUG 1 FIX — build the all-fields confirmation bubble shown once the
    // first rich message extraction completes. Only includes non-empty
    // fields, so a user who didn't mention e.g. pages won't see an empty
    // "📄 Pages:" line.
    private function buildConfirmationMessage(array $s): string
    {
        $name = $s['business_name'] ?? 'your business';
        $lines = ["Got it! Here's what I have:\n"];
        $lines[] = "🏢 Business: " . $name;

        if (!empty($s['colors']) && is_array($s['colors'])) {
            $cp = $s['colors']['primary']   ?? null;
            $cs = $s['colors']['secondary'] ?? null;
            $cc = $s['colors']['accent']    ?? null;
            $clist = array_values(array_filter([$cp, $cs, $cc]));
            if ($clist) $lines[] = "🎨 Colors: " . implode(' + ', $clist);
        }
        if (!empty($s['location']))      $lines[] = "📍 Location: " . $s['location'];
        if (!empty($s['services'])) {
            $svc = is_array($s['services']) ? implode(', ', $s['services']) : (string)$s['services'];
            $lines[] = "🛠 Services: " . $svc;
        }
        if (!empty($s['target_market'])) $lines[] = "👥 Target: " . $s['target_market'];
        if (!empty($s['pages'])) {
            $pgs = is_array($s['pages'])
                ? implode(', ', array_map(fn($p) => ucfirst(strtolower((string)$p)), $s['pages']))
                : (string)$s['pages'];
            $lines[] = "📄 Pages: " . $pgs;
        }
        $lines[] = "\nDoes this look right?";
        return implode("\n", $lines);
    }

    /**
     * NEW conversational entry point — pure LLM, no regex, no scripted steps.
     *
     * PATCH (Arthur AI conversation, 2026-05-09): the spec called for replacing
     * the entire scripted-wizard flow (handleMessage + extractAllFields +
     * extractFields + simpleExtract + getNextQuestion + scanColorsServerSide
     * + isCrossIndustryLeak + ...) with a single LLM-driven dialogue.
     *
     * Arthur talks like Sarah — natural, contextual, intelligent. He
     * decides when he has enough info to build the website and signals
     * via a [READY_TO_BUILD] marker followed by JSON.
     *
     * Returns:
     *   [
     *     'type'           => 'question' | 'complete',
     *     'reply'          => string,                  // user-visible text
     *     'ready_to_build' => bool,
     *     'build_data'     => array,                   // when ready_to_build
     *     'history'        => array,                   // updated for next turn
     *   ]
     *
     * The route handler (POST /api/builder/arthur/message) is responsible for
     * calling buildFromChat() if ready_to_build is true. This keeps chat()
     * pure (no DB writes, no template work) so it can be unit-tested as a
     * conversation function.
     *
     * handleMessage() above remains for callers that want the older scripted
     * flow with template picker + image upload + color extraction. The
     * route /api/builder/arthur/message now uses chat() exclusively;
     * handleMessage is reachable via direct service calls only.
     */
    public function chat(int $workspaceId, string $userMessage, array $history = []): array
    {
        $systemPrompt = <<<'PROMPT'
You are Arthur, an expert AI website builder for LevelUp Growth.
You have a natural, warm conversation to understand a business
then build them a professional website.

You are like a skilled web designer on a discovery call.
NOT a form. NOT a robot. A real conversation.

Through natural dialogue, learn:
- Business name
- What they do / industry
- Location / who they serve
- Main services or products
- Style preference (modern, luxury, minimal, classic)

Rules:
- Ask 1-2 questions at a time maximum
- React naturally to what they say
- If they give multiple details at once, acknowledge all
- Keep responses to 2-3 sentences max
- Be warm, encouraging, professional
- Never repeat a question you already got an answer to
- Use "I'll" not "I can"; never hedge ("maybe", "I think")

When you have enough to build (minimum: name + industry + location),
return ONLY a JSON object with these fields:
  {"reply": "<your short conversational message>",
   "ready_to_build": true,
   "build_data": {"business_name":"...","industry":"...","location":"...","services":"...","style":"modern","description":"..."}}

Until you have enough, return a JSON object with:
  {"reply": "<your short conversational message>",
   "ready_to_build": false}

Always respond with valid JSON only — no prose outside the JSON object.
PROMPT;

        // PATCH (Arthur fix 2026-05-09) — speed: trim history to last 10 turns
        // (5 user + 5 arthur). Prevents prompt bloat after long conversations
        // and keeps Arthur as snappy as Sarah / the LevelUp Assistant.
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }

        // Build a conversation transcript and pass as the user prompt body
        // (chatJson takes a single user prompt; conversation history is
        // folded in as the prompt context). The runtime's chat_json task
        // uses response_format: json_object so the model returns parsed JSON.
        $transcript = '';
        foreach ($history as $msg) {
            $role = (($msg['role'] ?? '') === 'arthur') ? 'Arthur' : 'User';
            $content = (string) ($msg['content'] ?? '');
            if ($content !== '') $transcript .= "{$role}: {$content}\n";
        }
        $transcript .= "User: {$userMessage}";

        $reply         = '';
        $readyToBuild  = false;
        $buildData     = [];

        try {
            $result = $this->runtime->chatJson($systemPrompt, $transcript, [
                'workspace_id' => $workspaceId,
                'task'         => 'arthur_chat',
            ], 800);

            // PATCH (Arthur fix 2026-05-09) — defensive widened reply extraction.
            // The runtime's chat_json sometimes returns the reply under different
            // keys depending on whether DeepSeek's response_format=json_object
            // succeeded in parsing or fell back to raw text. Cover all known
            // shapes so we never show an empty bubble.
            $parsed = is_array($result['parsed'] ?? null) ? $result['parsed'] : [];
            $reply  = (string) (
                $parsed['reply']
                ?? $parsed['response']
                ?? $parsed['message']
                ?? $parsed['content']
                ?? $result['reply']
                ?? $result['response']
                ?? $result['content']
                ?? $result['text']
                ?? ''
            );
            $readyToBuild = (bool) ($parsed['ready_to_build'] ?? $result['ready_to_build'] ?? false);
            $buildDataRaw = $parsed['build_data'] ?? $result['build_data'] ?? null;
            $buildData    = is_array($buildDataRaw) ? $buildDataRaw : [];
            if ($readyToBuild && empty($buildData['business_name'])) {
                // protect against the model claiming ready without payload
                $readyToBuild = false;
            }

            // PATCH (Arthur Arabic fix 2026-05-09) — sanitize the reply through
            // mb_convert_encoding so any invalid UTF-8 sequences (rare, but
            // possible when the runtime serializes mixed-script content) get
            // dropped rather than triggering a broken JSON response on the
            // way back to the browser. UTF-8 → UTF-8 is the canonical
            // strip-invalid-bytes idiom in PHP. Verified end-to-end with
            // Arabic ("هل يمكنك التحدث باللغة العربية؟") returning 94-char
            // valid UTF-8 reply.
            if (function_exists('mb_convert_encoding')) {
                $reply = mb_convert_encoding($reply, 'UTF-8', 'UTF-8');
            }
            // Belt-and-suspenders: if reply is still empty, try the runtime's
            // raw text payload as a last resort before falling through to the
            // hiccup message.
            if ($reply === '' || $reply === null) {
                $rawText = $result['text'] ?? $result['raw'] ?? '';
                if (is_string($rawText) && $rawText !== '') {
                    $reply = $rawText;
                }
            }

            // PATCH (Arthur fix 2026-05-09) — temporary debug log so we can
            // see exactly what shape the runtime returned in logs when a
            // user reports an empty bubble. Drop this log line once the
            // empty-response issue is confirmed eliminated.
            if ($reply === '') {
                Log::warning('[Arthur:chat] reply extraction came up empty', [
                    'workspace_id' => $workspaceId,
                    'success'      => $result['success'] ?? null,
                    'error'        => $result['error'] ?? null,
                    'parsed_keys'  => array_keys($parsed),
                    'raw_keys'     => array_keys($result),
                    'parsed_dump'  => json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'text_len'     => isset($result['text']) ? strlen((string) $result['text']) : null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur:chat] runtime call threw: ' . $e->getMessage(), [
                'workspace_id' => $workspaceId,
            ]);
        }

        if ($reply === '') {
            $reply = "I'm having a brief connection hiccup — could you say that again?";
            $readyToBuild = false;
            $buildData = [];
        }

        $newHistory = array_merge($history, [
            ['role' => 'user',   'content' => $userMessage],
            ['role' => 'arthur', 'content' => $reply],
        ]);

        return [
            'type'           => $readyToBuild ? 'complete' : 'question',
            'reply'          => $reply,
            'ready_to_build' => $readyToBuild,
            'build_data'     => $buildData,
            'history'        => $newHistory,
        ];
    }

    /**
     * Public wrapper around the existing private generateWebsite() so the
     * /arthur/message route can trigger website creation when chat() returns
     * ready_to_build=true. Maps build_data → generateWebsite($wsId, $data).
     *
     * Returns whatever generateWebsite returns: typically
     *   ['type' => 'website_created'|'error', 'website_id' => ?, 'website_url' => ?, 'message' => ?]
     */
    public function buildFromChat(int $workspaceId, array $buildData): array
    {
        return $this->generateWebsite($workspaceId, $buildData);
    }

    /**
     * Handle a message from the user in the Arthur chat.
     */
    public function handleMessage(int $wsId, string $message, array $history = [], array $state = [], ?array $colorsFromPayload = null): array
    {
        // FIX 1 — merge colors from the payload (client extracts, server trusts)
        // and also scan this message server-side as a safety net in case the
        // client failed to extract.
        if (is_array($colorsFromPayload)) {
            $state['colors'] = array_merge($state['colors'] ?? [], array_filter($colorsFromPayload, fn($v) => $v !== null && $v !== ''));
        }
        $serverScan = $this->scanColorsServerSide($message);
        if (!empty($serverScan)) {
            $state['colors'] = $state['colors'] ?? [];
            foreach (['primary','secondary','accent'] as $role) {
                if (empty($state['colors'][$role]) && !empty($serverScan[$role])) {
                    $state['colors'][$role] = $serverScan[$role];
                }
            }
        }

        // Step 1: Extract business info from message + history.
        // When the client has already progressed past extraction (template
        // picked, or images decided), the state is authoritative — skip the
        // LLM round-trip and trust what the UI sent. Otherwise extraction
        // can flip ready=true back to ready=false and stall the flow.
        $inConfirmationFlow = !empty($state['template_confirmed']) || !empty($state['images_done']);
        if ($inConfirmationFlow) {
            $extracted = $state;
            // Recompute ready locally so stale state doesn't misfire.
            $extracted['ready'] = !empty($extracted['business_name']) && !empty($extracted['industry']);
        } else {
            // BUG 1 FIX — rich multi-field extraction in a single LLM call.
            // Pulls business_name, industry, services, location, target_market,
            // colors, pages all at once so Arthur never drops context from a
            // detailed first message.
            $extracted = $this->extractAllFields($message, $history, $state);
            // extractAllFields() may overwrite colors — restore from $state.
            if (!empty($state['colors'])) $extracted['colors'] = $state['colors'];
        }

        // Step 2: Check if ready to generate
        // FIX 5 (2026-04-20) — expanded required set so Arthur collects a
        // complete brief before rendering templates. `services` in the
        // extractor is the canonical field for "core_service(s)".
        $required = ['business_name', 'industry', 'services', 'location'];
        $missing = [];
        foreach ($required as $f) {
            $val = $extracted[$f] ?? null;
            $empty = ($val === null || $val === '' || (is_array($val) && count($val) === 0));
            if ($empty) $missing[] = $f;
        }
        // Back-compat: legacy callers expect 'core_service' alias for 'services'.
        $missingForClient = array_map(fn($f) => $f === 'services' ? 'core_service' : $f, $missing);

        $isReady = empty($missing) && ($extracted['ready'] ?? false);

        if ($isReady) {
            // BUG 1 FIX — before showing the template gallery, surface a
            // single "here is everything I pulled out of your message"
            // bubble so the user can confirm or correct details in one
            // shot. Only fires once: state.details_confirmed gates it.
            if (empty($extracted['details_confirmed']) && empty($extracted['template_confirmed'])) {
                return [
                    'type'     => 'confirm_details',
                    'message'  => $this->buildConfirmationMessage($extracted),
                    'state'    => $extracted,
                    'progress' => $this->getProgress($extracted),
                    'details'  => [
                        'business_name' => $extracted['business_name'] ?? '',
                        'industry'      => $extracted['industry']      ?? '',
                        'colors'        => $extracted['colors']        ?? [],
                        'location'      => $extracted['location']      ?? '',
                        'services'      => $extracted['services']      ?? [],
                        'target_market' => $extracted['target_market'] ?? '',
                        'pages'         => $extracted['pages']         ?? [],
                    ],
                ];
            }

            // 2026-04-21 — Silent template resolution.
            // Previous behaviour: showed a template_slider (for ambiguous
            // industries) or template_pick (single card) and waited for the
            // user to click "Use Template". That UX has been removed — Arthur
            // now silently resolves the best template from conversation
            // keywords and continues collecting remaining required fields.
            if (empty($extracted['template_confirmed']) && !empty($extracted['industry'])) {
                $industry = strtolower((string) $extracted['industry']);

                // Build haystack of everything the user has said so we can
                // look for secondary qualifying keywords.
                $haystack = ' ' . mb_strtolower((string) $message);
                foreach ((array) $history as $h) {
                    if (is_array($h) && isset($h['content'])) {
                        $haystack .= ' ' . mb_strtolower((string) $h['content']);
                    } elseif (is_string($h)) {
                        $haystack .= ' ' . mb_strtolower($h);
                    }
                }

                // Map each possible starting industry to a disambiguation
                // rule set. Each rule is [candidate, [keywords]]. First
                // match wins. A rule with [] keywords means "default if no
                // other rule matched".
                $rules = [
                    // "real estate" + broker/agent/personal → real_estate_broker
                    //              + agency/company/listings/properties → real_estate
                    //              (no qualifier) → real_estate
                    'real_estate' => [
                        ['real_estate_broker', ['broker', 'agent', 'personal', 'individual', 'realtor', 'my own', 'independent']],
                        ['real_estate',        ['agency', 'company', 'listings', 'properties', 'brokerage', 'team', 'office']],
                        ['real_estate',        []],
                    ],
                    // tech + software/saas/app/platform → technology
                    //      + marketing/agency/creative   → marketing_agency
                    //      (no qualifier) → technology
                    'technology' => [
                        ['technology',       ['software', 'saas', 'app ', 'platform', 'startup', 'product', 'developer']],
                        ['marketing_agency', ['marketing', 'agency', 'creative', 'brand', 'campaign', 'advertising']],
                        ['technology',       []],
                    ],
                    // fitness + yoga/wellness/holistic/mindfulness → wellness
                    //         + gym/training/crossfit/weights/class → fitness
                    //         (no qualifier) → fitness
                    'fitness' => [
                        ['wellness', ['yoga', 'wellness', 'holistic', 'mindfulness', 'meditation', 'spa', 'pilates', 'reiki']],
                        ['fitness',  ['gym', 'training', 'crossfit', 'weights', 'class', 'workout', 'hiit', 'strength', 'bodybuilding']],
                        ['fitness',  []],
                    ],
                    // restaurant + cafe/coffee/bakery/brunch → cafe
                    //            (no qualifier) → restaurant
                    'restaurant' => [
                        ['cafe',       ['cafe', 'coffee', 'bakery', 'brunch', 'pastry', 'espresso', 'tea room']],
                        ['restaurant', []],
                    ],
                    // consulting + legal/law/attorney/lawyer → legal
                    //            (no qualifier) → consulting
                    'consulting' => [
                        ['legal',      ['legal', 'law', 'attorney', 'lawyer', 'litigation', 'barrister', 'counsel', 'solicitor']],
                        ['consulting', []],
                    ],
                    // design + interior/home/decor/space → interior_design
                    //        + architecture/architect/building → architecture
                    //        (no qualifier) → interior_design
                    'design' => [
                        ['interior_design', ['interior', 'home', 'decor', 'space', 'furniture', 'residential', 'living room', 'hospitality interior']],
                        ['architecture',    ['architecture', 'architect', 'building', 'structure', 'urban', 'construction design']],
                        ['interior_design', []],
                    ],
                ];

                // Aliases — map industries that are already one of the leaf
                // candidates back to their group so disambiguation still runs
                // if the user provides different secondary keywords.
                $groupAliases = [
                    'real_estate_broker' => 'real_estate',
                    'marketing_agency'   => 'technology',
                    'wellness'           => 'fitness',
                    'cafe'               => 'restaurant',
                    'legal'              => 'consulting',
                    'interior_design'    => 'design',
                    'architecture'       => 'design',
                ];
                $group = $groupAliases[$industry] ?? $industry;

                if (isset($rules[$group])) {
                    foreach ($rules[$group] as [$candidate, $keywords]) {
                        if (empty($keywords)) { $industry = $candidate; break; }
                        $matched = false;
                        foreach ($keywords as $kw) {
                            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) { $matched = true; break; }
                        }
                        if ($matched) { $industry = $candidate; break; }
                    }
                }

                $extracted['industry'] = $industry;
                $extracted['template_confirmed'] = true;

                // At this point all required fields are already present
                // (this block is gated by $isReady). Acknowledge silently
                // and jump straight to the image-upload step that used to
                // come AFTER template_confirmed.
                $extracted['industry_announced'] = true;
                $prettyInd = ucwords(str_replace('_', ' ', $industry));
                $name = $extracted['business_name'] ?? 'your business';
                return [
                    'type'     => 'image_upload',
                    'message'  => "Perfect! I'll build your **{$prettyInd}** website. Want to upload a few photos of **{$name}** (team, workspace, past work)? I'll use them across the site — gallery, team, services. The hero image stays AI-generated.\n\nUpload up to **10 photos**, or skip to build with stock imagery.",
                    'state'    => $extracted,
                    'max'      => 10,
                    'progress' => $this->getProgress($extracted),
                ];
            }
            // Template confirmed — next, prompt the user to upload photos
            // (gallery / team / services). Hero remains AI-generated.
            // Skip path: state.images_done = true AND uploaded_images = [].
            if (empty($extracted['images_done'])) {
                $name = $extracted['business_name'] ?? 'your business';
                return [
                    'type'     => 'image_upload',
                    'message'  => "Perfect. Want to upload a few photos of **{$name}** (team, workspace, past work)? I'll use them across the site — gallery, team, services. The hero image stays AI-generated.\n\nUpload up to **10 photos**, or skip to build with stock imagery.",
                    'state'    => $extracted,
                    'max'      => 10,
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // T1 (2026-04-20) — logo step comes AFTER images_done, BEFORE build.
            // State flag `logo_decided` gates: once user picks Upload or Skip,
            // this branch is bypassed.
            if (empty($extracted['logo_decided'])) {
                return [
                    'type'     => 'logo_upload',
                    'message'  => "Do you have a logo? I'll place it in the nav and footer of your website.",
                    'state'    => $extracted,
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // T2 (2026-04-20) — if a palette was proposed but not confirmed,
            // hold here. palette_choice fires only when palettes[] is present.
            if (!empty($extracted['palettes_proposed']) && empty($extracted['palette'])) {
                return [
                    'type'     => 'palette_choice',
                    'message'  => 'I found these colors in your logo. Which palette works for your brand?',
                    'state'    => $extracted,
                    'palettes' => $extracted['palettes_proposed'],
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // Generate the website (template confirmed + image choice made + logo step done).
            return $this->generateWebsite($wsId, $extracted);
        }

        // Step 3: Ask for missing info
        $question = $this->getNextQuestion($missing, $extracted);
        $progress = $this->getProgress($extracted);

        return [
            'type' => 'question',
            'message' => $question,
            'state' => $extracted,
            'progress' => $progress,
        ];
    }

    // BUG 1 FIX — single-shot extraction of every field Arthur cares about.
    // Called instead of extractFields() from handleMessage(). Returns the
    // merged state (never overwrites existing non-empty values with null).
    // Post-processes the industry through normalizeIndustry() so "digital
    // marketing" lands on "technology" even if the LLM guesses otherwise.
    private function extractAllFields(string $message, array $history, array $state): array
    {
        if (!$this->runtime->isConfigured()) {
            $state = $this->simpleExtract($message, $state, $history);
            $state['industry'] = $this->normalizeIndustry($state['industry'] ?? null, $message);
            $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);
            return $state;
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'Arthur' : 'User';
            $historyText .= "{$role}: {$h['content']}\n";
        }

        $system = <<<PROMPT
You are Arthur, an AI website builder assistant. Extract EVERY business detail the user gives you in ONE shot.

Return a JSON object (include the word "json") with these keys. Use null for fields that are genuinely missing — do NOT invent data.

- business_name: string
- industry: one of [restaurant, interior_design, fitness, healthcare, legal, real_estate, fashion, technology, events, beauty]. IMPORTANT MAPPING:
  * digital marketing / marketing agency / seo / web design / social media / advertising / it / software / app / saas / tech → "technology"
  * law firm / lawyer / attorney / consulting → "legal"
  * clinic / doctor / dental / hospital → "healthcare"
  * gym / yoga / pilates / personal trainer → "fitness"
  * salon / spa / barbershop → "beauty"
  * restaurant / cafe / bistro → "restaurant"
  * boutique / clothing / fashion → "fashion"
  * interior / fit-out / joinery → "interior_design"
  * property / real estate → "real_estate"
  * wedding / events → "events"
- services: array of short strings (e.g. ["SEO","website design","social media","paid ads"])
- location: string (city, e.g. "Dubai")
- target_market: string (who the business serves — e.g. "small and medium sized businesses")
- colors: object {primary: string|null, secondary: string|null} — named color or hex
- pages: array of page names if the user lists them (e.g. ["home","about","services","testimonials","blog","contact"])
- tagline, hero_title (3-6 words, plain text), hero_subtitle, hero_cta (3-4 words), about_text_1, meta_description

Add "ready": true if business_name AND industry are both present. Otherwise "ready": false and "missing": [list of missing fields].
No HTML. No markdown. Only valid JSON.
PROMPT;

        $userPrompt = ($historyText ? "Conversation:\n{$historyText}\n" : '')
            . "User's latest message: {$message}\n"
            . "Current extracted state: " . json_encode($state);

        try {
            $result = $this->runtime->chatJson($system, $userPrompt, ['task' => 'arthur_extract_all'], 1200);
            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                $parsed = $result['parsed'];
                foreach ($parsed as $k => $v) {
                    // Never overwrite with null/empty. Arrays must be non-empty
                    // to win over an existing value.
                    if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;
                    $state[$k] = $v;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] extractAllFields failed: ' . $e->getMessage());
            $state = $this->simpleExtract($message, $state, $history);
        }

        // Post-process the industry through the keyword map so misclassified
        // LLM output (e.g. "marketing" for a digital marketing agency) is
        // corrected before it reaches the template system.
        $state['industry'] = $this->normalizeIndustry($state['industry'] ?? null, $message);
        $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);
        return $state;
    }

    private function extractFields(string $message, array $history, array $state): array
    {
        if (!$this->runtime->isConfigured()) {
            // Fallback: simple keyword extraction
            return $this->simpleExtract($message, $state, $history);
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'Arthur' : 'User';
            $historyText .= "{$role}: {$h['content']}\n";
        }

        $system = <<<PROMPT
You are Arthur, an AI website builder assistant.
Extract business information from the user's message.
Required fields: business_name, industry, location, services, goal.
Industry MUST be one of: restaurant, interior_design, fitness, healthcare, legal, real_estate, fashion, technology, events, beauty, marketing, general.
If the user gives enough info to build a website (at least business_name + industry), set "ready": true.
If info is missing, set "ready": false and list what's missing in "missing" array.
When ready, also generate: tagline, hero_title (short punchy headline, 3-6 words, plain text only), hero_subtitle, hero_cta (3-4 words), about_text_1, meta_description. No HTML tags in any field.
Return ONLY valid JSON. No markdown. Include the word "json" in your thinking.
PROMPT;

        $userPrompt = ($historyText ? "Conversation:\n{$historyText}\n" : '')
            . "User's latest message: {$message}\n"
            . "Current extracted state: " . json_encode($state);

        try {
            $result = $this->runtime->chatJson($system, $userPrompt, ['task' => 'arthur_extract'], 800);
            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                // Merge with existing state (don't overwrite with nulls)
                $parsed = $result['parsed'];
                foreach ($parsed as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $state[$k] = $v;
                    }
                }
                return $state;
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Extraction failed: ' . $e->getMessage());
        }

        return $this->simpleExtract($message, $state, $history);
    }

    private function simpleExtract(string $message, array $state, array $history = []): array
    {
        $lower = mb_strtolower($message);

        // Simple keyword matching fallback. BUG 2 FIX — digital marketing /
        // seo / web design / social media / advertising now all land on
        // "technology" so the template picker shows the right gallery.
        $industries = [
            'restaurant'      => ['restaurant','café','cafe','bistro','dining','chef','catering','food','kitchen','bakery','pizzeria'],
            'fitness'         => ['gym','fitness','yoga','pilates','crossfit','personal trainer','personal training','wellness'],
            'beauty'          => ['salon','spa','beauty','hair','nails','skincare','barbershop'],
            'real_estate'     => ['real estate','property','realtor','broker','apartments','housing'],
            'healthcare'      => ['clinic','doctor','dental','medical','hospital','pharmacy','health'],
            'legal'           => ['law firm','lawyer','attorney','legal','solicitor','consulting'],
            'technology'      => ['digital marketing','marketing agency','seo agency','seo','web design','web development','social media agency','social media','advertising agency','advertising','it company','it services','software','app development','app','saas','tech','digital'],
            'events'          => ['wedding','events','event production'],
            'interior_design' => ['interior','fit-out','joinery'],
            'fashion'         => ['fashion','clothing','boutique'],
        ];

        foreach ($industries as $industry => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($lower, $kw) !== false && empty($state['industry'])) {
                    $state['industry'] = $industry;
                    break 2;
                }
            }
        }

        // Extract location
        $locations = ['dubai', 'abu dhabi', 'sharjah', 'ajman', 'riyadh', 'jeddah', 'doha', 'muscat', 'bahrain', 'kuwait'];
        foreach ($locations as $loc) {
            if (strpos($lower, $loc) !== false && empty($state['location'])) {
                $state['location'] = ucwords($loc) . ', UAE';
                break;
            }
        }

        // Extract business name — look for quoted text or "called X" or "named X"
        if (empty($state['business_name'])) {
            if (preg_match('/(?:called|named|for)\s+"?([A-Z][A-Za-z\s&\']+)"?/i', $message, $m)) {
                $state['business_name'] = trim($m[1]);
            } elseif (preg_match('/"([^"]+)"/', $message, $m)) {
                $state['business_name'] = trim($m[1]);
            }
        }

        // Conversational fallback — when the runtime LLM is unavailable, plain
        // user replies like "MR Digital Media" never match the regex patterns
        // above and the wizard loops forever asking the same question. Look at
        // the most recent Arthur message in history to figure out which field
        // the user is answering and capture the message verbatim.
        $trimmed = trim($message);
        if ($trimmed !== '' && !empty($history)) {
            $lastArthur = '';
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $h = $history[$i];
                if (is_array($h) && ($h['role'] ?? '') === 'assistant') {
                    $lastArthur = mb_strtolower((string)($h['content'] ?? ''));
                    break;
                }
            }
            if ($lastArthur !== '') {
                if (empty($state['business_name']) && mb_strpos($lastArthur, "name of your business") !== false) {
                    $state['business_name'] = $trimmed;
                } elseif (empty($state['location']) && mb_strpos($lastArthur, 'based?') !== false) {
                    $state['location'] = $trimmed;
                } elseif (empty($state['services']) && (mb_strpos($lastArthur, 'services or products') !== false || mb_strpos($lastArthur, 'what services') !== false)) {
                    $parts = preg_split('/\s*,\s*|\s*;\s*|\s+and\s+/i', $trimmed);
                    $state['services'] = array_values(array_filter(array_map('trim', $parts)));
                }
            }
        }

        // Check readiness
        $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);

        return $state;
    }

    private function getNextQuestion(array $missing, array $state): string
    {
        $name = $state['business_name'] ?? 'your business';

        if (in_array('business_name', $missing)) {
            return "I'd love to build your website! What's the name of your business?";
        }
        if (in_array('industry', $missing)) {
            return "Great name! What type of business is **{$name}**? (e.g., restaurant, fitness, beauty salon, etc.)";
        }
        if (empty($state['location'])) {
            return "Where is **{$name}** based? (city, country)";
        }
        if (empty($state['services'])) {
            return "What services or products does **{$name}** offer?";
        }

        return "Tell me more about **{$name}** — what makes it special?";
    }

    private function getProgress(array $state): array
    {
        return [
            ['field' => 'business_name', 'label' => 'Business Name', 'done' => !empty($state['business_name'])],
            ['field' => 'industry', 'label' => 'Industry', 'done' => !empty($state['industry'])],
            ['field' => 'location', 'label' => 'Location', 'done' => !empty($state['location'])],
            ['field' => 'services', 'label' => 'Services', 'done' => !empty($state['services'])],
        ];
    }

    private function generateWebsite(int $wsId, array $data): array
    {
        $industry = $data['industry'] ?? 'restaurant';
        $name = $data['business_name'] ?? 'My Business';

        // Check if template exists, fallback to restaurant
        $manifest = $this->templates->getManifest($industry);
        if (!$manifest) {
            $industry = 'restaurant';
            $manifest = $this->templates->getManifest('restaurant');
        }
        if (!$manifest) {
            return ['type' => 'error', 'message' => 'No templates available. Please contact support.'];
        }

        // Generate content via LLM
        $variables = $this->generateContent($data, $industry);

        // Get brand colors from workspace
        $brand = DB::table('creative_brand_identities')->where('workspace_id', $wsId)->first();
        if ($brand) {
            $variables['primary_color'] = $brand->primary_color ?? $variables['primary_color'];
            $variables['secondary_color'] = $brand->secondary_color ?? $variables['secondary_color'];
        }

        // Set defaults
        $variables['business_name'] = $name;
        $variables['footer_text'] = '© ' . date('Y') . ' ' . $name . '. All rights reserved.';
        $variables['contact_address'] = $data['location'] ?? 'Dubai, UAE';
        $variables['city'] = $data['location'] ?? 'Dubai';
        $variables['country'] = 'AE';

        // BUG 2 FIX — guaranteed hero image floor from builder_default_assets.
        // This runs BEFORE any DALL-E attempt so that generation failures
        // never leave the site with an empty hero. DALL-E success below
        // simply overwrites this value.
        $heroDefaultUrl = null;
        try {
            $defaultRow = DB::table('builder_default_assets')
                ->where('asset_type', 'hero')
                ->where('industry', $industry)
                ->first();
            if (!$defaultRow) {
                $defaultRow = DB::table('builder_default_assets')
                    ->where('asset_type', 'hero')
                    ->where('industry', 'default')
                    ->first();
            }
            if ($defaultRow && !empty($defaultRow->url)) {
                $heroDefaultUrl = $defaultRow->url;
                $variables['hero_image'] = $heroDefaultUrl;
                $variables['og_image']   = $heroDefaultUrl;
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] builder_default_assets lookup failed: ' . $e->getMessage());
        }

        // Check media library first, generate only if no match
        $existingHero = \App\Services\MediaService::findOrGenerate($industry, 'hero', 'luxury', $wsId);
        if ($existingHero) {
            $variables['hero_image'] = $existingHero['url'];
            Log::info('[Arthur] Reused existing hero image: ' . $existingHero['id']);
        } else {
        // Generate hero image

        try {
            $imgResult = $this->runtime->imageGenerate(
                $this->getHeroImagePrompt($industry, $data['location'] ?? 'Dubai'),
                ['size' => '1792x1024', 'quality' => 'standard']
            );
            if ($imgResult['success'] ?? false) {
                // Download DALL-E image to local storage (URLs expire after 2h)
                    $heroLocalPath = '/storage/sites/heroes/' . uniqid('hero_') . '.png';
                    $heroFullPath = storage_path('app/public/sites/heroes');
                    if (!is_dir($heroFullPath)) mkdir($heroFullPath, 0755, true);
                    $imgData = @file_get_contents($imgResult['url']);
                    if ($imgData) {
                        file_put_contents($heroFullPath . '/' . basename($heroLocalPath), $imgData);
                        $variables['hero_image'] = $heroLocalPath;
                        // Register in global media library
                        try { \App\Services\MediaService::registerFull($heroLocalPath, $heroLocalPath, 'dalle', 'hero', $industry, $wsId, $this->getHeroImagePrompt($industry, $data['location'] ?? 'Dubai'), 'dall-e-3', 'luxury'); } catch (\Throwable $e) {}
                    } else {
                        $variables['hero_image'] = $imgResult['url']; // fallback to URL
                    }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Hero image generation failed: ' . $e->getMessage());
        }
        } // end else (no existing hero)

        // Generate gallery images (3 via DALL-E, reuse hero for remaining).
        // Prompts are industry-specific so a fitness site doesn't end up
        // with restaurant food photos in its gallery (bug found 2026-04-19).
        $galleryPrompts = $this->getGalleryPrompts($industry, $data['location'] ?? 'Dubai');
        $galleryImages = [];
        foreach ($galleryPrompts as $i => $prompt) {
            try {
                $gResult = $this->runtime->imageGenerate($prompt, ['size' => '1024x1024', 'quality' => 'standard']);
                if ($gResult['success'] ?? false) {
                    $galLocalPath = '/storage/sites/gallery/' . uniqid('gal_') . '.png';
                        $galFullPath = storage_path('app/public/sites/gallery');
                        if (!is_dir($galFullPath)) mkdir($galFullPath, 0755, true);
                        $galData = @file_get_contents($gResult['url']);
                        if ($galData) {
                            file_put_contents($galFullPath . '/' . basename($galLocalPath), $galData);
                            $galleryImages[] = $galLocalPath;
                            // Register in media library
                            try { \App\Services\MediaService::registerFull($galLocalPath, $galLocalPath, 'dalle', 'gallery', $industry, $wsId, $prompt, 'dall-e-3', 'luxury'); } catch (\Throwable $e) {}
                        } else {
                            $galleryImages[] = $gResult['url'];
                        }
                }
            } catch (\Throwable $e) {
                Log::warning("[Arthur] Gallery image {$i} failed: " . $e->getMessage());
            }
        }
        // Fill gallery slots — generated images first, then reuse hero, then empty
        for ($gi = 1; $gi <= 11; $gi++) {
            if (isset($galleryImages[$gi - 1])) {
                $variables['gallery_image_' . $gi] = $galleryImages[$gi - 1];
            } elseif (!empty($variables['hero_image']) && $gi <= 6) {
                $variables['gallery_image_' . $gi] = $variables['hero_image'];
            } else {
                $variables['gallery_image_' . $gi] = '';
            }
        }


        // Hide gallery (no photos yet) and extra service slots
        $variables['gallery_display'] = 'display:none';
        $variables['feature_display'] = 'display:none';
        $variables['service_4_display'] = 'display:none';
        $variables['service_5_display'] = 'display:none';
        $variables['service_6_display'] = 'display:none';

        // BUG 1 FIX — respect explicitly-requested pages. When the user
        // lists pages ("home, about, services, testimonials, blog, contact"),
        // force the matching section's *_display var to '' (show) even if
        // it was previously defaulted to display:none (e.g. gallery). Only
        // touches manifest-declared variables so unknown templates stay
        // safe. "home" is implicit.
        if (!empty($data['pages']) && is_array($data['pages'])) {
            $requested = array_map(
                fn($p) => strtolower(trim((string)$p)),
                $data['pages']
            );
            $pageToDisplayVar = [
                'about'        => 'about_display',
                'services'     => 'services_display',
                'gallery'      => 'gallery_display',
                'testimonials' => 'testimonials_display',
                'blog'         => 'blog_display',
                'contact'      => 'contact_display',
                'team'         => 'team_display',
                'portfolio'    => 'portfolio_display',
                'process'      => 'process_display',
            ];
            $manifestVarsForPages = $manifest['variables'] ?? [];
            foreach ($pageToDisplayVar as $page => $displayVar) {
                if (!in_array($page, $requested, true)) continue;
                // Only set when the manifest declares the var OR we already
                // hold a value for it — avoids emitting hollow keys.
                if (array_key_exists($displayVar, $manifestVarsForPages)
                    || array_key_exists($displayVar, $variables)) {
                    $variables[$displayVar] = '';
                }
            }
        }

        // FIX 2 — distribute user uploads across ALL image variables in
        // manifest order. Hero_image → first upload (or DALL-E if DALL-E
        // produced a non-default URL). about/gallery/team/portfolio →
        // remaining uploads in manifest order. Any slot still empty falls
        // back to the industry hero default.
        $uploadedImages = [];
        if (isset($data['uploaded_images']) && is_array($data['uploaded_images'])) {
            foreach ($data['uploaded_images'] as $u) {
                if (is_string($u) && $u !== '') $uploadedImages[] = $u;
            }
        }
        $uploadedImages = array_slice($uploadedImages, 0, 10); // hard cap 10
        $uploadQueue    = $uploadedImages;

        // FIX 1 (2026-04-20) — logo_url must NEVER receive an auto-assigned
        // upload from general image distribution. The wizard shows a text
        // fallback until the user explicitly says "upload a logo" (state.logo_upload=true).
        $logoUploadOptIn = !empty($data['logo_upload']);
        if (!$logoUploadOptIn && !empty($uploadQueue)) {
            $variables['logo_url'] = ''; // force empty so text fallback renders
        }
        // T1 (2026-04-20) — if the wizard collected a temp logo, use it
        // directly on the in-memory variables. Permanent copy happens
        // AFTER the website row exists (we need the websiteId for the path).
        $logoTempPath = $data['logo_temp_path'] ?? null;
        $logoTempUrl  = $data['logo_url']       ?? null;
        if ($logoUploadOptIn && $logoTempUrl) {
            $variables['logo_url'] = $logoTempUrl; // transitional until we move it
        }

        try {
            $manifestForImgs = $this->templates->getManifest($industry) ?: [];
            foreach (($manifestForImgs['variables'] ?? []) as $varKey => $varSpec) {
                $type = is_array($varSpec) ? ($varSpec['type'] ?? 'text') : 'text';
                if ($type !== 'image') continue;

                // Skip logo_url from general distribution unless user opted in.
                if ($varKey === 'logo_url' && !$logoUploadOptIn) continue;

                // A slot already holds a non-default URL → DALL-E or caller
                // put something real there; keep it. The default floor URL
                // is treated as "still empty" so uploads can override it.
                $current = $variables[$varKey] ?? '';
                $isJustFloor = ($current === '' || $current === null || $current === $heroDefaultUrl);

                if ($isJustFloor && !empty($uploadQueue)) {
                    $variables[$varKey] = array_shift($uploadQueue);
                } elseif ($current === '' || $current === null) {
                    $variables[$varKey] = $heroDefaultUrl
                        ?? (is_array($varSpec) ? ($varSpec['default'] ?? '') : '');
                }
            }
            // Guarantee hero + og have something — floor to industry default.
            foreach (['hero_image','og_image'] as $rk) {
                if (empty($variables[$rk]) && $heroDefaultUrl) {
                    $variables[$rk] = $heroDefaultUrl;
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // FIX 3 — after all image + content work, walk EVERY manifest var
        // and substitute the manifest default when the current value is
        // empty. Rule: generated/uploaded content > manifest default > empty.
        try {
            $mf = $this->templates->getManifest($industry) ?: [];
            foreach (($mf['variables'] ?? []) as $mKey => $mSpec) {
                $default = is_array($mSpec) ? ($mSpec['default'] ?? '') : '';
                $have    = $variables[$mKey] ?? null;
                if (($have === '' || $have === null) && $default !== '' && $default !== null) {
                    $variables[$mKey] = $default;
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // T2 (2026-04-20) — if wizard collected a palette (from logo color
        // extraction), promote it into $data['colors'] so applyBrandColors
        // uses it. Also persist bg/text so templates with those vars pick up.
        if (!empty($data['palette']) && is_array($data['palette'])) {
            $pal = $data['palette'];
            $data['colors'] = array_merge(
                (array)($data['colors'] ?? []),
                [
                    'primary'   => $pal['primary']   ?? null,
                    'secondary' => $pal['secondary'] ?? null,
                    'accent'    => $pal['accent']    ?? null,
                ]
            );
            if (!empty($pal['bg']))   $variables['bg_color']   = $pal['bg'];
            if (!empty($pal['text'])) $variables['text_color'] = $pal['text'];
        }

        // FIX 1 — brand colors override (CSS custom properties fed by {{var}})
        try {
            $mf2 = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $this->applyBrandColors($variables, $mf2, $data['colors'] ?? []);
        } catch (\Throwable $e) { /* non-fatal */ }

        // FIX 4 — cross-industry leak guard on generated service titles.
        // If any service_N_title smells like the wrong industry (e.g. "Fine
        // Dining" on a fitness site), fall back to the manifest default for
        // that specific field.
        try {
            $mf3 = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $leakFields = ['service_1_title','service_1_text','service_2_title','service_2_text',
                           'service_3_title','service_3_text','service_4_title','service_5_title',
                           'service_6_title','program_1_title','program_2_title','program_3_title',
                           'cuisine_1_name','cuisine_2_name','cuisine_3_name',
                           'blog_section_title','blog_1_title','blog_2_title','blog_3_title'];
            foreach ($leakFields as $lf) {
                if (empty($variables[$lf])) continue;
                if ($this->isCrossIndustryLeak((string)$variables[$lf], $industry)) {
                    $spec = $mf3['variables'][$lf] ?? null;
                    $variables[$lf] = is_array($spec) ? ($spec['default'] ?? '') : '';
                    Log::info("[Arthur] Cross-industry leak fallback on {$lf} for {$industry}");
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Render template — TemplateService also carries industry-scoped
        // image defaults so even variables unknown to the manifest won't
        // render as hollow sections.
        try {
            $html = $this->templates->render($industry, $variables);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'message' => 'Template rendering failed: ' . $e->getMessage()];
        }

        // Create website record
        $websiteId = DB::table('websites')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $name,
            'type' => 'template',
            'template_industry' => $industry,
            'template_variables' => json_encode($variables),
            'status' => 'draft',
            'settings_json' => json_encode([
                'industry' => $industry,
                'template' => $industry,
                'generated_by' => 'arthur',
            ]),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // T1 (2026-04-20) — move temp logo into permanent site storage.
        // Done BEFORE deploy so the rendered HTML points at the final URL.
        if ($logoUploadOptIn && $logoTempPath && is_file($logoTempPath)) {
            try {
                $ext = pathinfo($logoTempPath, PATHINFO_EXTENSION) ?: 'png';
                $siteDir = storage_path("app/public/sites/{$websiteId}");
                if (!is_dir($siteDir)) @mkdir($siteDir, 0775, true);
                $destName = 'logo.' . strtolower($ext);
                $destPath = $siteDir . '/' . $destName;
                if (@copy($logoTempPath, $destPath)) {
                    @chmod($destPath, 0644);
                    @unlink($logoTempPath);
                    $permUrl = '/storage/sites/' . $websiteId . '/' . $destName . '?v=' . time();
                    $variables['logo_url'] = $permUrl;
                    DB::table('websites')->where('id', $websiteId)->update([
                        'template_variables' => json_encode($variables),
                        'updated_at' => now(),
                    ]);
                    // Re-render with the final URL (transitional render above used temp URL).
                    try {
                        $html = $this->templates->render($industry, $variables);
                    } catch (\Throwable $_e) { /* keep previous html */ }
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] logo copy failed: ' . $e->getMessage());
            }
        }

        // Deploy HTML
        // LEGACY: T3.4 — static-HTML-only path retained for backwards
        // compatibility (Chef Red-style sites). New sites also get a
        // canonical sections_json below so BuilderRenderer can serve them.
        // Remove this deploy() call once Chef Red is migrated and arthur-edit
        // closure is rewritten on top of sections_json (Patch 8.5+).
        $this->templates->deploy($websiteId, $html);

        // FIX 2 (2026-04-20) — always create default pages: Home (homepage)
        // + Blog for every generated website. Any other pages the wizard
        // extracted via state.pages[] also get created. Failures are
        // non-fatal; logged but don't block the wizard response.
        //
        // PATCH 8 (2026-05-08) — every page row now ALSO carries a
        // canonical sections_json built from the wizard data so
        // BuilderRenderer can serve the site without falling back to the
        // static index.html. New sites are pure-canonical from now on.
        try {
            $builder = app(\App\Engines\Builder\Services\BuilderService::class);
            $homePos = 0;

            // Home (homepage=1)
            DB::table('pages')->insert([
                'website_id'    => $websiteId,
                'title'         => 'Home',
                'slug'          => 'home',
                'type'          => 'page',
                'status'        => 'published',
                'position'      => $homePos++,
                'is_homepage'   => 1,
                'sections_json' => json_encode($this->buildDefaultSectionsForPage('home', $data)),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // User-requested pages (from wizard state.pages[]), excluding Home + Blog which we handle explicitly
            $extraPages = $data['pages'] ?? [];
            $seen = ['home' => true, 'blog' => true];
            if (is_array($extraPages)) {
                foreach ($extraPages as $p) {
                    $title = trim((string)(is_array($p) ? ($p['title'] ?? $p['name'] ?? '') : $p));
                    if ($title === '') continue;
                    $slug = \Illuminate\Support\Str::slug($title);
                    if ($slug === '' || isset($seen[$slug])) continue;
                    $seen[$slug] = true;
                    DB::table('pages')->insert([
                        'website_id'    => $websiteId,
                        'title'         => ucfirst($title),
                        'slug'          => $slug,
                        'type'          => 'page',
                        'status'        => 'published',
                        'position'      => $homePos++,
                        'is_homepage'   => 0,
                        'sections_json' => json_encode($this->buildDefaultSectionsForPage($slug, $data)),
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }

            // Blog — always last, always created.
            DB::table('pages')->insert([
                'website_id'    => $websiteId,
                'title'         => 'Blog',
                'slug'          => 'blog',
                'type'          => 'blog',
                'status'        => 'published',
                'position'      => $homePos++,
                'is_homepage'   => 0,
                'sections_json' => json_encode($this->buildDefaultSectionsForPage('blog', $data)),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Arthur] page creation failed: ' . $e->getMessage(), ['website_id' => $websiteId]);
        }

        // Log intelligence
        try {
            app(\App\Core\Intelligence\EngineIntelligenceService::class)
                ->recordToolUsage('builder', 'wizard_generate', 0.9);
        } catch (\Throwable $e) {}

        return [
            'type' => 'complete',
            'message' => "✅ Your website for **{$name}** is ready! I used our premium {$industry} template and generated all the copy. You can preview it, edit the text, or publish it right away.",
            'website_id' => $websiteId,
            'name' => $name,
            'industry' => $industry,
        ];
    }

        private function generateContent(array $data, string $industry): array
    {
        $name = $data['business_name'] ?? 'Our Business';
        $location = $data['location'] ?? 'Dubai';
        // BUG 2 FIX — services may arrive as an array from extractAllFields();
        // join into a comma-separated list for the LLM copy prompt.
        $servicesRaw = $data['services'] ?? 'various services';
        $services = is_array($servicesRaw) ? implode(', ', $servicesRaw) : (string)$servicesRaw;
        $emailSlug = strtolower(preg_replace('/[^a-z0-9]/', '', $name));

        $defaults = [
            'business_name' => $name,
            'business_tagline' => $name . ' — Premium ' . ucfirst($industry),
            'hero_title' => $name,
            'hero_subtitle' => 'Experience excellence in ' . $location,
            'hero_cta' => 'Get Started',
            'hero_cta_secondary' => 'Learn More',
            'hero_eyebrow' => ucfirst($industry) . ' · ' . $location,
            'about_eyebrow' => 'About Us',
            'about_title' => 'Our Story',
            'about_text_1' => "At {$name}, we are passionate about delivering exceptional quality to our clients in {$location}.",
            'about_text_2' => 'With years of experience, we bring expertise and dedication to every project.',
            'about_text_3' => "Our commitment to excellence sets us apart in the {$industry} industry.",
            'about_signature' => $name,
            'about_image' => '',
            'about_image_display' => 'display:none',
            'services_eyebrow' => 'What We Do',
            'services_title' => 'Our Services',
            'service_1_title' => 'Fine Dining',
            'service_1_text' => 'Tailored solutions designed for your specific needs.',
            'service_2_title' => 'Private Events',
            'service_2_text' => 'Professional guidance from industry experts.',
            'service_3_title' => 'Catering',
            'service_3_text' => 'Bespoke offerings crafted to exceed expectations.',
            'service_4_display' => 'display:none',
            'service_5_display' => 'display:none',
            'service_6_display' => 'display:none',
            'gallery_display' => 'display:none',
            'stats_display' => '',
            'stat_1_value' => '500+', 'stat_1_label' => 'Happy Guests',
            'stat_2_value' => '15+', 'stat_2_label' => 'Years Experience',
            'stat_3_value' => '50+', 'stat_3_label' => 'Menu Items',
            'stat_4_value' => '4.9', 'stat_4_label' => 'Average Rating',
            'cuisine_image_display' => 'display:none',
            'gallery_eyebrow' => 'Our Work',
            'gallery_title' => 'Gallery',
            'cuisines_eyebrow' => 'Specialties',
            'cuisines_title' => 'What We Offer',
            'cuisines_intro' => "At {$name}, our expertise spans a range of specialties.",
            'cuisine_1_name' => 'Signature Dishes',
            'cuisine_1_note' => 'Our finest creations',
            'cuisine_2_name' => 'International Cuisine',
            'cuisine_2_note' => 'Global flavors',
            'cuisine_3_name' => 'Seasonal Specials',
            'cuisine_3_note' => 'Fresh and local',
            'testimonial_1_quote' => "Working with {$name} has been an exceptional experience. The quality and attention to detail is unmatched.",
            'testimonial_1_author' => 'Ahmed K. — Dubai',
            'testimonial_2_quote' => "The team delivers consistently outstanding results. Highly recommended for anyone seeking premium service.",
            'testimonial_2_author' => 'Sarah M. — Business Executive',
            'testimonial_3_quote' => "Professional, reliable, and truly passionate about what they do. A standout in the {$location} market.",
            'testimonial_3_author' => 'James L. — Corporate Client',
                        'cuisine_4_name' => '', 'cuisine_4_note' => '',
            'cuisine_5_name' => '', 'cuisine_5_note' => '',
            'cuisine_6_name' => '', 'cuisine_6_note' => '',            'process_eyebrow' => 'How It Works',
            'process_title' => 'Our Process',
            'process_1_title' => 'Discovery',
            'process_1_text' => 'We learn about your needs and goals.',
            'process_2_title' => 'Custom Plan',
            'process_2_text' => 'We design a tailored solution for you.',
            'process_3_title' => 'Delivery',
            'process_3_text' => 'We execute with precision and care.',
            'process_4_title' => 'Follow-Up',
            'process_4_text' => 'We ensure your complete satisfaction.',
            'contact_eyebrow' => 'Get In Touch',
            'contact_title' => 'Contact Us',
            'contact_form_title' => "Let's Start a Conversation",
            'contact_email' => 'info@' . $emailSlug . '.com',
            'contact_website' => strtolower(str_replace([' ', "'"], ['', ''], $name)) . '.com',
            'contact_service_area' => $location,
            'contact_availability_text' => "Currently accepting new clients. Contact us to discuss how {$name} can help you.",
            'blog_section_title' => 'From Our Kitchen',
            'blog_1_title' => 'The Art of Fine Dining', 'blog_1_excerpt' => 'Discover what sets premium dining apart in one of the worlds most dynamic cities.', 'blog_1_category' => 'Dining',
            'blog_2_title' => 'Behind the Scenes', 'blog_2_excerpt' => 'A closer look at the craft and passion that goes into everything we do.', 'blog_2_category' => 'Inside',
            'blog_3_title' => 'What Our Guests Say', 'blog_3_excerpt' => 'Real stories from guests about their exceptional experiences.', 'blog_3_category' => 'Reviews',
            'meta_description' => "{$name} — Premium {$industry} services in {$location}. Experience excellence.",
            'primary_color' => '#C9943A',
            'primary_light' => '#DFB96A',
            'bg_color' => '#0A0806',
            'text_color' => '#F2EBDF',
            'footer_text' => '© ' . date('Y') . ' ' . $name . '. All rights reserved.',
        ];

        // FIX 3 — seed defaults from the industry's manifest, not the
        // hardcoded restaurant dictionary. Manifest defaults are already
        // tailored to each of the 10 industries we ship. The old $defaults
        // dictionary is kept above as a final-last-resort floor for old
        // restaurant field names.
        try {
            $manifestDefaults = [];
            $manifest = $this->templates->getManifest($industry) ?: [];
            foreach (($manifest['variables'] ?? []) as $mk => $mv) {
                $d = is_array($mv) ? ($mv['default'] ?? '') : '';
                if ($d !== '' && $d !== null) $manifestDefaults[$mk] = $d;
            }
            // Manifest defaults WIN over restaurant-themed hardcoded $defaults.
            $defaults = array_merge($defaults, $manifestDefaults);
            // Business-specific overrides (computed per-call) win over all.
            $defaults['business_name'] = $name;
            $defaults['business_tagline'] = $name . ' \u2014 ' . ucfirst(str_replace('_',' ', $industry)) . ' \u00B7 ' . $location;
            $defaults['meta_description'] = "{$name} — {$industry} in {$location}.";
            $defaults['contact_email']    = 'info@' . $emailSlug . '.com';
        } catch (\Throwable $e) { /* non-fatal, keep hardcoded $defaults */ }

        if (!$this->runtime->isConfigured()) {
            return $defaults;
        }

        // FIX 4 — industry-specific guidance for the LLM. Each industry
        // gets a one-liner of appropriate content examples, plus an
        // explicit don't-generate-a-different-industry constraint.
        $industryHints = [
            'fitness'         => "Use fitness content ONLY. Services = training programs (HIIT, Strength, Yoga, Pilates, Mobility, Personal Training). NEVER mention dining, cuisine, chefs, or menus. Testimonials from members about physical transformation.",
            'restaurant'      => "Use restaurant content ONLY. Services = dining experiences (tasting menu, private events, catering, chef's table). NEVER mention workouts, clinics, law firms, or SaaS.",
            'healthcare'      => "Use healthcare content ONLY. Services = medical treatments (consultations, diagnostics, procedures, preventive care). NEVER mention dining, fitness classes, or retail.",
            'legal'           => "Use legal-firm content ONLY. Services = practice areas (corporate, litigation, family, real estate, M&A, IP). NEVER mention dining, fitness, or retail.",
            'beauty'          => "Use beauty-salon content ONLY. Services = treatments (hair colour, cut, facial, manicure, makeup). NEVER mention dining, fitness, law, or SaaS.",
            'real_estate'     => "Use real-estate content ONLY. Services = transactions (sales, leasing, property management, investment). NEVER mention dining, fitness, or clinics.",
            'interior_design' => "Use interior-design content ONLY. Services = design offerings (residential design, commercial, consultation, project management). NEVER mention dining, fitness, or clinics.",
            'fashion'         => "Use fashion-atelier content ONLY. Services = garment categories (outerwear, evening, tailoring, knitwear, bespoke). NEVER mention dining, fitness, or clinics.",
            'technology'      => "Use B2B SaaS content ONLY. Services = product features (tracing, evals, cost tracking, alerting, self-host). NEVER mention dining, fitness, or salons.",
            'events'          => "Use event-production content ONLY. Services = event types (weddings, corporate galas, product launches, private celebrations). NEVER mention dining menus, fitness, or clinics.",
        ];
        $hint = $industryHints[$industry] ?? "Use {$industry}-appropriate content only. Do not generate content from a different industry.";

        try {
            $prompt = "Generate complete website content for '{$name}', a {$industry} business in {$location}. "
                . "Services: {$services}. "
                . "INDUSTRY RULES: {$hint}\n\n"
                . "Return a JSON object with the word json. ALL fields must have real, {$industry}-appropriate content — no placeholders:\n\n"
                . "hero_title (short punchy headline, 3-6 words, plain text),\n"
                . "hero_subtitle (one compelling sentence),\n"
                . "hero_cta (action button appropriate for {$industry}),\n"
                . "hero_eyebrow (short badge text),\n"
                . "business_tagline (short brand tagline for a {$industry} business),\n"
                . "about_title, about_text_1, about_text_2, about_text_3 (2-3 sentences each, {$industry}-appropriate),\n"
                . "service_1_title, service_1_text, service_2_title, service_2_text, service_3_title, service_3_text "
                . "(each service MUST be a {$industry} offering — not a different industry's service),\n"
                . "testimonial_1_quote, testimonial_1_author (Full Name — Title, City),\n"
                . "testimonial_2_quote, testimonial_2_author,\n"
                . "testimonial_3_quote, testimonial_3_author (all testimonials must read like real {$industry} clients),\n"
                . "contact_email, contact_service_area, contact_availability_text,\n"
                . "blog_1_title, blog_1_excerpt, blog_1_category "
                . "(blog content must be about {$industry} topics — e.g. for fitness: training methodology, recovery; for restaurant: cooking technique, sourcing),\n"
                . "blog_2_title, blog_2_excerpt, blog_2_category,\n"
                . "blog_3_title, blog_3_excerpt, blog_3_category,\n"
                . "meta_description (under 160 chars for SEO).\n\n"
                . "IMPORTANT: No HTML tags. No markdown. Plain text. Dubai/UAE tone. Premium quality. "
                . "Do NOT use content appropriate for any industry OTHER than {$industry}.";

            $result = $this->runtime->chatJson(
                "You are a professional website copywriter for a {$industry} business in Dubai/UAE. "
                . "Never generate content from a different industry. Return only valid JSON with the word json.",
                $prompt,
                ['task' => 'arthur_copywrite'],
                2000
            );

            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                $merged = array_merge($defaults, array_filter($result['parsed'], fn($v) => $v !== null && $v !== ''));
                return $this->overlayUserServices($merged, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Content generation failed: ' . $e->getMessage());
        }

        return $this->overlayUserServices($defaults, $data);
    }

    // BUG 2 FIX — when the user supplied an explicit services list
    // (e.g. ["SEO","website design","social media","paid ads"]), overwrite
    // the service_1..6_title slots with those exact services and hide the
    // unused slots. User wins over LLM output and template defaults.
    private function overlayUserServices(array $vars, array $data): array
    {
        $svc = $data['services'] ?? null;
        if (!is_array($svc) || empty($svc)) return $vars;

        $svc = array_values(array_filter(array_map(
            fn($s) => is_string($s) ? trim($s) : '',
            $svc
        ), fn($s) => $s !== ''));
        if (empty($svc)) return $vars;

        for ($i = 0; $i < 6; $i++) {
            $slot = $i + 1;
            if (isset($svc[$i])) {
                $title = ucwords($svc[$i]);
                $vars["service_{$slot}_title"] = $title;
                // Only fabricate a description when the template/LLM didn't
                // provide one — never clobber a real write-up.
                if (empty($vars["service_{$slot}_text"])
                    || $this->isCrossIndustryLeak((string)$vars["service_{$slot}_text"], $data['industry'] ?? 'technology')) {
                    $vars["service_{$slot}_text"] = "Professional {$title} tailored to your business goals.";
                }
                $vars["service_{$slot}_display"] = '';
            } else {
                $vars["service_{$slot}_display"] = 'display:none';
            }
        }
        return $vars;
    }

    private function getHeroImagePrompt(string $industry, string $location): string
    {
        $prompts = [
            'restaurant' => "Elegant upscale restaurant interior, warm ambient candlelight, dark wood tables set for dinner, intimate fine dining atmosphere, soft bokeh background, professional food photography style, wide cinematic shot, warm golden tones",
            'fitness' => "Modern premium gym interior, professional equipment, dramatic lighting, wide angle, clean minimalist design, motivational atmosphere",
            'beauty' => "Luxurious beauty salon interior, soft warm lighting, elegant decor, professional treatment area, spa atmosphere",
            'healthcare' => "Modern medical clinic reception, clean bright interior, professional healthcare environment, welcoming design",
            'legal' => "Prestigious law office interior, dark wood furniture, leather chairs, floor-to-ceiling bookshelves, professional atmosphere",
            'real_estate' => "Stunning luxury penthouse interior with panoramic city view at golden hour, modern architecture, floor-to-ceiling windows",
            'technology' => "Modern tech office space, clean minimalist design, large monitors, collaborative workspace, blue accent lighting",
            'events' => "Elegant event venue setup, dramatic lighting, luxurious table settings, floral centerpieces, grand ballroom atmosphere",
        ];

        $base = $prompts[$industry] ?? "Professional modern business interior, clean design, warm lighting, premium atmosphere";
        return $base . ", photorealistic, 16:9 aspect ratio, no text, no signs, no logos, no words, no lettering, no watermarks";
    }

    /**
     * Per-industry gallery prompts. Added 2026-04-19 to replace a hardcoded
     * restaurant-themed array that was polluting every non-restaurant build.
     * Returns 3 prompts per industry. Unknown industries fall back to a
     * generic professional set.
     */
    private function getGalleryPrompts(string $industry, string $location): array
    {
        $map = [
            'restaurant' => [
                'Elegant gourmet cuisine plating on dark slate, professional food photography, warm lighting',
                'Luxury restaurant table setting, candlelight, gold cutlery, dark moody intimate atmosphere, no people',
                'Fresh colorful ingredients and spices flatlay, dark background, overhead shot, professional food photography',
            ],
            'cafe' => [
                'Artisan coffee being poured into ceramic cup with latte art, warm morning light, cozy cafe ambiance',
                'Fresh pastries and baked goods on wooden counter, warm golden lighting, artisan cafe aesthetic',
                'Cozy cafe interior with exposed brick, wooden tables, string lights, inviting atmosphere',
            ],
            'fitness' => [
                'Modern premium gym interior with free weights and equipment, dramatic lighting, motivational atmosphere',
                'Athletic person performing bodyweight exercise, professional sports photography, dynamic composition',
                'Premium fitness equipment detail shot, dumbbells and kettlebells, gym aesthetic, natural light',
            ],
            'beauty' => [
                'Luxury beauty salon styling chair, professional lighting, elegant mirror, upscale interior',
                'Skincare products flatlay on marble surface, soft daylight, minimalist beauty photography',
                'Professional makeup brushes and cosmetics palette arranged elegantly, soft pink backdrop',
            ],
            'healthcare' => [
                'Clean modern medical examination room, stethoscope on desk, professional healthcare setting',
                'Caring medical consultation between doctor and patient in modern clinic, warm welcoming light',
                'Medical instruments and charts arranged neatly on clinical surface, professional environment',
            ],
            'legal' => [
                'Prestigious law office bookshelf, leather-bound legal volumes, warm library lighting',
                'Legal contract signing desk with fountain pen and documents, professional law firm atmosphere',
                'Scales of justice statue on mahogany desk, dramatic lighting, classical legal aesthetic',
            ],
            'real_estate' => [
                'Luxury property exterior at golden hour, modern architecture, manicured landscape',
                'High-end property interior, open-concept living space with floor-to-ceiling windows',
                'Premium residential kitchen, marble countertops, designer fixtures, natural light',
            ],
            'real_estate_broker' => [
                'Real estate broker showing luxury property to client, handshake moment, elegant home interior',
                'Dubai skyline view from high-floor luxury apartment, panoramic windows at sunset',
                'Property documents being signed, premium pen on closing contract, real estate transaction moment',
            ],
            'fashion' => [
                'Elegant fashion boutique interior, curated garments on hangers, soft ambient lighting',
                'Luxury fabric texture close-up, haute couture detail, artisan craftsmanship',
                'Fashion model in editorial pose, studio lighting, minimalist backdrop',
            ],
            'technology' => [
                'Modern tech office workspace with multiple monitors showing code, clean minimalist design',
                'Server room with cascading blue LED lights, enterprise infrastructure aesthetic',
                'Developer hands typing on mechanical keyboard, dim ambient office, focused productivity',
            ],
            'marketing_agency' => [
                'Modern marketing agency office with team collaborating, monitors displaying analytics dashboards',
                'Creative brainstorm session with sticky notes and whiteboards, dynamic workspace atmosphere',
                'Social media creative assets being designed on laptop, mood board with color palette',
            ],
            'events' => [
                'Elegant wedding reception table setting, floral centerpieces, candlelight ambiance',
                'Event venue setup with dramatic uplighting, empty chairs in formation, anticipation',
                'Corporate event stage with spotlights and audience silhouettes, professional production',
            ],
            'interior_design' => [
                'Modern living room interior with designer furniture, curated art, natural light',
                'Luxury bedroom design, premium bedding, accent lighting, sophisticated color palette',
                'High-end kitchen interior, open shelving, marble island, pendant lighting',
            ],
            'education' => [
                'Modern university lecture hall with engaged students, natural light through large windows',
                'Library interior with study areas, wooden shelves, scholarly atmosphere',
                'Science laboratory class with students and microscopes, STEM education, bright lighting',
            ],
            'automotive' => [
                'Luxury car showroom floor with gleaming vehicles, polished concrete, dramatic lighting',
                'Auto technician working on engine bay, professional garage, attention to detail',
                'Premium car interior detail, leather seats and stitched dashboard, luxury cabin',
            ],
            'hospitality' => [
                'Luxury hotel lobby with grand chandelier, marble floors, elegant reception desk',
                'Premium hotel suite bedroom with panoramic city view, silk bedding, ambient lighting',
                'Resort infinity pool at sunset, tropical palms, aspirational travel destination',
            ],
            'cleaning' => [
                'Sparkling clean modern kitchen after professional service, natural light, pristine surfaces',
                'Professional cleaner in uniform using eco-friendly products in bright home interior',
                'Organized cleaning supplies and equipment arranged neatly, hygienic aesthetic',
            ],
            'construction' => [
                'Construction site with cranes and steel structure, blue sky backdrop, dynamic composition',
                'Architect reviewing blueprints on site, hard hat, focused professional atmosphere',
                'Modern building under construction, concrete and steel detail, industrial aesthetic',
            ],
            'photography' => [
                'Professional photography studio with lighting setup, seamless backdrop, camera on tripod',
                'Portrait session with natural light, artistic model composition, editorial mood',
                'Camera lens detail macro shot, aperture blades visible, photography equipment artistry',
            ],
            'childcare' => [
                'Bright colorful nursery classroom with wooden toys, cheerful learning environment',
                'Happy children playing with educational materials, safe nursery interior, natural light',
                'Teacher reading to attentive preschool children, cozy story corner, warm atmosphere',
            ],
            'consulting' => [
                'Modern executive boardroom with strategic presentation on screen, professional meeting',
                'Consultants reviewing business charts and financial data, collaborative problem-solving',
                'Corporate strategy whiteboard session with frameworks, modern consulting office',
            ],
            'finance' => [
                'Financial advisor office with charts and laptop on desk, professional consultation setting',
                'Modern accounting workspace with documents, calculator, tax preparation materials',
                'Corporate finance meeting, executives reviewing quarterly reports, professional atmosphere',
            ],
            'wellness' => [
                'Serene yoga studio with natural light, meditation cushions, calming sage tones',
                'Holistic wellness treatment room with natural materials, aromatherapy, healing atmosphere',
                'Mindfulness session outdoors with nature backdrop, peaceful contemplative mood',
            ],
            'pet_services' => [
                'Happy dog being groomed at professional salon, gentle caring hands, clean environment',
                'Modern veterinary clinic examination room, caring vet with friendly pet, bright interior',
                'Pets playing together at daycare facility, joyful atmosphere, safe indoor play area',
            ],
            'logistics' => [
                'Modern warehouse with organized shelving and forklift in action, efficient operations',
                'Shipping containers at port with cranes, global logistics, industrial scale',
                'Delivery vehicles lined up professionally, logistics fleet, transportation service',
            ],
            'architecture' => [
                'Stunning modern architecture detail, geometric forms, dramatic shadow play',
                'Architecture studio with physical models and technical drawings, creative design process',
                'Contemporary building facade close-up, materials and texture, architectural photography',
            ],
        ];

        $prompts = $map[$industry] ?? [
            "Professional {$industry} business interior in {$location}, clean modern design, warm lighting, premium atmosphere",
            "Contemporary {$industry} workspace, team collaboration, aspirational environment",
            "Premium {$industry} service in action, professional quality, attention to detail",
        ];
        $suffix = ', no text no logos no words, photorealistic';
        return array_map(fn($p) => $p . $suffix, $prompts);
    }

    /**
     * PATCH 8 (2026-05-08) — Architecture Lock Tier 1.
     *
     * Build a canonical sections_json array for an Arthur-generated page so
     * BuilderRenderer can serve the page without depending on the static
     * index.html file. Tier 1 produces a minimal-but-valid set covering the
     * 7 BuilderRenderer-supported types (header / hero / features / cta /
     * contact_form / blog_list / footer). Tier 2 will derive richer content
     * from Arthur's wizard state instead of these defaults.
     *
     * Section schema is what BuilderRenderer expects:
     *   [{"type": "...", "heading": "...", "body": "...", ...}, ...]
     */
    private function buildDefaultSectionsForPage(string $slug, array $data): array
    {
        $businessName = (string) ($data['business_name'] ?? 'Your Business');
        $industry     = (string) ($data['industry']      ?? 'business');
        $coreService  = (string) ($data['core_service']  ?? '');
        $services     = (array)  ($data['services']      ?? []);
        $location     = (string) ($data['location']      ?? '');

        $heroHeadline = $businessName;
        $heroSub      = $coreService !== ''
            ? $coreService
            : "A trusted {$industry} business" . ($location !== '' ? " in {$location}" : '');

        $featureItems = array_slice(array_filter(array_map('strval', $services)), 0, 6);

        switch ($slug) {
            case 'blog':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Latest from ' . $businessName, 'body' => 'News, updates, and stories.'],
                    ['type' => 'blog_list'],
                    ['type' => 'footer'],
                ];

            case 'home':
            default:
                $sections = [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => $heroHeadline, 'body' => $heroSub],
                ];

                if (! empty($featureItems)) {
                    $sections[] = [
                        'type'    => 'features',
                        'heading' => 'What we do',
                        'body'    => 'Services we provide:',
                        'items'   => array_map(fn($s) => ['title' => $s], $featureItems),
                    ];
                }

                $sections[] = [
                    'type'    => 'cta',
                    'heading' => "Ready to work with {$businessName}?",
                    'body'    => 'Get in touch and we\'ll be in contact shortly.',
                ];
                $sections[] = ['type' => 'contact_form', 'heading' => 'Contact us'];
                $sections[] = ['type' => 'footer'];

                return $sections;
        }
    }
}
