<?php
declare(strict_types=1);
/**
 * Help centre. The old FAQ's thirteen questions re-filed by product and rewritten to the launched truth.
 * Numbers that belong to plans are NOT written here; the page reads them from $data['plans'].
 */
return [
    ['slug' => 'getting-started', 'title' => 'Getting started', 'items' => [
        ['What is LevelUpGrowth?', 'One account that holds the assets your business runs on: a website, a CRM, a calendar, SEO, content, social planning, a chatbot, creative and video, run by a workforce of named AI specialists who propose the work and wait for your approval.'],
        ['How quickly can I start?', 'Sign up, describe the business in about two minutes, and Arthur builds the site for your industry from your brief. You edit in place and publish when it is right; most owners publish on the first day.'],
        ['Do I need technical skills?', 'No. You describe the business in plain language and approve what the workforce proposes. You never write code or configure servers.'],
        ['Is there a free plan?', '{free_plan}'],
    ]],
    ['slug' => 'workforce', 'title' => 'The AI workforce', 'items' => [
        ['Who are the specialists?', '{agents}'],
        ['How does the workforce actually work?', 'Sarah, the Digital Marketing Manager, turns your goals into a plan and briefs the specialists. Each finished task passes her review for alignment with the brief, complete metadata and provenance before it reaches you. You approve; then it goes live. Work that fails the review returns to draft and is never counted as done.'],
        ['Can I talk to the workforce?', 'You talk to Sarah, who plans and delegates, and to Aria, the platform FAQ, about how the platform works. Aria is in the sidebar on every plan; Sarah is on the plans that include the workforce, shown on the pricing page.'],
        ['What are AI credits?', 'Credits are the meter for the work: research, writing, images, video and chatbot answers each cost credits depending on complexity. Every plan from AI Lite includes a monthly allowance that resets each month; Sarah tells you the cost before running work that spends them. {credits}'],
    ]],
    ['slug' => 'website', 'title' => 'Website and hosting', 'items' => [
        ['Is hosting included?', 'Yes. Every site is published on a LevelUpGrowth subdomain with SSL, on infrastructure we operate, at no extra cost, on every plan.'],
        ['Can I connect my own domain?', '{domains}'],
        ['Can I edit the site after it is built?', 'Yes. Click any heading, paragraph, image or button on the live page and change it, or ask Arthur for larger changes in plain language: "change the hero headline", "add a testimonials section". Changes wait for your approval before they publish.'],
        ['Can I take my data with me?', 'Your contacts and leads export as a CSV at any time, and a domain bought here is registered to you. Your sites publish on your own subdomain or domain.'],
    ]],
    ['slug' => 'plans', 'title' => 'Plans and billing', 'items' => [
        ['What plans are there?', '{plans}'],
        ['Can I cancel anytime?', 'Yes. Monthly plans have no contract; cancel from your account and keep access until the end of the period you paid for. Your data and sites stay accessible for thirty days after cancellation so you can export them.'],
        ['What is the refund policy?', 'Refund terms are being finalised and will be published on the Refunds page before public launch. Until then, write to us about any billing question.'],
    ]],
];
