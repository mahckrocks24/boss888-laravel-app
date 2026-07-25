# MW-1(b) Phase 5 — Marketing Launch Readiness + **MRC-1 Snapshot**
**VERDICT: COMPLETE WITH CONDITIONS** · 2026-07-24 · MW-1(a) gate intact · `PLATFORM_PUBLIC_LAUNCHED=false`

---

## 1–2. Verdict & Executive summary
All **structural, commercial, SEO, and truthfulness** work for the marketing website is complete. Pricing now leads with products; every page has canonical + description + Open Graph + Twitter cards; the sitemap advertises canonical URLs; the truthfulness sweep is clean; **console errors are now zero site-wide** (I found and fixed a real pre-existing JS bug); and the production gate passed every verification.

**However, the website is NOT yet launchable** — for two reasons outside code: **there is no Privacy Policy, Terms of Service, or Cookie policy anywhere on the site** (all 404), and **no analytics is installed**. Both require your input; I will not draft legal terms or invent a tracking property.

**Answer to the direct question — "Is the marketing website ready to become the public face of LevelUp Growth?"**
**Structurally, commercially and editorially: YES. Legally: NO.** The missing legal pages are a hard blocker for a public site that collects contact details. Everything else is MRC-1 ready.

## 3. Pricing review — products-first ✅
- Hero: *"Start Free. Scale with AI."* → **"Everything you own, in every plan."** with a products-first subhead that explicitly demotes credits: *"AI credits are simply how your AI workforce usage scales."*
- New **"What you own on every plan"** block above the grid: Website · CRM · Calendar · SEO · Email Marketing · Creative · Video · AI Workforce, plus **Domains / Business Email / Managed Hosting — Coming soon** (explicitly "cannot be activated yet").
- **Card order inverted via CSS `order`** (no risky HTML restructuring): name → desc → price → **products** → AI badge → AI agents → CTA. Credits are now an implementation detail.

## 4. SEO audit ✅ (fixed)
| Item | Before | After |
|---|---|---|
| Canonicals | 5/17 pages | **17/17** |
| Meta descriptions | 6/17 | **17/17** |
| Open Graph | 4/17 partial | **17/17** |
| Twitter cards | **0/17** | **17/17** |
| JSON-LD | 0 | **Organization schema on homepage** (no fake ratings/reviews) |
| Duplicate titles / descriptions | — | **0 / 0** |
| Sitemap | advertised **non-canonical** top-level URLs + duplicate `/features` | **17 canonical `/pages/` URLs**, `/features` and gated `/sign-up` removed |
| robots.txt | `Allow: /` only | `Allow: /` + **`Disallow: /app`**, apex sitemap |
| Staging indexability | — | **`X-Robots-Tag: noindex`** (MW-1a, still verified) |
**Key defect fixed:** every page was reachable at two URLs (`/crm` *and* `/pages/crm/`) with no canonical → duplicate content. Self-referencing canonicals now consolidate to the `/pages/` form.

## 5. Crawl audit ✅
17/17 pages **HTTP 200**; **1 `<h1>` each**; **0 duplicate titles**; **0 duplicate/missing descriptions**; **0 broken links**; **0 404s**; **0 redirect chains or loops** (all consolidations resolve in exactly 1 hop → 200); no orphan pages (sitemap = canonical set = internal-link set).

## 6. Accessibility ✅ (no regressions)
Single H1 per page, logical H1→H2→H3 order; nav dropdown triggers are focusable `<button>`s; mobile menu toggles at 390px; responsive system untouched. Logo `alt=""` is **correct decorative usage** (adjacent visible brand text) — the crawler's "2/2 imgNoAlt" is a false positive. *Not formally measured: colour-contrast ratios.*

## 7. Performance ⚠️ (partial)
Cache-busting is disciplined (`site.js?v=…` bumped every deploy, verified propagating); one shared CSS + JS pair, no duplicate JS/CSS; **0 console errors**; no layout shift observed (single nav/footer injection). *Not formally measured: Lighthouse scores, image optimisation, preload/lazy-load opportunities.*

## 8. Analytics audit ❌ **GAP**
**No analytics installed** — no GA4, GTM, Search Console tag, or Meta Pixel found anywhere. Consequently: no duplicate events, no duplicate pageviews, no staging IDs, no debug tracking (nothing to conflict). **Launch cannot be measured until a real property ID is provided.**

## 9. Legal audit ❌ **BLOCKER**
`/privacy`, `/terms`, `/cookies`, `/legal` (and `/pages/` equivalents) **all return 404**. No legal files exist on disk; no footer legal links. For a public site inviting contact, this is a compliance gap. **I have not drafted these** — a Privacy Policy/ToS depends on your actual data practices, processors, retention and jurisdictions, and needs legal review. Footer/contact details otherwise consistent (`hello@levelupgrowth.io`).

## 10. Brand consistency ✅
Ownership terminology, "AI Workforce", "Business Email" vs "Email Marketing", availability labels, and CTA wording are consistent across all pages. **No page reverts to the old AI-marketing narrative.** Zero internal engineering terms (INFRA888/Cloudflare/connector/etc.) on any customer surface.

