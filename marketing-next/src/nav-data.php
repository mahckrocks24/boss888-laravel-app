<?php
declare(strict_types=1);
/**
 * ONE navigation model drives the desktop mega menu, the Resources dropdown, and the phone accordion, so lists can
 * never drift apart (the original site's rule, kept). Infrastructure items appear only when their gates are open.
 * No "templates" anywhere: every site is built by Arthur from the owner's brief (Boss ruling 2026-09-07).
 *
 * Rebuilt 2026-09-08 on the Owner's review. What was wrong:
 *   - two competing taxonomies in one panel (Own your presence / growth / operations / Your AI, and then
 *     Build / Run / Grow / Scale). A visitor does not experience that as helpful, they experience it as not
 *     knowing where to click. The customer-journey one survives; the codebase one is gone.
 *   - fifteen products, two of them "coming soon", offered to someone who has not yet been told what this is.
 *     Breadth signalled before depth is granted reads as wide and thin.
 *   - a full sentence under every item. Fifteen sentences of prose inside a dropdown is not a thing anyone reads.
 *   - "Agencies" at top level: a second audience competing with Pricing. It moves to Resources.
 *   - no "How it works", which is the first thing a stranger to an unfamiliar product wants.
 */
return function (array $data): array {
    $infra = ! empty($data['infrastructure_live']);

    // Three groups in the customer's order, not ours. Labels only: the product pages carry the sentences.
    $build = [
        ['globe', 'Website builder', '', '/next/product/website-builder/'],
        ['image', 'Creative studio', '', '/next/product/creative/'],
        ['video', 'Video', '', '/next/product/video/'],
    ];
    if ($infra) { $build[] = ['globe', 'Domains', '', '/next/product/domains/']; }

    return [
        'groups' => [
            ['title' => 'Build', 'items' => $build],
            ['title' => 'Grow', 'items' => [
                ['search', 'SEO', '', '/next/product/seo/'],
                ['pen', 'Content', '', '/next/product/content/'],
                ['share', 'Social', '', '/next/product/social/'],
            ]],
            ['title' => 'Convert', 'items' => [
                ['bot', 'Chatbot', '', '/next/product/chatbot/'],
                ['building', 'CRM', '', '/next/product/crm/'],
                ['calendar', 'Calendar', '', '/next/product/calendar/'],
            ]],
        ],
        // The journey strip duplicated the groups above, so it is gone. One model, not two.
        'journey' => [],
        'featured' => ['label' => 'All products', 'href' => '/next/product/'],
        'resources' => [
            ['building', 'Solutions by industry', '/next/solutions/'],
            ['users', 'For agencies', '/next/agencies/'],
            ['search', 'Compare', '/next/compare/'],
            ['message', 'Help centre', '/next/help/'],
            ['pen', 'Blog', '/next/blog/'],
            ['zap', 'Changelog', '/next/changelog/'],
        ],
        // "How Sarah works" is the explanation of the whole, which the nav had no room for before.
        'primary' => [['How Sarah works', '/product/ai-workforce/'], ['Solutions', '/solutions/'], ['Pricing', '/pricing/']],
    ];
};
