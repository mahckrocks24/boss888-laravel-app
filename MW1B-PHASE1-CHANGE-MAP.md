# MW-1(b) — Phase 1: Inventory & Change Map
**2026-07-24. Analysis only — NO edits yet (per rule: no broad edits until the map is complete). Governs Phases 2–5.**
Grounded in the live static pages + `site.js`. MW-1(a) gate untouched; `PLATFORM_PUBLIC_LAUNCHED=false`.

## Architecture reality (decisive)
- Live marketing = **static HTML** in `public/marketing/` (18 pages) + `public/marketing/index.html`, served via `response()->file()`.
- **Nav + footer are JS-rendered from `public/marketing/js/site.js`** (`NAV_HTML` / `FOOTER_HTML` injected on every page) — **one shared edit point** for nav/footer/taxonomy/shared-CTAs. `site.js` also contains the MW-1(a) gate (do not disturb) + the "demo simulation".
- Per-page HTML holds hero + body content only.
- Sitemap + robots are Laravel routes in `routes/web.php`.

## Shared assets — change map
| Asset | Current | Target | Notes |
|---|---|---|---|
| `site.js` NAV_HTML "Platform ▾" | Website Builder · Email Marketing · CRM · Calendar · Creative · AI Video · AI Assistant · AI Agents · 20 Specialists | Regroup into **Ownership Stack**: Presence (Website) · Identity (Domains•, Business Email•) · Platform (Managed Hosting•) · Growth (SEO⁺, Content/Creative, Social, Email Marketing, Video) · Customers (CRM, Calendar) · **AI Workforce** (consolidated). Add availability badges (•=Coming Soon, ⁺=new page). | 1 file → all pages. Re-run matrix after (nav has /app CTAs the gate must still catch). |
| `site.js` nav-right CTAs | "Login"→/app/, "Start Free"→/app/#signup | Keep markup; **MW-1(a) gate already rewrites on prod** → "Get notified". No change needed to gate. | Verify gate still catches after nav edits. |
| `site.js` FOOTER_HTML | Old tool taxonomy + "Create Free Account"/Login (/app) | Ownership-Stack columns; add Domains/Business Email/Managed Hosting (Coming Soon), SEO; footer /app CTAs stay gated. | |
| `site.js` demo simulation (James/Priya/Marcus + fake counts) | Simulated "live agents working" + "142 Tasks/38 Content/29 Leads" | **Remove/relabel** fabricated live metrics; if kept, label clearly as an illustrative product demo, no fake customer numbers. | Truthfulness standard. |
| `routes/web.php` sitemap | lists `/sign-up` (gated), per-host base | Drop `/sign-up` + any gated route; force apex base; add new pages (SEO, Why-LevelUp); keep noindex-safe. | Small route edit, backed up. |
| `routes/web.php` robots | `Allow:/` all hosts | Keep (staging noindex is via `X-Robots-Tag` header — already live). Optionally `Disallow: /app`. | No change required for isolation. |
| Canonicals | **0 of 19 pages** have canonical | Add self-referencing canonical (apex) to every static page `<head>`. | Per-page edit (Phase 5). |

