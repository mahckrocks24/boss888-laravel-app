<?php
/** @var array $page */ /** @var array $data */ /** @var array $p (product) */
/*
 * WORKFORCE-PAGE-1 (Owner 2026-10-05: "this page is 3/10. it does not give justice to our capabilities, intelligence,
 * automation etc. both on design and copy"). The page sells the AI workforce as a managed team: Sarah, the Digital Marketing
 * Manager, and the specialists in four teams (from the agents table), how work flows from a goal to a result, what Sarah knows
 * and remembers (RFC-0023: owner model, outcome ledger, recall, anticipation, repair), how she watches and adapts (RFC-0019:
 * signals, market watch, check-ins), the review every task passes (SARAH-QA-1) and the approval gates (DEC-0018: one by one or
 * the plan once with a spend ceiling). It says what Sarah does, never how (SECRET-1), and never names a vendor (DEC-0047).
 */
$page['title'] = 'Your AI marketing team, managed by Sarah';
$page['description'] = 'Sarah, your Digital Marketing Manager, runs a team of AI specialists across content, SEO, social and leads. She plans from your goals, briefs the team, reviews every piece of work and brings it to you to approve. She remembers your business and adapts to what works.';
$mk = function (string $f): string { $q = __DIR__ . '/assets/mk/' . $f; return '/next/assets/mk/' . $f . (is_file($q) ? '?v=' . substr(md5_file($q), 0, 8) : ''); };
$agentImg = fn (string $slug) => '/img/agents/' . $slug . '.webp';
$page['head'] = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('lug-glass.css')) . '">' . "\n" . '<link rel="stylesheet" href="' . e($mk('home.css')) . '">';
$plans = $data['plans'];
$aiPlans = array_values(array_filter($plans, fn ($pl) => ! empty($pl['agents']['includes_dmm'])));
$first = $aiPlans[0] ?? null;
$signup = e(signup_href($data, $first['slug'] ?? null));
$perPlan = (int) ($first['agents']['count'] ?? 5);
$check = '<svg viewBox="0 0 24 24" class="ic" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
$arrow = '<svg class="ic ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$lock = '<svg viewBox="0 0 24 24" class="ic" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';

// the team, from the agents table (the same roster the app uses)
$team = ['content' => [], 'seo' => [], 'social' => [], 'crm' => []];
foreach ($data['agents'] as $a) { if (empty($a['is_dmm']) && isset($team[$a['category']])) $team[$a['category']][] = $a; }
$specialists = array_sum(array_map('count', $team));
$teams = [
  'content' => ['Content', 'Articles, pages, copy and video scripts in your voice, planned around what your customers search for.', '/next/product/content/', ['Website articles and guides', 'Page and offer copy', 'Social captions and scripts', 'A content plan for the season']],
  'seo'     => ['SEO', 'Finds what holds your site back, fixes it and grows the searches you show up for, locally and abroad.', '/next/product/seo/', ['Technical fixes found and handed over', 'Keywords tracked every week', 'Local listings and reviews', 'Links that point to you']],
  'social'  => ['Social', 'Plans the month, designs every post in your brand, publishes when your audience is online and answers comments.', '/next/product/social/', ['A 30-day plan per network', 'Posts designed in your colours', 'Replies to every comment', 'Buying comments become leads']],
  'crm'     => ['Leads and growth', 'Catches every enquiry in one place, drafts a reply within a minute and brings you a list of who to contact each morning.', '/next/product/crm/', ['Every enquiry in one place', 'A reply drafted in about a minute', 'Who to contact, every morning', 'A brief and next step per client']],
];
$faq = [
  ['Who is Sarah?', 'Sarah is your Digital Marketing Manager. She is the one you talk to: she turns your goals into a plan, briefs the specialists, checks their work and brings it to you. Every AI plan includes her.'],
  ['How many specialists do I get?', 'Every AI plan includes Sarah and ' . $perPlan . ' specialists, chosen for your business. Plans differ by credits, websites and team seats, not by how smart the team is. You can add more specialists for a monthly price.'],
  ['Does anything go live without me?', 'No. Every publish, post, send and spend waits for your approval. You can approve items one by one, or approve a campaign once with a spending limit; Sarah then comes back only if the plan changes or would cost more.'],
  ['What does Sarah remember?', 'What you tell her about your business, your goals and your preferences, what you liked and what you turned down, and what worked in every past campaign. You can ask her what she knows, and to change or forget anything.'],
  ['Will she act on her own?', 'She watches your site, your channels, your competitors and your market every day, and drafts what she would do next. Anything that changes what customers see, or spends credits, comes to you first.'],
  ['How are credits used?', 'Each piece of work costs credits for the research, writing, images or video it needs. Sarah tells you the cost before she starts, and the credits come from your plan\'s monthly allowance.'],
  ['What if the work is not right?', 'Decline it with a reason and Sarah has it redone. Work that fails her own review is sent back to draft before you ever see it, and it is never counted as done.'],
  ['Where do I talk to her?', 'In the chat inside your account, and on your phone in the companion app, where you also approve work and get notified when something needs you.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'AI workforce by LevelUpGrowth', 'provider' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth'], 'description' => $page['description'], 'url' => 'https://levelupgrowth.io/product/ai-workforce/'];
