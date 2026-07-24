/**
 * LevelUp Growth — Shared Site JS
 * Injects nav and footer, handles scroll reveal, mobile nav, demo simulation
 */
'use strict';

// ── MW-1b P5A — SINGLE navigation data model ─────────────────
// Drives the desktop mega menu AND the mobile accordion from one source, so
// product lists can never drift apart. Availability is truthful: items without
// a live page are rendered non-clickable with a readable state badge.
const NAV_DATA = {
    groups: [
        { title: 'Own your presence', items: [
            { name: 'Website',         desc: 'Build and launch your online presence.',                       href: '/pages/builder/',   state: 'available', icon: '🌐' },
            { name: 'Domains',         desc: 'Own your business identity.',                                  href: null,                state: 'soon',      icon: '🔗' },
            { name: 'Business Email',  desc: 'Professional inboxes using your domain.',                      href: null,                state: 'soon',      icon: '✉️' },
            { name: 'Managed Hosting', desc: 'Run your website on managed infrastructure.',                  href: null,                state: 'soon',      icon: '🖥️' }
        ]},
        { title: 'Own your growth', items: [
            { name: 'SEO',             desc: 'Improve visibility with assisted strategy and reporting.',      href: '/pages/seo/',       state: 'available', icon: '🔍' },
            { name: 'Creative',        desc: 'Produce campaign and brand assets.',                            href: '/pages/creative/',  state: 'available', icon: '🎨' },
            { name: 'Video',           desc: 'Create video content and promotional media.',                   href: '/pages/video/',     state: 'available', icon: '🎬' }
        ]},
        { title: 'Own your operations', items: [
            { name: 'CRM',             desc: 'Manage leads, customers and opportunities.',                    href: '/pages/crm/',       state: 'available', icon: '🤝' },
            { name: 'Calendar',        desc: 'Manage appointments, bookings and availability.',               href: '/pages/calendar/',  state: 'available', icon: '📅' },
            { name: 'AI Workforce',    desc: 'Coordinate AI assistants across the business.',                 href: '/pages/ai-agents/', state: 'available', icon: '🤖' }
        ]}
    ],
    journey: [
        ['Build', 'Website'], ['Own', 'Domain + Business Email'], ['Run', 'Hosting + CRM + Calendar'],
        ['Grow', 'SEO + Content + Creative'], ['Scale', 'Automation + AI Workforce']
    ],
    featured: { label: 'See how it works', href: '/pages/how-it-works/' }
};

function navBadge(state) {
    if (state === 'soon') { return '<span class="mega-badge mega-badge--soon">Coming soon</span>'; }
    if (state === 'inplatform') { return '<span class="mega-badge mega-badge--in">In platform</span>'; }
    return '';
}
function megaItemHtml(it) {
    var inner = '<span class="mega-ico" aria-hidden="true">' + it.icon + '</span>'
        + '<span class="mega-body"><span class="mega-name">' + it.name + navBadge(it.state) + '</span>'
        + '<span class="mega-desc">' + it.desc + '</span></span>';
    return it.href
        ? '<a class="mega-item" href="' + it.href + '">' + inner + '</a>'
        : '<span class="mega-item mega-item--static" role="none">' + inner + '</span>';
}
function megaHtml() {
    var cols = NAV_DATA.groups.map(function (g) {
        return '<div class="mega-col"><div class="mega-col-title">' + g.title + '</div>'
            + g.items.map(megaItemHtml).join('') + '</div>';
    }).join('');
    var journey = NAV_DATA.journey.map(function (j) {
        return '<div class="mega-j"><span class="mega-j-step">' + j[0] + '</span><span class="mega-j-val">' + j[1] + '</span></div>';
    }).join('');
    return '<div class="mega" id="mega-products" aria-label="Products">'
        + '<div class="mega-inner">' + cols
        + '<div class="mega-col mega-feature"><div class="mega-col-title">Ownership journey</div>'
        + journey + '<a class="mega-cta" href="' + NAV_DATA.featured.href + '">' + NAV_DATA.featured.label + ' →</a>'
        + '</div></div></div>';
}
function mobileProductsHtml() {
    return NAV_DATA.groups.map(function (g, i) {
        var items = g.items.map(function (it) {
            return it.href
                ? '<a href="' + it.href + '"><i class="m-ico" aria-hidden="true">' + it.icon + '</i>' + it.name + '</a>'
                : '<span class="m-static"><i class="m-ico" aria-hidden="true">' + it.icon + '</i>' + it.name + (it.state === 'soon'
                    ? ' <em class="m-soon">Coming soon</em>' : ' <em class="m-in">In platform</em>') + '</span>';
        }).join('');
        return '<button class="m-acc-trigger" aria-expanded="false" aria-controls="m-acc-' + i + '">'
            + g.title + '<span class="m-acc-chev" aria-hidden="true">▾</span></button>'
            + '<div class="m-acc-panel" id="m-acc-' + i + '" hidden>' + items + '</div>';
    }).join('');
}

