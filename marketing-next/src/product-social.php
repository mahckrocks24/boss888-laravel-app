<?php
/** @var array $page */ /** @var array $data */ /** @var array $p (product) */
/*
 * SOCIAL-PAGE-2 (Owner 2026-10-05: "We need to update the social page itself with the things we have on the homepage. Now it is
 * 4/10, make it enterprise grade. Sell the engine and the intelligence and automation. approval gates etc."). The page sells the
 * Social engine as a system: what it listens to, how it plans and creates, where the approval gates sit, what runs on its own and
 * what always waits for the owner. The gallery and the comment thread are the same blocks as the home (social-blocks.php).
 * Claims follow what the platform does: plan-level approval (DEC-0018 mandates), per-post approval in the review queue, signals
 * and market watch (RFC-0019), comment replies and comment leads (RFC-0016), the design library, the Monday report.
 */
$page['title'] = 'Social media, run by your AI team';
$page['description'] = 'Sarah and your social team plan, design and schedule your Facebook, Instagram and LinkedIn posts from trends and audience signals, answer comments and turn buyers into leads. Nothing posts until you approve.';
$mk = function (string $f): string { $q = __DIR__ . '/assets/mk/' . $f; return '/next/assets/mk/' . $f . (is_file($q) ? '?v=' . substr(md5_file($q), 0, 8) : ''); };
$agentImg = fn (string $slug) => '/img/agents/' . $slug . '.webp';
$page['head'] = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">' . "\n"
  . '<link rel="stylesheet" href="' . e($mk('lug-glass.css')) . '">' . "\n" . '<link rel="stylesheet" href="' . e($mk('home.css')) . '">';
