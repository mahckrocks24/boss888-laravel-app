<?php
/** @var array $page */ /** @var array $data */
/*
 * Advertising - ADV-PAGE-1 (Owner 2026-10-06: "add another page on LUG marketing website. Advertising. This is just a
 * landing page for people who would like to advertise on the FREE websites with ads at LUG. Map the current ads at LUG Free
 * Accounts."). Every fact below is the ad engine's own rule (app/Engines/Ads, ad_slots, ad_settings as of 2026-10-06):
 * three active placements (footer sticky bar 320x50, card below the hero 300x250, full-screen interstitial 1080x1080 or
 * 3:4), free-plan sites only, a "Sponsored" label, the category blocklist, human review of every creative, viewable
 * impressions by the IAB/MRC rule, the 12-per-visitor-per-day cap for paid campaigns, no paid ad on inventory classified
 * below 0.75 confidence, no paid ad on a site in its first week, CPM / CPC / flat pricing, a quote only where at least 3
 * sites and 10,000 impressions a month match. No audience numbers are stated: the engine's rollup has not run and the
 * event log is mostly our own certification traffic. The brief lands in the company CRM like Contact (topic: advertising).
 */
$page['title'] = 'Advertising';
$page['noindex'] = true;   // the whole site is noindex by the Owner's decision (2026-09-29); this page follows it
$page['description'] = 'Put your ad on the free business websites built on LevelUpGrowth: a bar, a card below the hero and a full-screen card, on real local businesses in your trade and your area. Send a brief; a person replies with reach and a price.';
$faq = [
    ['Where exactly does my ad appear?', 'On websites built and hosted on LevelUpGrowth whose owners are on the free plan. Those sites carry three sponsored placements: a bar that stays on screen, a card directly below the hero, and a full-screen card that appears once a visitor has read a second page. Businesses on a paid plan carry no ads at all, so you are never placed next to a competitor who pays us for a clean site.'],
    ['Can I choose which sites?', 'You choose the audience, not the site. Targeting runs by business family (medical, hospitality, trades, retail, professional services, design and construction, education, media, appointment services), by interest, and by place: country, region, city or the service area a business names. A site is only sold against a target we are confident about; if our classification of a site is uncertain, it carries our own ads instead of yours.'],
    ['How is an impression counted?', 'An impression is billed only when it is viewable by the IAB and MRC rule: at least half of the ad\'s pixels on screen for at least one continuous second. Invalid traffic is removed before anything is billed. Clicks are counted on our own click endpoint, not by your landing page.'],
    ['What can I not advertise?', 'Adult content, gambling, cryptocurrency, pharmaceuticals, political advertising and weapons are refused. Every creative is reviewed by a person before it serves, and the owner of a free site can report an ad that does not belong on their pages.'],
    ['What do I need to supply?', 'A click destination and the creative for the placements you buy: text with your logo for the bar, a 300 by 250 card, and a 1080 by 1080 or 1080 by 1440 image for the full-screen card, with the short edge at least 600 pixels. We can build the creative from your brand if you prefer.'],
    ['How often will one person see my ad?', 'At most twelve times a day per visitor, across every free site they visit. The full-screen card appears no more than once every three minutes, never on a checkout, cart, account or booking page, and never to a visitor who has just arrived from a search engine.'],
    ['How am I billed, and what do I see?', 'Per thousand viewable impressions, per click, or as a flat monthly sponsorship, agreed before the campaign starts and with a total budget you set. Your report shows viewable impressions, clicks and spend by day, from the same table we bill from, so the two can never disagree.'],
    ['Do you quote on any audience?', 'No. We quote only where at least three live sites and about ten thousand impressions a month match your target; smaller audiences are told so honestly rather than sold.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$check = icon('check', 16);
$page['head'] = <<<'CSS'
<style id="adv-css">
.adv{--adv-gap:clamp(56px,8vw,104px)}
.adv-sec{padding:var(--adv-gap) 0}
.adv-sec--tight{padding-top:24px}
.adv-head{max-width:720px;display:flex;flex-direction:column;gap:12px;margin-bottom:36px}
.adv-head--c{margin-inline:auto;text-align:center;align-items:center}
.adv-h1{font-size:clamp(38px,5.4vw,64px);line-height:1.04;letter-spacing:-.035em;font-weight:800;margin:0}
.adv-h2{font-size:clamp(28px,3.6vw,44px);line-height:1.1;letter-spacing:-.028em;font-weight:800;margin:0}
.adv-lead{font-size:clamp(17px,1.5vw,20px);line-height:1.55;opacity:.82;margin:0}
.adv-hero{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(32px,5vw,72px);align-items:center;padding-top:clamp(64px,5.5vw,72px)}
.adv-cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
.adv-trust{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:6px}
.adv-trust span{display:inline-flex;align-items:center;gap:6px;font-size:13px;opacity:.75}
.adv-trust .ic{color:var(--accent-ink,#7c3aed)}
/* the three placements, drawn as phones so the shape is unmistakable */
.adv-phones{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.adv-phone{position:relative;border-radius:28px;border:1px solid var(--border,rgba(0,0,0,.1));background:var(--card,#fff);padding:10px;box-shadow:0 20px 50px -30px rgba(20,16,40,.45)}
.adv-screen{position:relative;border-radius:20px;overflow:hidden;aspect-ratio:9/17;background:var(--bg-2,#f3f2f8);display:flex;flex-direction:column}
.adv-nav{height:34px;display:flex;align-items:center;justify-content:space-between;padding:0 12px;background:var(--card,#fff);border-bottom:1px solid var(--border,rgba(0,0,0,.08));font-size:11px;font-weight:700}
.adv-nav i{display:block;width:16px;height:2px;background:currentColor;box-shadow:0 5px 0 currentColor,0 -5px 0 currentColor}
.adv-herob{margin:10px;height:34%;border-radius:12px;background:linear-gradient(160deg,#30324d,#6a5a8a 55%,#b78a6a);position:relative}
.adv-herob b{position:absolute;left:12px;bottom:14px;right:12px;height:10px;border-radius:5px;background:rgba(255,255,255,.85)}
.adv-herob b+b{bottom:30px;width:60%}
.adv-line{height:7px;border-radius:4px;background:var(--border,rgba(0,0,0,.12));margin:8px 12px 0}
.adv-line.s{width:55%}
.adv-ad{position:absolute;border-radius:10px;background:var(--grad,linear-gradient(90deg,#8C25D2,#4C86DE));color:#fff;font-size:10.5px;font-weight:700;display:flex;align-items:center;gap:8px;padding:0 10px;box-shadow:0 10px 24px -12px rgba(76,134,222,.8)}
.adv-ad small{font-weight:600;opacity:.8;font-size:9px;letter-spacing:.06em;text-transform:uppercase;margin-left:auto}
.adv-ad--bar{left:10px;right:10px;bottom:10px;height:30px}
.adv-ad--card{left:12px;right:12px;top:calc(34% + 54px);height:26%;flex-direction:column;align-items:flex-start;justify-content:center;gap:6px;padding:12px}
.adv-ad--card em{font-style:normal;font-size:13px;line-height:1.2}
.adv-ad--card u{text-decoration:none;font-size:10px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.22)}
.adv-dim{position:absolute;inset:0;background:rgba(16,14,30,.55)}
.adv-ad--modal{left:10%;right:10%;top:18%;aspect-ratio:1/1;height:auto;flex-direction:column;justify-content:center;gap:8px;padding:16px;text-align:center;border-radius:16px}
.adv-ad--modal em{font-style:normal;font-size:14px;line-height:1.2}
.adv-ad--modal u{text-decoration:none;font-size:10px;padding:5px 10px;border-radius:999px;background:rgba(255,255,255,.22)}
.adv-ad--modal i{position:absolute;top:8px;right:10px;font-style:normal;opacity:.85}
.adv-ph-cap{padding:14px 6px 4px}
.adv-ph-cap h3{margin:0 0 6px;font-size:16px}
.adv-ph-cap p{margin:0;font-size:14px;opacity:.8}
.adv-ph-cap .adv-spec{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.adv-spec span{font-family:var(--font-mono,ui-monospace,monospace);font-size:11.5px;padding:3px 8px;border-radius:999px;border:1px solid var(--border,rgba(0,0,0,.1));opacity:.85}
/* targeting + steps */
.adv-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.adv-card{border-radius:var(--r-lg,22px);border:1px solid var(--border,rgba(0,0,0,.1));background:var(--card,#fff);padding:22px;min-width:0}
.adv-card h3{margin:0 0 8px;font-size:17px}
.adv-card p{margin:0;font-size:14.5px;opacity:.82}
.adv-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.adv-chips span{font-size:12.5px;padding:4px 9px;border-radius:999px;background:var(--accent-soft,rgba(124,58,237,.08));color:var(--accent-ink,#5b21b6)}
.adv-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;counter-reset:s}
.adv-step{border-radius:var(--r-lg,22px);border:1px solid var(--border,rgba(0,0,0,.1));background:var(--card,#fff);padding:20px;position:relative;min-width:0}
.adv-step::before{counter-increment:s;content:counter(s,decimal-leading-zero);font-family:var(--font-mono,ui-monospace,monospace);font-size:12px;letter-spacing:.08em;color:var(--accent-ink,#7c3aed);display:block;margin-bottom:10px}
.adv-step h3{margin:0 0 6px;font-size:16px}.adv-step p{margin:0;font-size:14px;opacity:.82}
.adv-rules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 28px;margin:0;padding:0;list-style:none}
.adv-rules li{display:flex;gap:10px;align-items:flex-start;font-size:15px;padding:10px 0;border-bottom:1px dashed var(--border,rgba(0,0,0,.1))}
.adv-rules .ic{flex:none;margin-top:3px;color:var(--accent-ink,#7c3aed)}
.adv-brief{display:grid;grid-template-columns:.9fr 1.1fr;gap:clamp(28px,5vw,64px);align-items:start}
.adv-faq details{border-bottom:1px solid var(--border,rgba(0,0,0,.1));padding:14px 0}
.adv-faq summary{cursor:pointer;font-weight:700;font-size:16px;list-style:none;display:flex;justify-content:space-between;gap:12px}
.adv-faq summary::after{content:"+";font-weight:600;opacity:.6}
.adv-faq details[open] summary::after{content:"\2013"}
.adv-faq p{margin:10px 0 0;font-size:15px;opacity:.82}
@media (max-width:980px){.adv-phones{grid-template-columns:1fr 1fr}.adv-steps{grid-template-columns:1fr 1fr}.adv-grid{grid-template-columns:1fr 1fr}}
@media (max-width:860px){.adv-hero{grid-template-columns:1fr;padding-top:40px}.adv-brief{grid-template-columns:1fr}}
@media (max-width:640px){.adv-phones{grid-template-columns:1fr}.adv-steps{grid-template-columns:1fr}.adv-grid{grid-template-columns:1fr}.adv-rules{grid-template-columns:1fr}}
</style>
CSS;
?>
<div class="adv">
<section class="adv-sec" style="padding-top:0">
  <div class="container adv-hero">
    <div style="display:flex;flex-direction:column;gap:18px">
      <p class="eyebrow">Advertising on LevelUpGrowth</p>
      <h1 class="adv-h1">Your ad, on the websites of real local businesses.</h1>
      <p class="adv-lead">Every free website built on LevelUpGrowth carries sponsored placements: a bar that stays on screen, a card below the hero, and a full-screen card. Those pages belong to restaurants, salons, clinics, builders, agencies and schools, read by the people who are about to call them. Tell us who you want to reach; a person replies with the reach and a price.</p>
      <div class="adv-cta">
        <a class="btn btn-primary btn-lg" href="#brief">Send a brief <?= icon('arrow-right', 18) ?></a>
        <a class="btn btn-secondary btn-lg" href="#placements">See the placements</a>
      </div>
      <div class="adv-trust">
        <span><?= $check ?>Marked “Sponsored”, always</span>
        <span><?= $check ?>Billed on viewable impressions only</span>
        <span><?= $check ?>Every creative reviewed by a person</span>
        <span><?= $check ?>No adult, gambling, crypto, pharma, political or weapons</span>
      </div>
    </div>
    <div class="adv-phone" aria-hidden="true">
      <div class="adv-screen">
        <div class="adv-nav"><span>Harbour Dental</span><i></i></div>
        <div class="adv-herob"><b></b><b></b></div>
        <div class="adv-ad adv-ad--card"><em>Your ad here</em><span style="font-weight:500;opacity:.9">300 × 250, in the page, under the hero.</span><u>Learn more</u><small>Sponsored</small></div>
        <div class="adv-line" style="margin-top:calc(26% + 70px)"></div><div class="adv-line s"></div><div class="adv-line"></div>
        <div class="adv-ad adv-ad--bar"><span>Your ad here · 320 × 50</span><small>Sponsored</small></div>
      </div>
    </div>
  </div>
</section>

<section class="adv-sec adv-sec--tight" id="placements">
  <div class="container">
    <div class="adv-head"><p class="eyebrow">The placements</p><h2 class="adv-h2">Three places on every page, each with its own rules.</h2><p class="adv-lead">The same three on every free site, phone and desktop. Nothing is injected anywhere else, and the owner of the site can never be surprised by where an ad appears.</p></div>
    <div class="adv-phones">
      <div>
        <div class="adv-phone" aria-hidden="true"><div class="adv-screen">
          <div class="adv-nav"><span>Ember &amp; Bean</span><i></i></div>
          <div class="adv-herob"><b></b><b></b></div>
          <div class="adv-line"></div><div class="adv-line s"></div><div class="adv-line"></div><div class="adv-line s"></div>
          <div class="adv-ad adv-ad--bar"><span>Your ad here</span><small>Sponsored</small></div>
        </div></div>
        <div class="adv-ph-cap"><h3>The bar</h3><p>Fixed to the bottom of the screen on every device, text and logo, the whole bar clickable. Closing it slides it down to a small tab; it returns a minute later.</p><div class="adv-spec"><span>320 × 50</span><span>every device</span><span>whole bar clickable</span></div></div>
      </div>
      <div>
        <div class="adv-phone" aria-hidden="true"><div class="adv-screen">
          <div class="adv-nav"><span>Vertex Construction</span><i></i></div>
          <div class="adv-herob"><b></b><b></b></div>
          <div class="adv-ad adv-ad--card"><em>Your ad here</em><span style="font-weight:500;opacity:.9">Where the visitor is reading.</span><u>Learn more</u><small>Sponsored</small></div>
          <div class="adv-line" style="margin-top:calc(26% + 70px)"></div><div class="adv-line s"></div>
        </div></div>
        <div class="adv-ph-cap"><h3>The card below the hero</h3><p>In the page itself, directly under the hero where the visitor has just started reading. It refreshes at most every thirty seconds and no more than ten times on one page.</p><div class="adv-spec"><span>300 × 250</span><span>every device</span><span>image or HTML</span></div></div>
      </div>
      <div>
        <div class="adv-phone" aria-hidden="true"><div class="adv-screen">
          <div class="adv-nav"><span>Greenleaf Medical</span><i></i></div>
          <div class="adv-herob"><b></b><b></b></div>
          <div class="adv-line"></div><div class="adv-line s"></div><div class="adv-line"></div>
          <div class="adv-dim"></div>
          <div class="adv-ad adv-ad--modal"><i>✕</i><em>Your ad here</em><span style="font-weight:500;opacity:.9">Full screen, once a visitor has settled in.</span><u>Learn more</u><small style="margin:0">Sponsored</small></div>
        </div></div>
        <div class="adv-ph-cap"><h3>The full-screen card</h3><p>Appears after eight seconds on a visitor's second page, at most once every three minutes, never on a checkout, cart, account or booking page, and never to someone who has just arrived from a search engine. It closes itself after six seconds.</p><div class="adv-spec"><span>1080 × 1080</span><span>or 3 : 4</span><span>no video</span></div></div>
      </div>
    </div>
  </div>
</section>

<section class="adv-sec">
  <div class="container">
    <div class="adv-head"><p class="eyebrow">Who sees it</p><h2 class="adv-h2">You buy an audience, not a list of sites.</h2><p class="adv-lead">Every free site is classified by what the business does and where it is, with a confidence score. Your campaign targets the classification; a site we are not confident about carries our own ads instead of yours. You get the audience you bought, or nothing.</p></div>
    <div class="adv-grid">
      <div class="adv-card"><h3>By trade</h3><p>Nine business families, down to the industry inside each one.</p><div class="adv-chips"><span>Medical &amp; Healthcare</span><span>Appointment Services</span><span>Professional &amp; Advisory</span><span>Design &amp; Construction</span><span>Retail &amp; Commerce</span><span>Hospitality &amp; Travel</span><span>Education &amp; Training</span><span>Media &amp; Publishing</span><span>Local Trade Services</span></div></div>
      <div class="adv-card"><h3>By interest</h3><p>Forty-one contextual interests derived from what each site is about, not from tracking its visitors.</p><div class="adv-chips"><span>Food &amp; Dining</span><span>Beauty &amp; Grooming</span><span>Home Improvement</span><span>Fitness &amp; Training</span><span>Real Estate</span><span>Travel &amp; Tourism</span><span>Automotive Care</span><span>Childcare &amp; Early Years</span><span>Legal Services</span><span>Events &amp; Weddings</span><span>…</span></div></div>
      <div class="adv-card"><h3>By place and device</h3><p>Country, region, city, or the service area a business names on its own site. Phone, desktop, or both.</p><div class="adv-chips"><span>Country</span><span>Region</span><span>City</span><span>Service area</span><span>Phone</span><span>Desktop</span></div></div>
    </div>
  </div>
</section>

<section class="adv-sec adv-sec--tight">
  <div class="container">
    <div class="adv-head"><p class="eyebrow">How it works</p><h2 class="adv-h2">Brief, quote, review, live.</h2></div>
    <div class="adv-steps">
      <div class="adv-step"><h3>Send a brief</h3><p>What you sell, where, who you want to reach, and a budget range. Two minutes in the form below.</p></div>
      <div class="adv-step"><h3>Reach and a price</h3><p>We check how many live sites and monthly impressions match your target and reply with both. We quote only where at least three sites and about ten thousand impressions a month match.</p></div>
      <div class="adv-step"><h3>Creative review</h3><p>You send the creative, or we build it from your brand. A person checks it against the rules before it can serve.</p></div>
      <div class="adv-step"><h3>Live and reported</h3><p>Your campaign outranks our own ads wherever it matches. Viewable impressions, clicks and spend by day, with invalid traffic removed before billing.</p></div>
    </div>
  </div>
</section>

<section class="adv-sec adv-sec--tight">
  <div class="container">
    <div class="adv-head"><p class="eyebrow">The rules</p><h2 class="adv-h2">What we promise the businesses whose pages carry you.</h2></div>
    <ul class="adv-rules">
      <li><?= $check ?><span>Only free-plan sites carry ads. A business that upgrades carries none, so no paying customer ever hosts a competitor's advertisement.</span></li>
      <li><?= $check ?><span>Every ad is labelled “Sponsored”.</span></li>
      <li><?= $check ?><span>A brand-new site carries only our own ads for its first week, so your brand never appears on pages nobody has looked at yet.</span></li>
      <li><?= $check ?><span>At most twelve paid impressions a day per visitor, across the whole network.</span></li>
      <li><?= $check ?><span>No adult, gambling, cryptocurrency, pharmaceutical, political or weapons advertising; a person approves every creative.</span></li>
      <li><?= $check ?><span>Frequency is counted from a hashed visitor signal that lives on our servers; no third-party cookies, nothing follows a visitor off the network.</span></li>
      <li><?= $check ?><span>Billing and your report read the same daily table, so they cannot disagree.</span></li>
      <li><?= $check ?><span>Pricing per thousand viewable impressions, per click, or a flat monthly sponsorship, with a budget you set.</span></li>
    </ul>
  </div>
</section>

<section class="adv-sec" id="brief">
  <div class="container adv-brief">
    <div>
      <p class="eyebrow">Send a brief</p>
      <h2 class="adv-h2">Tell us who you want in front of.</h2>
      <p class="adv-lead" style="margin-top:12px">What you sell, where, who you want to reach and a budget range is enough. The brief goes into our own CRM and a person replies with reach and a price, usually within two working days.</p>
      <ul class="feature-list" style="margin-top:18px">
        <li><?= icon('check', 18) ?><span>No minimum term; a budget you set</span></li>
        <li><?= icon('check', 18) ?><span>Creative built for you if you have none</span></li>
        <li><?= icon('check', 18) ?><span>Prefer email? hello@levelupgrowth.io, subject “Advertising”</span></li>
      </ul>
    </div>
    <form class="form card" id="advertise" data-api="/api/public/contact" method="post" action="/api/public/contact" novalidate>
      <input type="hidden" name="topic" value="advertising">
      <div class="form-row"><label for="ad-name">Your name</label><input id="ad-name" name="name" type="text" autocomplete="name" required minlength="2" maxlength="120"></div>
      <div class="form-row"><label for="ad-email">Work email</label><input id="ad-email" name="email" type="email" autocomplete="email" required maxlength="190"></div>
      <div class="form-row"><label for="ad-company">Company</label><input id="ad-company" name="company" type="text" autocomplete="organization" maxlength="150"></div>
      <div class="form-row"><label for="ad-message">Your brief</label><textarea id="ad-message" name="message" rows="6" required minlength="10" maxlength="4000" placeholder="What you sell, where, who you want to reach, and a monthly budget range."></textarea></div>
      <label class="check"><input type="checkbox" name="consent" value="1" required><span class="check-box" aria-hidden="true"><?= icon('check', 14) ?></span><span>You may reply to me by email. <a href="/next/legal/privacy/">Privacy</a>.</span></label>
      <div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website_confirm" tabindex="-1" autocomplete="off"></label></div>
      <input type="hidden" name="source_page" value="/next/advertising/">
      <p class="form-error" role="alert" hidden></p>
      <button class="btn btn-primary btn-lg" type="submit">Send the brief <?= icon('arrow-right', 18) ?></button>
      <div class="form-success" hidden><?= icon('check', 28) ?><h3>Received.</h3><p>Reference <strong class="ref"></strong>. A person will reply with reach and a price.</p></div>
    </form>
  </div>
</section>

<section class="adv-sec adv-sec--tight">
  <div class="container">
    <div class="adv-head"><p class="eyebrow">Questions</p><h2 class="adv-h2">Asked before the first brief.</h2></div>
    <div class="adv-faq"><?php foreach ($faq as $q): ?><details><summary><?= e($q[0]) ?></summary><p><?= e($q[1]) ?></p></details><?php endforeach; ?></div>
  </div>
</section>
</div>
