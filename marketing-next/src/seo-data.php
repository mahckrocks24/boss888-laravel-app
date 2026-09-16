<?php
/**
 * A REAL audit, captured from the platform's own engine.
 *
 * Provenance: SeoService::deepAudit() run 2026-09-08 against three published showcase sites. Rows seo_audits
 * #767 (ironhaus), #768 (aurelia), #769 (northgatedental) in the live database. Nothing here is written by hand:
 * every check, status and detail string below is the engine's own output, copied verbatim. If the engine changes
 * its checks, this file is stale and must be re-captured — do not edit it to match a nicer story.
 */
return [
    'captured'  => '2026-09-08',
    'audit_id'  => 767,
    'site'      => 'ironhaus.levelupgrowth.io',
    'site_name' => 'Ironhaus',
    'what'      => 'a strength gym in Bristol, built by Arthur from one paragraph',
    'score'     => 91,
    'passed'    => 20,
    'warnings'  => 2,
    'errors'    => 0,
    'total'     => 22,
    'categories' => [
        ['Meta tags', 'meta', 100],
        ['Technical', 'technical', 100],
        ['Security', 'security', 100],
        ['Mobile', 'mobile', 100],
        ['Schema', 'schema', 100],
        ['Content', 'content', 88],
        ['Performance', 'performance', 88],
    ],
    // Verbatim from results_json.checks
    'checks' => [
        ['technical', 'URL accessible', 'pass', 'HTTP 200'],
        ['security', 'HTTPS enabled', 'pass', 'Site uses HTTPS'],
        ['meta', 'Title tag exists', 'pass', 'Title: "Ironhaus — Forging strength in the heart of Bristol."'],
        ['meta', 'Title length (30-60)', 'pass', '52 chars'],
        ['meta', 'Meta description exists', 'pass', "Ironhaus is Bristol's specialist strength & Olympic lifting gym. Join small-grou…"],
        ['meta', 'Meta description length (70-160)', 'pass', '156 chars'],
        ['meta', 'H1 tag exists', 'pass', 'H1: "Train Heavy. Lift Strong."'],
        ['meta', 'Open Graph tags', 'pass', 'OG tags present'],
        ['meta', 'Canonical URL', 'pass', 'Canonical set'],
        ['performance', 'Server response time', 'pass', '263ms'],
        ['performance', 'Page size', 'pass', '65KB'],
        ['performance', 'Image alt text', 'pass', 'All 5 images have alt text'],
        ['performance', 'Compression', 'warning', 'No GZIP detected'],
        ['mobile', 'Viewport meta tag', 'pass', 'Responsive viewport found'],
        ['content', 'Content length', 'pass', '1496 words'],
        ['content', 'Heading structure', 'pass', 'H1:1 H2:8 H3:1'],
        ['content', 'Internal links', 'pass', '20 internal links'],
        ['content', 'External links', 'warning', 'No external links'],
        ['schema', 'Structured data', 'pass', 'JSON-LD: LocalBusiness'],
        ['schema', 'Twitter Cards', 'pass', 'Twitter Card tags present'],
        ['technical', 'URL length', 'pass', '34 chars'],
        ['security', 'HSTS header', 'pass', 'HSTS enabled'],
    ],
    // The same audit, run against two more showcase sites the same day.
    'others' => [
        ['Aurelia', 'aurelia', 91, 20, 2, 0],
        ['Northgate Dental', 'northgatedental', 86, 19, 3, 0],
    ],
];
