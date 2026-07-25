# MW-1(b) — Phase 2: Shared Nav/Footer/Taxonomy/Terminology — COMPLETE WITH CONDITIONS
**2026-07-24. Live production change. MW-1(a) gate untouched; `PLATFORM_PUBLIC_LAUNCHED=false`.**

## 1. Executive summary
Realigned the shared, JS-rendered navigation and footer (`site.js`) to the approved **Ownership Stack** taxonomy, softened the one internal-term leak (`faq.html`), and removed old tool-taxonomy labels — **without touching the MW-1(a) gate**. Post-change, the full entry-point matrix is **6/6 PASS** (baseline was 6/6 — no regression), all nav/footer destinations return 200, Coming-Soon items are non-clickable, no internal terms leak, no console errors, no duplicate render, mobile menu + keyboard work. **Verdict: COMPLETE WITH CONDITIONS** — conditions are forward-dependencies (Why-LevelUp + SEO nav entries await their Phase-3/4 pages; pre-existing homepage/results content defects belong to Phase 3/4), not defects in this phase.

## 2. Exact files changed
- `public/marketing/js/site.js` — `NAV_HTML` + `FOOTER_HTML` rewritten to Ownership Stack. **Gate logic (SPA-guard-independent `MW1A_LAUNCHED` block), `selectPlan()`, `SiteInit`, demo sim — untouched.**
- `public/marketing/pages/faq.html` — "hosted on our infrastructure" → "hosted on our secure, managed hosting".
- 18× `public/marketing/**.html` — cache-bust `site.js?v=2.1.1-mw1a` → `?v=2.2.0-mw1b` (version query only).

## 3. Backup location & checksums
- Backup: **`/root/mw1b-p2-20260724-103345/`** (`site.js`, `faq.html`).
- `site.js` md5: **pre `75fae31e56cef0876fcdfed28cb22718`** → **post `4678113b79ea0ede223c6b41c3a9b153`**.
- `faq.html` md5 pre: `d36a6afccab51d66d0dac88b1f0ef376`.

## 4. Final navigation structure
Top nav: **Products ▾ · Pricing · Resources ▾ · [Start Free → gate → "Get notified" on prod]**.
- **Products ▾** (Ownership Stack): Own your presence → **Website**; Own your identity → **Domains** (Coming soon•), **Business Email** (Coming soon•); Own your platform → **Managed Hosting** (Coming soon•); Own your growth → **Email Marketing**, **Creative**, **Video**; Own your customers → **CRM**, **Calendar**; Your AI Workforce → **AI Workforce**.
- **Resources ▾**: How It Works · Use Cases · Compare · FAQ · Blog.
- Mobile menu mirrors this (Coming-Soon as non-link spans). Coming-Soon (•) = `<span>` non-links (no href).

## 5. Final footer structure
- **Products**: Website · Domains (Coming soon) · Business Email (Coming soon) · Managed Hosting (Coming soon) · Email Marketing · Creative · Video · CRM · Calendar · AI Workforce.
- **Resources**: How It Works · Use Cases · Compare · Pricing · FAQ · Blog.
- **Company**: Contact (mailto) + a single "Start Free →" CTA (gate → "Get notified" on prod).
- Tagline → "Own your business online — your website, email, and growth — run for you by AI." Removed old "Get Started" login/create-account column, `/results` link, and individual AI-Assistant/Agents/Specialists links (consolidated to AI Workforce).

## 6. Product availability matrix (nav/footer)
| Product | State | In nav/footer |
|---|---|---|
| Website (Builder) | Live | linked |
| Email Marketing, Creative, Video, CRM, Calendar, AI Workforce | Live | linked |
| **Domains** | **Coming Soon** | non-link |
| **Business Email** | **Coming Soon** | non-link |
| **Managed Hosting** | **Coming Soon** | non-link |
| SEO | Live capability, **no page yet** | deferred to Phase 4 (no 404) |
| Why LevelUp | page pending | deferred to Phase 3 (no 404) |

## 7. Internal-term audit
`site.js` scanned for INFRA888/BOSS888/infrastructure/connector/cloudflare/migadu/custom hostname/fallback origin/provider → **clean**. `faq.html` "our infrastructure" → softened (verified live: "secure, managed hosting"). Rendered nav+footer internal-leak check → **false** (4× on prod).

## 8. Broken-link & destination audit
All 13 nav/footer destinations return **HTTP 200**: builder, email, creative, video, crm, calendar, ai-agents, pricing, how-it-works, use-cases, comparison, faq, blog. Coming-Soon items have no destination (non-links). No staging links on production.

## 9. Full production/staging entry-point matrix (post-change)
| Test | Production | Staging | Result |
|---|---|---|---|
| `/app` | Redirects → home | Opens app | ✅ |
| `/app/#signup` | Redirects → home | Opens signup | ✅ |
| Hero CTA | Waitlist ("Get notified") | Opens app | ✅ |
| Pricing CTA | Waitlist (0 /app links) | Opens signup | ✅ |
| Typed `/sign-up` | Blocked → home | Works | ✅ |
| Deep link `/app/dashboard` | Blocked → home | Works | ✅ |
Consistency (4× prod): `navCount=1, footerCount=1, appLinks=0, stagingLinks=0, comingSoonClickable=0`. Staging: `appLinks=3` (testable). **No regression vs baseline.**

## 10. Desktop / mobile / keyboard
- Desktop: Products/Resources dropdowns render (Ownership Stack groups). Evidence `MW1B-P2-prod-nav.png`.
- Mobile (390px): menu toggles open (display none→open). 
- Keyboard: `.nav-dd-trigger` is a focusable `<button>`.

## 11. Console & rendering
- Console errors (excluding pre-existing unrelated 401s): **0** (4× prod + staging).
- Single nav + single footer (no duplicate injection). No layout shift observed (SiteInit injects once on DOMContentLoaded).
- Cloudflare/browser cache: version-bumped; consistent across 4 samples.

## 12. Remaining Phase-3/4 content issues (register — NOT in Phase 2 scope)
Homepage: "142 Tasks Done / 38 Content Live / 29 Leads", "10x Faster", simulated live-agent activity (Sarah/James/Priya/Marcus/Elena/Alex + "Running SERP analysis…"). `results.html`: 50%/65% + testimonials. Pending verify: "20 Specialists", "24/7". Deferred structure: **Why LevelUp** page+nav (P3), **SEO** page+nav (P4), **AI page consolidation + redirects/canonicals** (P4), **canonicals + sitemap** (P5), `/results` content fix or redirect (P3/4; already removed from nav/footer).

## 13. Rollback procedure
Restore `site.js` + `faq.html` from `/root/mw1b-p2-20260724-103345/` (this preserves the gate, which lives in `site.js`); revert the version query `2.2.0-mw1b`→`2.1.1-mw1a` across `public/marketing` via sed; `chown www-data`. No DB/nginx/config change. Gate flag remains `false` throughout.

## 14. Evidence directory
`C:\Users\markr\LVL\handoff-2026-07-24\screenshots-mw1a\` — `MW1B-P2-prod-nav.png` (Ownership-Stack Products dropdown), `MW1B-P2-prod-footer.png`. Matrix + structural check logs captured this session.