// ── Nav HTML ─────────────────────────────────────────────────
const NAV_HTML = `
<nav id="nav">
  <div class="nav-inner">
    <a class="nav-logo" href="/">
      <div class="nav-logo-icon"><img src="/img/logo-icon-40.png" alt=""></div>
      <span>LevelUpGrowth</span>
    </a>
    <div class="nav-links">
      <div class="nav-dd-wrap">
        <button class="nav-link nav-dd-trigger" id="mega-trigger" aria-haspopup="true" aria-expanded="false" aria-controls="mega-products">Products <span aria-hidden="true">▾</span></button>
        ${megaHtml()}
      </div>
      <a class="nav-link" href="/pages/why-levelup/">Why LevelUp</a>
      <a class="nav-link" href="/pages/pricing/">Pricing</a>
      <div class="nav-dd-wrap">
        <button class="nav-link nav-dd-trigger">Resources ▾</button>
        <div class="nav-dd">
          <a class="nav-dd-item" href="/pages/how-it-works/">How It Works</a>
          <a class="nav-dd-item" href="/pages/automation/">Automation</a>
          <a class="nav-dd-item" href="/pages/use-cases/">Use Cases</a>
          <a class="nav-dd-item" href="/pages/comparison/">Compare</a>
          <a class="nav-dd-item" href="/pages/faq/">FAQ</a>
          <a class="nav-dd-item" href="/blog/">Blog</a>
        </div>
      </div>
    </div>
    <div class="nav-right">
      <a href="/app/#signup" class="btn btn-primary btn-sm">Start Free</a>
      <button id="mobile-nav-btn" onclick="toggleMobileNav()" aria-label="Menu">☰</button>
    </div>
  </div>
</nav>
<div id="mobile-nav-menu">
  ${mobileProductsHtml()}
  <div style="height:1px;background:rgba(255,255,255,.07);margin:8px 0"></div>
  <a href="/pages/why-levelup/">Why LevelUp</a>
  <a href="/pages/pricing/">Pricing</a>
  <a href="/pages/how-it-works/">How It Works</a>
  <a href="/pages/use-cases/">Use Cases</a>
  <a href="/pages/comparison/">Compare</a>
  <a href="/pages/faq/">FAQ</a>
  <a href="/blog/">Blog</a>
  <a href="/app/#signup" class="mobile-cta">Start Free →</a>
</div>`;

const FOOTER_HTML = `
<footer id="site-footer">
  <div class="footer-inner">
    <div class="footer-grid">
      <div>
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px">
          <div class="nav-logo-icon"><img src="/img/logo-icon-40.png" alt=""></div>
          <span style="font-family:var(--ff-h);font-weight:800;font-size:17px">LevelUpGrowth</span>
        </div>
        <p style="color:#4B5563;font-size:13.5px;line-height:1.75;max-width:240px">Own your business online — your website, email, and growth — run for you by AI.</p>
      </div>
      <div>
        <div class="footer-col-title">Products</div>
        <div class="footer-links">
          <a class="footer-link" href="/pages/builder/">Website</a>
          <span class="footer-link" style="opacity:.5;cursor:default">Domains — Coming soon</span>
          <span class="footer-link" style="opacity:.5;cursor:default">Business Email — Coming soon</span>
          <span class="footer-link" style="opacity:.5;cursor:default">Managed Hosting — Coming soon</span>
          <a class="footer-link" href="/pages/seo/">SEO</a>
          <a class="footer-link" href="/pages/creative/">Creative</a>
          <a class="footer-link" href="/pages/video/">Video</a>
          <a class="footer-link" href="/pages/crm/">CRM</a>
          <a class="footer-link" href="/pages/calendar/">Calendar</a>
          <a class="footer-link" href="/pages/ai-agents/">AI Workforce</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Resources</div>
        <div class="footer-links">
          <a class="footer-link" href="/pages/how-it-works/">How It Works</a>
          <a class="footer-link" href="/pages/use-cases/">Use Cases</a>
          <a class="footer-link" href="/pages/comparison/">Compare</a>
          <a class="footer-link" href="/pages/pricing/">Pricing</a>
          <a class="footer-link" href="/pages/faq/">FAQ</a>
          <a class="footer-link" href="/blog/">Blog</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Company</div>
        <div class="footer-links">
          <a class="footer-link" href="mailto:hello@levelupgrowth.io">Contact</a>
        </div>
        <div style="margin-top:16px">
          <a href="/app/#signup" class="btn btn-primary btn-sm">Start Free →</a>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <p style="color:#374151;font-size:13px">© 2026 LevelUpGrowth. All rights reserved.</p>
      <p style="color:#374151;font-size:13px;font-family:var(--ff-h)">Own your business online — run by AI</p>
    </div>
  </div>
</footer>`;

