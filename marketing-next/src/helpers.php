<?php
declare(strict_types=1);

/** HTML-escape. */
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Lucide icons, inlined (1.5px stroke). Add to this map as pages need them. */
function icon(string $name, int $size = 20, string $class = ''): string
{
    static $paths = [
        'check'      => '<path d="M20 6 9 17l-5-5"/>',
        'alert'      => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'arrow-right'=> '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
        'menu'       => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'x'          => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'globe'      => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'users'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'calendar'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'search'     => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'pen'        => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'message'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'image'      => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'video'      => '<path d="m22 8-6 4 6 4V8Z"/><rect width="14" height="12" x="2" y="6" rx="2"/>',
        'share'      => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4"/><path d="m15.4 6.5-6.8 4"/>',
        'shield'     => '<path d="M20 13c0 5-3.5 7.5-7.7 8.8a2 2 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.6a1 1 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'zap'        => '<path d="M4 14a1 1 0 0 1-.8-1.6l9-12A1 1 0 0 1 14 1v7h6a1 1 0 0 1 .8 1.6l-9 12A1 1 0 0 1 10 21v-7z"/>',
        'building'   => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'mail'       => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'server'     => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/>',
        'bot'        => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
        'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon'       => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'layers'     => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    ];
    $d = $paths[$name] ?? $paths['check'];
    $cls = trim('icon ' . $class);
    return "<svg class=\"$cls\" width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\">$d</svg>";
}

/** Money in USD, no cents when whole. */
function money(float|int|string $amount): string
{
    $a = (float) $amount;
    return '$' . (floor($a) == $a ? number_format($a) : number_format($a, 2));
}

/** A product frame. Until the demo workspace is approved it is a clearly labelled neutral frame, never a mock. */
function product_frame(string $url, string $caption, ?string $src = null, string $alt = ''): string
{
    $body = $src
        ? '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" decoding="async">'
        : '<div class="frame-pending" role="img" aria-label="Product screenshot pending">Screenshot from the demo workspace, captured at release</div>';
    return '<figure class="frame"><div class="frame-bar"><span class="frame-url">' . e($url) . '</span></div><div class="frame-body">' . $body . '</div>'
         . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>';
}

/** Where "Start free" goes: the app once launched, the account request page before. Decided at build time from PLATFORM_PUBLIC_LAUNCHED. */
function signup_href(array $data, ?string $plan = null): string
{
    // DEC-0053 (2026-09-15): /start/ creates the account on the spot, so it is the door at either state of the launch flag.
    // The retired /app/#signup deep link still redirects there (EV-0994); nothing links to it any more.
    return '/next/start/' . ($plan ? '?plan=' . rawurlencode($plan) : '');
}

/** The system view: a composition of real elements (names and roles from the agents table, real product names). Honest, not a mock. */
function system_view(array $data): string
{
    $agents = array_values(array_filter($data['agents'], fn ($a) => empty($a['is_dmm'])));
    $roster = array_slice($agents, 0, 6);
    $initial = fn (string $n) => mb_strtoupper(mb_substr($n, 0, 1));
    $tasks = [
        ['search', 'Keyword map for the launch pages', 'James'],
        ['pen', 'Two articles, briefed and drafted', 'Priya'],
        ['share', 'Thirty-day social calendar', 'Marcus'],
    ];
    $h = '<div class="sys" aria-label="How the workforce runs your business">';
    $h .= '<div class="sys-panel"><div class="sys-h"><span class="badge badge-dmm" aria-hidden="true">S</span><div><strong>Sarah</strong><br><span>Digital Marketing Manager · this week\'s plan</span></div></div><div class="sys-plan">';
    foreach ($tasks as [$ico, $t, $who]) { $h .= '<div class="sys-task">' . icon($ico, 16) . '<span>' . e($t) . '</span><span class="who">' . e($who) . '</span></div>'; }
    $h .= '</div><div class="sys-gate"><b>Review before you see it</b><span>' . icon('check', 14) . ' on brief · ' . icon('check', 14) . ' metadata complete · ' . icon('check', 14) . ' source shown</span></div>';
    $h .= '<div class="sys-approve"><span class="btn btn-primary">Approve plan</span><span class="btn btn-secondary">Send back</span></div></div>';
    $h .= '<div class="sys-panel"><div class="sys-h"><strong>Specialists on this plan</strong><span>' . count($agents) . ' available</span></div><div class="sys-roster">';
    foreach ($roster as $a) { $h .= '<div class="sys-agent"><span class="badge badge-' . e($a['category']) . '" aria-hidden="true">' . e($initial($a['name'])) . '</span><div><b>' . e($a['name']) . '</b>' . e($a['title']) . '</div></div>'; }
    $h .= '</div></div>';
    $h .= '<div class="sys-assets"><span>' . icon('globe', 14) . 'Website</span><span>' . icon('building', 14) . 'CRM</span><span>' . icon('calendar', 14) . 'Calendar</span><span>' . icon('bot', 14) . 'Chatbot</span><span>' . icon('search', 14) . 'SEO</span><span>' . icon('image', 14) . 'Creative</span><span>' . icon('video', 14) . 'Video</span></div>';
    return $h . '</div>';
}