## Per-page change map (19 pages)
| Page / route | Current purpose | Target purpose | Key changes | Route/redirect | Availability |
|---|---|---|---|---|---|
| `index.html` `/` | AI-marketing hero + flat feature grid + simulated demo + fabricated stats | **Ownership hero** → stitched-tools problem → Ownership Stack → AI Workforce engine → honest availability → real/omitted proof → products-first pricing → waitlist CTA | Re-sequence; new hero copy; **remove 142/38/29 + "10x"**; reframe demo; add availability badges | keep `/` | — |
| `pages/builder.html` `/builder`,`/features?` | "Website Builder + Hosting" | "Own your Website" (presence) | Ownership framing; hosting→"Managed Hosting" cross-link (Coming Soon) | keep | Website=Live |
| `pages/email.html` `/email` | **Email Marketing** (campaigns) | Keep = **Email Marketing** (disambiguate from Business Email); title already "Email Marketing" | Ensure copy never implies mailboxes; add "Business Email (mailboxes) → Coming Soon" pointer | keep | Email Marketing=Live |
| `pages/crm.html` `/crm` | CRM | "Own your Customers" (CRM + Customer Data) | Ownership framing | keep | CRM=Live |
| `pages/calendar.html` `/calendar` | Calendar/Booking | Under "Own your Customers/Operations" | Light reframe | keep | Live |
| `pages/creative.html` `/creative` | Creative Engine | Under "Own your Growth" | Light reframe | keep | Live |
| `pages/video.html` `/video` | AI Video | Under "Own your Growth" | Light reframe | keep | Live |
| `pages/ai-agents.html` `/ai-agents` | AI Agents | **Consolidate → AI Workforce hub** | Merge narrative; canonical to hub | 301 plan (preserve) | Live |
| `pages/ai-assistant.html` `/ai-assistant` | AI Assistant | Consolidate → AI Workforce | Merge; redirect/canonical | 301 plan | Live |
| `pages/assistant.html` `/assistant` | "Redirecting…" stub | Consolidate → AI Workforce | Confirm redirect target | 301 → AI hub | — |
| `pages/specialists.html` `/specialists` | "20 Specialists" marketplace | AI Workforce sub-view | **Verify "20 Specialists" is truthful**; keep agent personas (product, not fake customers) | keep/redirect | Live (verify count) |
| `pages/results.html` `/results` | "Real AI Marketing Results" — **50%/65% + testimonials** | **Truthfulness blocker** — no real customers pre-launch | Remove fabricated results/testimonials; repurpose to "what to expect" OR mark Coming Soon OR remove+redirect | 301 if removed | — |
| `pages/comparison.html` `/comparison` | vs Wix/HubSpot (tool-stack) | vs stitched stack → ownership framing | Reframe to Ownership differentiation | keep | — |
| `pages/use-cases.html` `/use-cases` | Industry use cases | Solutions (persona/industry) | Light reframe; remove any fabricated metrics | keep | — |
| `pages/how-it-works.html` `/how-it-works` | Sign-up→growth | Ownership commercial journey | Reframe to Website→Domain→Email→… ; honest steps | keep | — |
| `pages/pricing.html` `/pricing` | credit/agent-first tiers | **Products-first** (included products + availability + limits, credits as scaling) | Restructure; add availability; waitlist CTAs (gate) | keep | — |
| `pages/faq.html` `/faq` | FAQ | FAQ + ownership answers | **Fix "our infrastructure"→"our managed hosting"**; add domain/email/hosting FAQs w/ availability | keep | — |
| `pages/blog.html`,`blog-post.html` `/blog` | Blog (JS-loaded) | Keep | canonical only | keep | — |
| **NEW `Why LevelUp`** | — | Strategic "stop stitching tools" page | New page + nav entry | new route | — |
| **NEW `SEO`** | — (no page) | Truthful SEO product page | New page + nav entry | new route | SEO=Live (verify) |

## Cross-cutting
- **Internal-term leaks:** only `faq.html` "our infrastructure" → soften. (No INFRA888/Cloudflare/Migadu leaks found on customer pages.)
- **Fabricated claims to remove/correct:** homepage 142/38/29 + "10x Faster" + simulated live-agent counts; `results.html` 50%/65% + testimonials; verify "20 Specialists". Keep: AI agent personas (product), "24/7" only if truthful (AI runs continuously).
- **Availability matrix:** Website/Builder, CRM, Email Marketing, Creative, Video, Calendar, SEO, AI Workforce = **Live** (verify each renders/works); **Domains = Coming Soon** (until S3.5 CUSTOMER-LIVE); **Business Email = Coming Soon/Waitlist**; **Managed Hosting = Coming Soon**.
- **Canonicals:** add self-referencing apex canonical to all 19 pages (Phase 5). **Sitemap:** drop `/sign-up`, apex base, add new pages. **Redirects:** for any consolidated/removed page (assistant, possibly results), 301 to the surviving hub + canonical, preserving search value. **Staging:** stays noindex (header live).

## Edit plan → phases
- **P2 (shared):** `site.js` NAV_HTML/FOOTER_HTML → Ownership Stack + availability badges; fix faq internal term; **re-run matrix**.
- **P3:** `index.html` re-sequence (remove fabricated claims) + new **Why LevelUp** page; **re-run matrix**.
- **P4:** product pages reframe + Email-Marketing disambiguation + **SEO page** + **AI consolidation** (redirects/canonicals); **re-run matrix**.
- **P5:** products-first `pricing.html` + canonicals (all pages) + sitemap + redirect/link/mobile/a11y audits + **full matrix**.

**Phase 1 complete. No broad edits performed.** Proceeding to Phase 2.