// ── Nav + footer injection ────────────────────────────────────
function SiteInit() {
    // Inject nav
    const navEl = document.getElementById('site-nav');
    if (navEl) navEl.outerHTML = NAV_HTML;
    else document.body.insertAdjacentHTML('afterbegin', NAV_HTML);

    // Inject footer
    const footerEl = document.getElementById('site-footer-placeholder');
    if (footerEl) footerEl.outerHTML = FOOTER_HTML;
    else document.body.insertAdjacentHTML('beforeend', FOOTER_HTML);

    // MW-1b P4 — ownership journey block (related + next step), above the footer
    try { injectJourney(); } catch (e) {}

    // MW-1b P5A — mega menu (desktop) + mobile accordion
    try { injectMegaCss(); initMegaMenu(); initMobileAccordion(); } catch (e) {}

    // Nav scroll
    window.addEventListener('scroll', () => {
        document.getElementById('nav')?.classList.toggle('scrolled', window.scrollY > 24);
    });

    // Scroll reveal
    observeReveal();

    // Dropdown
    document.querySelectorAll('.nav-dd-trigger').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            btn.parentElement.classList.toggle('open');
        });
    });
    document.addEventListener('click', () => {
        document.querySelectorAll('.nav-dd-wrap').forEach(w => w.classList.remove('open'));
    });

    // Close mobile nav on link click
    document.querySelectorAll('#mobile-nav-menu a').forEach(a => {
        a.addEventListener('click', closeMobileNav);
    });
}

function observeReveal() {
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
    }, { threshold: .08 });
    document.querySelectorAll('.reveal,.reveal-left,.reveal-right').forEach(el => obs.observe(el));
}

function toggleMobileNav() {
    document.getElementById('mobile-nav-menu')?.classList.toggle('open');
}
function closeMobileNav() {
    document.getElementById('mobile-nav-menu')?.classList.remove('open');
}

// Close mobile nav on outside click
document.addEventListener('click', e => {
    const menu = document.getElementById('mobile-nav-menu');
    const btn  = document.getElementById('mobile-nav-btn');
    if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
        menu.classList.remove('open');
    }
});

// ── Plan selection (pricing → signup) ────────────────────────
// 2026-05-11: signups blocked on production hostnames only — staging
// (and other hosts) keep the original /app/#signup target.
function selectPlan(name, price) {
    try { localStorage.setItem('lu_selected_plan', name); localStorage.setItem('lu_selected_price', price); } catch(_) {}
    if (['levelupgrowth.io', 'www.levelupgrowth.io'].indexOf(location.hostname) !== -1) {
        window.location.href = '/';
    } else {
        window.location.href = '/app/#signup';
    }
}

// ── Escape helper ─────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Init
document.addEventListener('DOMContentLoaded', SiteInit);

window.SiteInit = SiteInit;
window.selectPlan = selectPlan;
window.toggleMobileNav = toggleMobileNav;