/** The Command Center panel from the original design: agent orbs with real names from the agents table, an example workflow, the honest label. */
function command_center(array $data): string
{
    $agents = $data['agents'];
    $lead = array_values(array_filter($agents, fn ($a) => ! empty($a['is_dmm'])))[0] ?? null;
    $others = array_values(array_filter($agents, fn ($a) => empty($a['is_dmm'])));
    // one specialist per discipline first (seo, content, social, crm), then fill to five
    $picked = []; $seen = [];
    foreach (['seo', 'content', 'social', 'crm'] as $cat) { foreach ($others as $a) { if ($a['category'] === $cat) { $picked[] = $a; $seen[$a['id']] = true; break; } } }
    foreach ($others as $a) { if (count($picked) >= 5) { break; } if (empty($seen[$a['id']])) { $picked[] = $a; $seen[$a['id']] = true; } }
    $cells = array_merge($lead ? [$lead] : [], $picked);
    $h = '<div class="cc" aria-label="Product preview">';
    $h .= '<div class="cc-bar"><span class="cc-dot" style="background:#F87171"></span><span class="cc-dot" style="background:#FBBF24"></span><span class="cc-dot" style="background:#34D399"></span><span class="cc-url">app.levelupgrowth.io/command-center</span></div>';
    $h .= '<div class="cc-head"><strong>AI Command Center</strong><span class="cc-tag">Product preview</span></div><div class="cc-agents">';
    foreach ($cells as $a) { $h .= '<div class="cc-agent"><span class="orb o-' . e($a['category']) . (! empty($a['is_dmm']) ? ' lead' : '') . '"></span>' . e($a['name']) . '</div>'; }
    $h .= '</div><div class="cc-feed"><div class="cc-feed-label">Example workflow</div>';
    $rows = [['seo', 'Research keywords for your industry'], ['content', 'Draft and publish SEO content'], ['social', 'Plan and schedule your channels for approval']];
    foreach ($rows as [$cat, $t]) { $h .= '<div class="cc-row"><span class="orb o-' . $cat . '"></span>' . e($t) . '<span class="ok">approved</span></div>'; }
    $h .= '</div><div class="cc-stats"><div class="cc-stat"><b>' . (int) $data['template_count'] . '</b><span>industries</span></div><div class="cc-stat"><b>' . count($others) . '</b><span>specialists</span></div><div class="cc-stat"><b>' . count($data['plans']) . '</b><span>plans from ' . e(money($data['plans'][0]['price_monthly'] ?? 0)) . '</span></div></div>';
    $h .= '<p class="cc-note">Illustrative preview — not live customer data</p>';
    return $h . '</div>';
}

/* ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
 * Redesign 2026-09-07 (Owner directive; baseline REPORT-MARKETING-NEXT-FORENSIC). Helpers for the new narrative.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────────────────── */

// Replacement bodies for shot() and product_shot() — mobile-first product evidence.
// A phone visitor gets the phone screen (m<name>.webp) inside a phone frame; a desktop visitor gets the desktop screen.
// Screens whose value IS the desktop view (wide dashboards, matrices) are marked desktop-only and say so.

