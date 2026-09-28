<?php

namespace App\Core\Sarah888;

/**
 * MinimumPath — DEC-0029 §5 (2026-09-02): "Sarah must take the MINIMUM EXECUTION PATH necessary for the user's request."
 *
 * Measured before this class (EV-0901, Runtime 2.37.11 live): "Hi Sarah." 17.5s — the folded 80k-character
 * execution prompt went to the Runtime assistant (≈11s) and then through a 21k-token extraction call for a
 * greeting. "List the pages on Fable QA Cafe Two." 23-32s — a classifier call, a 20k-token model call to decide
 * to call a tool, the tool, and a follow-up model call to phrase two page titles.
 *
 * Deterministic decisions, none of which invents content:
 *   isLightTurn()    — a greeting/acknowledgement gets a ~1k-token reply prompt (identity + business facts +
 *                      the last exchange), one small model call, no frames, no tools, no extraction.
 *   readIntent()     — "list/show the pages|articles|leads|keywords|events|tasks" with no work verb and no
 *                      judgement asked is a READ: execute the registered read tool and render its data. Zero
 *                      model calls. Anything ambiguous falls through to the model.
 *   keywordDomains() — domains for a WORK turn, where the mode classifier does not run (saves a 7s call).
 *   toolEngines()    — which engine families the tool schema should carry for this turn, from the classifier's
 *                      domains plus explicit keyword triggers. Platform and web reads always travel.
 */
final class MinimumPath
{
    public const READ_TOOLS = [
        'pages'      => 'platform.list_pages',
        'page'       => 'platform.list_pages',
        'articles'   => 'platform.list_articles',
        'article'    => 'platform.list_articles',
        'posts'      => 'platform.list_articles',
        'blog posts' => 'platform.list_articles',
        'drafts'     => 'platform.list_articles',
        'leads'      => 'crm.list_leads',
        'keywords'   => 'seo.list_keywords',
        'events'     => 'calendar.list_events',
        'bookings'   => 'calendar.list_events',
        'tasks'      => 'platform.list_tasks',
        'websites'   => 'platform.get_published_websites',
        'sites'      => 'platform.get_published_websites',
    ];

    private const WORK_OR_JUDGEMENT = '/\b(create|write|generate|publish|delete|remove|update|edit|fix|improve|build|schedule|send|add|why|should|best|worst|need|needs|most|least|recommend|optimi[sz]e|plan|strategy|compare|better|analy[sz]e)\b/iu';

    public static function isLightTurn(string $message, bool $hasAttachments = false, ?string $quickAction = null): bool
    {
        if ($hasAttachments || ($quickAction !== null && $quickAction !== '')) return false;
        return RouterIntent::isTrivialTurn($message);
    }

    /**
     * The compact prompt for a light turn. Identity and business facts are the route's own blocks (no second
     * source of truth); the last exchange keeps continuity; the rules forbid the failure modes measured on
     * greetings (work offers, draft lists, invented numbers).
     */
    public static function lightSystemPrompt(string $identityBlock, string $brandFactsBlock, string $voiceRule, string $history, int $lastLines = 4): string
    {
        $tail = '';
        $lines = array_values(array_filter(array_map('trim', explode("\n", $history)), fn ($l) => $l !== ''));
        if ($lines) $tail = "Last exchange:\n" . implode("\n", array_slice($lines, -$lastLines)) . "\n\n";

        return trim($identityBlock) . "\n\n"
            . trim($brandFactsBlock) . "\n\n"
            . trim($voiceRule) . "\n\n"
            . $tail
            . "THIS TURN IS A GREETING OR AN ACKNOWLEDGEMENT (DEC-0029 minimum path).\n"
            . "Reply as Sarah in one or two warm, natural sentences. Do not list work, drafts, tasks or numbers. "
            . "Do not offer to queue, publish or start anything. Do not name articles or ids. "
            . "If the owner greeted you, greet them back and ask what they need.\n"
            . "Output JSON only: {\"reply\":\"...\"}\n";
    }