// ── MW-1b P5A — mega menu styles + interaction ───────────────
function injectMegaCss() {
    if (document.getElementById('mega-css')) { return; }
    var css = `
.nav-dd-wrap{position:static}
.mega{position:absolute;left:0;right:0;top:100%;background:#0F1320;border-top:1px solid var(--border,#2D3748);border-bottom:1px solid var(--border,#2D3748);box-shadow:0 22px 54px rgba(0,0,0,.5);opacity:0;visibility:hidden;transform:translateY(-6px);transition:opacity .16s ease,transform .16s ease,visibility .16s;z-index:60}
.mega.open{opacity:1;visibility:visible;transform:none}
.mega-inner{max-width:1180px;margin:0 auto;padding:26px 28px 30px;display:grid;grid-template-columns:repeat(4,1fr);gap:24px}
.mega-col-title{font-family:var(--ff-h);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#8A93A6;margin-bottom:10px}
.mega-item{display:flex;gap:11px;padding:9px 10px;border-radius:10px;text-decoration:none;color:inherit;transition:background .15s}
a.mega-item:hover{background:rgba(255,255,255,.06)}
.mega-item--static{cursor:default}
.mega-ico{font-size:16px;line-height:1.35;flex:0 0 auto}
.mega-body{display:flex;flex-direction:column;gap:3px;min-width:0}
.mega-name{font-family:var(--ff-h);font-weight:700;font-size:13.5px;color:#E8ECF3;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.mega-desc{font-size:12px;line-height:1.5;color:#98A2B4}
.mega-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;white-space:nowrap;border:1px solid}
.mega-badge--soon{background:rgba(0,229,168,.16);color:#3BEFC0;border-color:rgba(0,229,168,.45)}
.mega-badge--in{background:rgba(148,163,184,.18);color:#CBD4E1;border-color:rgba(148,163,184,.4)}
.mega-feature{background:rgba(124,58,237,.07);border:1px solid rgba(124,58,237,.2);border-radius:14px;padding:16px 16px 18px}
.mega-j{display:flex;gap:10px;align-items:baseline;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06)}
.mega-j:last-of-type{border-bottom:none}
.mega-j-step{font-family:var(--ff-h);font-weight:800;font-size:11px;color:#A78BFA;min-width:40px}
.mega-j-val{font-size:12.5px;color:#B9C2D0;line-height:1.45}
.mega-cta{display:inline-block;margin-top:14px;font-family:var(--ff-h);font-weight:700;font-size:12.5px;color:#fff;background:#7C3AED;padding:9px 16px;border-radius:9px;text-decoration:none}
.mega-item:focus-visible,.mega-cta:focus-visible,.nav-dd-trigger:focus-visible,.m-acc-trigger:focus-visible{outline:2px solid #A78BFA;outline-offset:2px;border-radius:8px}
@media(max-width:1180px){.mega-inner{grid-template-columns:repeat(2,1fr);gap:18px;padding:22px 24px 26px}}
@media(max-width:768px){.mega{display:none}}
@media(prefers-reduced-motion:reduce){.mega{transition:none}}
.m-acc-trigger{display:flex;justify-content:space-between;align-items:center;width:100%;background:none;border:none;color:#E8ECF3;font-family:var(--ff-h);font-weight:700;font-size:14px;padding:11px 0;cursor:pointer}
.m-acc-panel{padding:0 0 8px 10px;display:flex;flex-direction:column;gap:1px}
.m-acc-panel[hidden]{display:none}
.m-ico{display:inline-block;width:22px;font-style:normal;text-align:left}
#mobile-nav-menu .m-acc-panel a{padding:8px 0;font-size:13.5px;display:block}
.m-static{padding:8px 0;font-size:13.5px;color:#9AA3B2;display:block}
.m-soon,.m-in{font-style:normal;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:100px;margin-left:6px;border:1px solid}
.m-soon{background:rgba(0,229,168,.16);color:#3BEFC0;border-color:rgba(0,229,168,.45)}
.m-in{background:rgba(148,163,184,.18);color:#CBD4E1;border-color:rgba(148,163,184,.4)}
.m-acc-chev{transition:transform .15s;display:inline-block}
.m-acc-trigger[aria-expanded="true"] .m-acc-chev{transform:rotate(180deg)}
`;
    var st = document.createElement('style'); st.id = 'mega-css'; st.textContent = css;
    document.head.appendChild(st);
}

