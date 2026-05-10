/**
 * LevelUp Growth — Shared Site JS
 * Injects nav and footer, handles scroll reveal, mobile nav, demo simulation
 */
'use strict';

// ── Nav HTML ─────────────────────────────────────────────────
const NAV_HTML = `
<nav id="nav">
  <div class="nav-inner">
    <a class="nav-logo" href="/">
      <div class="nav-logo-icon"><img src="/img/logo-icon-40.png" alt=""></div>
      <span>LevelUpGrowth</span>
    </a>
    <div class="nav-links">
      <a class="nav-link" href="/">Home</a>
      <div class="nav-dd-wrap">
        <button class="nav-link nav-dd-trigger">Platform ▾</button>
        <div class="nav-dd">
          <a class="nav-dd-item" href="/pages/builder/">🏗️ Website Builder</a>
          <a class="nav-dd-item" href="/pages/email/">📣 Email Marketing</a>
          <a class="nav-dd-item" href="/pages/crm/">🤝 CRM</a>
          <a class="nav-dd-item" href="/pages/calendar/">📅 Calendar</a>
          <a class="nav-dd-item" href="/pages/creative/">🎨 Creative Engine</a>
          <a class="nav-dd-item" href="/pages/video/">🎬 AI Video</a>
          <div style="height:1px;background:rgba(255,255,255,.07);margin:6px 0"></div>
          <a class="nav-dd-item" href="/pages/ai-assistant/">🤖 AI Assistant</a>
          <a class="nav-dd-item" href="/pages/ai-agents/">👥 AI Agents</a>
          <a class="nav-dd-item" href="/pages/specialists/">🧩 All 20 Specialists</a>
        </div>
      </div>
      <a class="nav-link" href="/pages/use-cases/">Use Cases</a>
      <a class="nav-link" href="/pages/how-it-works/">How It Works</a>
      <a class="nav-link" href="/pages/pricing/">Pricing</a>
      <a class="nav-link" href="/pages/faq/">FAQ</a>
      <a class="nav-link" href="/blog/">Blog</a>
    </div>
    <div class="nav-right">
      <a href="/app/" class="nav-login">Login</a>
      <a href="/app/#signup" class="btn btn-primary btn-sm">Start Free</a>
      <button id="mobile-nav-btn" onclick="toggleMobileNav()" aria-label="Menu">☰</button>
    </div>
  </div>
</nav>
<div id="mobile-nav-menu">
  <a href="/">Home</a>
  <a href="/pages/builder/">Website Builder</a>
  <a href="/pages/email/">Email Marketing</a>
  <a href="/pages/crm/">CRM</a>
  <a href="/pages/calendar/">Calendar</a>
  <a href="/pages/creative/">Creative Engine</a>
  <a href="/pages/video/">AI Video</a>
  <a href="/pages/ai-assistant/">🤖 AI Assistant</a>
  <a href="/pages/ai-agents/">👥 AI Agents</a>
  <a href="/pages/specialists/">🧩 All 20 Specialists</a>
  <div style="height:1px;background:rgba(255,255,255,.07);margin:8px 0"></div>
  <a href="/pages/use-cases/">Use Cases</a>
  <a href="/pages/how-it-works/">How It Works</a>
  <a href="/pages/pricing/">Pricing</a>
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
        <p style="color:#4B5563;font-size:13.5px;line-height:1.75;max-width:240px">Your AI Marketing Operating System. Website, SEO, Content, CRM, and more — all in one platform.</p>
      </div>
      <div>
        <div class="footer-col-title">Platform</div>
        <div class="footer-links">
          <a class="footer-link" href="/pages/builder/">Website Builder</a>
          <a class="footer-link" href="/pages/email/">Email Marketing</a>
          <a class="footer-link" href="/pages/crm/">CRM</a>
          <a class="footer-link" href="/pages/creative/">Creative Engine</a>
          <a class="footer-link" href="/pages/video/">AI Video</a>
          <a class="footer-link" href="/pages/ai-assistant/">AI Assistant</a>
          <a class="footer-link" href="/pages/ai-agents/">AI Agents</a>
          <a class="footer-link" href="/pages/specialists/">All 20 Specialists</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Company</div>
        <div class="footer-links">
          <a class="footer-link" href="/pages/how-it-works/">How It Works</a>
          <a class="footer-link" href="/pages/use-cases/">Use Cases</a>
          <a class="footer-link" href="/pages/results/">Results</a>
          <a class="footer-link" href="/pages/pricing/">Pricing</a>
          <a class="footer-link" href="/pages/comparison/">Compare</a>
          <a class="footer-link" href="/pages/faq/">FAQ</a>
          <a class="footer-link" href="/blog/">Blog</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Get Started</div>
        <div class="footer-links">
          <a class="footer-link" href="/app/#signup">Create Free Account</a>
          <a class="footer-link" href="/app/">Login</a>
        </div>
        <div style="margin-top:16px">
          <a href="/app/#signup" class="btn btn-primary btn-sm">Start Free →</a>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <p style="color:#374151;font-size:13px">© 2026 LevelUpGrowth. All rights reserved.</p>
      <p style="color:#374151;font-size:13px;font-family:var(--ff-h)">Your AI Marketing Team</p>
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
function selectPlan(name, price) {
    try { localStorage.setItem('lu_selected_plan', name); localStorage.setItem('lu_selected_price', price); } catch(_) {}
    window.location.href = '/app/#signup';
}

// ── Escape helper ─────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Init
document.addEventListener('DOMContentLoaded', SiteInit);

window.SiteInit = SiteInit;
window.selectPlan = selectPlan;
window.toggleMobileNav = toggleMobileNav;
window.closeMobileNav = closeMobileNav;
