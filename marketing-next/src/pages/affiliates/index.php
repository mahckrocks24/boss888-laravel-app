<?php
/** @var array $page */ /** @var array $data */
/*
 * Affiliate Program, v2 (Owner 2026-10-05: "the score is 6/10, visually and copy is not enterprise grade" - "sell the
 * journey"). The page follows one affiliate from applying to being paid, shows what each step looks like, and lets the
 * reader work out what their audience is worth. Codes only: no tracking links, no cookies (DEC-0084). Every number is the
 * programme's rule (app/Core/Partners/PartnerProgram.php); prices come from the plans table at build time; nothing is
 * claimed that the platform does not do. No supplier names except the two payout options the affiliate chooses between.
 */
$page['title'] = 'Affiliate Program';
$page['description'] = 'Recommend LevelUpGrowth with your own voucher code and earn up to 20% of every monthly payment for six months. You decide how much becomes your audience\'s discount.';
$by = []; foreach ($data['plans'] as $p) { $by[$p['slug']] = $p; }
$calcPlans = array_values(array_filter(array_map(fn ($s) => isset($by[$s]) ? ['name' => $by[$s]['name'], 'price' => (float) $by[$s]['price_monthly']] : null, ['starter', 'ai-lite', 'growth', 'pro', 'agency'])));
$pro = (float) ($by['pro']['price_monthly'] ?? 199);
$m2 = fn ($n) => '$' . number_format($n, 2);
$faq = [
    ['Who can join?', 'Creators, vloggers, bloggers, newsletter writers, consultants and agencies whose audience runs a small business. A person reads every application, usually within 2 working days.'],
    ['How does a business count as mine?', 'When it signs up with one of your codes. There are no tracking links and no cookies: your code is the only thing that connects a business to you.'],
    ['How do I choose the split?', 'When you create a code you drag one bar per product. Left keeps more for you; right gives your audience a bigger discount. Discount plus commission always equals the share: 20% on monthly plans, 15% on yearly plans, 10% on new domains.'],
    ['Can I make more than one code?', 'Yes. Up to 10 active codes at once, each with its own name, split, end date and use limit, and its own results in your portal. Pause one and you can make another.'],
    ['When do I get paid?', 'Each commission is held for 30 days in case of a refund, then becomes ready. Ready balances are paid on the 15th of each month, by PayPal or Wise, from $50. Smaller balances roll over.'],
    ['Do free trials earn?', 'Earnings start with a business\'s first payment. A trial that becomes a paid plan earns from that payment on.'],
    ['What does not earn?', 'Credit top-ups, domain renewals and our $1 first-year domain offer. Your own account and any business you own or belong to do not count.'],
    ['What do I see about the businesses I refer?', 'When they joined, which code they used, their plan, how many payments have earned you money and what you earned, with their email partly hidden. Never their work, their customers or their website.'],
    ['Do I need a LevelUpGrowth plan?', 'No. Affiliates have their own portal. If you also run your business on LevelUpGrowth, the same login opens both.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$check = '<svg class="ic ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
$page['head'] = <<<'CSS'
<style id="af-css">
.af{--af-gap:clamp(56px,8vw,104px)}
.af-sec{padding:var(--af-gap) 0}
.af-sec--tight{padding-top:24px}
.af-head{max-width:720px;display:flex;flex-direction:column;gap:12px;margin-bottom:36px}
.af-head--c{margin-inline:auto;text-align:center;align-items:center}
.af-h1{font-size:clamp(38px,5.4vw,64px);line-height:1.04;letter-spacing:-.035em;font-weight:800;margin:0}
.af-h2{font-size:clamp(28px,3.6vw,44px);line-height:1.1;letter-spacing:-.028em;font-weight:800;margin:0}
.af-lead{font-size:clamp(17px,1.5vw,20px);line-height:1.55;color:var(--ink-2,inherit);opacity:.82;margin:0}
.af-hero{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(32px,5vw,72px);align-items:center;padding-top:clamp(120px,14vw,168px)}
.af-cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
.af-trust{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:6px}
.af-trust span{display:inline-flex;align-items:center;gap:6px;font-size:13px;opacity:.75}
.af-trust .ic{color:var(--accent-text,#7c3aed)}
.af-card{border-radius:var(--r-lg,22px);padding:22px;position:relative;overflow:hidden}
.af-code{font-family:var(--font-mono,ui-monospace,monospace);font-weight:700;letter-spacing:.06em;font-size:clamp(26px,3vw,34px);display:inline-flex;align-items:center;gap:12px;padding:12px 18px;border-radius:14px;border:1.5px dashed color-mix(in srgb,var(--accent-text,#7c3aed) 55%,transparent);background:color-mix(in srgb,var(--accent-text,#7c3aed) 7%,transparent)}
.af-code small{font-family:var(--font,inherit);font-size:12px;font-weight:600;letter-spacing:0;opacity:.7}
.af-bar{position:relative;height:40px;border-radius:12px;overflow:hidden;border:1px solid var(--hairline,rgba(0,0,0,.08));background:var(--fill-hover,rgba(0,0,0,.04));touch-action:none;user-select:none}
.af-bar__them{position:absolute;inset:0 auto 0 0;background:linear-gradient(90deg,#10b981,#34d399)}
.af-bar__you{position:absolute;inset:0 0 0 auto;background:var(--brand-grad,linear-gradient(90deg,#8C25D2,#4C86DE))}
.af-bar__h{position:absolute;top:50%;width:28px;height:28px;margin:-14px 0 0 -14px;border-radius:50%;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.25),0 0 0 3px rgba(255,255,255,.55)}
.af-bar[role=slider]{cursor:pointer;outline:none}.af-bar[role=slider]:focus-visible{box-shadow:0 0 0 3px color-mix(in srgb,var(--accent-text,#7c3aed) 35%,transparent)}
.af-split{display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin:10px 0 6px}
.af-split .them{color:#059669}.af-split .you{color:var(--accent-text,#7c3aed)}
.af-feed{display:flex;flex-direction:column;gap:8px;margin-top:16px;min-height:162px}
.af-evt{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:14px;font-size:14px;opacity:0;transform:translateY(6px);animation:afIn .5s var(--ease-out,ease) forwards}
.af-evt b{font-variant-numeric:tabular-nums}
.af-evt i{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;flex:none;background:color-mix(in srgb,var(--accent-text,#7c3aed) 12%,transparent);color:var(--accent-text,#7c3aed);font-style:normal}
.af-evt:nth-child(1){animation-delay:.4s}.af-evt:nth-child(2){animation-delay:1.3s}.af-evt:nth-child(3){animation-delay:2.2s}
@keyframes afIn{to{opacity:1;transform:none}}
.af-journey{position:relative;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}
.af-step{border-radius:20px;padding:20px;display:flex;flex-direction:column;gap:8px;min-height:236px}
.af-step__n{font:700 11px/1 var(--font-mono,ui-monospace,monospace);letter-spacing:.1em;opacity:.6}
.af-step h3{font-size:17px;line-height:1.25;margin:0;letter-spacing:-.01em}
.af-step p{font-size:13.5px;line-height:1.5;margin:0;opacity:.78}
.af-mini{margin-top:auto;border-radius:14px;padding:10px;font-size:12.5px;line-height:1.4;border:1px solid var(--hairline,rgba(0,0,0,.08));background:var(--fill-hover,rgba(0,0,0,.03));display:flex;flex-direction:column;gap:6px}
.af-mini .row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.af-mini code{font-family:var(--font-mono,ui-monospace,monospace);font-weight:700;letter-spacing:.04em}
.af-mini .ok{color:#059669;font-weight:700;display:inline-flex;align-items:center;gap:4px}
.af-mini .bar{height:10px;border-radius:6px;overflow:hidden;display:flex}.af-mini .bar i{display:block;height:100%}
.af-ticks{display:flex;gap:4px}.af-ticks i{flex:1;height:22px;border-radius:6px;background:var(--brand-grad,linear-gradient(90deg,#8C25D2,#4C86DE));opacity:.9}
.af-rail{height:3px;border-radius:2px;margin:18px 0 0;background:linear-gradient(90deg,var(--brand-violet,#8C25D2),var(--brand-blue,#4C86DE),var(--brand-aqua,#25C6D2));opacity:.55}
.af-calc{display:grid;grid-template-columns:1fr 1fr;gap:0;border-radius:26px;overflow:hidden}
.af-calc__in{padding:clamp(22px,3vw,36px);display:flex;flex-direction:column;gap:22px}
.af-calc__out{padding:clamp(22px,3vw,36px);display:flex;flex-direction:column;gap:18px;justify-content:center;border-left:1px solid var(--hairline,rgba(0,0,0,.08));background:color-mix(in srgb,var(--accent-text,#7c3aed) 5%,transparent)}
.af-lbl{font-size:13px;font-weight:700;margin-bottom:8px;display:block}
.af-segs{display:flex;flex-wrap:wrap;gap:6px}
.af-segs button{border:1px solid var(--hairline,rgba(0,0,0,.1));background:transparent;color:inherit;font:600 13px/1 var(--font,inherit);padding:10px 14px;border-radius:999px;cursor:pointer;min-height:40px}
.af-segs button[aria-pressed=true]{background:var(--ink,#111);color:var(--ground,#fff);border-color:transparent}
.af-step-in{display:flex;align-items:center;gap:10px}
.af-step-in button{width:44px;height:44px;border-radius:12px;border:1px solid var(--hairline,rgba(0,0,0,.1));background:transparent;color:inherit;font:600 20px/1 var(--font,inherit);cursor:pointer}
.af-step-in output{min-width:64px;text-align:center;font:800 28px/1 var(--font,inherit);font-variant-numeric:tabular-nums}
.af-big{font-size:clamp(40px,5vw,60px);font-weight:800;letter-spacing:-.03em;line-height:1;font-variant-numeric:tabular-nums}
.af-out-row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding-top:14px;border-top:1px solid var(--hairline,rgba(0,0,0,.08))}
.af-out-row b{font-size:20px;font-variant-numeric:tabular-nums;white-space:nowrap}.af-step--go{justify-content:center;align-items:flex-start;background:linear-gradient(135deg,#6d28d9,#3b5bdb)!important;color:#fff!important;border-color:transparent!important}.af-step--go h3,.af-step--go p,.af-step--go .af-step__n{color:#fff!important}.af-step--go h3{font-size:22px}.af-step--go p{opacity:.92}.af-step--go .lg-btn{background:#fff!important;color:#4c1d95!important}
.af-share{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.af-share .af-card{display:flex;flex-direction:column;gap:6px}
.af-pct{font-size:clamp(48px,6vw,72px);font-weight:800;letter-spacing:-.04em;line-height:1;background:var(--brand-grad,linear-gradient(90deg,#8C25D2,#4C86DE));-webkit-background-clip:text;background-clip:text;color:transparent}
.af-feats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.af-feats .af-card{display:flex;flex-direction:column;gap:8px}
.af-feats .ic-wrap{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--accent-text,#7c3aed) 12%,transparent);color:var(--accent-text,#7c3aed)}
.af-feats h3{font-size:18px;margin:4px 0 0;letter-spacing:-.01em}.af-feats p{margin:0;font-size:15px;line-height:1.55;opacity:.8}
.af-final{border-radius:28px;padding:clamp(28px,5vw,56px);text-align:center;display:flex;flex-direction:column;align-items:center;gap:14px}
.af-faq{max-width:820px;margin:0 auto}

@media (max-width:900px){.af-hero{grid-template-columns:1fr}.af-calc{grid-template-columns:1fr}.af-calc__out{border-left:0;border-top:1px solid var(--hairline,rgba(0,0,0,.08))}.af-share,.af-feats{grid-template-columns:1fr}}
@media (max-width:640px){.af-journey{grid-template-columns:1fr}.af-step{min-height:0}.af-rail{display:none}.af-out-row{flex-wrap:wrap}.af-out-row b{font-size:17px}}
@media (prefers-reduced-motion:reduce){.af-evt{animation:none;opacity:1;transform:none}}
</style>
CSS;
$plansJson = json_encode($calcPlans);
$page['scripts'] = <<<JS
<script id="af-js">
(function(){
  var P = $plansJson, st = { plan: Math.min(3, P.length - 1), n: 5, d: 5 };
  var host = document.getElementById('af-calc'); if (!host) return;
  var money = function (v) { return '\$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var seg = host.querySelector('[data-plans]'), out = function (k) { return host.querySelector('[data-out="' + k + '"]'); };
  seg.innerHTML = P.map(function (p, i) { return '<button type="button" data-i="' + i + '" aria-pressed="' + (i === st.plan) + '">' + p.name + ' \$' + p.price + '</button>'; }).join('');
  seg.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; st.plan = +b.getAttribute('data-i'); draw(); });
  host.querySelector('[data-minus]').addEventListener('click', function () { st.n = Math.max(1, st.n - 1); draw(); });
  host.querySelector('[data-plus]').addEventListener('click', function () { st.n = Math.min(100, st.n + 1); draw(); });
  var bar = host.querySelector('.af-bar'), set = function (d) { d = Math.max(0, Math.min(20, Math.round(d))); if (d !== st.d) { st.d = d; draw(); } };
  var at = function (x) { var r = bar.getBoundingClientRect(); set((x - r.left) / r.width * 20); };
  bar.addEventListener('pointerdown', function (e) { bar.setPointerCapture(e.pointerId); at(e.clientX); var mv = function (ev) { at(ev.clientX); }, up = function () { bar.removeEventListener('pointermove', mv); bar.removeEventListener('pointerup', up); }; bar.addEventListener('pointermove', mv); bar.addEventListener('pointerup', up); });
  bar.addEventListener('keydown', function (e) { var k = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1, PageUp: 5, PageDown: -5 }[e.key]; if (k) { e.preventDefault(); set(st.d + k); } if (e.key === 'Home') { e.preventDefault(); set(0); } if (e.key === 'End') { e.preventDefault(); set(20); } });
  function draw() {
    var p = P[st.plan], you = 20 - st.d, per = p.price * you / 100, pays = p.price * (100 - st.d) / 100;
    Array.prototype.forEach.call(seg.children, function (b, i) { b.setAttribute('aria-pressed', i === st.plan); });
    host.querySelector('[data-n]').textContent = st.n;
    var pct = st.d / 20 * 100;
    bar.querySelector('.af-bar__them').style.width = pct + '%'; bar.querySelector('.af-bar__you').style.width = (100 - pct) + '%'; bar.querySelector('.af-bar__h').style.left = pct + '%';
    bar.setAttribute('aria-valuenow', st.d); bar.setAttribute('aria-valuetext', 'Customer saves ' + st.d + '%, you earn ' + you + '%');
    host.querySelector('[data-them]').textContent = 'Customer saves ' + st.d + '%'; host.querySelector('[data-you]').textContent = 'You earn ' + you + '%';
    out('per').textContent = money(per); out('pays').textContent = money(pays) + '/mo';
    out('six').textContent = money(per * 6); out('month6').textContent = money(st.n * per * 6);
    out('year').textContent = money(st.n * per * 57);   // n new businesses a month, each earning for 6 payments: 1+2+3+4+5+6x7 = 57 payments in 12 months
  }
  draw();
})();
</script>
JS;
?>
<div class="af">
<section class="af-sec" style="padding-top:0">
  <div class="container af-hero">
    <div style="display:flex;flex-direction:column;gap:18px">
      <span class="lg-badge lg-badge--brand" style="align-self:flex-start;height:30px;padding:0 12px;border-radius:999px"><span class="lg-dot"></span>LevelUpGrowth Affiliate Program</span>
      <h1 class="af-h1">Recommend us. <span class="t-grad">Earn while their business grows.</span></h1>
      <p class="af-lead">Give your audience your own voucher code. Every business that signs up with it earns you up to 20% of its monthly plan for six months, and you decide how much of that becomes their discount.</p>
      <div class="af-cta">
        <a class="lg-btn lg-btn--primary lg-btn--lg" href="/affiliates/portal?apply=1">Apply to join <svg class="ic ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        <a class="lg-btn lg-btn--glass lg-btn--lg" href="#journey">See the journey</a>
      </div>
      <div class="af-trust"><span><?= $check ?>Free to join</span><span><?= $check ?>A person reviews every application</span><span><?= $check ?>Paid monthly</span><span><?= $check ?>No tracking links or cookies</span></div>
    </div>
    <div class="lg-glass lg-glass--thick af-card" style="padding:26px" aria-label="An example affiliate code">
      <div class="lg-row lg-between" style="margin-bottom:14px"><span class="t-eyebrow">Your code</span><span class="lg-badge lg-badge--success">Active</span></div>
      <div class="af-code">MIRA5 <small>5% off for them</small></div>
      <div class="af-split" style="margin-top:18px"><span class="them">Customer saves 5%</span><span class="you">You earn 15%</span></div>
      <div class="af-bar" aria-hidden="true"><div class="af-bar__them" style="width:25%"></div><div class="af-bar__you" style="width:75%"></div><div class="af-bar__h" style="left:25%"></div></div>
      <div class="af-feed" aria-hidden="true">
        <div class="af-evt lg-glass lg-glass--thin"><i>+</i><span>Corner Bakery joined with <b>MIRA5</b></span></div>
        <div class="af-evt lg-glass lg-glass--thin"><i>$</i><span>First payment on Pro: <b><?= $m2($pro * .95) ?></b></span></div>
        <div class="af-evt lg-glass lg-glass--thin"><i><svg class="ic ic--sm" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></i><span>You earned <b><?= $m2($pro * .15) ?></b> &middot; 5 more payments to come</span></div>
      </div>
    </div>
  </div>
</section>

<section class="af-sec af-sec--tight" id="journey">
  <div class="container">
    <div class="af-head">
      <span class="t-eyebrow">The journey</span>
      <h2 class="af-h2">From your first video to your first payout.</h2>
      <p class="af-lead">Seven steps, most of them taken by someone else. You share a code you chose. LevelUpGrowth handles the sign-up, the onboarding and the counting, and pays you every month.</p>
    </div>
    <div class="af-journey">
      <div class="lg-glass af-step"><span class="af-step__n">01 · YOU</span><h3>Apply in two minutes</h3><p>Tell us where you publish and who watches. Free, and reviewed by a person.</p>
        <div class="af-mini"><span class="c-3">Where you publish</span><span>youtube.com/@miravlogs</span><span class="ok"><?= $check ?> Sent</span></div></div>
      <div class="lg-glass af-step"><span class="af-step__n">02 · US</span><h3>A person approves you</h3><p>We read every application, usually within 2 working days, and email you the moment it is decided.</p>
        <div class="af-mini"><span class="c-3">Email</span><span><b>You're in: the LevelUpGrowth Affiliate Program</b></span></div></div>
      <div class="lg-glass af-step"><span class="af-step__n">03 · YOU</span><h3>Create your code</h3><p>Choose a code your viewers can say out loud, then drag the split between their discount and your share.</p>
        <div class="af-mini"><div class="row"><code>MIRA10</code><span class="c-3">10% / 10%</span></div><div class="bar"><i style="width:50%;background:#10b981"></i><i style="width:50%;background:var(--brand-grad,#7c3aed)"></i></div></div></div>
      <div class="lg-glass af-step"><span class="af-step__n">04 · YOU</span><h3>Share it your way</h3><p>Say it in a video, pin it in a description, put it in a newsletter. A code per channel shows you what works.</p>
        <div class="af-mini"><span class="c-3">Video description</span><span>Build your site with LevelUpGrowth. Use code <code>MIRA10</code> for 10% off.</span></div></div>
      <div class="lg-glass af-step"><span class="af-step__n">05 · THEM</span><h3>They sign up with it</h3><p>Their discount is saved on the spot. Arthur builds their website, Sarah and the AI team start on their marketing.</p>
        <div class="af-mini"><span class="c-3">Have a code?</span><div class="row"><code>MIRA10</code><span class="ok"><?= $check ?> 10% off</span></div></div></div>
      <div class="lg-glass af-step"><span class="af-step__n">06 · YOU</span><h3>Earn on every payment</h3><p>Your share lands on each of their first six monthly payments, once on a yearly plan, and on new domains.</p>
        <div class="af-mini"><div class="row"><span class="c-3">Pro, 10% code</span><b><?= $m2($pro * .10) ?> each</b></div><div class="af-ticks"><i></i><i></i><i></i><i></i><i></i><i></i></div></div></div>
      <div class="lg-glass af-step"><span class="af-step__n">07 · US</span><h3>Get paid on the 15th</h3><p>After a 30-day hold, everything ready is paid every month by PayPal or Wise, from $50.</p>
        <div class="af-mini"><div class="row"><span class="c-3">Payout</span><b><?= $m2($pro * .10 * 6) ?></b></div><span class="ok"><?= $check ?> Sent on the 15th</span></div></div>
      <a class="lg-glass af-step af-step--go" href="/affiliates/portal?apply=1" style="text-decoration:none"><span class="af-step__n" style="opacity:.85">YOUR TURN</span><h3>Start your journey</h3><p>Apply in two minutes and create your first code the day you are approved.</p><span class="lg-btn lg-btn--glass" style="margin-top:8px">Apply to join &rarr;</span></a>
    </div>
    
  </div>
</section>

<section class="af-sec">
  <div class="container">
    <div class="af-head af-head--c">
      <span class="t-eyebrow">Your numbers</span>
      <h2 class="af-h2">See what your audience is worth.</h2>
      <p class="af-lead">Pick the plan your audience is likely to choose, how many businesses sign up with your code each month, and how much you want to give them.</p>
    </div>
    <div class="lg-glass lg-glass--thick af-calc" id="af-calc">
      <div class="af-calc__in">
        <div><span class="af-lbl">Plan they choose</span><div class="af-segs" data-plans role="group" aria-label="Plan"></div></div>
        <div><span class="af-lbl">New businesses with your code, per month</span><div class="af-step-in"><button type="button" data-minus aria-label="Fewer">&minus;</button><output data-n aria-live="polite">5</output><button type="button" data-plus aria-label="More">+</button></div></div>
        <div><span class="af-lbl">Your split</span>
          <div class="af-split"><span class="them" data-them>Customer saves 5%</span><span class="you" data-you>You earn 15%</span></div>
          <div class="af-bar" role="slider" tabindex="0" aria-label="Discount for your audience" aria-valuemin="0" aria-valuemax="20" aria-valuenow="5"><div class="af-bar__them"></div><div class="af-bar__you"></div><div class="af-bar__h"></div></div>
          <div class="lg-row lg-between t-caption c-3" style="margin-top:6px"><span>&#9664; more for you</span><span>bigger discount &#9654;</span></div>
        </div>
      </div>
      <div class="af-calc__out">
        <div><span class="t-eyebrow">In the first year</span><div class="af-big" data-out="year">$0.00</div><span class="t-footnote c-3">from the businesses you bring each month</span></div>
        <div class="af-out-row"><span>Each business earns you</span><b><span data-out="per">$0.00</span> a month</b></div>
        <div class="af-out-row"><span>Over its first six payments</span><b data-out="six">$0.00</b></div>
        <div class="af-out-row"><span>Every month from month six</span><b data-out="month6">$0.00</b></div>
        <div class="af-out-row"><span>They pay</span><b data-out="pays">$0.00</b></div>
        <p class="t-caption c-3" style="margin:0">An example, not a promise: it assumes every business stays on that monthly plan for six payments. Earnings follow the share rules below.</p>
      </div>
    </div>
  </div>
</section>

<section class="af-sec">
  <div class="container">
    <div class="af-head">
      <span class="t-eyebrow">Your own voucher codes</span>
      <h2 class="af-h2">A code with your name on it. As many as your audiences need.</h2>
      <p class="af-lead">People remember a code they heard you say. Run up to 10 at once, each with its own deal, its own dates and its own results.</p>
    </div>
    <div class="af-feats">
      <div class="lg-glass af-card"><span class="ic-wrap"><?= icon('pen', 20) ?></span><h3>Your name, your words</h3><p>Any code from 4 to 20 letters and numbers. One your viewers can say and type without a second look.</p></div>
      <div class="lg-glass af-card"><span class="ic-wrap"><?= icon('layers', 20) ?></span><h3>One per audience</h3><p>YouTube, your newsletter, a live session, a client workshop. Pause a code and make a new one any time.</p></div>
      <div class="lg-glass af-card"><span class="ic-wrap"><?= icon('chart', 20) ?></span><h3>Results per code</h3><p>Sign-ups, paying businesses and earnings for every code, so you know which video actually sells.</p></div>
      <div class="lg-glass af-card"><span class="ic-wrap"><?= icon('zap', 20) ?></span><h3>A different deal on each</h3><p>Keep the whole share on one code and lead with a bigger discount on another, per product.</p></div>
      <div class="lg-glass af-card"><span class="ic-wrap"><?= icon('calendar', 20) ?></span><h3>Launches and limited offers</h3><p>An end date or a use limit for a launch week or a first-50 offer. Everyone who joined keeps their terms.</p></div>
      <div class="lg-glass af-card"><span class="ic-wrap"><?= icon('shield', 20) ?></span><h3>No links, no cookies</h3><p>Nothing to install and nothing to track. Your audience sees a code and a saving, nothing else.</p></div>
    </div>
  </div>
</section>

<section class="af-sec">
  <div class="container">
    <div class="af-head">
      <span class="t-eyebrow">The share</span>
      <h2 class="af-h2">Up to 20% of every payment, split your way.</h2>
      <p class="af-lead">Discount plus your commission always adds up to the share. The standard code gives your audience 5% off and keeps the rest for you.</p>
    </div>
    <div class="af-share">
      <div class="lg-glass af-card"><span class="t-eyebrow">Monthly plans</span><span class="af-pct">20%</span><span class="t-subhead t-strong">of their first 6 monthly payments</span><span class="t-footnote c-3">Standard code: 5% off for them, 15% for you</span></div>
      <div class="lg-glass af-card"><span class="t-eyebrow">Yearly plans</span><span class="af-pct">15%</span><span class="t-subhead t-strong">of the yearly payment, once</span><span class="t-footnote c-3">Standard code: 5% off for them, 10% for you</span></div>
      <div class="lg-glass af-card"><span class="t-eyebrow">New domains</span><span class="af-pct">10%</span><span class="t-subhead t-strong">of registrations in their first 6 months</span><span class="t-footnote c-3">Standard code: 5% off for them, 5% for you</span></div>
    </div>
    <p class="t-footnote c-3" style="margin-top:16px">Commission is worked out on the price before the discount and before tax, held for 30 days in case of a refund, then paid on the 15th. Credit top-ups, domain renewals and your own businesses do not earn. <a href="/next/legal/affiliates/">Read the affiliate terms</a>.</p>
  </div>
</section>

<section class="af-sec">
  <div class="container af-faq">
    <div class="af-head af-head--c"><span class="t-eyebrow">Questions</span><h2 class="af-h2">Everything affiliates ask us.</h2></div>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>

<section class="af-sec" style="padding-top:0">
  <div class="container">
    <div class="lg-glass lg-glass--thick af-final">
      <span class="t-eyebrow">LevelUpGrowth Affiliate Program</span>
      <h2 class="af-h2" style="max-width:760px">Your audience already asks what you use. <span class="t-grad">Give them your code.</span></h2>
      <p class="af-lead" style="max-width:620px">Apply in two minutes. A person replies within 2 working days, and your first code takes about a minute to create.</p>
      <div class="af-cta" style="justify-content:center"><a class="lg-btn lg-btn--primary lg-btn--lg" href="/affiliates/portal?apply=1">Apply to join</a><a class="lg-btn lg-btn--glass lg-btn--lg" href="/affiliates/portal">Affiliate sign-in</a></div>
    </div>
  </div>
</section>
</div>