    /**
     * @return array{tool:string, params:array, noun:string}|null
     */
    public static function readIntent(string $message): ?array
    {
        $m = trim($message);
        if ($m === '' || mb_strlen($m) > 160) return null;
        if (preg_match(self::WORK_OR_JUDGEMENT, $m)) return null;
        // F-CB-F2 (2026-09-07): any short question about the website chatbot reads its real state first.
        if (preg_match('/\b(chat ?bot|chat widget|live chat|website chat|ai assistant on (my|the) (site|website))\b/iu', $m)
            && preg_match('/\b(is|are|set up|setup|live|enabled|on|off|working|how many|conversations?|leads?|embed|knowledge|status|which sites?|running)\b/iu', $m)
            && !preg_match('/^(please\s+)?(turn|switch|disable|enable|add|upload|delete|remove|create|mint|revoke)\b/iu', $m)) {
            return ['tool' => 'chatbot.get_state', 'params' => ['question' => $m], 'noun' => 'chatbot'];
        }
        // F-SOC-F5 (2026-09-06): social posts are the SOCIAL engine's rows, never the article list. Any short social
        // question about posts/drafts/queue/scheduled resolves here — with or without a list/show opener.
        if (\App\Core\Sarah888\SocialTurn::is($m) && preg_match('/\b(posts?|drafts?|queue|queued|scheduled|calendar)\b/iu', $m)
            && preg_match('/\b(what|which|show|list|how many|do we have|do i have|have we got|are there|any|give me|tell me)\b/iu', $m)
            && !preg_match('/^(please\s+|hey\s+sarah[,\s]+|sarah[,\s]+)?(draft|write|create|make|prepare|compose|queue|post|schedule|publish|put)\b/iu', $m)) { // F-SOC-F5d: a read cue, never an imperative
            $lower = mb_strtolower($m);
            if (preg_match('/\b(queue|queued|calendar|scheduled)\b/u', $lower) && !preg_match('/\bdrafts?\b/u', $lower)) return ['tool' => 'social.get_queue', 'params' => [], 'noun' => 'queue'];
            $params = [];
            if (preg_match('/\bdrafts?\b/u', $lower) && !preg_match('/\b(scheduled|queue|all|every)\b/u', $lower)) $params['status'] = 'draft';
            return ['tool' => 'social.list_posts', 'params' => $params, 'noun' => 'posts'];
        }
        if (!preg_match('/^(please\s+|hey\s+sarah[,\s]+|sarah[,\s]+)?(list|show( me)?|give me|what are|what pages|which pages|can you (list|show)|could you (list|show))\b/iu', $m)
            && !preg_match('/^(please\s+)?(list|show)\b/iu', $m)) {
            return null;
        }
        $lower = mb_strtolower($m);
        foreach (['blog posts', 'websites', 'sites', 'pages', 'page', 'articles', 'article', 'posts', 'drafts', 'leads', 'keywords', 'events', 'bookings', 'tasks'] as $noun) {
            if (preg_match('/\b' . preg_quote($noun, '/') . '\b/u', $lower)) {
                $tool = self::READ_TOOLS[$noun];
                $params = [];
                if ($tool === 'platform.list_articles' && $noun === 'drafts') $params['status'] = 'draft';
                return ['tool' => $tool, 'params' => $params, 'noun' => $noun];
            }
        }
        return null;
    }

    /**
     * DEC-0030 (2026-09-02): a TRUTHFUL, deterministic "work state" label for the ack response of a COMPLEX turn.
     * Returns null for a simple/deterministic turn (no progress theatre — the light/read lanes answer in 2-6s).
     * For a complex turn it names, in the present continuous, the category of workspace data the turn is about
     * to assemble into its reasoning frame — never a completed action ("I checked X"), never chain-of-thought.
     * When no domain is specific (keywordDomains returned the full set = nothing matched), it is the honest
     * generic "Working on that..." rather than a claim about data.
     *
     * @param string[] $domains  the deterministic domains for this turn (MinimumPath::keywordDomains)
     */
    public static function workState(string $message, array $domains): ?string
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') return null;

        // A plan/strategy request: name the deliverable, not the data.
        if (preg_match('/\b(plan|strategy|roadmap|30[- ]?day|60[- ]?day|90[- ]?day|next (month|quarter|week)|grow|scale)\b/u', $m)) {
            return 'Working through your growth plan';
        }