## 11. Truthfulness audit ✅ **clean**
Final sweep across all marketing HTML returned **no** unsupported numbers, percentages, guarantees, uptime claims, testimonials, customer names, ranking or autonomy claims. Removed this phase: an invented **"Estimated 3.2% conversion rate"** and a **"20 specialist AI agents — working 24/7"** bio. Benign remainders: a leftover CSS class name, our own disclaimer sentence, and `ai-assistant.html` (301-redirected, unreachable).

## 12. Product availability matrix (frozen)
| Product | State | Surface |
|---|---|---|
| Website, CRM, Calendar, SEO, Email Marketing, Creative, Video, AI Workforce | **Available in platform** (no "Live" claim pre-launch) | linked pages + journey |
| **Domains** | **Coming Soon** | non-link chips only |
| **Business Email** | **Coming Soon** | non-link chips only |
| **Managed Hosting** | **Coming Soon** | non-link chips only |

## 13. CTA matrix
Production: **every** app-entry CTA resolves to **"Get notified"** (gated); secondary CTAs are internal navigation ("Learn more", "View pricing", "Why LevelUp"); Coming-Soon items are non-clickable. **0 `/app` links, 0 staging links** on production. Staging retains full app entry for testing.

## 14. Redirect & canonical inventory (frozen)
**301s (1 hop → 200):** `/ai-assistant`, `/assistant`, `/specialists`, `/pages/ai-assistant/`, `/pages/assistant/`, `/pages/specialists/` → **`/pages/ai-agents/`**.
**Canonicals:** all 17 pages self-canonical to the `https://levelupgrowth.io/pages/…/` form (homepage → `/`).

## 15. MW-1(a) verification matrix — **6/6 PASS**
`/app` · `/app/#signup` · Hero CTA · Pricing CTA · typed `/sign-up` · deep link — production gated / staging functional, re-verified after every Phase-5 deploy. 4× cache-propagation consistency clean.

## 16. Remaining launch risks
1. **[BLOCKER] Legal pages absent** — Privacy, Terms, Cookies must exist before public launch (needs you + legal).
2. **[GAP] No analytics** — needs a real GA4/GTM property + Search Console verification.
3. **[MINOR] Performance not formally benchmarked** (no Lighthouse run); contrast ratios not measured.
4. **[MINOR] Dead content** in `ai-assistant.html` (301-redirected, unreachable) could be deleted for tidiness.
5. **[BY DESIGN] Domains / Business Email / Managed Hosting remain Coming Soon** until their own CUSTOMER-LIVE gates.

## 17. MRC-1 SNAPSHOT (frozen baseline)
- **Navigation:** Products ▾ · Why LevelUp · Pricing · Resources ▾ · [Get notified]
- **Products ▾ (Ownership Stack):** *Own your presence* → Website · *Own your identity* → Domains•, Business Email• · *Own your platform* → Managed Hosting• · *Own your growth* → SEO, Email Marketing, Creative, Video · *Own your customers* → CRM, Calendar · *Your AI Workforce* → AI Workforce   (• = Coming Soon, non-link)
- **Resources ▾:** How It Works · Use Cases · Compare · FAQ · Blog
- **Footer:** Products / Resources / Company(Contact) + single gated CTA
- **Customer journey:** Launch → Own → Grow → Operate → Scale, mapped Website → Domain → Business Email → Managed Hosting → SEO → CRM → Automation → AI Workforce
- **Page inventory (17):** `/`, `/pages/{builder, seo, email, crm, calendar, creative, video, ai-agents, why-levelup, results, use-cases, how-it-works, comparison, faq, pricing}/`, `/blog/`
- **Messaging hierarchy:** Ownership (promise) → One platform (consolidation) → AI Workforce (engine) → Owned assets → Proof/trust
- **Internal linking map:** nav + footer + per-page journey block (next step + 3 related) on 7 product/strategy pages
- Baselines saved: `/tmp/mrc1-baseline.json` (pre-Phase-5), `/tmp/mrc1-final.json` (MRC-1 state)

## 18. Rollback
Backups: `/root/mw1b-p4-20260724-111347/` (site.js, web.php, **all pages**), `/root/mw1b-p3-20260724-104823/`, `/root/mw1b-p2-20260724-103345/`, `/root/mw1a-20260724-085316/`. Restore `routes/web.php` (reverts sitemap/robots/301s) and any page from `pages/`; revert `site.js?v=` query. No DB, nginx, or gate-config change at any point; `PLATFORM_PUBLIC_LAUNCHED` remained `false` throughout.

## 19. Evidence directory
`C:\Users\markr\LVL\handoff-2026-07-24\` — this report, phase reports (P1–P4), `screenshots-mw1a\` (nav, footer, homepage hero, Ownership Stack, Why LevelUp). Crawl JSON baselines + matrix/redirect/canonical/claim-sweep logs captured on the droplet this session.

---
**Recommendation:** declare **MRC-1** for information architecture, messaging hierarchy, product taxonomy, navigation and customer journey — these are frozen and should not be redesigned. **Do not open the site publicly until the legal pages exist and analytics is installed.**
