<?php
declare(strict_types=1);
/**
 * Legal pages, ported at build time from the old site's drafts (read-only: public/marketing/pages/*.html).
 * The old drafts carry [[OWNER:KEY]] placeholders for business facts nobody but Boss can supply (legal entity,
 * registered address, effective date, contact addresses). They are rendered as visible "to confirm" marks, never
 * invented. Refunds and the AI disclosure are updated to the launched truth.
 */
$old = dirname(__DIR__, 2) . '/public/marketing/pages';   // P0-1: src moved out of the webroot
$map = ['terms' => 'terms.html', 'privacy' => 'privacy.html', 'cookies' => 'cookies.html', 'refunds' => 'refund.html', 'ai-disclosure' => 'ai-disclosure.html'];
$titles = ['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy', 'cookies' => 'Cookie Policy', 'refunds' => 'Refunds and Billing', 'ai-disclosure' => 'AI Disclosure'];
$pages = []; $ownerKeys = [];
foreach ($map as $slug => $file) {
    $html = @file_get_contents("$old/$file") ?: '';
    $body = '';
    if (preg_match('#<div class="legal">(.*?)<div id="site-footer-placeholder">#s', $html, $m)) { $body = $m[1]; }
    $body = preg_replace('#<div class="draft-banner">.*?</div>#s', '', $body) ?? $body;
    $body = preg_replace('#<h1>.*?</h1>#s', '', $body, 1) ?? $body;
    $body = preg_replace_callback('#<span class="ph">\[\[OWNER:([A-Z_]+)\]\]</span>#', function ($mm) use (&$ownerKeys) { $ownerKeys[$mm[1]] = true; return '<mark class="owner" data-key="' . $mm[1] . '">to be confirmed before launch</mark>'; /* P0-4: never a literal placeholder word on a public page */ }, $body) ?? $body;
    $body = preg_replace('#\[\[OWNER:([A-Z_]+)\]\]#', '<mark class="owner">to be confirmed before launch</mark>', $body) ?? $body;
    $body = preg_replace('#<script\b.*?</script>#s', '', $body) ?? $body;
    // old-site links → new routes
    $body = strtr($body, ['href="/pages/terms/"' => 'href="/next/legal/terms/"', 'href="/pages/privacy/"' => 'href="/next/legal/privacy/"', 'href="/pages/cookies/"' => 'href="/next/legal/cookies/"', 'href="/pages/refund/"' => 'href="/next/legal/refunds/"', 'href="/pages/ai-disclosure/"' => 'href="/next/legal/ai-disclosure/"', 'href="/pages/automation/"' => 'href="/next/product/automation/"', 'href="/pages/pricing/"' => 'href="/next/pricing/"']);
    // launched truths
    if ($slug === 'refunds') {
        // P0-4 (DEC-0043 §4): the refund window is an Owner-held legal fact (REFUND_WINDOW_AND_TERMS); nothing is asserted until it is set.
        $body = preg_replace('#<h2>4\. Refunds</h2>.*?(?=<h2>5\.)#s', '<h2>4. Refunds</h2><p>Refund terms are being finalised and will be published here before public launch. Cancel at any time from Billing; the plan runs to the end of the period you paid for. For any billing question, <a href="/next/contact/">write to us</a>.</p>', $body, 1) ?? $body;
    }
    if ($slug === 'ai-disclosure') {
        // DEC-0054 (2026-09-15): Aria is the platform FAQ, not a workspace-reading assistant.
        $body = strtr($body, [
            '<strong>Aria — the platform intelligence assistant.</strong> Answers questions about your own workspace (your agents, tasks, websites and data) and directs you around the platform.' => '<strong>Aria — the platform FAQ.</strong> Answers questions about how the platform works from the platform documentation, reads your plan name and credit balance, and directs you around the app. She does not execute work.',
            'Aria answers from your live platform data and is designed not to invent information' => 'Aria answers from the platform documentation and is designed not to invent information',
        ]);
        $body = preg_replace('#<h2>2\. You stay in control</h2>#', '<h2>2. You stay in control</h2><p>Every task the workforce finishes is reviewed by Sarah, the Digital Marketing Manager, for alignment with your brief, complete metadata and provenance before it reaches you. Work that fails that review returns to draft and is never counted as done or included in a bulk publish. Nothing publishes, posts or sends without your approval.</p><p>On our own blog, posts are written by Sarah on the same rails and carry her byline; this page is linked from every post.</p>', $body, 1) ?? $body;
    }
    $pages[] = ['slug' => $slug, 'title' => $titles[$slug], 'body' => trim($body), 'source' => $file];
}
return ['pages' => $pages, 'owner_keys' => array_keys($ownerKeys)];