        // Nothing specific was matched (over-inclusion default) → do not claim to be looking at any one thing.
        sort($domains);
        $full = RouterIntent::DOMAINS; sort($full);
        if ($domains === $full || count($domains) >= 5 || count($domains) === 0) {
            return 'Working on that';
        }

        $phrase = [
            'seo'        => 'your search visibility',
            'content'    => 'your content',
            'crm'        => 'your leads and pipeline',
            'tasks'      => 'your work queue',
            'incident'   => 'what is failing',
            'commercial' => 'your budget and spend',
        ];
        $parts = [];
        foreach ($domains as $d) { if (isset($phrase[$d])) $parts[] = $phrase[$d]; }
        if (!$parts) return 'Working on that';

        $last = array_pop($parts);
        $joined = $parts ? implode(', ', $parts) . ' and ' . $last : $last;
        return 'Looking at ' . $joined;
    }

    /**
     * DEC-0030: is this turn SIMPLE enough that it needs no progress state at all? Deterministic, no model call —
     * a greeting/acknowledgement, a read request, or an unambiguous STATUS lookup. Everything else is treated as
     * possibly-complex and gets a truthful work state.
     */
    public static function isSimpleTurn(string $message, bool $hasAttachments = false, ?string $quickAction = null): bool
    {
        if (self::isLightTurn($message, $hasAttachments, $quickAction)) return true;
        if (self::readIntent($message) !== null) return true;
        return RouterIntent::deterministicMode($message) === RouterIntent::STATUS;
    }

    /**
     * Deterministic domains for a WORK turn (directive/authorisation), where the mode classifier does not run.
     * Measured 2026-09-02 05:08: without this, ContextSelector paid a separate 333-token domains() call (7.0s)
     * on every work turn. Keyword-derived; returns every domain when nothing matches (over-inclusion is the safe
     * failure, as ContextSelector documents).
     *
     * @return string[]
     */
    public static function keywordDomains(string $message): array
    {
        $m = mb_strtolower($message);
        $d = [];
        if (preg_match('/\b(lead|leads|crm|pipeline|contact|contacts|follow.?up|booking|bookings)\b/u', $m)) $d[] = 'crm';
        if (preg_match('/\b(seo|keyword|keywords|rank|ranking|rankings|audit|backlink|links?|orphan|orphans|meta)\b/u', $m)) $d[] = 'seo';
        if (preg_match('/\b(article|articles|blog|draft|drafts|content|write|publish|image|images|page|pages|website|websites|site|sites|social|post|posts)\b/u', $m)) $d[] = 'content';
        if (preg_match('/\b(task|tasks|queue|pending|approval|approvals|running|failed|blocked|waiting)\b/u', $m)) $d[] = 'tasks';
        if (preg_match('/\b(broken|down|failing|error|errors|incident|degraded|stuck)\b/u', $m)) $d[] = 'incident';
        if (preg_match('/\b(credit|credits|budget|spend|cost|costs|price|pricing|revenue|plan)\b/u', $m)) $d[] = 'commercial';
        return $d ?: RouterIntent::DOMAINS;
    }

    /**
     * Engine families whose tools belong in this turn's schema.
     * @param string[] $domains RouterIntent domains (crm, seo, content, tasks, incident, commercial)
     * @return string[] engines
     */
    /** CRM-SARAH-3: a question about, or an action on, the owner's clients — answered with the Clients tools. */
    public static function isClientTurn(string $message): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '' || mb_strlen($m) > 400) return false;
        if (preg_match('/\b(brief me|tell me about|what about|how is|how\'?s|update on|who (hasn\'?t|has not|didn\'?t|should i|do i need to)|heard back|waiting for a reply|not contacted|gone quiet|needs me today|to contact today)\b/u', $m)
            && (preg_match('/\b(clients?|customers?|patients?|guests?|buyers?|leads?|students?|members?|enquir|inquir|heard back|reply|contact)\b/u', $m) || self::mentionsClient($m))) return true;
        if (preg_match('/\b(book|schedule|set up|arrange|put)\b.{0,40}\b(call|meeting|appointment|viewing|follow.?up)\b.{0,30}\b(with|for)\b/u', $m)) return true;
        if (preg_match('/\b(move|mark|put)\b.{0,60}\b(to|as|in|into)\b.{0,30}\b(booked|won|lost|contacted|qualified|offer made|under contract|closed|quote sent|visited|regular|enrolled|paid)\b/u', $m) && self::mentionsClient($m)) return true;
        if (preg_match('/\b(i (just )?(called|spoke|talked|met|emailed))\b/u', $m) && self::mentionsClient($m)) return true;
        return self::mentionsClient($m) && (bool) preg_match('/\?|\b(brief|summary|status|stage|where are we|what\'?s happening)\b/u', $m);
    }

    /** CRM-SARAH-3: the message names one of the workspace's clients (full name, or first + last). */
    public static function mentionsClient(string $lowerMessage): bool
    {
        try {
            $ws = (int) (request()?->attributes->get('workspace_id') ?? 0);
            if (! $ws || mb_strlen($lowerMessage) > 600) return false;
            $names = \Illuminate\Support\Facades\Cache::remember('crm-names:' . $ws, 120, fn () => \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $ws)->whereNull('deleted_at')
                ->orderByDesc('updated_at')->limit(3000)->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter(fn ($n) => mb_strlen($n) >= 5 && str_contains($n, ' '))->unique()->values()->all());
            foreach ($names as $n) if (str_contains($lowerMessage, $n)) return true;
        } catch (\Throwable $e) {}
        return false;
    }

    public static function toolEngines(array $domains, string $message): array
    {
        $map = ['crm' => ['crm'], 'seo' => ['seo'], 'content' => ['write', 'creative'], 'tasks' => [], 'incident' => [], 'commercial' => []];
        $engines = [];
        foreach ($domains as $d) foreach ($map[$d] ?? [] as $e) $engines[$e] = true;
        $m = mb_strtolower($message);
        if (preg_match('/\b(website|websites|site|sites|page|pages|homepage|headline|hero|layout|template|builder|arthur)\b/u', $m)) $engines['builder'] = true;
        if (preg_match('/\b(social|instagram|facebook|linkedin|tiktok|twitter|hashtags?|marcus|post it)\b/u', $m)) $engines['social'] = true;
        if (preg_match('/\b(image|images|picture|photo|design|banner|visual|video|creative|creatives|artwork|graphic|graphics|mockup|poster|flyer|illustration)\b/u', $m)) $engines['creative'] = true;
        if (preg_match('/\b(calendar|meeting|booking|bookings|event|events|appointment)\b/u', $m)) $engines['calendar'] = true;
        if (preg_match('/\b(article|articles|blog|draft|drafts|content|write|meta|headline)\b/u', $m)) $engines['write'] = true;
        if (preg_match('/\b(lead|leads|crm|pipeline|contact|contacts|follow.?up)\b/u', $m)) $engines['crm'] = true;
        // CRM-SARAH-3: the owner talks about clients in their own words, or names one
        if (preg_match('/\b(clients?|customers?|patients?|guests?|buyers?|sellers?|tenants?|students?|members?|enquir(y|ies|ed)|inquir(y|ies|ed)|heard back|brief me|who (should|do) i (call|contact)|reply to|replied|booked|offer made|under contract|quote sent|no.?show|stage)\b/u', $m)
            || preg_match('/\b(book|schedule|set up|arrange|put)\b.{0,40}\b(call|meeting|appointment|viewing|follow.?up|strategy meeting)\b/u', $m)) $engines['crm'] = true;
        if (! isset($engines['crm']) && self::mentionsClient($m)) $engines['crm'] = true;
        if (preg_match('/\b(seo|keyword|keywords|rank|ranking|audit|backlink|links?)\b/u', $m)) $engines['seo'] = true;
        if (preg_match('/\b(email|newsletter|campaign)\b/u', $m)) $engines['marketing'] = true;
        if (preg_match('/\b(job|jobs|vacancy|vacancies|hiring|recruit|recruitment|employer|job portal|job board)\b/u', $m)) $engines['jobs'] = true; // KABAYAN888 JOBS-1
        return array_keys($engines);
    }
}