function initMegaMenu() {
    var trigger = document.getElementById('mega-trigger');
    var panel = document.getElementById('mega-products');
    if (!trigger || !panel) { return; }
    var openT = null, closeT = null;

    function open() {
        clearTimeout(closeT);
        panel.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
    }
    function close(returnFocus) {
        clearTimeout(openT);
        panel.classList.remove('open');
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) { try { trigger.focus(); } catch (e) {} }
    }
    function isOpen() { return panel.classList.contains('open'); }

    trigger.addEventListener('click', function (e) { e.preventDefault(); isOpen() ? close(false) : open(); });
    // deliberate hover intent (no flashing while crossing the nav)
    trigger.addEventListener('mouseenter', function () { clearTimeout(closeT); openT = setTimeout(open, 140); });
    trigger.addEventListener('mouseleave', function () { clearTimeout(openT); closeT = setTimeout(function () { close(false); }, 260); });
    panel.addEventListener('mouseenter', function () { clearTimeout(closeT); });
    panel.addEventListener('mouseleave', function () { closeT = setTimeout(function () { close(false); }, 260); });
    // Escape closes + returns focus
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen()) { close(true); } });
    // click outside closes
    document.addEventListener('click', function (e) {
        if (!isOpen()) { return; }
        if (!panel.contains(e.target) && e.target !== trigger && !trigger.contains(e.target)) { close(false); }
    });
    // focus leaving the panel closes it
    panel.addEventListener('focusout', function (e) {
        setTimeout(function () {
            if (isOpen() && !panel.contains(document.activeElement) && document.activeElement !== trigger) { close(false); }
        }, 0);
    });
}

function initMobileAccordion() {
    Array.prototype.forEach.call(document.querySelectorAll('.m-acc-trigger'), function (btn) {
        btn.addEventListener('click', function () {
            var panel = document.getElementById(btn.getAttribute('aria-controls'));
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!expanded));
            if (panel) { panel.hidden = expanded; }
        });
    });
}

// ── MW-1b P4 — Ownership journey (related products + next step) ──
// One data-driven block so every product page leads to the next stage of the
// Business Ownership journey: Website → Domain → Business Email → Managed
// Hosting → SEO → CRM → Automation → AI Workforce. Items with no page yet are
// rendered as truthful, non-clickable "Coming soon" chips.
const JOURNEY = {
    '/pages/builder/': {
        next: { label: 'Connect your own domain', href: null, soon: true, why: 'Your site deserves your own brand address — yourbrand.com, owned by you.' },
        related: [['Business Email', null], ['Managed Hosting', null], ['SEO', '/pages/seo/']]
    },
    '/pages/seo/': {
        next: { label: 'Capture the leads it brings', href: '/pages/crm/', soon: false, why: 'Traffic only matters if you keep the customers it sends you.' },
        related: [['Website', '/pages/builder/'], ['Creative', '/pages/creative/'], ['AI Workforce', '/pages/ai-agents/']]
    },
    '/pages/crm/': {
        next: { label: 'Let your AI Workforce run it', href: '/pages/ai-agents/', soon: false, why: 'Once your customers are in one place, AI can work the follow-up for you.' },
        related: [['Calendar', '/pages/calendar/'], ['Automation', '/pages/automation/'], ['SEO', '/pages/seo/']]
    },
    '/pages/creative/': {
        next: { label: 'Turn creative into video', href: '/pages/video/', soon: false, why: 'The same brand assets go further as short-form video.' },
        related: [['CRM', '/pages/crm/'], ['SEO', '/pages/seo/'], ['Website', '/pages/builder/']]
    },
    '/pages/video/': {
        next: { label: 'Show it on your website', href: '/pages/builder/', soon: false, why: 'Video works hardest on the pages your customers already visit.' },
        related: [['Creative', '/pages/creative/'], ['SEO', '/pages/seo/'], ['CRM', '/pages/crm/']]
    },
    '/pages/calendar/': {
        next: { label: 'Track who books with you', href: '/pages/crm/', soon: false, why: 'Every booking is a customer worth keeping.' },
        related: [['Website', '/pages/builder/'], ['AI Workforce', '/pages/ai-agents/'], ['Calendar', '/pages/calendar/']]
    },
    '/pages/ai-agents/': {
        next: { label: 'See what each plan includes', href: '/pages/pricing/', soon: false, why: 'Your AI Workforce scales with the plan you choose.' },
        related: [['SEO', '/pages/seo/'], ['CRM', '/pages/crm/'], ['Website', '/pages/builder/']]
    },
    '/pages/why-levelup/': {
        next: { label: 'See what each plan includes', href: '/pages/pricing/', soon: false, why: 'Products first — credits scale the AI.' },
        related: [['Website', '/pages/builder/'], ['SEO', '/pages/seo/'], ['AI Workforce', '/pages/ai-agents/']]
    },
    '/pages/results/': {
        next: { label: 'See the honest case for LevelUp', href: '/pages/why-levelup/', soon: false, why: 'Until there are customer stories to show, the argument stands on its own.' },
        related: [['Website', '/pages/builder/'], ['SEO', '/pages/seo/'], ['Pricing', '/pages/pricing/']]
    }
};