$av = fn (string $slug, int $s = 32, string $cls = '') => '<img class="lg-avatar' . $cls . '" src="' . e($agentImg($slug)) . '" alt="" width="' . $s . '" height="' . $s . '" style="width:' . $s . 'px;height:' . $s . 'px;flex:none" loading="lazy" decoding="async">';
?>
<style>
  .aw .aw-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.04fr);gap:clamp(32px,5vw,64px);align-items:center;padding-top:clamp(64px,6vw,88px)}
  .aw .aw-h1{font-size:clamp(36px,5vw,60px);line-height:1.04;font-weight:800;letter-spacing:-.035em;margin:0;color:var(--ink);text-wrap:balance}
  .aw .aw-crumbs{display:flex;gap:8px;font-size:13px;color:var(--ink-3)}.aw .aw-crumbs a{color:var(--ink-2);text-decoration:none}
  .aw .aw-cta{display:flex;gap:12px;flex-wrap:wrap}
  .aw .aw-proof{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-width:560px}
  .aw .aw-proof div{border-radius:16px;padding:12px 14px;display:flex;flex-direction:column;gap:2px}
  .aw .aw-proof b{font:800 22px/1.1 var(--font);letter-spacing:-.02em;color:var(--ink)}.aw .aw-proof span{font-size:12.5px;line-height:17px;color:var(--ink-2)}
  .aw .aw-head{display:flex;flex-direction:column;gap:14px;max-width:780px;margin-bottom:28px}
  .aw .aw-head--c{margin-inline:auto;text-align:center;align-items:center}

  /* hero scene: Sarah in the middle, the four teams around her, the week's work moving through */
  .aw .tv{position:relative;width:100%;max-width:620px;margin-left:auto;aspect-ratio:1/1;container-type:inline-size}
  .aw .tv-glow{position:absolute;inset:10%;border-radius:50%;background:radial-gradient(closest-side,rgba(124,58,237,.34),rgba(37,99,235,.16) 55%,transparent);filter:blur(12px)}
  .aw .tv-lines{position:absolute;inset:0;width:100%;height:100%;z-index:1}
  .aw .tv-lines path{fill:none;stroke:url(#aw-grad);stroke-width:.35;stroke-dasharray:1.2 1.2;opacity:.7}
  .aw .tv-sarah{position:absolute;left:30cqw;top:33cqw;width:40cqw;z-index:3;border-radius:4cqw;padding:3cqw;display:flex;flex-direction:column;gap:1.8cqw;align-items:center;text-align:center}
  .aw .tv-sarah img{width:13cqw;height:13cqw;border-radius:50%;box-shadow:0 0 0 .8cqw rgba(124,58,237,.18)}
  .aw .tv-sarah > div{display:flex;flex-direction:column;gap:.6cqw}
  .aw .tv-sarah b{font:800 3.4cqw/1.1 var(--font);color:var(--ink)}.aw .tv-sarah small{font-size:2.3cqw;color:var(--ink-3)}
  .aw .tv-sarah .tv-live{display:inline-flex;align-items:center;gap:1cqw;font:700 2.1cqw/1 var(--font);color:var(--success);background:var(--success-soft,rgba(16,185,129,.14));padding:1cqw 1.8cqw;border-radius:3cqw}
  .aw .tv-sarah .tv-live i{width:1.4cqw;height:1.4cqw;border-radius:50%;background:currentColor}
  .aw .tv-pod{position:absolute;z-index:2;width:30cqw;border-radius:3.4cqw;padding:2.2cqw 2.4cqw;display:flex;flex-direction:column;gap:1.3cqw}
  .aw .tv-pod b{font:800 2.6cqw/1.1 var(--font);color:var(--ink)}
  .aw .tv-faces{display:flex}.aw .tv-faces img{width:6cqw;height:6cqw;border-radius:50%;margin-right:-1.6cqw;box-shadow:0 0 0 .45cqw var(--raised,#fff)}
  .aw .tv-pod span{font-size:2.05cqw;line-height:1.35;color:var(--ink-2)}
  .aw .tv-pod--content{left:0;top:2cqw}.aw .tv-pod--seo{right:0;top:2cqw}.aw .tv-pod--social{left:0;bottom:2cqw}.aw .tv-pod--crm{right:0;bottom:2cqw}
  .aw .tv-chip{position:absolute;z-index:4;display:flex;align-items:center;gap:1.4cqw;padding:1.4cqw 2cqw;border-radius:3cqw;font-size:2.05cqw;line-height:1.25;color:var(--ink);max-width:38cqw}
  .aw .tv-chip img{width:4.6cqw;height:4.6cqw;border-radius:50%;flex:none}
  .aw .tv-chip small{display:block;color:var(--ink-3);font-size:1.75cqw}
  .aw .tv-chip--a{left:30cqw;top:23cqw}.aw .tv-chip--b{right:0;top:47cqw;max-width:28cqw}.aw .tv-chip--c{left:0;top:47cqw;max-width:28cqw}
  .aw .tv-ok{width:4.6cqw;height:4.6cqw;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb));color:#fff}.aw .tv-ok svg{width:2.6cqw;height:2.6cqw}

  /* the work loop */
  .aw .aw-loop{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;position:relative}
  .aw .aw-loop:before{content:"";position:absolute;left:5%;right:5%;top:36px;height:2px;background:linear-gradient(90deg,transparent,var(--accent-text,#7c3aed),transparent);opacity:.35}
  .aw .aw-step{border-radius:20px;padding:16px;display:flex;flex-direction:column;gap:9px;position:relative}
  .aw .aw-step__n{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font:800 16px/1 var(--font);color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}
  .aw .aw-step b{font-size:15.5px;color:var(--ink)}.aw .aw-step p{margin:0;font-size:13.5px;line-height:20px;color:var(--ink-2)}
  .aw .aw-step__who{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink-3);margin-top:auto}
  .aw .aw-step--you .aw-step__n{background:var(--ink);color:var(--raised,#fff)}

  /* the four teams */
  .aw .aw-teams{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .aw .aw-team{border-radius:24px;padding:22px;display:flex;flex-direction:column;gap:14px}
  .aw .aw-team__top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
  .aw .aw-team h3{margin:0;font-size:21px;letter-spacing:-.01em;color:var(--ink)}.aw .aw-team p{margin:0;font-size:14.5px;line-height:22px;color:var(--ink-2)}
  .aw .aw-team a.aw-more{display:inline-flex;align-items:center;gap:4px;font:600 13.5px/1 var(--font);color:var(--accent-text);text-decoration:none;white-space:nowrap}
  .aw .aw-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
  .aw .aw-person{display:flex;gap:8px;align-items:center;padding:8px 10px;border-radius:14px;background:var(--fill-hover);min-width:0}
  .aw .aw-person div{min-width:0}.aw .aw-person b{display:block;font-size:13.5px;color:var(--ink)}.aw .aw-person span{display:block;font-size:11.5px;line-height:15px;color:var(--ink-3)}
  .aw .aw-does{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 14px;margin:0;padding:0;list-style:none}
  .aw .aw-does li{display:flex;gap:8px;font-size:13.5px;line-height:19px;color:var(--ink-2)}.aw .aw-does svg{flex:none;width:16px;height:16px;margin-top:2px;color:var(--success)}

  /* intelligence */
  .aw .aw-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start}
  .aw .aw-caps{display:flex;flex-direction:column;gap:10px}
  .aw .aw-cap{display:flex;gap:14px;padding:14px 16px;border-radius:18px}
  .aw .aw-cap__i{width:38px;height:38px;border-radius:12px;flex:none;display:grid;place-items:center;color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}.aw .aw-cap__i svg{width:19px;height:19px}
  .aw .aw-cap b{display:block;font-size:15.5px;color:var(--ink)}.aw .aw-cap div > span{display:block;font-size:14px;line-height:21px;color:var(--ink-2)}
  .aw .aw-know{border-radius:24px;padding:20px;display:flex;flex-direction:column;gap:14px}
  .aw .aw-know__h{display:flex;gap:12px;align-items:center}
  .aw .aw-know__h b{display:block;font-size:16px;color:var(--ink)}.aw .aw-know__h span{font-size:12.5px;color:var(--ink-3)}
  .aw .aw-kgrp{display:flex;flex-direction:column;gap:8px}
  .aw .aw-kgrp h4{margin:0;font:700 11.5px/1 var(--font);letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3)}
  .aw .aw-tags{display:flex;gap:6px;flex-wrap:wrap}
  .aw .aw-tag{font:600 12.5px/1 var(--font);padding:7px 10px;border-radius:999px;background:var(--fill-hover);color:var(--ink)}
  .aw .aw-tag--ok{background:var(--success-soft,rgba(16,185,129,.14));color:var(--success)}
  .aw .aw-tag--no{background:rgba(220,38,38,.1);color:#dc2626}
  .aw .aw-row{display:flex;justify-content:space-between;gap:12px;font-size:13.5px;color:var(--ink-2);padding:8px 0;border-top:1px solid var(--hairline)}
  .aw .aw-row:first-child{border-top:0}.aw .aw-row b{color:var(--ink);font-variant-numeric:tabular-nums;text-align:right}
  .aw .aw-bubble{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:18px;font-size:14px;line-height:21px;color:var(--ink)}

  /* watches and adapts */
  .aw .aw-watch{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1.1fr);gap:16px;align-items:center}
  .aw .aw-sigs{border-radius:22px;padding:18px;display:flex;flex-direction:column}
  .aw .aw-sig{display:flex;gap:10px;align-items:center;padding:10px 0;border-top:1px solid var(--hairline);font-size:14px;color:var(--ink-2)}
  .aw .aw-sig:first-of-type{border-top:0}.aw .aw-sig b{color:var(--ink)}
  .aw .aw-sig i{width:30px;height:30px;border-radius:10px;flex:none;display:grid;place-items:center;font-style:normal;background:var(--fill-hover);color:var(--accent-text)}.aw .aw-sig i svg{width:16px;height:16px}
  .aw .aw-arrow{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}
  .aw .aw-decide{border-radius:22px;padding:18px;display:flex;flex-direction:column;gap:12px}
  .aw .aw-rhythm{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}
  .aw .aw-rhythm div{border-radius:16px;padding:14px;font-size:13.5px;line-height:20px;color:var(--ink-2)}
  .aw .aw-rhythm b{display:block;color:var(--ink);font-size:14.5px;margin-bottom:2px}.aw .aw-rhythm small{display:block;font:700 11px/1 var(--font);letter-spacing:.08em;text-transform:uppercase;color:var(--accent-text);margin-bottom:8px}

  /* quality and approvals */
  .aw .aw-gates{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .aw .aw-gate{border-radius:22px;padding:20px;display:flex;flex-direction:column;gap:12px}
  .aw .aw-gate h3{margin:0;font-size:18px;color:var(--ink)}.aw .aw-gate p{margin:0;font-size:14px;line-height:21px;color:var(--ink-2)}
  .aw .aw-checks{display:flex;flex-direction:column;gap:7px;margin:0;padding:0;list-style:none}
  .aw .aw-checks li{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;color:var(--ink-2);padding:7px 10px;border-radius:10px;background:var(--fill-hover)}
  .aw .aw-checks li b{color:var(--success);font-weight:700}.aw .aw-checks li b.no{color:#dc2626}
  .aw .aw-mode{border-radius:14px;padding:12px;border:1px solid var(--hairline);display:flex;flex-direction:column;gap:6px;font-size:13.5px;color:var(--ink-2)}
  .aw .aw-mode b{color:var(--ink);font-size:14.5px}
  .aw .aw-waits{display:flex;flex-direction:column;gap:8px;margin:0;padding:0;list-style:none}
  .aw .aw-waits li{display:flex;gap:10px;font-size:14px;line-height:20px;color:var(--ink-2)}.aw .aw-waits svg{flex:none;width:17px;height:17px;margin-top:2px;color:var(--accent-text)}

  /* where you work with her */
  .aw .aw-where{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .aw .aw-wcard{border-radius:22px;padding:20px;display:flex;flex-direction:column;gap:10px}
  .aw .aw-wcard h3{margin:0;font-size:18px;color:var(--ink)}.aw .aw-wcard p{margin:0;font-size:14px;line-height:21px;color:var(--ink-2)}
  .aw .aw-wcard a{display:inline-flex;align-items:center;gap:4px;font:600 13.5px/1 var(--font);color:var(--accent-text);text-decoration:none;margin-top:auto}

  .aw .aw-plans{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .aw .aw-plan{border-radius:20px;padding:18px;display:flex;flex-direction:column;gap:8px;text-decoration:none;color:var(--ink)}
  .aw .aw-plan b{font-size:17px}.aw .aw-plan .aw-price{font:800 26px/1 var(--font);letter-spacing:-.02em}.aw .aw-plan .aw-price small{font:500 13px/1 var(--font);color:var(--ink-3)}
  .aw .aw-plan span{font-size:13px;line-height:19px;color:var(--ink-2)}
  .aw .aw-faq{max-width:820px;margin:0 auto}
  .aw .aw-final{border-radius:28px;padding:clamp(28px,5vw,56px);text-align:center;display:flex;flex-direction:column;align-items:center;gap:16px}
  .aw .aw-final .aw-faces{display:flex;justify-content:center}.aw .aw-final .aw-faces img{width:44px;height:44px;border-radius:50%;margin-right:-10px;box-shadow:0 0 0 3px var(--raised,#fff)}

  @media (max-width:1100px){.aw .aw-loop{grid-template-columns:repeat(3,minmax(0,1fr))}.aw .aw-loop:before{display:none}.aw .aw-plans{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media (max-width:900px){.aw .aw-hero,.aw .aw-teams,.aw .aw-split,.aw .aw-gates,.aw .aw-where{grid-template-columns:1fr}.aw .tv{margin:8px auto 0}.aw .aw-watch{grid-template-columns:1fr}.aw .aw-arrow{transform:rotate(90deg);justify-self:center}.aw .aw-rhythm{grid-template-columns:repeat(2,minmax(0,1fr))}.aw .aw-proof b{font-size:16px}.aw .aw-proof{gap:8px}.aw .aw-proof div{padding:10px}}
  @media (max-width:600px){.aw .aw-loop,.aw .aw-plans,.aw .aw-does{grid-template-columns:1fr}.aw .aw-rhythm{grid-template-columns:1fr}
    .aw .tv{aspect-ratio:1/1.12}.aw .tv-pod{width:44cqw;padding:2.6cqw}.aw .tv-pod b{font-size:3.6cqw}.aw .tv-pod span{display:none}.aw .tv-faces img{width:8cqw;height:8cqw}
    .aw .tv-sarah{left:22cqw;top:38cqw;width:56cqw}.aw .tv-sarah b{font-size:5cqw}.aw .tv-sarah small{font-size:3.4cqw}.aw .tv-sarah img{width:18cqw;height:18cqw}.aw .tv-sarah .tv-live{font-size:3.2cqw}
    .aw .tv-chip{display:none}.aw .tv-pod--social,.aw .tv-pod--crm{bottom:0}}
</style>
<div class="lg mk aw">

  <section class="mk-sec" style="padding-top:0;padding-bottom:40px">
    <div class="mk-wrap aw-hero">
      <div class="lg-stack gap-4">
        <nav class="aw-crumbs" aria-label="Breadcrumb"><a href="/next/product/">Products</a><span>/</span><span>AI workforce</span></nav>
        <span class="lg-badge lg-badge--brand" style="align-self:flex-start;height:30px;padding:0 12px;border-radius:999px"><span class="lg-dot"></span>Your AI marketing team</span>
        <h1 class="aw-h1">A marketing team that works around the clock. <span class="t-grad">You stay in charge.</span></h1>
        <p class="mk-lead">Sarah, your Digital Marketing Manager, turns your goals into a plan and runs a team of specialists across content, SEO, social and leads. She checks every piece of work, brings it to you to approve, and learns what works for your business.</p>
        <div class="aw-cta"><a class="lg-btn lg-btn--primary lg-btn--lg" href="<?= $signup ?>" data-lu-signup><?= e(cta_label($data)) ?> <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="#how">See how the team works</a></div>
        <div class="aw-proof">
          <div class="lg-glass lg-glass--thin"><b><?= (int) $specialists ?> specialists</b><span>across four teams, managed by Sarah</span></div>
          <div class="lg-glass lg-glass--thin"><b>24/7</b><span>watching your site, channels and market</span></div>
          <div class="lg-glass lg-glass--thin"><b>Your yes</b><span>before anything goes live or spends</span></div>
        </div>
      </div>
      <div class="tv" aria-label="Example: Sarah in the middle of the four teams, with this week's work moving through">
        <div class="tv-glow"></div>
        <svg class="tv-lines" viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="aw-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8C25D2"/><stop offset=".55" stop-color="#4C86DE"/><stop offset="1" stop-color="#3FDFDF"/></linearGradient></defs>
          <path d="M30 22 C 40 30, 42 36, 46 40"/><path d="M70 22 C 60 30, 58 36, 54 40"/><path d="M30 80 C 40 72, 42 66, 46 62"/><path d="M70 80 C 60 72, 58 66, 54 62"/></svg>
        <?php $pods = ['content' => 'Writing this week\'s articles', 'seo' => '3 fixes found on your site', 'social' => '12 posts planned for October', 'crm' => '2 enquiry replies drafted']; foreach ($pods as $k => $line): ?>
        <div class="tv-pod tv-pod--<?= $k ?> lg-glass lg-glass--thick"><b><?= e($teams[$k][0]) ?></b><div class="tv-faces"><?php foreach (array_slice($team[$k], 0, 5) as $a): ?><img src="<?= e($agentImg($a['slug'])) ?>" alt="" width="40" height="40" loading="lazy" decoding="async"><?php endforeach; ?></div><span><?= e($line) ?></span></div>
        <?php endforeach; ?>
        <div class="tv-sarah lg-glass lg-glass--thick"><img src="<?= e($agentImg('sarah')) ?>" alt="" width="80" height="80"><div><b>Sarah</b><small>Digital Marketing Manager</small></div><span class="tv-live"><i></i>Running your October plan</span></div>
        <div class="tv-chip tv-chip--a lg-glass lg-glass--thick"><img src="<?= e($agentImg('priya')) ?>" alt="" width="30" height="30"><span>Article drafted<small>Priya · with Sarah for review</small></span></div>
        <div class="tv-chip tv-chip--b lg-glass lg-glass--thick"><span class="tv-ok"><?= $check ?></span><span>3 items need your yes<small>Approve in one tap</small></span></div>
        <div class="tv-chip tv-chip--c lg-glass lg-glass--thick"><img src="<?= e($agentImg('elena')) ?>" alt="" width="30" height="30"><span>New enquiry from Instagram<small>Elena · added to Clients</small></span></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="how" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">How the team works</span><h2 class="mk-h2">From your goal to a result, with a manager in between.</h2><p class="mk-lead">You never brief six people. You tell Sarah what you want; she plans it, gives each part to the right specialist, checks the work and brings it to you finished.</p></div>
      <div class="aw-loop">
        <?php foreach ([
          ['You set the goal', 'More bookings for the slow months, a new service launched, more reviews. In your own words.', 'you', 'You', true],
          ['Sarah plans it', 'A campaign with dated steps: articles, posts, pages, emails and offers, with the cost up front.', 'sarah', 'Sarah', false],
          ['She briefs the team', 'Each step goes to the specialist who does it best, with your brand, voice and facts attached.', 'sarah', 'Sarah', false],
          ['The specialists deliver', 'Content, SEO, social and leads work in parallel, on your site and channels.', 'priya', 'Priya, Alex, Marcus, Elena', false],
          ['Sarah reviews it', 'On topic, true to your business, in your voice, complete. Anything that fails goes back.', 'sarah', 'Sarah', false],
          ['You approve, it goes live', 'One tap per item, or the whole plan once. Then she measures and learns what worked.', 'you', 'You', true],
        ] as $i => [$t, $d, $who, $whoName, $you]): ?>
        <div class="aw-step lg-glass<?= $you ? ' aw-step--you' : '' ?>"><span class="aw-step__n"><?= $i + 1 ?></span><b><?= e($t) ?></b><p><?= e($d) ?></p><span class="aw-step__who"><?= $who === 'you' ? '' : $av($who, 22) ?><?= e($whoName) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="canvas" style="padding-top:40px">
    <style>
      /* WORKFORCE-CANVAS-1 (Owner 2026-10-05: "add also a simulation of the Workspace canvass into the page. Sarah Delegating tasks"):
         the Workspace canvas from the app (dotted grid, zones, agent cards with Ongoing / Upcoming / Completed, task nodes coloured
         by category, lines from Sarah to the team), playing one example week. Plays only while on screen, holds its size (every
         part is placed absolutely on a fixed-shape stage), and shows the finished state with reduced motion. */
      .aw .cv-frame{border-radius:22px;overflow:hidden;box-shadow:0 30px 80px rgba(10,8,40,.35),0 0 0 1px rgba(255,255,255,.06)}
      .aw .cv-chrome{display:flex;align-items:center;gap:7px;padding:10px 14px;background:#0d0f1c;border-bottom:1px solid rgba(255,255,255,.06)}
      .aw .cv-chrome i{width:10px;height:10px;border-radius:50%;background:#2a2d3d}
      .aw .cv-chrome span{margin-left:10px;font:600 12px/1 var(--font);color:#8a90a8}
      .aw .cv{position:relative;width:100%;aspect-ratio:100/58;container-type:inline-size;overflow:hidden;color:#e8eaf6;
        background:radial-gradient(60% 70% at 20% 10%,rgba(140,37,210,.30),transparent 60%),radial-gradient(50% 60% at 85% 90%,rgba(63,223,223,.16),transparent 60%),#090b18;
        --f:1.12cqw}
      .aw .cv:before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.10) 1px,transparent 1.2px);background-size:1.8cqw 1.8cqw}
      .aw .cv *{box-sizing:border-box}
      .aw .cv .cv-card{display:block}.aw .cv .cv-card.cv-task{display:flex}.aw .cv b{color:#f2f3fb;font-weight:700}.aw .cv p{max-width:none}
      .aw .cv-zone{position:absolute;border-radius:1.2cqw;border:1px dashed rgba(124,58,237,.35);background:rgba(124,58,237,.05)}
      .aw .cv-zone b{position:absolute;left:1cqw;top:.7cqw;font:700 .8cqw/1 var(--font);letter-spacing:.12em;color:#7dd3c0}
      .aw .cv-svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
      .aw .cv-svg path{fill:none;stroke-width:1.6;stroke-dasharray:var(--len,400);stroke-dashoffset:var(--len,400);transition:stroke-dashoffset .9s ease,opacity .4s}
      .aw .cv-svg path.on{stroke-dashoffset:0}
      .aw .cv-el{position:absolute;left:var(--x);top:var(--y);width:var(--w);font-size:var(--f);line-height:1.35;opacity:0;transform:translateY(.6em) scale(.98);transition:opacity .45s ease,transform .45s ease,box-shadow .4s}
      .aw .cv-el.in{opacity:1;transform:none}
      .aw .cv-el.fixed{opacity:1;transform:none}
      .aw .cv-card{border-radius:.9em;background:rgba(17,20,38,.88);border:1px solid rgba(255,255,255,.09);box-shadow:0 1em 2.4em rgba(0,0,0,.35);padding:.8em .9em;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
      .aw .cv-hd{display:flex;align-items:center;gap:.6em}
      .aw .cv-hd img{width:2.4em;height:2.4em;border-radius:50%;flex:none;box-shadow:0 0 0 .15em rgba(124,58,237,.5)}
      .aw .cv-hd b{display:block;font-size:1.05em}.aw .cv-hd small{display:block;color:#8a90a8;font-size:.82em}
      .aw .cv-counts{display:grid;grid-template-columns:1fr auto;gap:.15em .6em;margin-top:.6em;font-size:.78em;letter-spacing:.06em;text-transform:uppercase;color:#8a90a8}
      .aw .cv-counts b{font-variant-numeric:tabular-nums;text-align:right}.aw .cv-counts .g{color:#34d399}.aw .cv-counts .o{color:#fbbf24}.aw .cv-counts .p{color:#a78bfa}
      .aw .cv-status{display:inline-flex;align-items:center;gap:.4em;margin-top:.6em;font:700 .8em/1 var(--font);padding:.35em .6em;border-radius:1em;background:rgba(255,255,255,.06);color:#c9cde0}
      .aw .cv-status i{width:.55em;height:.55em;border-radius:50%;background:#8a90a8}
      .aw .cv-node.busy .cv-status{background:rgba(52,211,153,.14);color:#34d399}.aw .cv-node.busy .cv-status i{background:#34d399}
      .aw .cv-node.busy{box-shadow:0 0 0 .14em rgba(52,211,153,.45),0 1em 2.4em rgba(0,0,0,.35)}
      .aw .cv-sarah.busy{box-shadow:0 0 0 .16em rgba(167,139,250,.7),0 0 3em rgba(124,58,237,.45)}
      .aw .cv-task{display:flex;gap:.7em;align-items:flex-start;border-left:.28em solid var(--c)}
      .aw .cv-task__t{min-width:0}.aw .cv-task b{display:block;font-size:.98em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .aw .cv-task small{display:block;color:#8a90a8;font-size:.8em}
      .aw .cv-pill{margin-left:auto;flex:none;font:700 .72em/1 var(--font);padding:.4em .55em;border-radius:1em;background:rgba(251,191,36,.14);color:#fbbf24;white-space:nowrap}
      .aw .cv-task.go .cv-pill{background:rgba(52,211,153,.14);color:#34d399}
      .aw .cv-task.done .cv-pill{background:rgba(167,139,250,.16);color:#c4b5fd}
      .aw .cv-task.rev .cv-pill{background:linear-gradient(135deg,#8C25D2,#4C86DE);color:#fff}
      .aw .cv-bubble{border-radius:1em 1em 1em .3em;padding:.75em .9em;background:linear-gradient(135deg,#8A3BEA,#6A45E4);color:#fff;box-shadow:0 1em 2.4em rgba(80,40,200,.4)}
      .aw .cv-bubble small{display:block;opacity:.8;font-size:.78em;margin-bottom:.2em}
      .aw .cv-camp b{display:block;font-size:1em}.aw .cv-camp small{display:block;color:#8a90a8;font-size:.8em}
      .aw .cv-camp .cv-bar{height:.45em;border-radius:1em;background:rgba(255,255,255,.08);overflow:hidden;margin-top:.6em}.aw .cv-camp .cv-bar i{display:block;height:100%;width:var(--p,0%);background:linear-gradient(90deg,#8C25D2,#4C86DE,#3FDFDF);transition:width .8s ease}
      .aw .cv-appr{border:1px solid rgba(167,139,250,.45)}
      .aw .cv-appr p{margin:.4em 0 .6em;color:#c9cde0;font-size:.9em}
      .aw .cv-btns{display:flex;gap:.5em;justify-content:flex-end}
      .aw .cv-btn{font:700 .82em/1 var(--font);padding:.55em .9em;border-radius:1em;background:rgba(255,255,255,.07);color:#e8eaf6}
      .aw .cv-btn--p{background:linear-gradient(135deg,#8A3BEA,#6A45E4);color:#fff}
      .aw .cv-appr.ok .cv-btn--p{background:#34d399;color:#06281d}
      .aw .cv-feed h5{margin:0 0 .5em;font:700 .78em/1 var(--font);letter-spacing:.12em;text-transform:uppercase;color:#7dd3c0}
      .aw .cv-feed ol{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.38em}
      .aw .cv-feed li{display:flex;gap:.6em;align-items:center;height:1.7em;font-size:.86em;color:#c9cde0;white-space:nowrap;overflow:hidden}
      .aw .cv-feed li span{overflow:hidden;text-overflow:ellipsis}
      .aw .cv-feed li i{width:.55em;height:.55em;border-radius:50%;flex:none;background:var(--c,#8a90a8)}
      .aw .cv-feed li time{margin-left:auto;flex:none;color:#6b7190;font-size:.9em;font-variant-numeric:tabular-nums}
      .aw .cv-legend{font-size:.8em;color:#c9cde0}.aw .cv-legend b{display:block;font:700 .9em/1 var(--font);letter-spacing:.1em;color:#8a90a8;margin:.2em 0 .45em}
      .aw .cv-legend span{display:flex;align-items:center;gap:.5em;margin-bottom:.3em}.aw .cv-legend span i{width:1.1em;height:.25em;border-radius:1em;background:var(--c)}
      .aw .cv-tools{display:flex;gap:.5em;font-size:.85em}.aw .cv-tools span{padding:.5em .8em;border-radius:1em;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);white-space:nowrap}
      .aw .cv-tools span.p{background:linear-gradient(135deg,#8A3BEA,#6A45E4);border-color:transparent}
      .aw .cv-note{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;font-size:13px;color:var(--ink-3)}
      .aw .cv-replay{font:600 13px/1 var(--font);color:var(--accent-text);background:none;border:0;cursor:pointer;padding:0}
      @media (max-width:760px){
        .aw .cv{aspect-ratio:100/222;--f:3.05cqw}
        .aw .cv:before{background-size:5cqw 5cqw}
        .aw .cv-hide-m{display:none}
        .aw .cv-zone b{font-size:2.2cqw}
        .aw .cv-el{left:var(--mx);top:var(--my);width:var(--mw)}
        .aw .cv-chrome span{font-size:11px}
      }
      @media (prefers-reduced-motion:reduce){.aw .cv-el,.aw .cv-svg path,.aw .cv-camp .cv-bar i{transition:none}}
    </style>
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">The Workspace</span><h2 class="mk-h2">Watch Sarah run the week.</h2><p class="mk-lead">This is the Workspace in your account: Sarah in the middle, the team around her, every task on the board as it moves from upcoming to done. Here is one example request, from the owner's message to the work going live.</p></div>
      <div class="cv-frame">
        <div class="cv-chrome"><i></i><i></i><i></i><span>Workspace · Kettle Row Coffee (example)</span></div>
        <div class="cv" id="cv" aria-label="Example: Sarah plans a campaign, delegates the tasks to the team, reviews the work and brings it to the owner to approve">
          <div class="cv-zone cv-hide-m" style="left:16cqw;top:13cqw;width:38cqw;height:37cqw"><b>LEADERSHIP</b></div>
          <div class="cv-zone cv-hide-m" style="left:56cqw;top:3cqw;width:42.5cqw;height:53cqw"><b>EXECUTION</b></div>
          <svg class="cv-svg" id="cvSvg" aria-hidden="true"></svg>

          <div class="cv-el fixed cv-hide-m cv-legend" style="--x:1.5cqw;--y:1.6cqw;--w:13cqw"><b>STATUS</b><span style="--c:#34d399"><i></i>Ongoing</span><span style="--c:#fbbf24"><i></i>Upcoming</span><span style="--c:#a78bfa"><i></i>Completed</span><b>CATEGORY</b><span style="--c:#4C86DE"><i></i>Create</span><span style="--c:#34d399"><i></i>Optimize</span><span style="--c:#fb923c"><i></i>Publish</span><span style="--c:#f472b6"><i></i>CRM</span></div>
          <div class="cv-el fixed cv-hide-m cv-tools" style="--x:16cqw;--y:1.6cqw;--w:40cqw"><span>Center</span><span>New meeting</span><span class="p">Strategy room</span><span>Activity</span></div>

          <div class="cv-el cv-bubble" data-at="1" style="--x:17cqw;--y:5.5cqw;--w:21cqw;--mx:4cqw;--my:3cqw;--mw:92cqw"><small>You · 09:02</small>November is always slow. Can we get more people in?</div>

          <div class="cv-el fixed cv-card cv-node cv-sarah" id="cvSarah" style="--x:39cqw;--y:19cqw;--w:13.5cqw;--mx:54cqw;--my:20cqw;--mw:42cqw">
            <div class="cv-hd"><img src="<?= e($agentImg('sarah')) ?>" alt="" width="40" height="40"><div><b>Sarah</b><small>Digital Marketing Manager</small></div></div>
            <span class="cv-status"><i></i><em id="cvSarahSt" style="font-style:normal">Watching your business</em></span>
          </div>

          <div class="cv-el cv-card cv-camp" data-at="2" style="--x:17cqw;--y:19cqw;--w:19.5cqw;--mx:4cqw;--my:20cqw;--mw:46cqw">
            <small>Campaign · Sarah's plan</small><b>Take a loaf home</b><small>4 steps · 1 to 30 November · 36 credits</small><div class="cv-bar"><i id="cvBar"></i></div>
          </div>

          <div class="cv-el cv-card cv-appr" id="cvAppr" data-at="3" style="--x:17cqw;--y:33cqw;--w:19.5cqw;--mx:4cqw;--my:46cqw;--mw:92cqw">
            <div class="cv-hd"><img src="<?= e($agentImg('sarah')) ?>" alt="" width="40" height="40"><div><b>Approve the plan?</b><small>Spending limit 40 credits</small></div></div>
            <p>An article, 12 posts, 3 page fixes and replies to 2 enquiries. Each step runs as planned; I come back if anything changes.</p>
            <div class="cv-btns"><span class="cv-btn">Change it</span><span class="cv-btn cv-btn--p" id="cvApprBtn">Approve</span></div>
          </div>

          <?php $cvAgents = [['priya', 'Priya', 'Content Manager', 4, 78], ['marcus', 'Marcus', 'Social Media Manager', 17, 100], ['alex', 'Alex', 'Technical SEO Engineer', 30, 122], ['elena', 'Elena', 'Lead & CRM Manager', 43, 146]];
          $cvTasks = [
            ['Article: why our sourdough takes 36 hours', 'Create · website', '#4C86DE'],
            ['12 posts for November', 'Publish · Instagram, Facebook', '#fb923c'],
            ['Speed up 3 slow pages', 'Optimize · your website', '#34d399'],
            ['Replies to 2 new enquiries', 'CRM · drafted for you', '#f472b6'],
          ];
          foreach ($cvAgents as $i => [$slug, $nm, $role, $y, $my]): ?>
          <div class="cv-el fixed cv-card cv-node" id="cvA<?= $i ?>" style="--x:58cqw;--y:<?= $y ?>cqw;--w:14cqw;--mx:4cqw;--my:<?= $my ?>cqw;--mw:42cqw">
            <div class="cv-hd"><img src="<?= e($agentImg($slug)) ?>" alt="" width="40" height="40" loading="lazy" decoding="async"><div><b><?= e($nm) ?></b><small><?= e($role) ?></small></div></div>
            <div class="cv-counts cv-hide-m"><span>Ongoing</span><b class="g" data-n="go">0</b><span>Upcoming</span><b class="o" data-n="up">0</b><span>Completed</span><b class="p" data-n="done"><?= [14, 31, 9, 22][$i] ?></b></div>
            <span class="cv-status"><i></i><em style="font-style:normal" data-st>Free</em></span>
          </div>
          <div class="cv-el cv-card cv-task" id="cvT<?= $i ?>" data-at="4" style="--c:<?= $cvTasks[$i][2] ?>;--x:75.5cqw;--y:<?= $y + 1.5 ?>cqw;--w:22cqw;--mx:50cqw;--my:<?= $my + 1 ?>cqw;--mw:46cqw">
            <div class="cv-task__t"><b><?= e($cvTasks[$i][0]) ?></b><small><?= e($cvTasks[$i][1]) ?></small></div><span class="cv-pill">Upcoming</span>
          </div>
          <?php endforeach; ?>

          <div class="cv-el fixed cv-card cv-feed" style="--x:1.5cqw;--y:41cqw;--w:13.5cqw;--mx:4cqw;--my:170cqw;--mw:92cqw">
            <h5>Activity</h5><ol id="cvFeed"></ol>
          </div>
        </div>
      </div>
      <div class="cv-note"><span>Example business and timings. In your account the Workspace shows your own team and tasks.</span><button type="button" class="cv-replay" id="cvReplay">Play again</button></div>
    </div>
    <script>
    (function () {
      var st = document.getElementById('cv'); if (!st) return;
      var svg = document.getElementById('cvSvg'), feed = document.getElementById('cvFeed'), sarah = document.getElementById('cvSarah'), sSt = document.getElementById('cvSarahSt'),
          appr = document.getElementById('cvAppr'), apprBtn = document.getElementById('cvApprBtn'), bar = document.getElementById('cvBar');
      var A = [0, 1, 2, 3].map(function (i) { return document.getElementById('cvA' + i); }), T = [0, 1, 2, 3].map(function (i) { return document.getElementById('cvT' + i); });
      var base = A.map(function (a) { return +a.querySelector('[data-n=done]').textContent; });
      var feedRows = 5, lines = [], step = -1, timer = 0, running = false;
      var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
      function ns(t) { return document.createElementNS('http://www.w3.org/2000/svg', t); }
      function box(el) { var r = el.getBoundingClientRect(), s = st.getBoundingClientRect(); return { l: r.left - s.left, t: r.top - s.top, r: r.right - s.left, b: r.bottom - s.top, w: r.width, h: r.height }; }
      function drawLines() {
        var s = st.getBoundingClientRect(); svg.setAttribute('viewBox', '0 0 ' + s.width + ' ' + s.height); svg.innerHTML = '';
        var defs = ns('defs'), g = ns('linearGradient'); g.id = 'cvG'; [['0', '#8C25D2'], ['.55', '#4C86DE'], ['1', '#3FDFDF']].forEach(function (c) { var o = ns('stop'); o.setAttribute('offset', c[0]); o.setAttribute('stop-color', c[1]); g.appendChild(o); }); defs.appendChild(g); svg.appendChild(defs);
        var phone = s.width < 500, sb = box(sarah); lines = [];
        A.forEach(function (a, i) {
          var ab = box(a), tb = box(T[i]), p1 = ns('path'), p2 = ns('path'), d1, d2;
          if (phone) { var x1 = sb.l + sb.w / 2, y1 = sb.b; d1 = 'M' + x1 + ' ' + y1 + ' C ' + x1 + ' ' + (y1 + 40) + ', ' + (ab.r + 20) + ' ' + (ab.t + ab.h / 2) + ', ' + ab.r + ' ' + (ab.t + ab.h / 2); }
          else { var x = sb.r, y = sb.t + sb.h / 2; d1 = 'M' + x + ' ' + y + ' C ' + (x + 40) + ' ' + y + ', ' + (ab.l - 40) + ' ' + (ab.t + ab.h / 2) + ', ' + ab.l + ' ' + (ab.t + ab.h / 2); }
          d2 = 'M' + ab.r + ' ' + (ab.t + ab.h / 2) + ' L ' + tb.l + ' ' + (tb.t + tb.h / 2);
          [[p1, d1], [p2, d2]].forEach(function (q) { q[0].setAttribute('d', q[1]); q[0].setAttribute('stroke', 'url(#cvG)'); svg.appendChild(q[0]); var L = q[0].getTotalLength ? q[0].getTotalLength() : 400; q[0].style.setProperty('--len', Math.ceil(L)); });
          lines.push([p1, p2]);
        });
        paint(step, true);
      }
      function log(txt, c, time) {
        var li = document.createElement('li'); li.style.setProperty('--c', c); li.innerHTML = '<i></i><span></span><time></time>'; li.children[1].textContent = txt; li.children[2].textContent = time;
        feed.insertBefore(li, feed.firstChild); while (feed.children.length > feedRows) feed.removeChild(feed.lastChild);
      }
      var STEPS = [
        function () { feed.innerHTML = ''; log('Watching Kettle Row Coffee', '#7dd3c0', '08:00'); },
        function () { log('You asked for more November visits', '#a78bfa', '09:02'); },
        function () { sarah.classList.add('busy'); sSt.textContent = 'Planning a campaign'; log('Sarah planned "Take a loaf home"', '#a78bfa', '09:03'); },
        function () { sSt.textContent = 'Waiting for your OK'; },
        function () { appr.classList.add('ok'); apprBtn.textContent = 'Approved'; sSt.textContent = 'Briefing the team'; log('You approved the plan, limit 40 credits', '#34d399', '09:05'); },
        function () { [0, 1, 2, 3].forEach(function (i) { lines[i] && lines[i][0].classList.add('on'); }); log('Sarah briefed Priya, Marcus, Alex and Elena', '#a78bfa', '09:05'); },
        function () { [0, 1, 2, 3].forEach(function (i) { lines[i] && lines[i][1].classList.add('on'); setTask(i, 'go'); }); sSt.textContent = 'Managing 4 tasks'; bar.style.setProperty('--p', '25%'); log('4 tasks started', '#34d399', '09:06'); },
        function () { setTask(2, 'done'); setTask(3, 'done'); bar.style.setProperty('--p', '55%'); log('Alex sped up 3 pages', '#34d399', '10:40'); log('Elena drafted 2 enquiry replies', '#f472b6', '10:52'); },
        function () { setTask(0, 'done'); setTask(1, 'done'); bar.style.setProperty('--p', '80%'); log('Priya finished the article', '#4C86DE', '12:15'); log('Marcus designed 12 posts', '#fb923c', '12:30'); },
        function () { sSt.textContent = 'Reviewing the work'; [0, 1, 2, 3].forEach(function (i) { setTask(i, 'rev'); }); bar.style.setProperty('--p', '100%'); log('Sarah reviewed all 4: passed', '#a78bfa', '12:41'); },
        function () { sarah.classList.remove('busy'); sSt.textContent = 'Results on Monday'; log('Article live, posts scheduled for November', '#34d399', '12:42'); }
      ];
      function setTask(i, s) {
        var t = T[i], a = A[i]; t.classList.remove('go', 'done', 'rev'); if (s) t.classList.add(s);
        t.querySelector('.cv-pill').textContent = { go: 'Ongoing', done: 'Completed', rev: 'Reviewed' }[s] || 'Upcoming';
        a.classList.toggle('busy', s === 'go');
        a.querySelector('[data-st]').textContent = s === 'go' ? 'Working' : (s ? 'Done' : 'Free');
        a.querySelector('[data-n=go]').textContent = s === 'go' ? 1 : 0;
        a.querySelector('[data-n=up]').textContent = s ? 0 : (t.classList.contains('in') ? 1 : 0);
        a.querySelector('[data-n=done]').textContent = base[i] + (s === 'done' || s === 'rev' ? 1 : 0);
      }
      function reset() {
        st.querySelectorAll('.cv-el[data-at]').forEach(function (e) { e.classList.remove('in'); });
        lines.forEach(function (l) { l[0].classList.remove('on'); l[1].classList.remove('on'); });
        [0, 1, 2, 3].forEach(function (i) { setTask(i, ''); A[i].querySelector('[data-n=up]').textContent = 0; });
        appr.classList.remove('ok'); apprBtn.textContent = 'Approve'; sarah.classList.remove('busy'); sSt.textContent = 'Watching your business'; bar.style.setProperty('--p', '0%');
      }
      function paint(upTo, quiet) {
        if (upTo < 0) return; reset(); feed.innerHTML = '';
        for (var k = 0; k <= upTo; k++) { st.querySelectorAll('.cv-el[data-at="' + k + '"]').forEach(function (e) { e.classList.add('in'); }); if (k === 4) [0, 1, 2, 3].forEach(function (i) { A[i].querySelector('[data-n=up]').textContent = 1; }); STEPS[k](); }
      }
      function go(k) {
        step = k; st.querySelectorAll('.cv-el[data-at="' + k + '"]').forEach(function (e) { e.classList.add('in'); });
        if (k === 4) [0, 1, 2, 3].forEach(function (i) { A[i].querySelector('[data-n=up]').textContent = 1; });
        STEPS[k]();
      }
      function tick() {
        if (!running) return;
        if (step >= STEPS.length - 1) { timer = setTimeout(function () { reset(); step = -1; tick(); }, 5200); return; }
        go(step + 1); timer = setTimeout(tick, step === 0 ? 1100 : 1500);
      }
      function start() { if (running || reduce) return; running = true; tick(); }
      function stop() { running = false; clearTimeout(timer); }
      drawLines();
      if (reduce) { step = STEPS.length - 1; paint(step); }
      else if ('IntersectionObserver' in window) { new IntersectionObserver(function (es) { es.forEach(function (e) { e.isIntersecting ? start() : stop(); }); }, { threshold: .35 }).observe(st); }
      else { start(); }
      var rz = 0; window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(drawLines, 150); });
      document.getElementById('cvReplay').addEventListener('click', function () { stop(); reset(); step = -1; if (reduce) { step = STEPS.length - 1; paint(step); } else { running = true; tick(); } });
    })();
    </script>
  </section>

  <section class="mk-sec" id="teams" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">The specialists</span><h2 class="mk-h2"><?= (int) $specialists ?> specialists in four teams. Every one briefed by Sarah.</h2><p class="mk-lead">Every AI plan includes Sarah and <?= $perPlan ?> specialists picked for your business. Add more whenever you need them.</p></div>
      <div class="aw-teams">
        <?php foreach ($teams as $k => [$name, $what, $href, $does]): ?>
        <div class="aw-team lg-glass">
          <div class="aw-team__top"><div class="lg-stack gap-2"><h3><?= e($name) ?></h3><p><?= e($what) ?></p></div><a class="aw-more" href="<?= e($href) ?>">More <?= $arrow ?></a></div>
          <div class="aw-people"><?php foreach ($team[$k] as $a): ?><div class="aw-person"><?= $av($a['slug'], 34) ?><div><b><?= e($a['name']) ?></b><span><?= e($a['title']) ?></span></div></div><?php endforeach; ?></div>
          <ul class="aw-does"><?php foreach ($does as $d): ?><li><?= $check ?><?= e($d) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="intelligence" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">Intelligence</span><h2 class="mk-h2">Sarah knows your business, and gets better at it every week.</h2><p class="mk-lead">She is not a blank chat window. She keeps what you tell her, what you liked and turned down, and what every campaign returned, and uses it in the next decision.</p></div>
      <div class="aw-split">
        <div class="aw-caps">
          <?php foreach ([
            ['<path d="M12 3a7 7 0 0 0-4 12.7V19h8v-3.3A7 7 0 0 0 12 3z"/><path d="M10 22h4"/>', 'Remembers what you tell her', 'Your goals, your customers, your prices, your no-go topics and how you like to be spoken to. Said once, used every time.'],
            ['<path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 14 4-4 3 3 5-6"/>', 'Knows what worked', 'Every campaign\'s results are kept: which topics brought visitors, which posts brought enquiries, which offers sold.'],
            ['<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 'Sees what is coming', 'Seasons, holidays, local events and your slow months, planned for before they arrive.'],
            ['<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>', 'Picks up where you left off', 'Ask about something from last month and she knows what you mean, what was decided and what happened next.'],
            ['<path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10z"/>', 'Makes it right', 'If something missed the mark, she notices, says so, and fixes it without being asked twice.'],
          ] as [$ic, $t, $d]): ?>
          <div class="aw-cap lg-glass lg-glass--thin"><span class="aw-cap__i"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $ic ?></svg></span><div><b><?= e($t) ?></b><span><?= e($d) ?></span></div></div>
          <?php endforeach; ?>
        </div>
        <div class="aw-know lg-glass lg-glass--thick" aria-label="Example: what Sarah knows about a café">
          <div class="aw-know__h"><?= $av('sarah', 40) ?><div><b>What Sarah knows about Kettle Row Coffee</b><span>Example business · ask her to change or forget any of it</span></div></div>
          <div class="aw-kgrp"><h4>Goals</h4><div class="aw-tags"><span class="aw-tag">Fill the 2 to 5 pm gap</span><span class="aw-tag">Sell more loaves to take home</span><span class="aw-tag">100 Google reviews by December</span></div></div>
          <div class="aw-kgrp"><h4>Preferences</h4><div class="aw-tags"><span class="aw-tag aw-tag--ok">Warm, local, a little playful</span><span class="aw-tag aw-tag--ok">Show the baking, not stock photos</span><span class="aw-tag aw-tag--no">No discounts over 20%</span><span class="aw-tag aw-tag--no">Never post on Sundays</span></div></div>
          <div class="aw-kgrp"><h4>What worked</h4>
            <div><div class="aw-row"><span>Behind-the-scenes baking reels</span><b>Most saved</b></div><div class="aw-row"><span>Posts at 13:30</span><b>Best reach</b></div><div class="aw-row"><span>"Why our sourdough takes 36 hours"</span><b>Top article</b></div></div></div>
          <div class="aw-bubble lg-glass lg-glass--thin"><?= $av('sarah', 28) ?><span>November is your quietest month. I have drafted a "Take a loaf home" campaign around it, with afternoon posts at 13:30. Want to see it?</span></div>
        </div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="adapts" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">Watches and adapts</span><h2 class="mk-h2">She notices before you have to ask.</h2><p class="mk-lead">Sarah keeps an eye on your website, your channels, your reviews, your competitors and your market every day. When something changes, she works out what it means for you and brings you a decision, not a dashboard.</p></div>
      <div class="aw-watch">
        <div class="aw-sigs lg-glass">
          <?php foreach ([
            ['<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>', '<b>Searches for "brunch near me"</b> up 40% this month'],
            ['<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>', '<b>A competitor</b> started a weekday lunch offer'],
            ['<path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10z"/>', '<b>Reels</b> are getting three times the saves of photos'],
            ['<path d="M4 4h16v12H5.2L4 17.2z"/>', '<b>Two reviews</b> mention slow service on Saturdays'],
          ] as [$ic, $txt]): ?>
          <div class="aw-sig"><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $ic ?></svg></i><span><?= $txt ?></span></div>
          <?php endforeach; ?>
        </div>
        <span class="aw-arrow" aria-hidden="true"><?= $arrow ?></span>
        <div class="aw-decide lg-glass lg-glass--thick">
          <div class="lg-row gap-3" style="align-items:center"><?= $av('sarah', 36) ?><div class="lg-stack" style="gap:1px"><span class="t-subhead t-strong">Sarah's suggestion</span><span class="t-caption c-3">Waiting for your yes</span></div></div>
          <p class="t-body" style="margin:0;color:var(--ink)">Brunch searches are rising and your Reels outperform everything else. I would move two of this week's photo posts to Reels about the weekend brunch, add a brunch page to your website, and reply to both Saturday reviews with what you are changing.</p>
          <div class="lg-row gap-2" style="flex-wrap:wrap"><span class="lg-badge">2 Reels</span><span class="lg-badge">1 website page</span><span class="lg-badge">2 review replies</span><span class="lg-badge lg-badge--brand">36 credits</span></div>
          <div class="lg-row gap-2" style="justify-content:flex-end"><span class="lg-btn lg-btn--glass lg-btn--sm" style="height:34px">Change it</span><span class="lg-btn lg-btn--primary lg-btn--sm" style="height:34px">Approve</span></div>
        </div>
      </div>
      <div class="aw-rhythm">
        <div class="lg-glass lg-glass--thin"><small>Every morning</small><b>Your brief</b>What happened yesterday, what is planned today and what needs you.</div>
        <div class="lg-glass lg-glass--thin"><small>Afternoon</small><b>A check-in</b>A short question about how business is going, so the plan follows real life.</div>
        <div class="lg-glass lg-glass--thin"><small>Every Monday</small><b>The weekly report</b>Visitors, enquiries, posts and leads, with what she will do differently.</div>
        <div class="lg-glass lg-glass--thin"><small>Every Friday</small><b>Your feedback</b>One question on how the week felt. Your answer changes next week.</div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="control" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">Quality and control</span><h2 class="mk-h2">Checked twice. Approved once. <span class="t-grad">Always by you.</span></h2><p class="mk-lead">Every piece of work passes Sarah's review before you see it, and nothing reaches your customers or spends your credits without your approval.</p></div>
      <div class="aw-gates">
        <div class="aw-gate lg-glass">
          <h3>Sarah's review</h3><p>Every task is checked before it is called done. Work that fails goes back to the specialist; it is never counted as finished.</p>
          <ul class="aw-checks"><li>On the topic you asked for<b>Pass</b></li><li>True to your business facts<b>Pass</b></li><li>In your brand voice<b>Pass</b></li><li>Titles, descriptions and tags complete<b>Pass</b></li><li>Sources for every claim<b class="no">Sent back</b></li></ul>
        </div>
        <div class="aw-gate lg-glass">
          <h3>Your approval, your way</h3><p>Choose how hands-on you want to be, campaign by campaign.</p>
          <div class="aw-mode"><b>One by one</b>Approve or decline each article, post and email. Decline with a reason and it is redone.</div>
          <div class="aw-mode"><b>The plan, once</b>Approve a campaign with a spending limit. Its steps go out as planned; Sarah comes back only if it changes or would cost more.</div>
        </div>
        <div class="aw-gate lg-glass">
          <h3>Always waits for you</h3><p>Whatever you choose, these never happen without your yes.</p>
          <ul class="aw-waits"><?php foreach (['Publishing to your website', 'Posting to your social networks', 'Sending emails to your customers', 'Spending credits on new work', 'Changing a campaign you approved'] as $w): ?><li><?= $lock ?><?= e($w) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="where" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">Working with Sarah</span><h2 class="mk-h2">One conversation, wherever you are.</h2></div>
      <div class="aw-where">
        <div class="aw-wcard lg-glass"><h3>In your account</h3><p>Talk to Sarah in plain words. Previews of every piece of work appear right in the chat, ready to approve.</p></div>
        <div class="aw-wcard lg-glass"><h3>On your phone</h3><p>The companion app keeps the same conversation, your approvals and a notification when something needs you.</p><a href="/next/product/companion-app/">The companion app <?= $arrow ?></a></div>
        <div class="aw-wcard lg-glass"><h3>Every business you run</h3><p>Run more than one business from one account. Sarah keeps each one's brand, goals and results apart.</p><a href="/next/pricing/">Plans and businesses <?= $arrow ?></a></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="plans" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="aw-head"><span class="t-eyebrow">Plans</span><h2 class="mk-h2">The same team on every AI plan.</h2><p class="mk-lead">Every AI plan includes Sarah and <?= $perPlan ?> specialists. Plans differ by monthly credits, websites and team seats.</p></div>
      <div class="aw-plans"><?php foreach ($aiPlans as $pl): ?><a class="aw-plan lg-glass" href="/next/pricing/#<?= e($pl['slug']) ?>"><b><?= e($pl['name']) ?></b><span class="aw-price"><?= money($pl['price_monthly']) ?><small> /month</small></span><span>Sarah + <?= (int) $pl['agents']['count'] ?> specialists · <?= number_format((int) $pl['credits_per_month']) ?> credits a month · <?= (int) $pl['max_websites'] ?> website<?= (int) $pl['max_websites'] === 1 ? '' : 's' ?></span></a><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px">
    <div class="mk-wrap aw-faq">
      <div class="aw-head aw-head--c"><span class="t-eyebrow">Questions</span><h2 class="mk-h2">What owners ask about the team.</h2></div>
      <div class="faq"><?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px;padding-bottom:72px">
    <div class="mk-wrap"><div class="aw-final lg-glass lg-glass--thick">
      <div class="aw-faces"><?php foreach (['sarah', 'priya', 'alex', 'marcus', 'elena', 'nora'] as $s): ?><img src="<?= e($agentImg($s)) ?>" alt="" width="44" height="44" loading="lazy" decoding="async"><?php endforeach; ?></div>
      <h2 class="mk-h2" style="max-width:760px">Meet your team. <span class="t-grad">Keep the final say.</span></h2>
      <p class="mk-lead" style="text-align:center">Start free. Tell Sarah about your business and she will show you what she would do first.</p>
      <div class="aw-cta" style="justify-content:center"><a class="lg-btn lg-btn--primary lg-btn--lg" href="<?= $signup ?>" data-lu-signup><?= e(cta_label($data)) ?> <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="/next/pricing/">See pricing</a></div>
    </div></div>
  </section>
</div>