/**
 * A real product screen.
 *
 * Two editorial decisions, made per screen rather than per device (Owner, 2026-09-08: "Do not limit the desktop view
 * and mobile view on the devices used. It must be logic and based on the better design judgment" … "you can also use
 * light mode screenshots not all dark … depending on the background of particular section").
 *
 *   $mode  — which shape of the product to show, at every viewport:
 *     'phone'       the phone capture in a phone frame. For conversations, queues and lists.
 *     'desktop'     the desktop capture in a browser frame. For calendars, tables, dashboards, the studio canvas.
 *                   On a narrow viewport it scrolls inside its own frame rather than shrinking into illegibility.
 *     'responsive'  swap on viewport, when both captures are genuinely good. The default.
 *
 *   $tone  — which theme of the app to show, so the screenshot sits on its section instead of punching a hole in it:
 *     'auto'  follow the reader's colour scheme, because a plain section is light in the light theme and dark in the
 *             dark one. Emitted as <picture> media, so only one file is fetched.
 *     'dark'  always the dark app. For a .section-dark row, which is dark in both themes.
 *
 * Files: assets/product/<name>.webp (dark, 1440×900) · m<name>.webp (dark phone, 390×844)
 *        assets/product/l<name>.webp (light) · lm<name>.webp (light phone)
 * A light or phone capture that does not exist is never invented; the mode falls back to what is on disk.
 */
function shot(string $name, string $caption, string $url = '', bool $eager = false, string $mode = 'responsive', string $tone = 'dark'): string
{
    $dir = __DIR__ . '/assets/product/';
    if (! is_file($dir . $name . '.webp')) { return ''; }
    $u = fn (string $file) => '/next/assets/product/' . $file . '.webp';

    $hasPhone      = is_file($dir . 'm' . $name . '.webp');
    $hasLight      = is_file($dir . 'l' . $name . '.webp');
    $hasLightPhone = is_file($dir . 'lm' . $name . '.webp');
    if ($mode === 'phone' && ! $hasPhone) { $mode = 'desktop'; }
    if ($tone === 'auto' && ! $hasLight) { $tone = 'dark'; }

    $attrs = ' alt="' . e($caption) . '"' . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"';
    $dim   = $mode === 'phone' ? ' width="780" height="1688"' : ' width="1440" height="900"';

    // The base image is the one a browser falls back to: the light capture when we have one, else the dark.
    if ($mode === 'phone') {
        $base    = $tone === 'auto' && $hasLightPhone ? 'lm' . $name : 'm' . $name;
        $sources = [];
        if ($tone === 'auto' && $hasLightPhone) { $sources[] = ['(prefers-color-scheme: dark)', 'm' . $name]; }
        $cls = 'frame frame-phone' . ($tone === 'dark' ? ' frame-dark' : '');
    } elseif ($mode === 'desktop') {
        // A 1440px dashboard cropped into a 390px window is a blank sliver, so on a phone we show the app's own
        // phone layout when we captured one. The desktop framing is still the point on a desktop.
        $base    = $tone === 'auto' ? 'l' . $name : $name;
        $sources = [];
        if ($tone === 'auto' && $hasPhone) { $sources[] = ['(prefers-color-scheme: dark) and (max-width:760px)', 'm' . $name]; }
        if ($hasPhone) { $sources[] = ['(max-width:760px)', $tone === 'auto' && $hasLightPhone ? 'lm' . $name : 'm' . $name]; }
        if ($tone === 'auto') { $sources[] = ['(prefers-color-scheme: dark)', $name]; }
        $cls = 'frame frame-wide' . ($hasPhone ? ' frame-wide-has-phone' : '') . ($tone === 'dark' ? ' frame-dark' : '');
    } else {
        $base    = $tone === 'auto' ? 'l' . $name : $name;
        $sources = [];
        // First match wins, so the narrowest and darkest combination is declared first.
        if ($tone === 'auto' && $hasLightPhone && $hasPhone) { $sources[] = ['(prefers-color-scheme: dark) and (max-width:760px)', 'm' . $name]; }
        if ($tone === 'auto') { $sources[] = ['(prefers-color-scheme: dark)', $name]; }
        if ($hasPhone) { $sources[] = ['(max-width:760px)', $tone === 'auto' && $hasLightPhone ? 'lm' . $name : 'm' . $name]; }
        $cls = 'frame' . ($hasPhone ? ' frame-responsive' : '') . ($tone === 'dark' ? ' frame-dark' : '');
    }

    $img = '<img src="' . $u($base) . '"' . $dim . $attrs . '>';
    $body = $img;
    if ($sources) {
        $body = '<picture>';
        foreach ($sources as [$media, $file]) { $body .= '<source media="' . $media . '" srcset="' . $u($file) . '">'; }
        $body .= $img . '</picture>';
    }

    return '<figure class="' . $cls . '"><div class="frame-bar"><span class="frame-url">' . e($url !== '' ? $url : 'app.levelupgrowth.io') . '</span></div>'
         . '<div class="frame-body">' . $body . '</div>'
         . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>';
}

