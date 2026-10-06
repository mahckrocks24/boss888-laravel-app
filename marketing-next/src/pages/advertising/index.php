<?php
/** @var array $page */ /** @var array $data */
/*
 * ADV-PAGE-2 (Owner 2026-10-06: the first Advertising page scored 6/10 - "check other pages on website and adopt the kind of
 * simulation and design enterprise level we have there. content must sell value"). Built the way /product/social/ and
 * /affiliates/ are built: a composed self-playing hero, the three placements as live device stages, a media-plan builder
 * that writes the brief, an example report with counting figures, packages and a rate card that prints prices only once the
 * Owner approves them (advertising-rates.php, RFC-0031). Every claim is what the ad engine does (app/Engines/Ads, DEC-0091):
 * free-plan websites only, "Sponsored" on every ad, a person reviews every creative, CPM bills viewable impressions by the
 * IAB/MRC rule, 12 paid ads a day per visitor, no paid ad on inventory under 0.75 confidence, quotes only when at least 3
 * matching sites and 10,000 monthly impressions exist. Figures in the mocks are examples and say so. No audience counts,
 * no site counts (never-state-template-count), no "template".
 */
$page['title'] = 'Advertise on the websites of local businesses';
$page['description'] = 'Put your ad on the websites of real local businesses, in your trade and your area. Sponsored, reviewed by a person, billed only on impressions that were actually seen. Tell us who you want to reach and get a media plan within two working days.';
$page['noindex'] = true;
$page['og_image'] = rtrim($data['site']['url'] ?? 'https://levelupgrowth.io', '/') . '/next/assets/og/advertising.jpg';   // OG-SHARE-1: JPEG under WhatsApp's limit, rendered by /root/advpage/og-adv.cjs
$page['og_image_alt'] = 'Advertising on the LevelUpGrowth network: your ad on the websites of local businesses';
$mk = function (string $f): string { $q = __DIR__ . '/../../assets/mk/' . $f; return '/next/assets/mk/' . $f . (is_file($q) ? '?v=' . substr(md5_file($q), 0, 8) : ''); };
$shot = function (string $slug): string { $p = __DIR__ . '/../../assets/product/sites/' . $slug . '.webp'; return '/next/assets/product/sites/' . $slug . '.webp' . (is_file($p) ? '?v=' . substr(md5_file($p), 0, 8) : ''); };
$shotM = function (string $slug) use ($shot): string { $p = __DIR__ . '/../../assets/product/sites-m/' . $slug . '.webp'; return is_file($p) ? '/next/assets/product/sites-m/' . $slug . '.webp?v=' . substr(md5_file($p), 0, 8) : $shot($slug); };
$rates = is_file(__DIR__ . '/../../advertising-rates.php') ? require __DIR__ . '/../../advertising-rates.php' : ['approved' => false];
$priced = ! empty($rates['approved']);
$m0 = fn (float $n) => '$' . number_format($n, $n == floor($n) ? 0 : 2);
$check = '<svg viewBox="0 0 24 24" class="ic" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
/* ADV-ADS-1 (Owner 2026-10-06: "the way those were presented are good, but the actual ads are..."): the creatives inside every mock are
   composed display ads, not cropped social posts - brand mark, headline, line, call to action, "Sponsored", on text-free photographs from
   the platform's own image bank (assets/mk/ads/, EV-1336). Each creative is a container, so it scales with whatever slot holds it. */
$brands = [
  'gym' => ['Ironhouse Fitness', 'I', '#0f172a', '#a3e635', 'First session free', 'Three coaches, small groups. Manchester.', 'Book a session', 'ads/gym.webp'],
  'cafe' => ['Kettle Row Coffee', 'K', '#0f766e', '#fde68a', 'Fresh croissants from 7am', 'Monday to Saturday on Harbour Street.', 'See the menu', 'ads/cafe.webp'],
  'restaurant' => ['Saffron Row', 'S', '#7c2d12', '#fdba74', 'Table for two this Friday?', 'Seasonal menu. Walk-ins welcome.', 'Book a table', 'ads/restaurant.webp'],
  'barber' => ['Fadehouse', 'F', '#18181b', '#e4e4e7', 'Walk in. Walk out sharp.', 'Open till nine, seven days.', 'Book a chair', 'ads/barber.webp'],
];
$ad = function (string $kind, string $key) use ($brands, $mk): string {
  [$name, $ini, $bg, $ac, $h, $s, $cta, $img] = $brands[$key];
  $style = 'style="--b:' . $bg . ';--a:' . $ac . '"'; $brand = '<span class="adc-brand"><i>' . e($ini) . '</i>' . e($name) . '</span>';
  if ($kind === 'bar') return '<div class="adc adc-bar" ' . $style . ' aria-hidden="true"><span class="adc-logo">' . e($ini) . '</span><span class="adc-t"><b>' . e($h) . '</b><small>' . e($name) . ' · ' . e($s) . '</small></span><span class="adc-cta">' . e($cta) . '</span><span class="adc-sp">Sponsored</span></div>';
  if ($kind === 'mrec') return '<div class="adc adc-mrec" ' . $style . ' aria-hidden="true"><div class="adc-ph" style="background-image:url(' . e($mk($img)) . ')"><span class="adc-sp">Sponsored</span>' . $brand . '</div><div class="adc-body"><b>' . e($h) . '</b><small>' . e($s) . '</small><span class="adc-cta">' . e($cta) . '</span></div></div>';
  return '<div class="adc adc-modal" style="--b:' . $bg . ';--a:' . $ac . ';background-image:url(' . e($mk($img)) . ')" aria-hidden="true"><span class="adc-sp">Sponsored</span><span class="adc-x">Close</span><div class="adc-mbody">' . $brand . '<b>' . e($h) . '</b><small>' . e($s) . '</small><span class="adc-cta">' . e($cta) . '</span></div></div>';
};
$arrow = '<svg class="ic ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$faq = [
  ['Where exactly will my ad appear?', 'On the websites of local businesses that run on the free LevelUpGrowth plan: cafés, clinics, salons, gyms, agencies, venues and more. Each website carries at most three placements: a slim bar at the bottom of the screen, one card below the hero and, on a second visit, one full-screen message. Paying customers\' websites never carry ads.'],
  ['How do you choose the websites?', 'Every website in the network is profiled by trade, town and the interests of its visitors. You choose the trades, the areas and the interests you want; your ad is shown only on websites that match and only on inventory we can profile with confidence.'],
  ['How many people will see it?', 'We tell you before you pay. Our estimator counts the matching websites and projects from their real traffic. If fewer than three websites match, or fewer than 10,000 monthly impressions, we tell you that instead of selling you a number.'],
  ['What do I pay for?', 'A viewable impression by the IAB/MRC standard: at least half of your ad on screen for at least a second. Served-but-unseen impressions are never billed. You can also pay per click, or a flat monthly price for a placement set.'],
  ['Is my brand safe here?', 'Yes. A person reviews every creative before it serves. Adult, gambling, crypto, pharma, political and weapons advertising is never accepted, so your ad never sits next to any of it. Every ad is labelled "Sponsored", and visitors can close any ad.'],
  ['Can I make the ads myself?', 'Yes, send finished creatives in the three sizes, or ask us to design them. Our studio lays your message out in your brand colours in all three sizes, usually within two working days, and you approve them before anything serves.'],
  ['How often will one person see my ad?', 'At most twelve paid ads a day per visitor across the whole network, and the full-screen message appears at most once every three minutes and only on a second visit. People remember an ad they saw a few times, not one they could not escape.'],
  ['What reporting do I get?', 'Impressions, viewable impressions, clicks and spend by day, by placement and by website, with invalid traffic shown separately rather than quietly removed. Figures are rolled up nightly in UTC and every report says how fresh it is.'],
  ['Can I stop or change a campaign?', 'Yes. Campaigns have a start and an end date and a budget they can never exceed. Ask us to pause, change creatives or redirect the targeting at any time; changes take effect within a few minutes.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'Advertising on the LevelUpGrowth network', 'provider' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth'], 'description' => $page['description'], 'url' => 'https://levelupgrowth.io/advertising/', 'areaServed' => 'Local businesses\' websites on the LevelUpGrowth network'];