$plans = $data['plans']; $incl = array_values(array_filter($plans, fn ($pl) => ! empty($pl['features'][$p['flag']])));
$first = $incl[0] ?? null;
$signup = e(signup_href($data, $first['slug'] ?? null));
$check = '<svg viewBox="0 0 24 24" class="ic" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
$arrow = '<svg class="ic ic--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$faq = [
  ['Which networks?', 'Facebook, Instagram and LinkedIn. You connect each one to your workspace and can disconnect it at any time.'],
  ['Does anything post without me?', 'No. Every post waits for your approval. You can approve them one by one, or approve a month\'s plan once: the posts in it then go out as planned, and Sarah comes back to you only if the plan changes materially or would cost more than you approved.'],
  ['Where do the ideas come from?', 'From what is trending in your industry, what your competitors and customers are saying, what your audience responds to, and how every one of your past posts performed. Sarah tells you why she picked each topic.'],
  ['Who designs the posts?', 'Your posts are laid out in the looks you pick from the design library, in your brand colours, with images made in each network\'s sizes.'],
  ['Does it reply to comments?', 'Yes. Sarah drafts a reply to each comment in your voice and posts it when you approve. Comments that ask about prices, dates or availability are followed up by message and added to your clients as leads.'],
  ['What does it cost?', 'Social is included in the plans listed below. Each post uses credits from your monthly allowance, and Sarah shows the cost before anything is made.'],
  ['What if a channel is not connected?', 'Posts are still planned, made and scheduled, and wait for the connection. Nothing is lost.'],
];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
$page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'Social by LevelUpGrowth', 'provider' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth'], 'description' => $page['description'], 'url' => 'https://levelupgrowth.io/product/social/'];
?>
<style>
  .sp .sp-hero{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(32px,5vw,64px);align-items:center;padding-top:clamp(72px,7vw,96px)}
  .sp .sp-h1{font-size:clamp(36px,5vw,60px);line-height:1.04;font-weight:800;letter-spacing:-.035em;margin:0;color:var(--ink);text-wrap:balance}
  .sp .sp-crumbs{display:flex;gap:8px;font-size:13px;color:var(--ink-3)}.sp .sp-crumbs a{color:var(--ink-2);text-decoration:none}
  .sp .sp-cta{display:flex;gap:12px;flex-wrap:wrap}
  .sp .sp-trust{display:flex;gap:16px;flex-wrap:wrap;font-size:13.5px;color:var(--ink-2)}.sp .sp-trust span{display:inline-flex;gap:6px;align-items:center}.sp .sp-trust svg{width:16px;height:16px;color:var(--success)}
  .sp .sp-nets{display:flex;gap:8px;flex-wrap:wrap}
  .sp .sp-net{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 14px 0 6px;border-radius:999px;font:600 14px/1 var(--font);color:var(--ink)}
  .sp .sp-ico{display:grid;place-items:center;width:28px;height:28px;border-radius:8px;background:#fff;box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 3px rgba(0,0,0,.18)}.sp .sp-ico svg{width:18px;height:18px}
  .sp .sp-stack{display:flex;flex-direction:column;gap:12px;max-width:470px;margin-left:auto;width:100%}
  .sp .sp-mini{display:flex;gap:12px;align-items:center;padding:12px 14px;border-radius:18px}
  .sp .sp-mini img.sp-thumb{width:64px;height:80px;border-radius:10px;object-fit:cover;flex:none}
  .sp .sp-head{display:flex;flex-direction:column;gap:14px;max-width:760px;margin-bottom:28px}
  .sp .sp-head--c{margin-inline:auto;text-align:center;align-items:center}
  .sp .sp-flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;position:relative}
  .sp .sp-flow:before{content:"";position:absolute;left:6%;right:6%;top:38px;height:2px;background:linear-gradient(90deg,transparent,var(--accent-text,#7c3aed),transparent);opacity:.35}
  .sp .sp-step{border-radius:20px;padding:18px;display:flex;flex-direction:column;gap:10px;position:relative}
  .sp .sp-step__n{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font:800 16px/1 var(--font);color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}
  .sp .sp-step b{font-size:16px;color:var(--ink)}.sp .sp-step p{margin:0;font-size:14px;line-height:21px;color:var(--ink-2)}
  .sp .sp-step__who{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink-3)}.sp .sp-step__who img{width:22px;height:22px;border-radius:50%}
  .sp .sp-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .sp .sp-card{border-radius:22px;padding:22px;display:flex;flex-direction:column;gap:12px}
  .sp .sp-card h3{margin:0;font-size:19px;letter-spacing:-.01em;color:var(--ink)}.sp .sp-card p{margin:0;font-size:14.5px;line-height:22px;color:var(--ink-2)}
  .sp .sp-chip{display:inline-flex;align-items:center;gap:6px;font:600 12.5px/1 var(--font);padding:7px 10px;border-radius:999px;background:var(--fill-hover);color:var(--ink)}
  .sp .sp-chip i{font-style:normal;font-weight:700}.sp .sp-up{color:var(--success)}.sp .sp-down{color:#dc2626}
  .sp .sp-sig{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;color:var(--ink-2);padding:8px 0;border-top:1px solid var(--hairline)}.sp .sp-sig:first-child{border-top:0}.sp .sp-sig b{color:var(--ink);font-variant-numeric:tabular-nums}
  .sp .sp-bar{height:8px;border-radius:999px;background:var(--fill-hover);overflow:hidden}.sp .sp-bar i{display:block;height:100%;border-radius:999px;background:var(--brand-grad,linear-gradient(90deg,#7c3aed,#2563eb))}
  .sp .sp-decide{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);gap:16px;align-items:center;margin-top:16px;padding:18px;border-radius:22px}
  .sp .sp-decide__arrow{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb))}
  .sp .sp-gates{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .sp .sp-q{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:14px}
  .sp .sp-plan{border-radius:16px;padding:14px;display:flex;flex-direction:column;gap:10px;border:1px solid var(--hairline)}
  .sp .sp-plan__row{display:flex;justify-content:space-between;font-size:13.5px;color:var(--ink-2)}.sp .sp-plan__row b{color:var(--ink)}
  .sp .sp-always{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}
  .sp .sp-always div{border-radius:16px;padding:14px;font-size:14px;line-height:20px;color:var(--ink-2)}.sp .sp-always b{display:block;color:var(--ink);margin-bottom:4px}
  .sp .sp-split{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .sp .sp-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px}
  .sp .sp-list li{display:flex;gap:10px;align-items:flex-start;font-size:15px;line-height:22px;color:var(--ink-2)}.sp .sp-list svg{flex:none;width:18px;height:18px;margin-top:2px;color:var(--success)}
  .sp .sp-list--wait svg{color:var(--accent-text,#7c3aed)}
  .sp .sp-plans{display:flex;gap:10px;flex-wrap:wrap}
  .sp .sp-planchip{display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border-radius:14px;font-size:14px;color:var(--ink);text-decoration:none}
  .sp .sp-planchip span{color:var(--ink-3)}
  .sp .sp-faq{max-width:820px;margin:0 auto}
  .sp .sp-final{border-radius:28px;padding:clamp(28px,5vw,56px);text-align:center;display:flex;flex-direction:column;align-items:center;gap:16px}
  @media (max-width:1000px){.sp .sp-flow{grid-template-columns:repeat(2,minmax(0,1fr))}.sp .sp-flow:before{display:none}.sp .sp-always{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media (max-width:900px){.sp .sp-hero,.sp .sp-grid3,.sp .sp-gates,.sp .sp-split{grid-template-columns:1fr}.sp .sp-stack{margin:0}.sp .sp-decide{grid-template-columns:1fr}.sp .sp-decide__arrow{transform:rotate(90deg);justify-self:center}}
  @media (max-width:560px){.sp .sp-flow,.sp .sp-always{grid-template-columns:1fr}}
</style>
<?php
$icFb = '<svg viewBox="0 0 512 512" aria-hidden="true"><path fill="#0866FF" d="M512 256C512 114.6 397.4 0 256 0S0 114.6 0 256C0 376 82.7 476.8 194.2 504.5V334.2H141.4V256h52.8V222.3c0-87.1 39.4-127.5 125-127.5c16.2 0 44.2 3.2 55.7 6.4V172c-6-.6-16.5-1-29.6-1c-42 0-58.2 15.9-58.2 57.2V256h83.6l-14.4 78.2H287V510.1C413.8 494.8 512 386.9 512 256h0z"/></svg>';
$icIgS = '<svg viewBox="0 0 448 512" aria-hidden="true"><defs><linearGradient id="sp-ig" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FEDA75"/><stop offset=".3" stop-color="#FA7E1E"/><stop offset=".55" stop-color="#D62976"/><stop offset=".8" stop-color="#962FBF"/><stop offset="1" stop-color="#4F5BD5"/></linearGradient></defs><path fill="url(#sp-ig)" d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>';
$icIn = '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="#0A66C2" d="M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z"/></svg>';
?>
<div class="lg mk sp">

  <section class="mk-sec" style="padding-top:0;padding-bottom:40px">
    <style>
      /* SOCIAL-HERO-2 (Owner: "hero image on social media page is 2/10", "Hero text 4/10"): a composed scene - the post live on a
         phone with its comments answered, the same idea on Facebook and LinkedIn behind it, and what the team is doing around it */
      .sp .sp-hero{grid-template-columns:minmax(0,1fr) minmax(0,1.02fr)}
      .sp .sp-kick{display:inline-flex;align-items:center;gap:10px;align-self:flex-start;padding:5px 12px 5px 6px;border-radius:999px;font:600 13px/1 var(--font);color:var(--ink)}
      .sp .sp-kick__logos{display:inline-flex}.sp .sp-kick__logos .sp-ico{width:24px;height:24px;border-radius:7px;margin-right:-5px;box-shadow:0 0 0 2px var(--raised,#fff)}.sp .sp-kick__logos .sp-ico svg{width:15px;height:15px}
      .sp .sp-proof{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-width:560px}
      .sp .sp-proof div{border-radius:16px;padding:12px 14px;display:flex;flex-direction:column;gap:2px}
      .sp .sp-proof b{font:800 22px/1.1 var(--font);letter-spacing:-.02em;color:var(--ink)}.sp .sp-proof span{font-size:12.5px;line-height:17px;color:var(--ink-2)}
      .sp .sv{position:relative;width:100%;max-width:600px;margin-left:auto;aspect-ratio:1/1.04;container-type:inline-size}
      .sp .sv-glow{position:absolute;inset:8% 6% 4%;border-radius:50%;background:radial-gradient(closest-side,rgba(124,58,237,.35),rgba(37,99,235,.18) 55%,transparent);filter:blur(10px);z-index:0}
      .sp .sv-post{position:absolute;background:#fff;color:#14161c;border-radius:3cqw;overflow:hidden;box-shadow:0 2cqw 6cqw rgba(15,10,50,.28),0 0 0 .15cqw rgba(0,0,0,.06);font:400 2cqw/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
      .sp .sv-post img{display:block;width:100%;height:auto}
      .sp .sv-hd{display:flex;align-items:center;gap:1.4cqw;padding:1.6cqw 1.8cqw}
      .sp .sv-av{width:4.6cqw;height:4.6cqw;border-radius:50%;flex:none;display:grid;place-items:center;color:#fff;font:700 2cqw/1 var(--font)}
      .sp .sv-hd b{font-size:2.1cqw;display:block}.sp .sv-hd small{font-size:1.7cqw;color:#65676b;display:block}
      .sp .sv-hd .sv-net{margin-left:auto;width:3.2cqw;height:3.2cqw}.sp .sv-net svg{width:100%;height:100%;display:block}
      .sp .sv-cap{padding:0 1.8cqw 1.4cqw;font-size:1.9cqw}
      .sp .sv-act{display:flex;justify-content:space-around;padding:1.2cqw;border-top:.15cqw solid #e4e6eb;font-size:1.8cqw;font-weight:600;color:#65676b}
      .sp .sv-fb{left:0;top:15cqw;width:33cqw;transform:rotate(-6deg);z-index:1}
      .sp .sv-in{right:0;top:21cqw;width:33cqw;transform:rotate(5deg);z-index:1}
      .sp .sv-phone{position:absolute;left:28.5cqw;top:3cqw;width:43cqw;height:91cqw;border-radius:7cqw;background:#0b0d12;padding:1.5cqw;box-shadow:0 3cqw 8cqw rgba(10,8,40,.45),inset 0 0 0 .3cqw #2a2d36;z-index:3}
      .sp .sv-screen{width:100%;height:100%;border-radius:5.6cqw;overflow:hidden;background:#fff;color:#14161c;display:flex;flex-direction:column;font:400 2cqw/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
      .sp .sv-bar{display:flex;justify-content:space-between;align-items:center;padding:2.2cqw 3.4cqw .8cqw;font-size:1.7cqw;font-weight:600}
      .sp .sv-notch{width:12cqw;height:2.6cqw;border-radius:2cqw;background:#0b0d12;margin:0 auto}
      .sp .sv-igtop{display:flex;align-items:center;gap:1.4cqw;padding:1.4cqw 2.4cqw}
      .sp .sv-igtop .sv-av{box-shadow:0 0 0 .35cqw #fff,0 0 0 .7cqw #d62976}
      .sp .sv-icons{display:flex;gap:2.2cqw;padding:1.4cqw 2.4cqw .6cqw}.sp .sv-icons svg{width:3.4cqw;height:3.4cqw;fill:none;stroke:#14161c;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      .sp .sv-likes{padding:0 2.4cqw;font-weight:700;font-size:1.9cqw}
      .sp .sv-cm{display:flex;gap:1.2cqw;padding:.9cqw 2.4cqw;font-size:1.85cqw}.sp .sv-cm .sv-av{width:3.6cqw;height:3.6cqw;font-size:1.5cqw}
      .sp .sv-cm b{font-weight:700;margin-right:.6cqw}.sp .sv-cm--r{padding-left:7.2cqw}
      .sp .sv-tag{display:inline-block;margin-left:.8cqw;font-size:1.5cqw;font-weight:700;color:#7c3aed;background:#f3eeff;border-radius:2cqw;padding:.2cqw 1cqw}
      .sp .sv-card{position:absolute;z-index:4;border-radius:3.2cqw;padding:2.2cqw 2.4cqw;display:flex;gap:1.8cqw;align-items:flex-start;font-size:2.1cqw;line-height:1.38;color:var(--ink)}
      .sp .sv-card img.sv-face{width:5.6cqw;height:5.6cqw;border-radius:50%;flex:none}
      .sp .sv-card b{display:block;font-size:2.15cqw}.sp .sv-card small{display:block;color:var(--ink-3);font-size:1.75cqw;margin-top:.2cqw}
      .sp .sv-btn{display:inline-flex;align-items:center;height:4.4cqw;padding:0 2cqw;border-radius:2.2cqw;font:700 1.9cqw/1 var(--font);color:#fff;background:var(--brand-grad,linear-gradient(135deg,#7c3aed,#2563eb));margin-top:1.2cqw}
      .sp .sv-sarah{left:0;top:0;width:41cqw}
      .sp .sv-trend{left:1cqw;top:64cqw;width:34cqw;flex-direction:column;gap:1.2cqw}
      .sp .sv-trend span{display:flex;justify-content:space-between;gap:2cqw;align-self:stretch;font-size:1.95cqw}.sp .sv-trend i{font-style:normal;font-weight:800;color:var(--success)}
      .sp .sv-appr{right:0;top:63cqw;width:37cqw}
      .sp .sv-lead{left:4cqw;top:89cqw;width:37cqw;align-items:center}
      .sp .sv-dot{width:4cqw;height:4cqw;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--success-soft,rgba(16,185,129,.15));color:var(--success)}
      .sp .sv-dot svg{width:2.4cqw;height:2.4cqw}
      @media (max-width:600px){.sp .sv{aspect-ratio:1/1.34}.sp .sv-fb,.sp .sv-in,.sp .sv-trend{display:none}.sp .sv-phone{left:24cqw;width:52cqw;height:112cqw;top:4cqw}.sp .sv-card{font-size:12px;padding:9px 11px;gap:8px;border-radius:14px}.sp .sv-card b{font-size:12.5px}.sp .sv-card small{font-size:10.5px}.sp .sv-card img.sv-face{width:26px;height:26px}.sp .sv-btn{font-size:11px;height:24px;padding:0 10px;border-radius:12px;margin-top:6px}.sp .sv-dot{width:22px;height:22px}.sp .sv-sarah{width:56cqw;top:0}.sp .sv-appr{width:52cqw;top:66cqw}.sp .sv-lead{width:64cqw;top:auto;bottom:0;left:18cqw}}
      @media (max-width:900px){.sp .sp-hero{grid-template-columns:1fr}.sp .sv{margin:8px auto 0}.sp .sp-proof{grid-template-columns:repeat(3,minmax(0,1fr))}.sp .sp-proof b{font-size:18px}}
    </style>
    <div class="mk-wrap sp-hero">
      <div class="lg-stack gap-4">
        <nav class="sp-crumbs" aria-label="Breadcrumb"><a href="/next/product/">Products</a><span>/</span><span>Social</span></nav>
        <span class="sp-kick lg-glass lg-glass--thin"><span class="sp-kick__logos"><span class="sp-ico"><?= $icFb ?></span><span class="sp-ico"><?= $icIgS ?></span><span class="sp-ico"><?= $icIn ?></span></span>Social engine for Facebook, Instagram and LinkedIn</span>
        <h1 class="sp-h1">A social media team that plans, posts and replies <span class="t-grad">around the clock.</span> You just approve.</h1>
        <p class="mk-lead">Sarah reads what is trending in your industry and what your followers respond to. Your social team turns it into a month of on-brand posts, scheduled for when your customers are online. Every comment gets a reply, every buyer becomes a lead, and nothing goes out without your yes.</p>
        <div class="sp-cta"><a class="lg-btn lg-btn--primary lg-btn--lg" href="<?= $signup ?>" data-lu-signup><?= e(cta_label($data)) ?> <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="#engine">See how it works</a></div>
        <div class="sp-proof">
          <div class="lg-glass lg-glass--thin"><b>30 days</b><span>planned at a time, across every network</span></div>
          <div class="lg-glass lg-glass--thin"><b>Minutes</b><span>to reply to a comment, in your voice</span></div>
          <div class="lg-glass lg-glass--thin"><b>100%</b><span>approved by you before it goes out</span></div>
        </div>
      </div>
      <div class="sv" aria-label="Example: a post live on Instagram with its comments answered, the same post on Facebook and LinkedIn, and Sarah's team at work">
        <div class="sv-glow" aria-hidden="true"></div>
        <article class="sv-post sv-fb" aria-hidden="true">
          <div class="sv-hd"><span class="sv-av" style="background:#3f6212">I</span><div><b>Ironhouse Fitness</b><small>Monday at 7:00 · Public</small></div><span class="sv-net"><?= $icFb ?></span></div>
          <div class="sv-cap">Three coaches, small groups, real progress.</div>
          <img src="<?= e($mk('social/gym-fitness-03.webp')) ?>" alt="" width="640" height="800" loading="eager">
          <div class="sv-act"><span>Like</span><span>Comment</span><span>Share</span></div>
        </article>
        <article class="sv-post sv-in" aria-hidden="true">
          <div class="sv-hd"><span class="sv-av" style="background:#1e3a8a">C</span><div><b>Crown Court Realty</b><small>1,240 followers · 2d</small></div><span class="sv-net"><?= $icIn ?></span></div>
          <img src="<?= e($mk('social/real-estate-agency-09.webp')) ?>" alt="" width="640" height="800" loading="eager">
          <div class="sv-act"><span>Like</span><span>Comment</span><span>Repost</span></div>
        </article>
        <div class="sv-phone" aria-hidden="true"><div class="sv-screen">
          <div class="sv-bar"><span>9:41</span><span class="sv-notch"></span><span>5G</span></div>
          <div class="sv-igtop"><span class="sv-av" style="background:#14532d">S</span><div style="flex:1;min-width:0"><b style="font-size:2.1cqw;display:block">saltmarshtravel</b><small style="font-size:1.6cqw;color:#65676b">Lake District</small></div><span class="sv-net" style="width:3.2cqw;height:3.2cqw"><?= $icIgS ?></span></div>
          <img src="<?= e($mk('social/travel-agency-08.webp')) ?>" alt="" width="640" height="800" style="display:block;width:100%;height:auto" loading="eager">
          <div class="sv-icons"><svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" fill="#ed4956" stroke="#ed4956"/></svg><svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-11.7 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg><svg viewBox="0 0 24 24"><path d="M21 4 3 11l7 2 2 7 9-16z"/></svg></div>
          <div class="sv-likes">1,204 likes</div>
          <div class="sv-cm"><span class="sv-av" style="background:#db2777">M</span><div><b>maya.wanders</b>Is this available in April? 😍</div></div>
          <div class="sv-cm sv-cm--r"><span class="sv-av" style="background:#14532d">S</span><div><b>saltmarshtravel</b>It is, Maya! Dates and prices are in your messages 💌<span class="sv-tag">Sarah</span></div></div>
          <div class="sv-cm"><span class="sv-av" style="background:#0891b2">T</span><div><b>tom.k_travels</b>How much for two, 5 nights?</div></div>
        </div></div>
        <div class="sv-card sv-sarah lg-glass lg-glass--thick"><img class="sv-face" src="<?= e($agentImg('sarah')) ?>" alt=""><div><b>Sarah</b>Sunrise posts got 3× the saves this week, so I've planned two more.<small>Planning · just now</small></div></div>
        <div class="sv-card sv-trend lg-glass lg-glass--thick"><b>Trending in travel</b><span>Slow travel <i>▲ 42%</i></span><span>Lake stays <i>▲ 18%</i></span><span>Honeymoons <i>▲ 11%</i></span></div>
        <div class="sv-card sv-appr lg-glass lg-glass--thick"><img class="sv-face" src="<?= e($agentImg('marcus')) ?>" alt=""><div><b>This week · 5 posts</b><small>Marcus · 3 networks · 20 credits</small><span class="sv-btn">Approve the week</span></div></div>
        <div class="sv-card sv-lead lg-glass lg-glass--thick"><span class="sv-dot"><svg viewBox="0 0 24 24" class="ic"><path d="m5 12 5 5 9-10"/></svg></span><div><b>New lead in Clients</b><small>Maya · honeymoon, April · from a comment</small></div></div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="engine" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="sp-head"><span class="t-eyebrow">The engine</span><h2 class="mk-h2">From a trend to a published post, in five steps.</h2><p class="mk-lead">Every post goes through the same pipeline. Each step is done by a specialist, and the approval gate sits before anything leaves your account.</p></div>
      <div class="sp-flow">
        <?php foreach ([
          ['Listen', 'Trends in your industry, competitors, mentions, comments and what your past posts achieved.', 'sarah', 'Sarah'],
          ['Plan', 'A calendar a month at a time, balanced across your networks and tied to what your website and offers are doing.', 'marcus', 'Marcus'],
          ['Create', 'Copy in your voice for each network, laid out in your chosen looks from the design library, in your colours.', 'priya', 'Your writers'],
          ['Approve', 'You approve each post, or the whole plan once. The cost is shown before anything is made.', 'sarah', 'Sarah asks, you decide'],
          ['Publish and learn', 'Posted when your audience is online. Results feed straight back into the next plan.', 'james', 'The team'],
        ] as $i => [$h, $t, $av, $who]): ?>
        <div class="sp-step lg-glass lg-glass--thick"><span class="sp-step__n"><?= $i + 1 ?></span><b><?= e($h) ?></b><p><?= e($t) ?></p><span class="sp-step__who"><img src="<?= e($agentImg($av)) ?>" alt="" width="22" height="22" loading="lazy"><?= e($who) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="intelligence" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="sp-head"><span class="t-eyebrow">The intelligence layer</span><h2 class="mk-h2">Every post starts with a reason.</h2><p class="mk-lead">Sarah and your specialists do not guess what to post. They read three kinds of signal and tell you why they chose each topic, look and time.</p></div>
      <div class="sp-grid3">
        <div class="sp-card lg-glass lg-glass--thick">
          <span class="t-eyebrow">Market and trends</span><h3>What your market is talking about</h3>
          <p>Trending topics in your industry, what competitors post and where your business is mentioned. You switch market watch on once; it runs until you stop it.</p>
          <div class="lg-row gap-2" style="flex-wrap:wrap"><span class="sp-chip">Slow travel <i class="sp-up">▲ 42%</i></span><span class="sp-chip">Lake stays <i class="sp-up">▲ 18%</i></span><span class="sp-chip">City breaks <i class="sp-down">▼ 9%</i></span></div>
        </div>
        <div class="sp-card lg-glass lg-glass--thick">
          <span class="t-eyebrow">Audience signals</span><h3>What your followers respond to</h3>
          <p>Comments, saves, shares and messages, and the ones that show someone is ready to buy.</p>
          <div><div class="sp-sig"><span>Comments asking about dates</span><b>12</b></div><div class="sp-sig"><span>Saves on sunrise posts</span><b>3.1×</b></div><div class="sp-sig"><span>Messages from posts</span><b>27</b></div></div>
        </div>
        <div class="sp-card lg-glass lg-glass--thick">
          <span class="t-eyebrow">Your results</span><h3>What has worked for you</h3>
          <p>Every post's reach and response, remembered. The best formats, topics and times rise; the rest are retired.</p>
          <div class="lg-stack gap-2"><span class="t-caption c-3">Best time to post, this month</span><?php foreach ([['Thu 18:00', 92], ['Sun 10:00', 78], ['Mon 9:00', 61]] as [$t, $w]): ?><div class="lg-row gap-2" style="align-items:center"><span class="t-caption" style="width:74px;flex:none"><?= $t ?></span><div class="sp-bar lg-grow"><i style="width:<?= $w ?>%"></i></div></div><?php endforeach; ?></div>
        </div>
      </div>
      <div class="sp-decide lg-glass lg-glass--thin">
        <div class="lg-stack gap-2"><span class="t-eyebrow">The signal</span><span class="t-body" style="font-size:15px;color:var(--ink)">Sunrise posts got <b>3.1× the saves</b> of other posts, and 12 comments asked about April dates.</span></div>
        <span class="sp-decide__arrow" aria-hidden="true"><?= $arrow ?></span>
        <div class="lg-stack gap-2"><span class="t-eyebrow">The decision, proposed to you</span><span class="t-body" style="font-size:15px;color:var(--ink)">Two more sunrise posts and an April offer this week, with a Reel on Thursday at 18:00. <b>Waiting for your approval.</b></span></div>
      </div>
    </div>
  </section>

  <?php $socPage = true; require __DIR__ . '/social-blocks.php'; ?>

  <section class="mk-sec" id="gates" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="sp-head"><span class="t-eyebrow">Approval gates</span><h2 class="mk-h2">Automation you can trust, because you hold the keys.</h2><p class="mk-lead">Choose how much you want to see. Approve post by post, or approve a plan once and let it run. Either way, nothing leaves your account without a yes from you, and every decision is on the record.</p></div>
      <div class="sp-gates">
        <div class="sp-card lg-glass lg-glass--thick">
          <span class="t-eyebrow">Post by post</span><h3>Every post in one review queue</h3>
          <p>Each post waits with a preview of how it will look, who made it and what it cost. Approve it, change it, or say no.</p>
          <div class="lg-stack gap-2">
            <?php foreach ([['marcus', 'Thursday Reel · Instagram', 'Marcus · 6 credits'], ['priya', 'April offer · Facebook', 'Priya · 4 credits'], ['marcus', 'Team retreats · LinkedIn', 'Marcus · 4 credits']] as [$av, $t, $s]): ?>
            <div class="sp-q lg-glass lg-glass--thin"><img class="lg-avatar lg-avatar--sm" src="<?= e($agentImg($av)) ?>" alt="" width="32" height="32"><div class="lg-stack lg-grow" style="min-width:0"><span class="t-subhead t-strong"><?= e($t) ?></span><span class="t-caption c-3"><?= e($s) ?></span></div><span class="lg-btn lg-btn--primary lg-btn--sm" style="height:30px">Approve</span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="sp-card lg-glass lg-glass--thick">
          <span class="t-eyebrow">Plan by plan</span><h3>Approve the month once</h3>
          <p>Approve a plan and everything inside it goes out as planned, within the spend you agreed. Sarah only comes back if something material changes.</p>
          <div class="sp-plan">
            <div class="lg-row lg-between"><span class="t-subhead t-strong">October social plan</span><span class="lg-badge lg-badge--info">Waiting for you</span></div>
            <div class="sp-plan__row"><span>Posts</span><b>22 across 3 networks</b></div>
            <div class="sp-plan__row"><span>Spend ceiling</span><b>96 credits</b></div>
            <div class="sp-plan__row"><span>Comment replies</span><b>Drafted for approval</b></div>
            <div class="lg-row gap-2" style="justify-content:flex-end"><span class="lg-btn lg-btn--glass lg-btn--sm" style="height:32px">Review each post</span><span class="lg-btn lg-btn--primary lg-btn--sm" style="height:32px">Approve the plan</span></div>
          </div>
        </div>
      </div>
      <div class="sp-always">
        <div class="lg-glass lg-glass--thin"><b>Cost before work</b>Every post and plan shows its credits before anything is made.</div>
        <div class="lg-glass lg-glass--thin"><b>A spend ceiling</b>An approved plan never spends more than you agreed. More needs a new yes.</div>
        <div class="lg-glass lg-glass--thin"><b>Material changes come back</b>If the plan has to change in a way that matters, Sarah asks first.</div>
        <div class="lg-glass lg-glass--thin"><b>A full record</b>Who made each post, what it cost, who approved it and when.</div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="automation" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="sp-head"><span class="t-eyebrow">Automation</span><h2 class="mk-h2">What runs on its own, and what always waits for you.</h2></div>
      <div class="sp-split">
        <div class="sp-card lg-glass lg-glass--thick">
          <h3>Runs on its own</h3>
          <ul class="sp-list">
            <li><?= $check ?>Watching trends, competitors, mentions and comments, every day.</li>
            <li><?= $check ?>Planning the month and proposing changes when the signals move.</li>
            <li><?= $check ?>Writing, designing and sizing each post for its network.</li>
            <li><?= $check ?>Posting approved posts at the scheduled time.</li>
            <li><?= $check ?>Drafting replies to every comment, and adding buyers to your clients as leads.</li>
            <li><?= $check ?>A Monday report: reach, responses, leads and what Sarah will change.</li>
          </ul>
        </div>
        <div class="sp-card lg-glass lg-glass--thick">
          <h3>Always waits for you</h3>
          <ul class="sp-list sp-list--wait">
            <li><?= $check ?>Publishing any post, unless it is part of a plan you approved.</li>
            <li><?= $check ?>Posting a reply to a comment.</li>
            <li><?= $check ?>Anything that would cost more than you approved.</li>
            <li><?= $check ?>Material changes to an approved plan.</li>
            <li><?= $check ?>Connecting a network to your account, and you can disconnect it at any time.</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <section class="mk-sec" id="plans" style="padding-top:40px">
    <div class="mk-wrap">
      <div class="sp-head"><span class="t-eyebrow">Plans</span><h2 class="mk-h2">Included in</h2><p class="mk-lead">Each post uses credits from your plan's monthly allowance.</p></div>
      <div class="sp-plans"><?php foreach ($incl as $pl): ?><a class="sp-planchip lg-glass lg-glass--thin" href="/next/pricing/#<?= e($pl['slug']) ?>"><?= $check ?><?= e($pl['name']) ?> <span><?= money($pl['price_monthly']) ?>/mo</span></a><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px">
    <div class="mk-wrap sp-faq">
      <div class="sp-head sp-head--c"><span class="t-eyebrow">Questions</span><h2 class="mk-h2">What owners ask about Social.</h2></div>
      <div class="faq"><?php foreach ($faq as [$q, $a]): ?><details class="faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mk-sec" style="padding-top:40px;padding-bottom:72px">
    <div class="mk-wrap"><div class="sp-final lg-glass lg-glass--thick">
      <span class="t-eyebrow">Your social team is ready</span>
      <h2 class="mk-h2" style="max-width:760px">Hand over the posting. <span class="t-grad">Keep the final say.</span></h2>
      <p class="mk-lead" style="text-align:center">Start free. Sarah plans your first week and shows you every post before anything goes out.</p>
      <div class="sp-cta" style="justify-content:center"><a class="lg-btn lg-btn--primary lg-btn--lg" href="<?= $signup ?>" data-lu-signup><?= e(cta_label($data)) ?> <?= $arrow ?></a><a class="lg-btn lg-btn--glass lg-btn--lg" href="/next/pricing/">See pricing</a></div>
    </div></div>
  </section>
</div>
