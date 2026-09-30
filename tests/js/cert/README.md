# Certification harness (RFC-0021, wave 0)

Real-browser certification of the "Build my website" journey against staging, on FRESH accounts.
Never run against a customer workspace (INC-0007).

Requires Node 22 and Playwright (`PLAYWRIGHT_PATH` may point at an installed copy).

- `journey.cjs <scenario>` — sign-up, Arthur brief, build, editor (scenarios in `scenarios.json`: bakery, vague-phone, offcatalogue, injection, arabic). Writes `run-<scenario>/`.
- `publish.cjs <scenario>` — address picker and Publish from the editor.
- `siteaudit.cjs <url>` — live-site audit (security headers, SEO, overflow, console, contact form).
- `edits.cjs` — Arthur post-publish edits.
- `edge.cjs`, `edge-build.cjs` — fail-first API probes (addresses, hostile uploads, tenancy, script in name, double submit, low credits).
- `selfedit*.cjs <scenario> <site> <live-url> [phone]` — the owner's per-element tools (text, paste, links, photos, logo, toolbox, undo, versions, catalogues, Back, Publish). `part-*.js` are the step bodies.

Origin: REPORT-0067 and REPORT-0068 (VAULT888, 2026-09-30). Every run leaves a `log.json` and screenshots.