function injectJourney() {
    var path = location.pathname;
    if (!/\/$/.test(path)) { path += '/'; }
    var j = JOURNEY[path];
    if (!j || document.getElementById('journey-next')) { return; }

    var chips = j.related.map(function (r) {
        return r[1]
            ? '<a class="footer-link" style="display:inline-block;padding:7px 14px;border:1px solid var(--border2);border-radius:100px;margin:4px 5px 0 0" href="' + r[1] + '">' + r[0] + '</a>'
            : '<span class="footer-link" style="display:inline-block;padding:7px 14px;border:1px solid var(--border2);border-radius:100px;margin:4px 5px 0 0;opacity:.55;cursor:default">' + r[0] + ' — Coming soon</span>';
    }).join('');

    var cta = j.next.soon
        ? '<span style="display:inline-block;padding:12px 26px;border:1px solid var(--border2);border-radius:10px;opacity:.6;cursor:default;font-family:var(--ff-h);font-weight:700;font-size:14px">' + j.next.label + ' — Coming soon</span>'
        : '<a href="' + j.next.href + '" class="btn btn-primary">' + j.next.label + ' →</a>';

    var html = '<section id="journey-next" style="padding:64px 28px;background:rgba(18,24,38,.45);border-top:1px solid var(--border)">'
        + '<div class="wrap" style="max-width:900px;text-align:center">'
        + '<div class="tag" style="background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.25);color:var(--violet);display:inline-block">Your next step</div>'
        + '<h2 style="margin-top:14px;font-size:28px">' + j.next.label + '</h2>'
        + '<p style="color:var(--muted);font-size:15px;line-height:1.7;max-width:560px;margin:12px auto 22px">' + j.next.why + '</p>'
        + '<div>' + cta + '</div>'
        + '<div style="margin-top:30px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;font-weight:700;margin-bottom:6px">Related products</div>' + chips + '</div>'
        + '</div></section>';

    var footer = document.getElementById('site-footer');
    if (footer) { footer.insertAdjacentHTML('beforebegin', html); }
    else { document.body.insertAdjacentHTML('beforeend', html); }
}

// ── MW-1a launch gate ─────────────────────────────────────────
// Pre-launch on production hosts, neutralize every direct marketing→app link
// so no visitor can enter unfinished functionality. Complements the SPA-shell
// guard (server-of-record backstop), selectPlan() (buttons) and the /sign-up
// redirect. Staging (staging.levelupgrowth.io) is untouched for testing.
// Flip MW1A_LAUNCHED = true at official platform launch.
(function () {
    var MW1A_LAUNCHED = false;
    var PROD = ['levelupgrowth.io', 'www.levelupgrowth.io'];
    var GATED = 'mailto:hello@levelupgrowth.io?subject=Notify%20me%20when%20LevelUp%20launches';
    if (MW1A_LAUNCHED || PROD.indexOf(location.hostname) === -1) { return; }

    // Any CTA whose text OR href would take a visitor into the app / signup.
    var CTA_RX = /(start free|try it|get started|create free account|start growing|go pro|get agency|start with|hire your ai|^login$|^sign in$|^sign up$)/i;
    var SEL = 'a[href^="/app"], a[href*="/app/#signup"], a.btn-primary, button.btn-primary, .btn-primary, .plan-cta, .cta-primary, .nav-cta, .nav-login, .mobile-cta, .hero-ctas a, .hero-ctas button';

    function gateOne(el) {
        if (el.getAttribute('data-mw1a-gated')) { return; }
        var href = el.getAttribute('href') || '';
        var text = (el.textContent || '').trim();
        var isAppLink = /^\/app/.test(href);
        if (!isAppLink && !CTA_RX.test(text)) { return; }
        el.setAttribute('data-mw1a-gated', '1');
        if (el.tagName === 'A') { el.setAttribute('href', GATED); }
        el.textContent = 'Get notified';
        el.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); window.location.href = GATED; }, true);
    }
    function mw1aGate() {
        Array.prototype.forEach.call(document.querySelectorAll(SEL), gateOne);
    }
    function run() {
        mw1aGate();
        // Catch late/dynamic renders.
        setTimeout(mw1aGate, 400);
        setTimeout(mw1aGate, 1500);
        try {
            var mo = new MutationObserver(function () { mw1aGate(); });
            mo.observe(document.body, { childList: true, subtree: true });
        } catch (e) {}
    }
    if (document.readyState !== 'loading') { run(); }
    else { document.addEventListener('DOMContentLoaded', run); }
})();
window.closeMobileNav = closeMobileNav;
