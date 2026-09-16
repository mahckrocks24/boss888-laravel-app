<?php
declare(strict_types=1);
/** Changelog entries, newest first. Add an entry per release; keep each item a fact. */
return [
    ['date' => '2026-09-07', 'area' => 'Website', 'title' => 'New marketing site', 'items' => [
        'Pricing, product availability and the workforce roster are read from the platform at build time; a drift check fails the build if the page and the plans table disagree.',
        'Every page ships a generated social preview image; comparison pages carry sources and a verification month.',
        'The company blog runs on the same articles table and review gate as customers\' blogs; posts carry BlogPosting schema, an RSS feed and a sitemap with last-modified dates.',
        'A live status page and a contact form that lands in the company CRM.',
    ]],
    ['date' => '2026-09-07', 'area' => 'Builder', 'title' => 'Mobile safety on every published site', 'items' => [
        'A guard injected at render, deploy and serve time means no site built here can scroll sideways on a phone; long headings wrap instead.',
        'Seven missing hero images across industry site builds restored.',
    ]],
    ['date' => '2026-09-06', 'area' => 'Workforce', 'title' => 'Sarah reviews every finished task', 'items' => [
        'Finished work is checked for alignment with the brief, complete metadata and provenance before it reaches the owner.',
        'Rejected work returns to draft, is excluded from bulk publishing and is never counted as done.',
    ]],
    ['date' => '2026-09-06', 'area' => 'Platform', 'title' => 'Engine certification', 'items' => [
        'Chat and credits, CRM, SEO and content, social, email, chatbot and the experience layer each passed a certification pass with fixes recorded.',
        'The chatbot answers only on allowed domains, captures names and phone numbers correctly, and never spends a foreign site\'s credits.',
    ]],
];