$page['head'] = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('lug-glass.css')) . '">' . "\n" . '<link rel="stylesheet" href="' . e($mk('home.css')) . '">' . "\n" . <<<'CSS'
<style id="ad-css">
  .ad .ad-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.02fr);gap:clamp(32px,5vw,64px);align-items:center;padding-top:clamp(72px,7vw,96px)}
  .ad .ad-h1{font-size:clamp(36px,5vw,60px);line-height:1.04;font-weight:800;letter-spacing:-.035em;margin:0;color:var(--ink);text-wrap:balance}
  .ad .ad-cta{display:flex;gap:12px;flex-wrap:wrap}
  .ad .ad-proof{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-width:560px}
  .ad .ad-proof div{border-radius:16px;padding:12px 14px;display:flex;flex-direction:column;gap:2px}
  .ad .ad-proof b{font:800 22px/1.1 var(--font);letter-spacing:-.02em;color:var(--ink)}.ad .ad-proof span{font-size:12.5px;line-height:17px;color:var(--ink-2)}
  .ad .ad-head{display:flex;flex-direction:column;gap:14px;max-width:760px;margin-bottom:28px}
  .ad .ad-head--c{margin-inline:auto;text-align:center;align-items:center}
  .ad .ad-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .ad .ad-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .ad .ad-card{border-radius:22px;padding:22px;display:flex;flex-direction:column;gap:12px;min-width:0}
  .ad .ad-card h3{margin:0;font-size:19px;letter-spacing:-.01em;color:var(--ink)}.ad .ad-card p{margin:0;font-size:14.5px;line-height:22px;color:var(--ink-2)}
  .ad .ad-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px}
  .ad .ad-list li{display:flex;gap:10px;align-items:flex-start;font-size:15px;line-height:22px;color:var(--ink-2)}.ad .ad-list svg{flex:none;width:18px;height:18px;margin-top:2px;color:var(--success)}
  .ad .ad-list--wait svg{color:var(--accent-text,#7c3aed)}
  .ad .ad-chip{display:inline-flex;align-items:center;gap:6px;font:600 12.5px/1 var(--font);padding:7px 10px;border-radius:999px;background:var(--fill-hover);color:var(--ink)}
  .ad .ad-spec{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;color:var(--ink-2);padding:8px 0;border-top:1px solid var(--hairline)}.ad .ad-spec:first-child{border-top:0}.ad .ad-spec b{color:var(--ink);text-align:right;font-variant-numeric:tabular-nums}
  .ad .ad-flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;position:relative}
  .ad .ad-flow:before{content:"";position:absolute;left:6%;right:6%;top:38px;height:2px;background:linear-gradient(90deg,transparent,var(--accent-text,#7c3aed),transparent);opacity:.35}
  .ad .ad-step{border-radius:20px;padding:18px;display:flex;flex-direction:column;gap:10px;position:relative}
  .ad .ad-step__n{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font:800 16px/1 var(--font);color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}
  .ad .ad-step b{font-size:16px;color:var(--ink)}.ad .ad-step p{margin:0;font-size:14px;line-height:21px;color:var(--ink-2)}
  .ad .ad-step__who{font-size:12px;color:var(--ink-3)}
  .ad .ad-mini{margin-top:auto;border-radius:14px;padding:10px;font-size:12.5px;line-height:1.4;border:1px solid var(--hairline);background:var(--fill-hover);display:flex;flex-direction:column;gap:6px;color:var(--ink-2)}
  .ad .ad-mini b{color:var(--ink)}.ad .ad-mini .ok{color:var(--success);font-weight:700;display:inline-flex;align-items:center;gap:4px}.ad .ad-mini .ok svg{width:14px;height:14px}
  .ad .ad-final{border-radius:28px;padding:clamp(28px,5vw,56px);text-align:center;display:flex;flex-direction:column;align-items:center;gap:16px}
  .ad .ad-faq{max-width:820px;margin:0 auto}
  .ad .ad-kick{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;height:30px;padding:0 12px;border-radius:999px}
  .ad .ad-sp{display:inline-flex;align-items:center;font:600 10px/1 var(--font);letter-spacing:.06em;text-transform:uppercase;color:#65676b;background:#f0f2f5;border-radius:4px;padding:3px 5px}
  /* the creatives: real display ads that scale with the slot that holds them (container units are the slot's own width) */
  .ad .adc{container-type:inline-size;display:block;width:100%;height:100%;position:relative;overflow:hidden;background:#fff;color:#0f172a;font-family:var(--font);text-align:left}
  .ad .adc-sp{position:absolute;z-index:2;font:600 2.6cqw/1 var(--font);letter-spacing:.06em;text-transform:uppercase;color:#475569;background:rgba(255,255,255,.94);border-radius:1.2cqw;padding:1.1cqw 1.6cqw}
  .ad .adc-logo{display:grid;place-items:center;border-radius:2.2cqw;background:var(--b);color:#fff;font:800 5.5cqw/1 var(--font)}
  .ad .adc-cta{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:var(--a);color:#0b0d12;font:700 3.6cqw/1 var(--font);padding:2.2cqw 4cqw;white-space:nowrap}
  .ad .adc-brand{position:absolute;left:3cqw;top:3cqw;z-index:2;display:inline-flex;align-items:center;gap:1.6cqw;font:700 3.8cqw/1 var(--font);color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.45)}
  .ad .adc-brand i{width:6.2cqw;height:6.2cqw;border-radius:1.8cqw;background:var(--b);display:grid;place-items:center;font:800 3.4cqw/1 var(--font);font-style:normal;color:#fff;box-shadow:0 0 0 .4cqw rgba(255,255,255,.85)}
  .ad .adc-bar{display:flex;align-items:center;gap:2cqw;padding:0 2cqw}
  .ad .adc-bar .adc-logo{width:9cqw;height:9cqw;flex:none;font-size:4.6cqw;border-radius:1.8cqw}
  .ad .adc-bar .adc-t{flex:1;min-width:0;display:flex;flex-direction:column;gap:.5cqw}
  .ad .adc-bar .adc-t b{font-size:3.5cqw;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ad .adc-bar .adc-t small{font-size:2.5cqw;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ad .adc-bar .adc-cta{font-size:2.7cqw;padding:1.6cqw 2.8cqw;flex:none}
  .ad .adc-bar .adc-sp{position:static;font-size:1.8cqw;padding:.7cqw 1cqw;background:#f1f5f9;flex:none}
  .ad .adc-mrec{display:flex;flex-direction:column}
  .ad .adc-mrec .adc-ph{position:relative;height:56%;flex:none;background-size:cover;background-position:center}
  .ad .adc-mrec .adc-ph:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.08),rgba(0,0,0,.38))}
  .ad .adc-mrec .adc-sp{right:3cqw;top:3cqw}
  .ad .adc-mrec .adc-body{flex:1;min-height:0;padding:3.4cqw 4cqw;display:flex;flex-direction:column;gap:1.4cqw;justify-content:center}
  .ad .adc-mrec .adc-body b{font-size:7.4cqw;line-height:1.08;letter-spacing:-.02em}
  .ad .adc-mrec .adc-body small{font-size:3.7cqw;line-height:1.3;color:#475569}
  .ad .adc-mrec .adc-cta{align-self:flex-start;margin-top:1.2cqw;font-size:3.7cqw;padding:2.4cqw 4.6cqw}
  .ad .adc-modal{background-size:cover;background-position:center;display:flex;align-items:flex-end;color:#fff}
  .ad .adc-modal:before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.08) 28%,rgba(0,0,0,.76))}
  .ad .adc-modal .adc-sp{left:3.5cqw;top:3.5cqw}
  .ad .adc-x{position:absolute;right:3.5cqw;top:3.5cqw;z-index:2;font:700 2.8cqw/1 var(--font);color:#0f172a;background:rgba(255,255,255,.94);border-radius:999px;padding:1.7cqw 3cqw}
  .ad .adc-mbody{position:relative;z-index:1;padding:5cqw;display:flex;flex-direction:column;gap:2.2cqw;width:100%;box-sizing:border-box}
  .ad .adc-mbody .adc-brand{position:static;text-shadow:none;margin-bottom:1cqw}
  .ad .adc-mbody b{font-size:9.4cqw;line-height:1.04;letter-spacing:-.025em;text-wrap:balance}
  .ad .adc-mbody small{font-size:4cqw;line-height:1.35;color:rgba(255,255,255,.92)}
  .ad .adc-mbody .adc-cta{align-self:flex-start;font-size:4cqw;padding:2.8cqw 5.4cqw;margin-top:1cqw}
  /* the composed hero scene: a phone showing a café's website with the bar and the card live, two more websites behind it, and the campaign's own cards around it */
  .ad .av{position:relative;width:100%;max-width:600px;margin-left:auto;aspect-ratio:1/1.04;container-type:inline-size}
  .ad .av-glow{position:absolute;inset:8% 6% 4%;border-radius:50%;background:radial-gradient(closest-side,rgba(124,58,237,.35),rgba(37,99,235,.18) 55%,transparent);filter:blur(10px);z-index:0}
  .ad .av-web{position:absolute;background:#fff;border-radius:2.6cqw;overflow:hidden;box-shadow:0 2cqw 6cqw rgba(15,10,50,.28),0 0 0 .15cqw rgba(0,0,0,.06);z-index:1}
  .ad .av-web img{display:block;width:100%;height:auto}
  .ad .av-web__bar{height:3.6cqw;display:flex;align-items:center;gap:.9cqw;padding:0 1.6cqw;background:#f5f6f8;border-bottom:.15cqw solid #e6e8ec}
  .ad .av-web__bar i{width:1.2cqw;height:1.2cqw;border-radius:50%;background:#d9dce3;display:block}
  .ad .av-web__bar span{margin-left:auto;font:500 1.5cqw/1 var(--font);color:#8a8f9c}
  .ad .av-web--l{left:0;top:14cqw;width:36cqw;transform:rotate(-6deg)}
  .ad .av-web--r{right:0;top:20cqw;width:36cqw;transform:rotate(5deg)}
  .ad .av-mrec{position:absolute;left:50%;transform:translateX(-50%);width:62%;aspect-ratio:300/250;border-radius:1.4cqw;overflow:hidden;background:#fff;box-shadow:0 1cqw 3cqw rgba(15,10,50,.22),0 0 0 .15cqw rgba(0,0,0,.06)}
  .ad .av-web--l .av-mrec{top:34%}.ad .av-web--r .av-mrec{top:38%}
  .ad .av-phone{position:absolute;left:28.5cqw;top:3cqw;width:43cqw;height:91cqw;border-radius:7cqw;background:#0b0d12;padding:1.5cqw;box-shadow:0 3cqw 8cqw rgba(10,8,40,.45),inset 0 0 0 .3cqw #2a2d36;z-index:3}
  .ad .av-screen{position:relative;width:100%;height:100%;border-radius:5.6cqw;overflow:hidden;background:#fff;color:#14161c;font:400 2cqw/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .ad .av-site{position:absolute;inset:0;background:#fff;overflow:hidden;display:flex;flex-direction:column}
  .ad .av-site img{display:block;width:100%;height:auto;flex:none}
  /* the website continues below its captured hero (Owner 10-06: "website shows white empty space in the phone"): a page continuation in the site's greys */
  .ad .av-more{flex:1;min-height:0;background:linear-gradient(#e6e8ee,#e6e8ee) 6% 8%/44% 3% no-repeat,linear-gradient(#eef0f4,#eef0f4) 6% 15%/88% 2% no-repeat,linear-gradient(#eef0f4,#eef0f4) 6% 20%/72% 2% no-repeat,linear-gradient(#e1e4ea,#e1e4ea) 6% 29%/42% 28% no-repeat,linear-gradient(#e1e4ea,#e1e4ea) 52% 29%/42% 28% no-repeat,linear-gradient(#eef0f4,#eef0f4) 6% 64%/60% 2% no-repeat,linear-gradient(#eef0f4,#eef0f4) 6% 69%/82% 2% no-repeat,linear-gradient(#1b1d24,#1b1d24) 6% 80%/44% 7% no-repeat,#fff}
  .ad .av-status{position:absolute;left:0;right:0;top:0;height:4.2cqw;display:flex;justify-content:space-between;align-items:center;padding:0 3.4cqw;font-size:1.7cqw;font-weight:600;color:#14161c;background:linear-gradient(#fff,rgba(255,255,255,.7));z-index:2}
  .ad .av-notch{width:12cqw;height:2.6cqw;border-radius:2cqw;background:#0b0d12}
  .ad .av-card{position:absolute;left:4cqw;right:4cqw;top:46cqw;aspect-ratio:300/250;border-radius:1.6cqw;overflow:hidden;background:#fff;box-shadow:0 1.2cqw 3.6cqw rgba(15,10,50,.3),0 0 0 .15cqw rgba(0,0,0,.08);z-index:4;opacity:0;transform:translateY(2cqw) scale(.97);transition:opacity .5s ease,transform .5s ease}
  /* the drawer is the real 320x50 strip: 50 px on a 390 px screen = 12.8% of the phone's width (Owner 10-06: "it's supposed to be thin in height") */
  .ad .av-bar{position:absolute;left:0;right:0;bottom:0;height:5.5cqw;display:flex;align-items:stretch;background:#fff;border-top:.15cqw solid #e4e6eb;box-shadow:0 -1cqw 3cqw rgba(0,0,0,.12);z-index:5;transform:translateY(100%);transition:transform .55s cubic-bezier(.2,.8,.2,1)}
  .ad .av-bar .adc{flex:1;min-width:0;height:auto}
  .ad .av-bar__x{width:4cqw;display:grid;place-items:center;background:#f1f5f9;color:#64748b;font:700 2cqw/1 var(--font);flex:none}
  .ad .av-modal{position:absolute;inset:0;z-index:6;display:grid;place-items:center;background:rgba(10,8,30,.55);opacity:0;visibility:hidden;transition:opacity .45s ease,visibility 0s linear .45s}
  .ad .av-modal__box{width:84%;aspect-ratio:1/1;border-radius:2.4cqw;overflow:hidden;background:#fff;box-shadow:0 2cqw 6cqw rgba(0,0,0,.4);position:relative;transform:scale(.94);transition:transform .45s ease}
  .ad .av.is-bar .av-bar{transform:none}.ad .av.is-card .av-card{opacity:1;transform:none}.ad .av.is-modal .av-modal{opacity:1;visibility:visible;transition:opacity .45s ease}.ad .av.is-modal .av-modal__box{transform:none}
  .ad .av-fc{position:absolute;z-index:7;border-radius:3.2cqw;padding:2.2cqw 2.4cqw;display:flex;gap:1.8cqw;align-items:flex-start;font-size:2.1cqw;line-height:1.38;color:var(--ink)}
  .ad .av-fc b{display:block;font-size:2.15cqw}.ad .av-fc small{display:block;color:var(--ink-3);font-size:1.75cqw;margin-top:.2cqw}
  .ad .av-dot{width:4cqw;height:4cqw;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--success-soft,rgba(16,185,129,.15));color:var(--success)}.ad .av-dot svg{width:2.4cqw;height:2.4cqw}
  .ad .av-dot--brand{background:var(--tint,rgba(124,58,237,.12));color:var(--accent-text,#7c3aed)}
  .ad .av-live{display:inline-flex;align-items:center;gap:.8cqw;font:600 1.6cqw/1 var(--font);color:var(--success)}.ad .av-live i{width:1.4cqw;height:1.4cqw;border-radius:50%;background:var(--success);box-shadow:0 0 0 .5cqw var(--success-soft,rgba(16,185,129,.2))}
  .ad .av-camp{left:0;top:0;width:40cqw}
  .ad .av-count{left:1cqw;top:66cqw;width:33cqw;flex-direction:column;gap:1.2cqw}
  .ad .av-count span{display:flex;justify-content:space-between;gap:2cqw;align-self:stretch;font-size:1.95cqw}.ad .av-count i{font-style:normal;font-weight:800;font-variant-numeric:tabular-nums;color:var(--ink)}
  .ad .av-ok{right:0;top:64cqw;width:36cqw}
  .ad .av-on{left:5cqw;top:90cqw;width:38cqw;align-items:center}
  .ad .av-on .av-thumbs{display:inline-flex;flex:none;padding-right:1.2cqw}.ad .av-on .av-thumbs img{width:4.6cqw;height:4.6cqw;border-radius:1.2cqw;object-fit:cover;object-position:top;margin-right:-1cqw;box-shadow:0 0 0 .3cqw var(--raised,#fff)}
  .ad .av-flash{position:absolute;left:0;right:0;bottom:9.2cqw;z-index:5;display:flex;justify-content:center;pointer-events:none}
  .ad .av-flash span{font:700 1.5cqw/1 var(--font);color:#fff;background:rgba(16,185,129,.95);padding:.9cqw 1.6cqw;border-radius:999px;opacity:0;transform:translateY(1cqw);transition:opacity .3s,transform .3s}
  .ad .av.is-flash .av-flash span{opacity:1;transform:none}
  /* placements: three phones, each playing its placement */
  .ad .pl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .ad .pl-stage{position:relative;width:100%;aspect-ratio:1/1.12;container-type:inline-size;border-radius:18px;overflow:hidden;background:radial-gradient(120% 90% at 50% 0%,rgba(124,58,237,.18),transparent 60%),var(--fill-hover)}
  .ad .pl-phone{position:absolute;left:50%;top:7cqw;transform:translateX(-50%);width:62cqw;height:124cqw;border-radius:9cqw;background:#0b0d12;padding:2cqw;box-shadow:0 4cqw 10cqw rgba(10,8,40,.35),inset 0 0 0 .4cqw #2a2d36}
  .ad .pl-screen{position:relative;width:100%;height:100%;border-radius:7.2cqw;overflow:hidden;background:#fff;display:flex;flex-direction:column}
  .ad .pl-screen>img{display:block;width:100%;height:auto;flex:none}
  .ad .pl-bar{position:absolute;left:0;right:0;bottom:0;height:7.4cqw;display:flex;align-items:stretch;background:#fff;border-top:.2cqw solid #e4e6eb;box-shadow:0 -1cqw 4cqw rgba(0,0,0,.12);transform:translateY(100%)}
  .ad .pl-bar .adc{flex:1;min-width:0;height:auto}
  .ad .pl-bar__x{width:5.6cqw;display:grid;place-items:center;background:#f1f5f9;color:#64748b;font:700 2.8cqw/1 var(--font);flex:none}
  .ad .pl-card{position:absolute;left:5cqw;right:5cqw;top:62cqw;aspect-ratio:300/250;border-radius:2.2cqw;overflow:hidden;background:#fff;box-shadow:0 1.6cqw 4.8cqw rgba(15,10,50,.3);opacity:0;transform:translateY(3cqw) scale(.97)}
  .ad .pl-modal{position:absolute;inset:0;display:grid;place-items:center;background:rgba(10,8,30,.55);opacity:0}
  .ad .pl-modal__box{width:84%;aspect-ratio:1/1;border-radius:3cqw;overflow:hidden;background:#fff;box-shadow:0 2cqw 7cqw rgba(0,0,0,.4);position:relative}
  .ad .pl-tag{position:absolute;left:3cqw;top:3cqw;z-index:2;display:inline-flex;align-items:center;gap:1.2cqw;font:600 2.4cqw/1 var(--font);color:var(--ink);padding:1.4cqw 2cqw;border-radius:999px}
  .ad .pl-tag i{width:1.6cqw;height:1.6cqw;border-radius:50%;background:var(--success)}
  .ad .pl-stage.is-live .pl-bar{animation:ad-bar 9s ease-in-out infinite}
  .ad .pl-stage.is-live .pl-card{animation:ad-card 9s ease-in-out infinite}
  .ad .pl-stage.is-live .pl-modal{animation:ad-modal 9s ease-in-out infinite}
  @keyframes ad-bar{0%,12%{transform:translateY(100%)}20%,78%{transform:none}86%,100%{transform:translateY(100%)}}
  @keyframes ad-card{0%,14%{opacity:0;transform:translateY(3cqw) scale(.97)}24%,80%{opacity:1;transform:none}90%,100%{opacity:0;transform:translateY(3cqw) scale(.97)}}
  @keyframes ad-modal{0%,24%{opacity:0}32%,72%{opacity:1}80%,100%{opacity:0}}
  @media (prefers-reduced-motion:reduce){.ad .pl-stage .pl-bar,.ad .pl-stage.is-live .pl-bar{animation:none;transform:none}.ad .pl-stage .pl-card,.ad .pl-stage.is-live .pl-card{animation:none;opacity:1;transform:none}.ad .pl-stage .pl-modal,.ad .pl-stage.is-live .pl-modal{animation:none;opacity:1}.ad .av-bar{transform:none}.ad .av-card{opacity:1;transform:none}}
  /* the network strip */
  .ad .nw-strip{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;margin:0 -8px;padding:6px 8px 18px}.ad .nw-strip::-webkit-scrollbar{display:none}
  .ad .nw-site{flex:0 0 232px;scroll-snap-align:start;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 0 0 .5px rgba(0,0,0,.08),0 12px 32px rgba(20,16,60,.16);position:relative}
  .ad .nw-site img{display:block;width:100%;aspect-ratio:232/290;object-fit:cover;object-position:top}
  .ad .nw-site__m{position:absolute;left:0;right:0;bottom:0;padding:10px 12px;background:linear-gradient(transparent,rgba(10,8,30,.85));color:#fff;display:flex;flex-direction:column;gap:2px}
  .ad .nw-site__m b{font-size:13.5px}.ad .nw-site__m span{font-size:12px;opacity:.85}
  .ad .nw-site .ad-sp{position:absolute;left:10px;top:10px}
  .ad .nw-sectors{display:flex;gap:8px;flex-wrap:wrap}
  /* the media-plan builder */
  .ad .pb{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:0;border-radius:26px;overflow:hidden}
  .ad .pb__in{padding:clamp(22px,3vw,34px);display:flex;flex-direction:column;gap:20px}
  .ad .pb__out{padding:clamp(22px,3vw,34px);display:flex;flex-direction:column;gap:16px;border-left:1px solid var(--hairline);background:color-mix(in srgb,var(--accent-text,#7c3aed) 5%,transparent)}
  .ad .pb-lbl{font-size:13px;font-weight:700;color:var(--ink);margin-bottom:8px;display:flex;justify-content:space-between;gap:8px}.ad .pb-lbl span{font-weight:500;color:var(--ink-3)}
  .ad .pb-segs{display:flex;flex-wrap:wrap;gap:6px}
  .ad .pb-segs button{border:1px solid var(--hairline);background:transparent;color:var(--ink);font:600 13px/1 var(--font);padding:10px 14px;border-radius:999px;cursor:pointer;min-height:40px}
  .ad .pb-segs button[aria-pressed=true]{background:var(--ink);color:var(--ground,#fff);border-color:transparent}
  .ad .pb-in{height:44px;border-radius:12px;border:1px solid var(--hairline);background:var(--raised,#fff);color:var(--ink);font:500 15px/1 var(--font);padding:0 14px;width:100%;box-sizing:border-box}
  .ad .pb-in:focus{outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent-text,#7c3aed) 30%,transparent)}
  .ad .pb-plan{display:flex;flex-direction:column;gap:10px}
  .ad .pb-row{display:flex;justify-content:space-between;gap:12px;font-size:14px;line-height:20px;color:var(--ink-2);padding:8px 0;border-top:1px solid var(--hairline)}.ad .pb-row:first-child{border-top:0;padding-top:0}.ad .pb-row b{color:var(--ink);text-align:right}
  .ad .pb-price{font-size:clamp(30px,3.6vw,40px);font-weight:800;letter-spacing:-.03em;line-height:1;color:var(--ink);font-variant-numeric:tabular-nums}
  .ad .pb-note{font-size:13px;line-height:19px;color:var(--ink-3);margin:0}
  .ad .pb-next{display:flex;flex-direction:column;gap:8px}
  .ad .pb-next div{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;line-height:19px;color:var(--ink-2)}.ad .pb-next svg{flex:none;width:16px;height:16px;margin-top:1px;color:var(--accent-text,#7c3aed)}
  /* the example report */
  .ad .rp{border-radius:24px;overflow:hidden}
  .ad .rp-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--hairline)}
  .ad .rp-body{padding:20px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .ad .rp-kpi{border-radius:16px;padding:14px 16px;display:flex;flex-direction:column;gap:4px;min-width:0}
  .ad .rp-kpi span{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-3)}
  .ad .rp-kpi b{font:800 26px/1.1 var(--font);letter-spacing:-.02em;color:var(--ink);font-variant-numeric:tabular-nums}
  .ad .rp-kpi small{font-size:12.5px;color:var(--ink-2)}
  .ad .rp-split{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:12px}
  .ad .rp-panel{border-radius:16px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;min-width:0}
  .ad .rp-h{display:flex;justify-content:space-between;align-items:baseline;gap:8px}.ad .rp-h b{font-size:14px;color:var(--ink)}.ad .rp-h span{font-size:12px;color:var(--ink-3)}
  .ad .rp-bars{display:flex;flex-direction:column;gap:8px}
  .ad .rp-bar{display:grid;grid-template-columns:minmax(0,1fr) 64px;gap:10px;align-items:center;font-size:13px;color:var(--ink-2)}
  .ad .rp-bar div{display:flex;flex-direction:column;gap:4px;min-width:0}.ad .rp-bar div span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink)}
  .ad .rp-bar i{display:block;height:8px;border-radius:999px;background:var(--fill-press);overflow:hidden;position:relative}.ad .rp-bar i:after{content:"";position:absolute;inset:0;width:var(--w,0%);border-radius:999px;background:var(--brand-grad,linear-gradient(90deg,#7c3aed,#2563eb));transition:width 1.2s cubic-bezier(.2,.8,.2,1)}
  .ad .rp-bar b{text-align:right;font-variant-numeric:tabular-nums;color:var(--ink)}
  .ad .rp-days{display:flex;align-items:flex-end;gap:4px;height:92px}
  .ad .rp-days i{flex:1;border-radius:3px 3px 0 0;background:var(--brand-grad,linear-gradient(180deg,#7c3aed,#2563eb));transform:scaleY(var(--h,.1));transform-origin:bottom;transition:transform .9s cubic-bezier(.2,.8,.2,1);display:block;height:100%;opacity:.9}
  .ad .rp-inv{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
  .ad .rp-inv div{border-radius:12px;padding:10px;background:var(--fill-hover);display:flex;flex-direction:column;gap:2px}.ad .rp-inv span{font-size:11.5px;color:var(--ink-3)}.ad .rp-inv b{font-size:16px;color:var(--ink);font-variant-numeric:tabular-nums}
  /* packages */
  .ad .pk{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .ad .pk-card{border-radius:22px;padding:22px;display:flex;flex-direction:column;gap:12px;min-width:0}
  .ad .pk-card h3{margin:0;font-size:20px;letter-spacing:-.015em;color:var(--ink)}.ad .pk-card p{margin:0;font-size:14.5px;line-height:22px;color:var(--ink-2)}
  .ad .pk-price{display:flex;align-items:baseline;gap:6px}.ad .pk-price b{font:800 30px/1 var(--font);letter-spacing:-.03em;color:var(--ink);font-variant-numeric:tabular-nums}.ad .pk-price span{font-size:13px;color:var(--ink-3)}
  .ad .pk-models{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:16px}
  .ad .pk-models div{border-radius:16px;padding:14px;font-size:14px;line-height:20px;color:var(--ink-2)}.ad .pk-models b{display:block;color:var(--ink);margin-bottom:4px}
  .ad .pk-feat{margin-top:auto}
  /* rules */
  .ad .ru{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .ad .ru-no{display:flex;gap:8px;flex-wrap:wrap}
  .ad .ru-no span{display:inline-flex;align-items:center;gap:6px;font:600 13px/1 var(--font);padding:8px 12px;border-radius:999px;background:color-mix(in srgb,#dc2626 10%,transparent);color:#b91c1c}
  /* brief */
  .ad .br{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:16px;align-items:start}
  .ad .br form{border-radius:22px;padding:clamp(20px,3vw,28px);display:grid;gap:12px}
  .ad .br .f2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .ad .br label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600;color:var(--ink)}
  .ad .br input,.ad .br textarea,.ad .br select{height:46px;border-radius:12px;border:1px solid var(--hairline);background:var(--raised,#fff);color:var(--ink);font:500 15px/1.3 var(--font);padding:0 14px;width:100%;box-sizing:border-box}
  .ad .br textarea{height:auto;min-height:132px;padding:12px 14px;resize:vertical}
  .ad .br input:focus,.ad .br textarea:focus,.ad .br select:focus{outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent-text,#7c3aed) 30%,transparent)}
  .ad .br .consent{flex-direction:row;align-items:flex-start;gap:10px;font-weight:500;font-size:13.5px;line-height:19px;color:var(--ink-2)}.ad .br .consent input{width:18px;height:18px;margin-top:1px;flex:none}
  .ad .br .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
  .ad .br .form-error{font-size:14px;line-height:20px;color:#b91c1c;margin:0}.ad .br .form-error[hidden]{display:none}
  .ad .br .form-success{display:none;flex-direction:column;align-items:flex-start;padding:8px 0}
  .ad .br form.is-done .form-success{display:flex}.ad .br form.is-done .f2,.ad .br form.is-done>label,.ad .br form.is-done .ad-form__send,.ad .br form.is-done .form-error{display:none}
  .ad .br input[aria-invalid=true],.ad .br textarea[aria-invalid=true]{box-shadow:0 0 0 3px color-mix(in srgb,#dc2626 30%,transparent)}
  @media (max-width:1000px){.ad .ad-flow{grid-template-columns:repeat(2,minmax(0,1fr))}.ad .ad-flow:before{display:none}.ad .rp-body{grid-template-columns:repeat(2,minmax(0,1fr))}.ad .pk-models{grid-template-columns:1fr}}
  @media (max-width:900px){.ad .ad-hero,.ad .ad-grid3,.ad .ad-grid2,.ad .pl-grid,.ad .pb,.ad .pk,.ad .ru,.ad .br,.ad .rp-split{grid-template-columns:1fr}.ad .av{margin:8px auto 0}.ad .pb__out{border-left:0;border-top:1px solid var(--hairline)}.ad .ad-proof b{font-size:18px}.ad .pl-stage{max-width:420px;margin:0 auto}}
  @media (max-width:600px){.ad .av{aspect-ratio:1/1.34}.ad .av-web{display:none}.ad .av-phone{left:24cqw;width:52cqw;height:112cqw;top:4cqw}.ad .av-bar{height:6.7cqw}.ad .av-bar__x{width:4.8cqw;font-size:2.4cqw}.ad .av-fc{font-size:12px;padding:9px 11px;gap:8px;border-radius:14px}.ad .av-fc b{font-size:12.5px}.ad .av-fc small{font-size:10.5px}.ad .av-dot{width:22px;height:22px}.ad .av-dot svg{width:13px;height:13px}.ad .av-live{font-size:11px}.ad .av-live i{width:7px;height:7px}.ad .av-camp{width:58cqw;top:0}.ad .av-count{display:none}.ad .av-ok{width:54cqw;top:70cqw}.ad .av-on{width:66cqw;top:auto;bottom:0;left:17cqw}.ad .av-on .av-thumbs{padding-right:10px}.ad .av-on .av-thumbs img{width:22px;height:22px;border-radius:6px;margin-right:-5px}.ad .ad-flow{grid-template-columns:1fr}.ad .rp-body{grid-template-columns:1fr 1fr}.ad .rp-inv{grid-template-columns:1fr 1fr 1fr}.ad .br .f2{grid-template-columns:1fr}.ad .nw-site{flex-basis:68%}}
</style>
CSS;
// example websites in the network strip: real websites built on the platform (assets/product/sites), never "template"
$network = [
  ['cafe_kettlerow', 'Kettle Row Coffee', 'Café · Manchester'],
  ['dental_elmrow', 'Elm Row Dental', 'Dental clinic · Leeds'],
  ['gym_ironhouse', 'Ironhouse Fitness', 'Gym · Manchester'],
  ['beauty_salon', 'Rosefinch Beauty', 'Salon · Dubai'],
  ['real_crowncourt', 'Crown Court Realty', 'Estate agency · London'],
  ['hotel_rookery', 'The Rookery', 'Hotel · Norwich'],
  ['auto_torque', 'Torque Auto', 'Garage · Bristol'],
  ['interior_northaisle', 'North Aisle Interiors', 'Interior design · Dubai'],
  ['barbershop_fadehouse', 'Fadehouse', 'Barbershop · Manchester'],
  ['restaurant_blackdoor', 'Blackdoor', 'Restaurant · London'],
];
$sectors = ['Food & drink', 'Health & dental', 'Beauty & barbers', 'Fitness & wellbeing', 'Home & property', 'Hotels, travel & venues', 'Professional services', 'Retail & automotive', 'Learning & family'];
$ratesJson = json_encode(['priced' => $priced, 'cpm' => $rates['cpm'] ?? [], 'cpc' => $rates['cpc'] ?? [], 'flat' => $rates['flat'] ?? [], 'creative' => $rates['creative_set'] ?? 0, 'min' => $rates['minimum'] ?? 0]);
$page['scripts'] = <<<JS
<script id="ad-js">
(function () {
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  /* 1. the hero: the bar arrives, the card appears, viewable impressions count, the full-screen message comes and goes, the review card lands; loops while on screen */
  (function () {
    var av = document.getElementById('ad-av'); if (!av) return;
    var vi = av.querySelector('[data-av=vi]'), cl = av.querySelector('[data-av=cl]'), ok = av.querySelector('[data-av=ok]'), st = av.querySelector('[data-av=status]');
    var base = { vi: 3104, cl: 41 }, gen = 0, visible = false, running = false;
    function set(v, c) { if (vi) vi.textContent = v.toLocaleString('en-US'); if (cl) cl.textContent = c; }
    function finished() { av.classList.add('is-bar', 'is-card'); av.classList.remove('is-modal', 'is-flash'); set(base.vi + 128, base.cl + 3); if (ok) ok.style.opacity = 1; if (st) st.textContent = 'Live · Manchester'; }
    function reset() { av.classList.remove('is-bar', 'is-card', 'is-modal', 'is-flash'); set(base.vi, base.cl); if (ok) ok.style.opacity = 0; if (st) st.textContent = 'Going live…'; }
    async function run() {
      var my = ++gen, live = function () { return my === gen && visible; };
      running = true; reset(); await wait(700); if (!live()) return (running = false);
      av.classList.add('is-bar'); if (st) st.textContent = 'Live · Manchester'; await wait(900); if (!live()) return (running = false);
      av.classList.add('is-flash'); set(base.vi + 1, base.cl); await wait(900); av.classList.remove('is-flash'); if (!live()) return (running = false);
      av.classList.add('is-card'); await wait(700); av.classList.add('is-flash'); set(base.vi + 2, base.cl); await wait(900); av.classList.remove('is-flash'); if (!live()) return (running = false);
      for (var i = 3; i <= 9; i++) { set(base.vi + i * 14, base.cl + (i > 6 ? 1 : 0)); await wait(260); }
      if (!live()) return (running = false);
      av.classList.add('is-modal'); await wait(600); av.classList.add('is-flash'); set(base.vi + 128, base.cl + 1); await wait(900); av.classList.remove('is-flash'); await wait(1400); if (!live()) return (running = false);
      av.classList.remove('is-modal'); await wait(500); set(base.vi + 128, base.cl + 3); if (ok) { ok.style.opacity = 1; } await wait(4200); if (!live()) return (running = false);
      running = false; if (visible) run();
    }
    if (reduce || !('IntersectionObserver' in window)) { finished(); return; }
    reset();
    new IntersectionObserver(function (es) { es.forEach(function (e) { visible = e.isIntersecting; if (visible && !running) run(); else if (!visible) { gen++; running = false; finished(); } }); }, { threshold: .3 }).observe(av);
  })();
  /* 2. the placement stages play only while on screen (CSS keyframes, no layout change) */
  (function () {
    var st = Array.prototype.slice.call(document.querySelectorAll('.pl-stage')); if (!st.length) return;
    if (reduce || !('IntersectionObserver' in window)) return;
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { e.target.classList.toggle('is-live', e.isIntersecting); }); }, { threshold: .25 });
    st.forEach(function (s) { io.observe(s); });
  })();
  /* 3. the media-plan builder: the reader's choices become the plan and the brief */
  (function () {
    var host = document.getElementById('ad-pb'); if (!host) return;
    var R = $ratesJson;
    var S = { trades: ['Food & drink'], area: 'Manchester', places: { footer_sticky: true, in_content_mrec: true, interstitial_modal: false }, model: 'cpm', weeks: 4, design: true };
    var q = function (s) { return host.querySelector(s); }, qa = function (s) { return Array.prototype.slice.call(host.querySelectorAll(s)); };
    var names = { footer_sticky: 'Footer bar', in_content_mrec: 'Card below the hero', interstitial_modal: 'Full-screen message' };
    var modelName = { cpm: 'Per 1,000 viewable impressions (CPM)', cpc: 'Per click (CPC)', flat: 'Flat monthly price' };
    qa('[data-trade]').forEach(function (b) { b.addEventListener('click', function () { var t = b.getAttribute('data-trade'), i = S.trades.indexOf(t); if (i >= 0) { if (S.trades.length > 1) S.trades.splice(i, 1); } else if (S.trades.length < 3) S.trades.push(t); draw(); }); });
    qa('[data-area]').forEach(function (b) { b.addEventListener('click', function () { S.area = b.getAttribute('data-area'); q('[data-area-in]').value = S.area === 'Your area' ? '' : S.area; draw(); }); });
    q('[data-area-in]').addEventListener('input', function (e) { S.area = e.target.value.trim() || 'Your area'; draw(false); });
    qa('[data-place]').forEach(function (b) { b.addEventListener('click', function () { var k = b.getAttribute('data-place'); var on = Object.keys(S.places).filter(function (x) { return S.places[x]; }); if (S.places[k] && on.length === 1) return; S.places[k] = !S.places[k]; if (S.model === 'cpc' && S.places.interstitial_modal && !S.places.footer_sticky && !S.places.in_content_mrec) S.model = 'cpm'; draw(); }); });
    qa('[data-model]').forEach(function (b) { b.addEventListener('click', function () { S.model = b.getAttribute('data-model'); draw(); }); });
    qa('[data-weeks]').forEach(function (b) { b.addEventListener('click', function () { S.weeks = +b.getAttribute('data-weeks'); draw(); }); });
    qa('[data-design]').forEach(function (b) { b.addEventListener('click', function () { S.design = b.getAttribute('data-design') === 'yes'; draw(); }); });
    function money(v) { return '$' + (Math.round(v * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
    function priceLine() {
      if (!R.priced) return null;
      var on = Object.keys(S.places).filter(function (k) { return S.places[k]; });
      if (S.model === 'flat') { var tier = on.length === 3 ? 'area' : 'local'; return { big: money(R.flat[tier] || 0) + ' a month', small: (tier === 'area' ? 'Area Takeover' : 'Local Presence') + (S.design ? ' · ad set ' + money(R.creative) + ' once' : '') }; }
      if (S.model === 'cpc') { var cp = on.filter(function (k) { return R.cpc[k]; }).map(function (k) { return R.cpc[k]; }); return { big: money(Math.min.apply(null, cp)) + ' a click', small: 'Minimum campaign ' + money(R.min) + (S.design ? ' · ad set ' + money(R.creative) + ' once' : '') }; }
      var cpm = on.map(function (k) { return R.cpm[k]; }); return { big: money(Math.min.apply(null, cpm)) + '–' + money(Math.max.apply(null, cpm)) + ' CPM', small: 'Per 1,000 viewable impressions · minimum ' + money(R.min) + (S.design ? ' · ad set ' + money(R.creative) + ' once' : '') };
    }
    function brief() {
      var on = Object.keys(S.places).filter(function (k) { return S.places[k]; }).map(function (k) { return names[k]; });
      return 'Media plan request\\n' + 'Trades: ' + S.trades.join(', ') + '\\n' + 'Area: ' + S.area + '\\n' + 'Placements: ' + on.join(', ') + '\\n' + 'Pricing: ' + modelName[S.model] + '\\n' + 'Duration: ' + S.weeks + ' weeks\\n' + 'Creatives: ' + (S.design ? 'please design the ad set in our brand' : 'we will supply finished creatives') + '\\n\\nAbout our business and what we want people to do: ';
    }
    function draw(sync) {
      qa('[data-trade]').forEach(function (b) { b.setAttribute('aria-pressed', S.trades.indexOf(b.getAttribute('data-trade')) >= 0); });
      qa('[data-area]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-area') === S.area); });
      qa('[data-place]').forEach(function (b) { b.setAttribute('aria-pressed', !!S.places[b.getAttribute('data-place')]); });
      var cpcOk = S.places.footer_sticky || S.places.in_content_mrec; var cpcBtn = q('[data-model=cpc]'); if (cpcBtn) { cpcBtn.disabled = !cpcOk; cpcBtn.style.opacity = cpcOk ? '' : '.45'; }
      qa('[data-model]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-model') === S.model); });
      qa('[data-weeks]').forEach(function (b) { b.setAttribute('aria-pressed', +b.getAttribute('data-weeks') === S.weeks); });
      qa('[data-design]').forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-design') === 'yes') === S.design); });
      var on = Object.keys(S.places).filter(function (k) { return S.places[k]; });
      q('[data-out=trades]').textContent = S.trades.join(', '); q('[data-out=area]').textContent = S.area; q('[data-out=places]').textContent = on.map(function (k) { return names[k]; }).join(' + ');
      q('[data-out=model]').textContent = modelName[S.model]; q('[data-out=weeks]').textContent = S.weeks + ' weeks'; q('[data-out=design]').textContent = S.design ? 'Designed by our studio in your brand, approved by you' : 'You supply finished creatives in ' + on.length + (on.length > 1 ? ' sizes' : ' size');
      var p = priceLine(), pe = q('[data-out=price]'), ps = q('[data-out=price-small]');
      if (p) { pe.textContent = p.big; ps.textContent = p.small; } else { pe.textContent = 'Quoted with your reach'; ps.textContent = 'The rate card comes with your media plan, within two working days.'; }
      var ta = document.querySelector('#ad-brief textarea[name=message]'); if (ta && (sync !== false) && (!ta.value || ta.getAttribute('data-auto') === '1')) { ta.value = brief(); ta.setAttribute('data-auto', '1'); }
    }
    var ta0 = document.querySelector('#ad-brief textarea[name=message]'); if (ta0) ta0.addEventListener('input', function () { ta0.setAttribute('data-auto', ta0.value ? '0' : '1'); });
    q('[data-send]').addEventListener('click', function () { var ta = document.querySelector('#ad-brief textarea[name=message]'); if (ta) { ta.value = brief(); ta.setAttribute('data-auto', '1'); } var f = document.getElementById('ad-brief'); if (f) { f.scrollIntoView({ behavior: 'smooth', block: 'start' }); var n = f.querySelector('input[name=name]'); setTimeout(function () { if (n) n.focus({ preventScroll: true }); }, 600); } });
    draw();
  })();
  /* 4. the example report counts up when it comes on screen */
  (function () {
    var rp = document.getElementById('ad-rp'); if (!rp) return;
    var nums = Array.prototype.slice.call(rp.querySelectorAll('[data-n]')), bars = Array.prototype.slice.call(rp.querySelectorAll('[data-w]')), days = Array.prototype.slice.call(rp.querySelectorAll('[data-h]'));
    function fmt(el, v) { var t = el.getAttribute('data-fmt') || ''; if (t === 'pct') return v.toFixed(1) + '%'; if (t === 'money') return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); return Math.round(v).toLocaleString('en-US'); }
    function finish() { nums.forEach(function (el) { el.textContent = fmt(el, +el.getAttribute('data-n')); }); bars.forEach(function (b) { b.style.setProperty('--w', b.getAttribute('data-w') + '%'); }); days.forEach(function (d) { d.style.setProperty('--h', d.getAttribute('data-h')); }); }
    if (reduce || !('IntersectionObserver' in window)) { finish(); return; }
    var done = false;
    new IntersectionObserver(function (es) { es.forEach(function (e) { if (!e.isIntersecting || done) return; done = true;
      var t0 = performance.now(), dur = 1500; (function tick(now) { var k = Math.min(1, (now - t0) / dur), ease = 1 - Math.pow(1 - k, 3); nums.forEach(function (el) { el.textContent = fmt(el, +el.getAttribute('data-n') * ease); }); if (k < 1) requestAnimationFrame(tick); })(t0);
      bars.forEach(function (b) { b.style.setProperty('--w', b.getAttribute('data-w') + '%'); }); days.forEach(function (d) { d.style.setProperty('--h', d.getAttribute('data-h')); });
    }); }, { threshold: .3 }).observe(rp);
  })();
})();
</script>
JS;
?>
<div class="lg mk ad">

  <section class="mk-sec" style="padding-top:0;padding-bottom:40px">
    <div class="mk-wrap ad-hero">
      <div class="lg-stack gap-4">
        <span class="lg-badge lg-badge--brand ad-kick"><span class="lg-dot"></span>Advertising on the LevelUpGrowth network</span>
        <h1 class="ad-h1">Your ad, on the websites <span class="t-grad">your customers already visit.</span></h1>
        <p class="mk-lead">Local businesses build their websites on LevelUpGrowth. Their visitors are your neighbours, looking for a café, a dentist, a gym, a builder. Put your message in front of them, in your trade and your area, and pay only for impressions that were actually seen.</p>
        <div class="ad-cta"><a class="lg-btn lg-btn--primary lg-btn--lg" href="#plan">Build your media plan <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="#placements">See the placements</a></div>
        <div class="ad-proof">
          <div class="lg-glass lg-glass--thin"><b>Seen</b><span>billed only on viewable impressions, by the IAB/MRC rule</span></div>
          <div class="lg-glass lg-glass--thin"><b>Local</b><span>by trade, by town, by what visitors are interested in</span></div>
          <div class="lg-glass lg-glass--thin"><b>Reviewed</b><span>a person approves every ad before it serves</span></div>
        </div>
      </div>
      <div class="av" id="ad-av" aria-label="Example: a gym's ad live on a café's website, with the campaign's live figures around it">
        <div class="av-glow" aria-hidden="true"></div>
        <article class="av-web av-web--l" aria-hidden="true"><div class="av-web__bar"><i></i><i></i><i></i><span>elmrowdental.co.uk</span></div><img src="<?= e($shot('dental_elmrow')) ?>" alt="" width="1440" height="900" loading="eager"><div class="av-mrec"><?= $ad('mrec', 'gym') ?></div></article>
        <article class="av-web av-web--r" aria-hidden="true"><div class="av-web__bar"><i></i><i></i><i></i><span>rosefinchbeauty.ae</span></div><img src="<?= e($shot('beauty_salon')) ?>" alt="" width="1440" height="900" loading="eager"><div class="av-mrec"><?= $ad('mrec', 'gym') ?></div></article>
        <div class="av-phone" aria-hidden="true"><div class="av-screen">
          <div class="av-status"><span>9:41</span><span class="av-notch"></span><span>5G</span></div>
          <div class="av-site"><img src="<?= e($shotM('cafe_kettlerow')) ?>" alt="" width="780" height="1200" loading="eager"><div class="av-more"></div></div>
          <div class="av-card"><?= $ad('mrec', 'gym') ?></div>
          <div class="av-flash"><span>Viewable impression counted</span></div>
          <div class="av-bar"><?= $ad('bar', 'gym') ?><span class="av-bar__x">×</span></div>
          <div class="av-modal"><div class="av-modal__box"><?= $ad('modal', 'gym') ?></div></div>
        </div></div>
        <div class="av-fc av-camp lg-glass lg-glass--thick"><span class="av-dot av-dot--brand"><svg viewBox="0 0 24 24" class="ic"><path d="M4 11h4l3 8 3-16 3 8h3"/></svg></span><div><b>Ironhouse Fitness · Manchester</b><small>Gyms, cafés and salons within 10 miles · 4 weeks</small><span class="av-live" style="margin-top:.8cqw"><i></i><span data-av="status">Live · Manchester</span></span></div></div>
        <div class="av-fc av-count lg-glass lg-glass--thick"><b>Today, so far</b><span>Viewable impressions <i data-av="vi">3,104</i></span><span>Clicks <i data-av="cl">41</i></span><span>Invalid traffic <i>0.9%</i></span></div>
        <div class="av-fc av-ok lg-glass lg-glass--thick" data-av="ok" style="transition:opacity .5s"><span class="av-dot"><?= $check ?></span><div><b>Creatives approved</b><small>Reviewed by a person · 3 sizes · brand safe</small></div></div>
        <div class="av-fc av-on lg-glass lg-glass--thick"><span class="av-thumbs"><img src="<?= e($shot('cafe_kettlerow')) ?>" alt="" width="1440" height="900" loading="lazy"><img src="<?= e($shot('dental_elmrow')) ?>" alt="" width="1440" height="900" loading="lazy"><img src="<?= e($shot('beauty_salon')) ?>" alt="" width="1440" height="900" loading="lazy"><img src="<?= e($shot('barbershop_fadehouse')) ?>" alt="" width="1440" height="900" loading="lazy"></span><div><b>Appearing on local websites</b><small>Cafés, clinics, salons and barbers that match your plan</small></div></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="why" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>Why it works</span><h2 class="mk-h2" data-rv>Not a feed. Not a search result. The website of the business next door.</h2><p class="mk-lead" data-rv>When someone opens a local café's website, they are a few streets away and already in a buying frame of mind. Your ad meets them there, on a page with nothing else competing for attention, labelled honestly and shown with restraint.</p></div>
      <div class="ad-grid3">
        <div class="ad-card lg-glass lg-glass--thick"><span class="t-eyebrow">Context</span><h3>Local intent, no guessing</h3><p>Every website in the network is profiled by trade, town and the interests of its visitors. You pick the trades and the area; your ad appears only where they match.</p><div class="lg-row gap-2" style="flex-wrap:wrap"><span class="ad-chip">Cafés · Manchester</span><span class="ad-chip">Dental · Leeds</span><span class="ad-chip">Fitness · Dubai</span></div></div>
        <div class="ad-card lg-glass lg-glass--thick"><span class="t-eyebrow">Attention</span><h3>Three placements, one advertiser at a time</h3><p>A slim bar, one card below the hero, one full-screen message on a second visit. No ad walls, no auto-playing video, no ad next to your competitor's.</p><div><div class="ad-spec"><span>Ads per page, at most</span><b>3</b></div><div class="ad-spec"><span>Paid ads per visitor, per day</span><b>12</b></div><div class="ad-spec"><span>Full-screen message</span><b>Once per 3 min, from the 2nd visit</b></div></div></div>
        <div class="ad-card lg-glass lg-glass--thick"><span class="t-eyebrow">Honesty</span><h3>You pay for what was seen</h3><p>CPM bills a viewable impression only: half the ad on screen for a second, the IAB/MRC standard. Invalid traffic is counted and shown, never quietly netted out.</p><div><div class="ad-spec"><span>Served, not seen</span><b>$0</b></div><div class="ad-spec"><span>Viewable</span><b>Billed</b></div><div class="ad-spec"><span>Invalid traffic</span><b>Reported, not billed</b></div></div></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="placements" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>The placements</span><h2 class="mk-h2" data-rv>Three ways to be seen. Each one designed to be noticed, not endured.</h2><p class="mk-lead" data-rv>Every placement is labelled "Sponsored" and can be closed. The sizes are the industry's, so a creative you already have will fit.</p></div>
      <div class="pl-grid">
        <div class="ad-card lg-glass lg-glass--thick">
          <div class="pl-stage" aria-label="Example: the footer bar arriving on a café's website on a phone"><span class="pl-tag lg-glass lg-glass--thin"><i></i>Footer bar</span><div class="pl-phone"><div class="pl-screen"><img src="<?= e($shotM('cafe_kettlerow')) ?>" alt="" width="780" height="1200" loading="lazy"><div class="av-more"></div><div class="pl-bar"><?= $ad('bar', 'gym') ?><span class="pl-bar__x">×</span></div></div></div></div>
          <h3>The footer bar</h3><p>A slim bar along the bottom of the screen, on every page of the website. Closes to a tab and stays closed for a minute, so it is present without being in the way.</p>
          <div><div class="ad-spec"><span>Size</span><b>320 × 50</b></div><div class="ad-spec"><span>Devices</span><b>Phone, tablet, desktop</b></div><div class="ad-spec"><span>Best for</span><b>Offers and reminders</b></div></div>
        </div>
        <div class="ad-card lg-glass lg-glass--thick">
          <div class="pl-stage" aria-label="Example: the card appearing below the hero of a dental clinic's website"><span class="pl-tag lg-glass lg-glass--thin"><i></i>Card below the hero</span><div class="pl-phone"><div class="pl-screen"><img src="<?= e($shotM('dental_elmrow')) ?>" alt="" width="780" height="1200" loading="lazy"><div class="av-more"></div><div class="pl-card"><?= $ad('mrec', 'cafe') ?></div></div></div></div>
          <h3>The card below the hero</h3><p>One card, directly under the website's opening section, where the eye lands after the headline. Refreshes at most every 30 seconds while the visitor reads.</p>
          <div><div class="ad-spec"><span>Size</span><b>300 × 250</b></div><div class="ad-spec"><span>Devices</span><b>Phone, tablet, desktop</b></div><div class="ad-spec"><span>Best for</span><b>Brand and product stories</b></div></div>
        </div>
        <div class="ad-card lg-glass lg-glass--thick">
          <div class="pl-stage" aria-label="Example: the full-screen message on a salon's website"><span class="pl-tag lg-glass lg-glass--thin"><i></i>Full-screen message</span><div class="pl-phone"><div class="pl-screen"><img src="<?= e($shotM('beauty_salon')) ?>" alt="" width="780" height="1200" loading="lazy"><div class="av-more"></div><div class="pl-modal"><div class="pl-modal__box"><?= $ad('modal', 'restaurant') ?></div></div></div></div></div>
          <h3>The full-screen message</h3><p>The whole screen, once. Shown after eight seconds on a second visit, never to someone arriving from a search, never on a checkout or booking page, and the visitor can close it after six seconds.</p>
          <div><div class="ad-spec"><span>Size</span><b>1080 × 1080 or 3:4</b></div><div class="ad-spec"><span>Frequency</span><b>At most once every 3 minutes</b></div><div class="ad-spec"><span>Best for</span><b>Launches and events</b></div></div>
        </div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="network" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>The network</span><h2 class="mk-h2" data-rv>Real businesses. Real visitors. The places people actually look.</h2><p class="mk-lead" data-rv>The network is made of the websites local businesses build and run on LevelUpGrowth's free plan: the café on the corner, the clinic, the salon, the garage, the venue. New websites join every week, and each one is profiled before it carries a single paid ad.</p></div>
      <div class="nw-sectors" data-rv style="margin-bottom:18px"><?php foreach ($sectors as $s): ?><span class="ad-chip lg-glass lg-glass--thin" style="height:34px;padding:0 12px;font-size:13px"><?= e($s) ?></span><?php endforeach; ?></div>
      <div class="nw-strip" data-rv role="list" aria-label="Examples of websites in the network">
        <?php foreach ($network as [$slug, $biz, $sub]): ?>
        <div class="nw-site" role="listitem"><img src="<?= e($shotM($slug)) ?>" alt="<?= e($biz . ' website') ?>" width="780" height="1200" loading="lazy" decoding="async"><div class="nw-site__m"><b><?= e($biz) ?></b><span><?= e($sub) ?></span></div></div>
        <?php endforeach; ?>
      </div>
      <p class="t-caption c-3" style="margin:0">Examples of websites built on the platform. Your ad appears only on websites that match the trades, area and interests in your plan.</p>
    </div>
  </section>

  <section class="mk-sec" id="plan" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head ad-head--c"><span class="t-eyebrow" data-rv>Your media plan</span><h2 class="mk-h2" data-rv>Say who you want to reach. We check the inventory and come back with a plan.</h2><p class="mk-lead" data-rv style="text-align:center">Build the outline here; it becomes your brief. We confirm how many matching websites exist and what reach they carry before you pay anything.</p></div>
      <div class="lg-glass lg-glass--thick pb" id="ad-pb">
        <div class="pb__in">
          <div><span class="pb-lbl">Trades whose customers you want <span>up to 3</span></span><div class="pb-segs" role="group" aria-label="Trades"><?php foreach ($sectors as $s): ?><button type="button" data-trade="<?= e($s) ?>" aria-pressed="false"><?= e($s) ?></button><?php endforeach; ?></div></div>
          <div><span class="pb-lbl">Area</span><div class="pb-segs" role="group" aria-label="Area" style="margin-bottom:8px"><button type="button" data-area="Manchester" aria-pressed="true">Manchester</button><button type="button" data-area="London" aria-pressed="false">London</button><button type="button" data-area="Dubai" aria-pressed="false">Dubai</button><button type="button" data-area="Your area" aria-pressed="false">Somewhere else</button></div><input class="pb-in" data-area-in type="text" value="Manchester" placeholder="Type a town, a region or a country" aria-label="Area"></div>
          <div><span class="pb-lbl">Placements</span><div class="pb-segs" role="group" aria-label="Placements"><button type="button" data-place="footer_sticky" aria-pressed="true">Footer bar</button><button type="button" data-place="in_content_mrec" aria-pressed="true">Card below the hero</button><button type="button" data-place="interstitial_modal" aria-pressed="false">Full-screen message</button></div></div>
          <div class="ad-grid2" style="gap:16px">
            <div><span class="pb-lbl">How you want to pay</span><div class="pb-segs" role="group" aria-label="Pricing model"><button type="button" data-model="cpm" aria-pressed="true">Per 1,000 seen</button><button type="button" data-model="cpc" aria-pressed="false">Per click</button><button type="button" data-model="flat" aria-pressed="false">Flat monthly</button></div></div>
            <div><span class="pb-lbl">Duration</span><div class="pb-segs" role="group" aria-label="Duration"><button type="button" data-weeks="2" aria-pressed="false">2 weeks</button><button type="button" data-weeks="4" aria-pressed="true">4 weeks</button><button type="button" data-weeks="8" aria-pressed="false">8 weeks</button></div></div>
          </div>
          <div><span class="pb-lbl">Creatives</span><div class="pb-segs" role="group" aria-label="Creatives"><button type="button" data-design="yes" aria-pressed="true">Design them for us</button><button type="button" data-design="no" aria-pressed="false">We have our own</button></div></div>
        </div>
        <div class="pb__out">
          <span class="t-eyebrow">Your plan</span>
          <div class="pb-plan">
            <div class="pb-row"><span>Reaching customers of</span><b data-out="trades">Food &amp; drink</b></div>
            <div class="pb-row"><span>In</span><b data-out="area">Manchester</b></div>
            <div class="pb-row"><span>Placements</span><b data-out="places">Footer bar + Card below the hero</b></div>
            <div class="pb-row"><span>Pricing</span><b data-out="model">Per 1,000 viewable impressions (CPM)</b></div>
            <div class="pb-row"><span>Duration</span><b data-out="weeks">4 weeks</b></div>
            <div class="pb-row"><span>Creatives</span><b data-out="design">Designed by our studio in your brand, approved by you</b></div>
          </div>
          <div><div class="pb-price" data-out="price">Quoted with your reach</div><p class="pb-note" data-out="price-small" style="margin-top:6px">The rate card comes with your media plan, within two working days.</p></div>
          <div class="pb-next">
            <div><?= $check ?><span>We count the websites that match and project their real traffic. If fewer than three match or fewer than 10,000 monthly impressions, we tell you so instead of quoting.</span></div>
            <div><?= $check ?><span>You get the plan, the reach and the price in writing. Nothing is charged until you approve it.</span></div>
          </div>
          <button type="button" class="lg-btn lg-btn--primary lg-btn--lg" data-send style="align-self:flex-start">Send this plan as my brief <?= $arrow ?></button>
        </div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="how" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>How a campaign runs</span><h2 class="mk-h2" data-rv>From brief to report in five steps. Most of them are ours.</h2></div>
      <div class="ad-flow">
        <?php foreach ([
          ['Brief', 'Tell us the trades, the area, the message and the budget. Two minutes with the builder above.', 'You', '<b>Media plan request</b><span>Food &amp; drink · Manchester · 4 weeks</span><span class="ok">' . $check . ' Sent</span>'],
          ['Plan and quote', 'We check the matching inventory and send a plan: websites, reach, placements, price, dates.', 'Us · within 2 working days', '<div class="lg-row lg-between"><span>Matching websites</span><b>Confirmed</b></div><div class="lg-row lg-between"><span>Projected reach</span><b>From real traffic</b></div><div class="lg-row lg-between"><span>Price</span><b>In writing</b></div>'],
          ['Creatives', 'Send yours in the three sizes, or our studio designs them in your brand. A person reviews every creative.', 'You and us', '<div class="lg-row lg-between"><span>320 × 50 · 300 × 250 · 1080 × 1080</span><span class="ok">' . $check . ' Approved</span></div><span>Reviewed for brand safety and quality</span>'],
          ['Live', 'Your campaign serves on matching websites, within its dates and never beyond its budget. Change or pause it any time.', 'The network', '<span class="ok">' . $check . ' Live</span><div class="lg-row lg-between"><span>Budget used</span><b>38%</b></div><div class="lg-row lg-between"><span>Pacing</span><b>Even, on schedule</b></div>'],
          ['Report', 'Impressions, viewable impressions, clicks and spend by day, placement and website, invalid traffic shown separately.', 'Us · nightly, UTC', '<div class="lg-row lg-between"><span>Viewable</span><b>66.7%</b></div><div class="lg-row lg-between"><span>Invalid traffic</span><b>2.3%, not billed</b></div>'],
        ] as $i => [$h, $t, $who, $mini]): ?>
        <div class="ad-step lg-glass lg-glass--thick"><span class="ad-step__n"><?= $i + 1 ?></span><b><?= e($h) ?></b><p><?= $t ?></p><span class="ad-step__who"><?= e($who) ?></span><div class="ad-mini"><?= $mini ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="report" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>Your report</span><h2 class="mk-h2" data-rv>Numbers you can take to your accountant.</h2><p class="mk-lead" data-rv>Every figure is rolled up nightly in one timezone and says how fresh it is. Gross, invalid and net are always three separate numbers, so what you were billed for is never in doubt.</p></div>
      <div class="rp lg-glass lg-glass--thick" id="ad-rp" aria-label="Example campaign report">
        <div class="rp-top"><div class="lg-stack" style="gap:2px"><span class="t-subhead t-strong">Ironhouse Fitness · Manchester · 4 weeks</span><span class="t-caption c-3">Example report · figures to 23:59 UTC yesterday · today is partial</span></div><span class="lg-badge lg-badge--success">Live · day 19 of 28</span></div>
        <div class="rp-body">
          <div class="rp-kpi lg-glass lg-glass--thin"><span>Impressions, net</span><b data-n="47090">0</b><small>48,210 gross · 1,120 invalid</small></div>
          <div class="rp-kpi lg-glass lg-glass--thin"><span>Viewable</span><b data-n="31418">0</b><small><span data-n="66.7" data-fmt="pct">0%</span> of net impressions</small></div>
          <div class="rp-kpi lg-glass lg-glass--thin"><span>Clicks</span><b data-n="412">0</b><small><span data-n="1.31" data-fmt="pct">0%</span> of viewable</small></div>
          <div class="rp-kpi lg-glass lg-glass--thin"><span>Spend</span><b data-n="251.34" data-fmt="money">$0</b><small>viewable impressions only · 38% of budget</small></div>
          <div class="rp-split">
            <div class="rp-panel lg-glass lg-glass--thin"><div class="rp-h"><b>Viewable impressions by day</b><span>last 14 days</span></div><div class="rp-days" aria-hidden="true"><?php foreach ([.42, .55, .61, .58, .72, .8, .66, .7, .76, .83, .9, .86, .95, 1] as $h): ?><i data-h="<?= $h ?>"></i><?php endforeach; ?></div><div class="rp-inv"><div><span>Gross</span><b>48,210</b></div><div><span>Invalid, not billed</span><b>1,120</b></div><div><span>Net</span><b>47,090</b></div></div></div>
            <div class="rp-panel lg-glass lg-glass--thin"><div class="rp-h"><b>By website</b><span>viewable</span></div><div class="rp-bars"><?php foreach ([['Kettle Row Coffee · café', 9240, 100], ['Elm Row Dental · clinic', 7115, 77], ['Rosefinch Beauty · salon', 6402, 69], ['Fadehouse · barbershop', 4871, 53], ['11 more websites', 3790, 41]] as [$n, $v, $w]): ?><div class="rp-bar"><div><span><?= e($n) ?></span><i data-w="<?= $w ?>"></i></div><b><?= number_format($v) ?></b></div><?php endforeach; ?></div></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="packages" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>Packages</span><h2 class="mk-h2" data-rv>Start with one street. Grow to a whole trade.</h2><p class="mk-lead" data-rv>Three ways in, each built from the same placements and the same rules. Every package can be bought per thousand viewable impressions, per click or as a flat month.</p></div>
      <div class="pk">
        <div class="pk-card lg-glass lg-glass--thick"><span class="t-eyebrow">Local Presence</span><h3>Your area, your trade</h3><p>The footer bar and the card on the websites of up to three trades in one town or area. The way most advertisers start.</p>
          <div class="pk-price"><?php if ($priced): ?><b><?= $m0((float) $rates['flat']['local']) ?></b><span>a month, flat · or CPM / CPC</span><?php else: ?><b style="font-size:22px">Rate card on request</b><?php endif; ?></div>
          <ul class="ad-list pk-feat"><li><?= $check ?>Footer bar + card below the hero</li><li><?= $check ?>One area, up to three trades</li><li><?= $check ?>Reach confirmed before you pay</li><li><?= $check ?>Weekly report by email</li></ul></div>
        <div class="pk-card lg-glass lg-glass--thick" style="box-shadow:inset 0 0 0 1.5px var(--accent-text,#7c3aed)"><span class="t-eyebrow" style="color:var(--accent-text,#7c3aed)">Area Takeover</span><h3>Every placement, one area, yours</h3><p>All three placements, including the full-screen message, across the matching websites of one town or area, with no other paid advertiser in your trade group there.</p>
          <div class="pk-price"><?php if ($priced): ?><b><?= $m0((float) $rates['flat']['area']) ?></b><span>a month, flat · or CPM</span><?php else: ?><b style="font-size:22px">Rate card on request</b><?php endif; ?></div>
          <ul class="ad-list pk-feat"><li><?= $check ?>Bar + card + full-screen message</li><li><?= $check ?>Exclusive in your trade group for the area</li><li><?= $check ?>Ad set designed in your brand, included</li><li><?= $check ?>Daily report and a named contact</li></ul></div>
        <div class="pk-card lg-glass lg-glass--thick"><span class="t-eyebrow">Industry Sponsor</span><h3>One trade, the whole network</h3><p>Be the sponsor of a trade wherever it appears: every café, or every clinic, or every salon on the network, in every area, for a month at a time.</p>
          <div class="pk-price"><?php if ($priced): ?><b><?= $m0((float) $rates['flat']['industry']) ?></b><span>a month, flat</span><?php else: ?><b style="font-size:22px">Rate card on request</b><?php endif; ?></div>
          <ul class="ad-list pk-feat"><li><?= $check ?>All placements across one trade, network-wide</li><li><?= $check ?>First right to renew each month</li><li><?= $check ?>Ad set designed and refreshed monthly</li><li><?= $check ?>Daily report and a named contact</li></ul></div>
      </div>
      <div class="pk-models">
        <div class="lg-glass lg-glass--thin"><b>Per 1,000 viewable impressions (CPM)</b>You pay for impressions that met the IAB/MRC viewability rule. Served-but-unseen impressions cost nothing. Rates differ by placement.</div>
        <div class="lg-glass lg-glass--thin"><b>Per click (CPC)</b>You pay when someone clicks through from the bar or the card, counted at our own click endpoint. Not offered on the full-screen message.</div>
        <div class="lg-glass lg-glass--thin"><b>Flat month</b>A fixed price for a placement set over a calendar month, with the reach we confirmed in your plan. Simple to budget, simple to renew.</div>
      </div>
      <p class="t-caption c-3" style="margin-top:14px"><?php if ($priced): ?>Prices in USD before tax. Minimum campaign <?= $m0((float) $rates['minimum']) ?>. An ad set designed by our studio is <?= $m0((float) $rates['creative_set']) ?> once where it is not included.<?php else: ?>Prices in USD before tax. The rate card is sent with your media plan; nothing is charged until you approve the plan.<?php endif; ?></p>
    </div>
  </section>

  <section class="mk-sec" id="rules" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="ad-head"><span class="t-eyebrow" data-rv>The rules</span><h2 class="mk-h2" data-rv>Strict on purpose. It is why visitors still read the ads.</h2></div>
      <div class="ru">
        <div class="ad-card lg-glass lg-glass--thick"><h3>What we never show</h3><p>Not on any website, not next to your ad, not at any price.</p><div class="ru-no"><span>Adult</span><span>Gambling</span><span>Crypto</span><span>Pharma</span><span>Political</span><span>Weapons</span></div><p>Every creative is reviewed by a person before it serves, and a website's owner can switch a placement off on their own site.</p></div>
        <div class="ad-card lg-glass lg-glass--thick"><h3>How every ad behaves</h3>
          <ul class="ad-list">
            <li><?= $check ?>Labelled "Sponsored", every time, on every placement.</li>
            <li><?= $check ?>Closable by the visitor. The bar closes to a tab; the full-screen message closes after six seconds.</li>
            <li><?= $check ?>At most twelve paid ads a day per visitor across the network.</li>
            <li><?= $check ?>No video, no sound, no ads on checkout, cart, account or booking pages.</li>
            <li><?= $check ?>No paid ad on a website in its first week, or on inventory we cannot profile with confidence.</li>
            <li><?= $check ?>Click-through goes to your website, never to a form that harvests the visitor's details.</li>
          </ul></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px">
    <div class="mk-wrap ad-faq">
      <div class="ad-head ad-head--c"><span class="t-eyebrow">Questions</span><h2 class="mk-h2">What advertisers ask us.</h2></div>
      <div class="faq"><?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" id="brief" style="padding-top:40px;padding-bottom:72px">
    <div class="mk-wrap br">
      <div class="lg-stack gap-4" style="padding-top:8px">
        <span class="t-eyebrow">Your brief</span>
        <h2 class="mk-h2">Tell us who you want to reach.</h2>
        <p class="mk-lead">The plan you built above is already in the message. Add a line about your business and what you want people to do, and we come back within two working days with the websites, the reach and the price.</p>
        <ul class="ad-list">
          <li><?= $check ?>No commitment. The plan and the quote are free.</li>
          <li><?= $check ?>A person reads every brief and replies by email.</li>
          <li><?= $check ?>Nothing is charged until you approve the plan in writing.</li>
        </ul>
      </div>
      <form class="lg-glass lg-glass--thick ad-form" id="ad-brief" data-api="/api/public/contact" method="post" action="/api/public/contact" novalidate>
        <input type="hidden" name="topic" value="advertising">
        <input type="hidden" name="source_page" value="/advertising/">
        <div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website_confirm" tabindex="-1" autocomplete="off"></label></div>
        <div class="f2"><label>Your name<input type="text" name="name" required minlength="2" maxlength="120" autocomplete="name"></label><label>Work email<input type="email" name="email" required maxlength="190" autocomplete="email"></label></div>
        <label>Business<input type="text" name="company" maxlength="150" autocomplete="organization" placeholder="Your business and its website"></label>
        <label>Your plan and your message<textarea name="message" required minlength="10" maxlength="4000" data-auto="1"></textarea></label>
        <label class="consent"><input type="checkbox" name="consent" value="1" required><span>You may reply to me by email about this brief. I have read the <a href="/next/legal/privacy/">privacy notice</a>.</span></label>
        <p class="form-error" role="alert" hidden></p>
        <div class="lg-row lg-between ad-form__send" style="flex-wrap:wrap;gap:10px"><button type="submit" class="lg-btn lg-btn--primary lg-btn--lg">Send my brief <?= $arrow ?></button><span class="t-caption c-3">Reply within 2 working days</span></div>
        <div class="form-success" hidden><span class="av-dot" style="width:40px;height:40px"><?= $check ?></span><h3 style="margin:10px 0 4px;font-size:20px;color:var(--ink)">Received.</h3><p style="margin:0;color:var(--ink-2)">Reference <strong class="ref"></strong>. A person will reply with the websites, the reach and the price within two working days.</p></div>
      </form>
    </div>
  </section>
</div>
