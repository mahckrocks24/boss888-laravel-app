# Palette audit — every template x every palette, measured in a real browser

Run after any change to a template, a palette (ColorTheme), PaletteRoles or the palette switch (ArthurService::applyPalette).

1. **Generate** (on the server, low priority, ~10 min for 233 x 18):
   `nice -n 19 php /root/pg1/boot.php tools/palette-audit/generate.php 0 1000 [slug,slug]`
   Renders each template with its defaults, applies each palette through the REAL palette switch on one private QA
   scratch site, and writes `public/palaudit-<token>/<template>/<palette>.html` (+ `_original.html`).
2. **Scan** (on a workstation with Chrome, ~13 min): put `slug/palette` lines in `list.txt`, then
   `node tools/palette-audit/scan.cjs` — the live contrast fix (lu-contrast.js) is switched OFF, so the templates
   themselves are measured. Text over photos / video / strong gradients / floating headers is out of scope (unknowable).
3. **Summarise**: `node tools/palette-audit/summarise.cjs` — unreadable (< 3:1) per palette / template / place.

Target: **0 unreadable on every palette page.** Baseline 2026-09-28 (round 3): 0 / 4194 palette pages; 2 raw-default
pages (construction service-icon, training_center testimonial-result) — defaults never ship, the live fix covers them.