/**
 * Which real screen illustrates a product page, which shape suits it, and which theme.
 * Product hero rows are plain sections, so their screens follow the reader's theme.
 */
function product_shot(string $slug): ?array
{
    $map = [
        'website-builder' => ['31-site-cakes', 'A site Arthur built from a two-minute brief, published on its own subdomain.', 'harbourlanecakes.levelupgrowth.io', 'responsive', 'dark'],
        'ai-workforce'    => ['10-sarah', 'Sarah in a demo workspace: what needs your OK, and the plan she is running.', 'app.levelupgrowth.io', 'phone', 'dark'],
        'seo'             => ['41-seo-live', 'The SEO view for one site: audit areas, tracked keywords, quick wins.', 'SEO', 'desktop', 'dark'],
        'content'         => ['46-write-live', 'The content dashboard: what is published, what is still a draft.', 'Content', 'desktop', 'dark'],
        'social'          => ['43-social-live', 'A month planned for a travel agency. Publishing needs a connected account, and the screen says so.', 'Social', 'desktop', 'dark'],
        'crm'             => ['47-crm-live', 'The pipeline, with the enquiry the chatbot captured.', 'CRM', 'desktop', 'dark'],
        'calendar'        => ['48-calendar-live', 'Events and booking requests in one view.', 'Calendar', 'desktop', 'dark'],
        'chatbot'         => ['44-chatbot-live', 'Chatbot setup: welcome, fallback, business context.', 'Chatbot', 'desktop', 'dark'],
        'creative'        => ['45-studio-live', 'The Studio, where images and video are made.', 'Studio', 'desktop', 'dark'],
        'video'           => ['45-studio-live', 'Where the video is made.', 'Studio', 'desktop', 'dark'],
        'automation'      => ['47-crm-live', 'Follow-ups and hand-offs live in the CRM.', 'CRM', 'desktop', 'dark'],
        'aria'            => ['49-workspace-live', 'The workspace Aria answers from.', 'Workspace', 'desktop', 'dark'],
        'domains'         => ['27-domains', 'Search, buy and connect, from the account.', 'Domains', 'desktop', 'dark'],
    ];
    if (empty($map[$slug])) { return null; }
    [$name, $caption, $url, $mode, $tone] = $map[$slug];
    return is_file(__DIR__ . '/assets/product/' . $name . '.webp')
        ? ['name' => $name, 'caption' => $caption, 'url' => $url, 'mode' => $mode, 'tone' => $tone]
        : null;
}

/** The primary CTA label agrees with where it goes: real signup once launched, a clearly named account request before. */
function cta_label(array $data): string
{
    // Registration is open and the free plan is real, so the label says so at either state of the launch flag.
    return 'Start free';
}

/**
 * The trial, as the platform actually grants it: at signup, every new account receives a three-day AI trial with 50 free
 * credits (EV-0916/0920/0922: register → 50 credits, 3-day trial). Not "after your first site", not tied to a paid plan.
 */
function trial_line(array $data): string
{
    return 'Three-day AI trial with 50 free credits at signup, no card';
}

/** One line per plan for the home summary, from the plan row. */
function plan_summary(array $p): string
{
    $parts = [];
    $parts[] = (int) $p['max_websites'] . ' website' . ((int) $p['max_websites'] === 1 ? '' : 's');
    if (! empty($p['features']['custom_domain'])) { $parts[] = 'custom domain'; }
    if (! empty($p['agents']['includes_dmm'])) {
        $parts[] = 'Sarah + ' . (int) $p['agents']['count'] . ' specialist' . ((int) $p['agents']['count'] === 1 ? '' : 's');
        $parts[] = number_format((int) $p['credits_per_month']) . ' credits/month';
    } else {
        $parts[] = 'no ongoing AI (build with Arthur only)';
    }
    return implode(' · ', $parts);
}

/** The workforce line for a plan, from the plan row: count and registry level, with the wording bug fixed. */
function workforce_line(array $p): string
{
    $a = $p['agents'];
    if (empty($a['includes_dmm']) && (int) ($a['count'] ?? 0) === 0) { return 'No AI workforce'; }
    $parts = [];
    if (! empty($a['includes_dmm'])) { $parts[] = 'Sarah, Digital Marketing Manager'; }
    if ((int) $a['count'] > 0) {
        $level = (string) ($a['level'] ?? '');
        $parts[] = (int) $a['count'] . ' specialist' . ((int) $a['count'] > 1 ? 's' : '') . ($level !== '' ? ' (' . $level . ' level)' : '');
    }
    return implode(' + ', $parts);
}

/** Home FAQ: four questions people ask first, answered from the record. */
function home_faq(array $data): array
{
    $lite = array_values(array_filter($data['plans'], fn ($p) => $p['slug'] === 'ai-lite'))[0] ?? null;
    return [
        ['Does anything go live without me?', 'No. Every publish, post or send waits for your approval, and Sarah reviews finished work for alignment, metadata and provenance before it reaches you. Rejected work returns to draft and is never counted as done.'],
        ['What does it cost?', 'Free is $0 with one website and no ongoing AI. The AI Growth OS starts on ' . ($lite ? $lite['name'] . ' at ' . money($lite['price_monthly']) . '/month with ' . number_format((int) $lite['credits_per_month']) . ' credits' : 'AI Lite') . '. Credits meter the workforce\'s work; Sarah states the cost before anything runs and every credit is visible in Billing.'],
        ['Do I own what gets built?', 'Yes. Your sites publish on your own subdomain or domain, your domain is registered to you, and your contacts and leads export as a CSV at any time.'],
        ['Can one account run more than one website?', 'Yes. A workspace is your business and can hold several websites, all run by the same Sarah, the same specialists and one credit balance. Limits per plan are on the pricing page.'],
    ];
}

/**
 * A readable DETAIL of a real screen, instead of a whole window shrunk until its numbers are unreadable.
 *
 * The region is expressed in fractions of the source image, which is how you actually think about a crop:
 *   'l' left edge, 't' top edge, 'w' width — all 0..1 of the image.
 * The zoom follows from the width, and because a CSS translate percentage is relative to the element's own size,
 * the offset is simply the region's own left and top. No magic numbers in the markup.
 *
 * Options: l, t, w (region), ratio ('16'|'4'|'1'|'tall'), tone ('auto'|'dark'), eager (bool), note (string).
 */
function crop(string $name, string $alt, array $o = []): string
{
    $dir = __DIR__ . '/assets/product/';
    if (! is_file($dir . $name . '.webp')) { return ''; }
    $l = max(0.0, min(1.0, (float) ($o['l'] ?? 0)));
    $t = max(0.0, min(1.0, (float) ($o['t'] ?? 0)));
    $w = max(0.05, min(1.0, (float) ($o['w'] ?? 1)));
    $z = round(100 / $w);
    // The site is one dark world, so a screen is the dark app unless a page asks otherwise.
    $tone = $o['tone'] ?? 'dark';
    $hasLight = is_file($dir . 'l' . $name . '.webp');
    if ($tone === 'auto' && ! $hasLight) { $tone = 'dark'; }
    $ratio = ['16' => 'crop-16', '4' => 'crop-4', '1' => 'crop-1', 'tall' => 'crop-tall'][$o['ratio'] ?? '16'] ?? 'crop-16';
    $load = ! empty($o['eager']) ? ' fetchpriority="high"' : ' loading="lazy"';

    $u = fn (string $f) => '/next/assets/product/' . $f . '.webp';
    $img = '<img src="' . $u($tone === 'auto' ? 'l' . $name : $name) . '" alt="' . e($alt) . '"' . $load . ' decoding="async">';
    if ($tone === 'auto') {
        $img = '<picture><source media="(prefers-color-scheme: dark)" srcset="' . $u($name) . '">' . $img . '</picture>';
    }

    $style = '--z:' . $z . '%;--x:' . round(-$l * 100, 2) . '%;--y:' . round(-$t * 100, 2) . '%';
    $cls = 'crop ' . $ratio . ($tone === 'dark' ? ' crop-dark' : '');
    $note = (string) ($o['note'] ?? '');

    return '<figure class="crop-fig"><div class="' . $cls . '" style="' . $style . '">' . $img . '</div>'
         . ($note !== '' ? '<figcaption class="crop-note">' . e($note) . '</figcaption>' : '') . '</figure>';
}
