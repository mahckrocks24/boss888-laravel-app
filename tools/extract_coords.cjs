#!/usr/bin/env node
/**
 * Extract precise coords + typography of every [data-field] element
 * from a rendered source template. Outputs JSON:
 *   { field_name: {x, y, w, h, font_size, font_weight, font_family, color, text_align, anchor, content, display} }
 * Plus canvas dimensions + animation hints.
 *
 * Usage: node extract_coords.cjs <template_url> <out_json> [freeze_ms]
 */
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

function _resolveChromePath(){
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) return process.env.PUPPETEER_EXECUTABLE_PATH;
  const cacheRoot = process.env.PUPPETEER_CACHE_DIR || '/var/www/levelup-staging/.puppeteer-cache';
  try { const r = path.join(cacheRoot,'chrome'); const vs = fs.readdirSync(r).filter(v=>v.startsWith('linux-')).sort(); return path.join(r,vs[vs.length-1],'chrome-linux64','chrome'); } catch(_){}
  return puppeteer.executablePath();
}

(async()=>{
  const [url, out, tArg] = process.argv.slice(2);
  const t = parseInt(tArg || '8000', 10);
  const browser = await puppeteer.launch({
    executablePath: _resolveChromePath(),
    userDataDir: '/tmp/extract-' + process.pid,
    headless: 'new',
    args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-zygote','--disable-crash-reporter','--hide-scrollbars'],
  });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 1 });
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch(_){}
    await new Promise(r => setTimeout(r, 500));

    // Override responsive wrappers so we measure at native 1080x1920
    await page.addStyleTag({ content:
      '.scene{padding:0!important;display:block!important;min-height:0!important;background:transparent!important}' +
      '.sw,.scale-wrap{transform:none!important;width:auto!important;height:auto!important;display:block!important}' +
      'html,body{overflow:hidden!important;width:1080px!important;height:1920px!important;margin:0!important}'
    });

    // Freeze animations at final frame and force-visible
    await page.evaluate((ms) => {
      document.getAnimations().forEach(a => { try { a.currentTime = ms; a.pause(); } catch(_){} });
      document.querySelectorAll('*').forEach(el => {
        var cs = window.getComputedStyle(el);
        if (cs.opacity === '0') el.style.opacity = '1';
        if (cs.transform && cs.transform !== 'none' && cs.transform !== 'matrix(1, 0, 0, 1, 0, 0)') {
          el.style.transform = 'none';
        }
      });
    }, t);
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    await new Promise(r => setTimeout(r, 400));

    // Extract bbox + style for each [data-field]
    const result = await page.evaluate(() => {
      function reelRoot(){
        return document.querySelector('.reel') || document.body;
      }
      function trim(f){ return f ? f.replace(/^['"]|['"]$/g, '').split(',')[0].trim() : ''; }

      // element_type inference (4-priority ladder — see element_types.md)
      // Class-name patterns ordered most-specific → generic.
      const CLASS_RULES = [
        [/\bheadline-accent\b|\bhl-outline\b|\bhl-color\b|\bhl-pink\b|\bhl-stroke\b/i, 'headline_accent'],
        [/\bheadline\b|\bhl-\w|\bh1-display\b/i,                                       'headline'],
        [/\beyebrow\b|\bey-\w/i,                                                        'eyebrow'],
        [/\bsubtext\b|\bsub-copy\b|\bsub-body\b|\bsub\b/i,                              'subtext'],
        [/\bcta-ghost\b|\bcta-outline\b|\bcta-link\b|\bcta-secondary\b/i,               'cta_ghost'],
        [/\bcta\b|\bcta-primary\b|\bbtn-primary\b/i,                                    'cta'],
        [/\bstat-val\b|\bstat-value\b|\bstat-number\b|\bsc-val\b/i,                     'stat_value'],
        [/\bstat-label\b|\bstat-caption\b|\bsc-label\b/i,                               'stat_label'],
        [/\bprice-now\b|\bprice-hero\b|\bprice-value\b/i,                               'price_value'],
        [/\bprice-period\b|\bprice-per\b|\bprice-was\b/i,                               'price_period'],
        [/\bquote\b|\btestimonial\b/i,                                                  'quote'],
        [/\bfeat-check\b/i,                                                             'feature_pill'],
        [/\bfeat\b|\bfeature-pill\b|\bpill\b|\bp-\w/i,                                  'feature_pill'],
        [/\blive-badge\b|\blive-dot\b/i,                                                'badge_live'],
        [/\bfloat-badge\b|\bfb-\w/i,                                                    'badge_float'],
        [/\bbrand-name\b|\bbrand-logo\b|\bbrand-mark\b/i,                                'brand_name'],
        [/\bbrand-pill\b/i,                                                             'brand_pill'],
        [/\bfooter-handle\b|\bhandle\b/i,                                               'handle'],
        [/\bfooter-url\b|\burl\b/i,                                                     'url'],
        [/\bl-name\b|\bl-desc\b|\bl-price\b|\bl-icon\b|\bli\b/i,                        'listing_line'],
      ];
      // Field-name regex → type (runs if class rules didn't match)
      const FIELD_RULES = [
        [/^headline_?\d*$/i,                'headline'],
        [/^eyebrow/i,                       'eyebrow'],
        [/^subtext|^sub_/i,                 'subtext'],
        [/^stat_\d+_value$/i,               'stat_value'],
        [/^stat_\d+_label$/i,               'stat_label'],
        [/^feature_/i,                      'feature_pill'],
        [/^cta_ghost|^cta_secondary/i,      'cta_ghost'],
        [/^cta_/i,                          'cta'],
        [/^price_period|^price_was/i,       'price_period'],
        [/^price_/i,                        'price_value'],
        [/^brand_name/i,                    'brand_name'],
        [/^brand_pill/i,                    'brand_pill'],
        [/^badge_/i,                        'badge_float'],
        [/^live_/i,                         'badge_live'],
        [/^footer_url/i,                    'url'],
        [/^footer_handle/i,                 'handle'],
        [/^listing_|^li_/i,                 'listing_line'],
        [/^quote/i,                         'quote'],
      ];
      function classifyByClass(el){
        var cls = el.className || '';
        // SVG elements yield SVGAnimatedString — stringify safely
        if (typeof cls !== 'string' && cls.baseVal != null) cls = cls.baseVal;
        if (typeof cls !== 'string') cls = '';
        // Walk up to 3 ancestors so class on wrapper still counts
        var allCls = cls;
        var node = el.parentElement;
        for (var i = 0; i < 3 && node; i++, node = node.parentElement){
          var c = node.className;
          if (typeof c !== 'string' && c && c.baseVal != null) c = c.baseVal;
          if (typeof c === 'string') allCls += ' ' + c;
        }
        for (var j = 0; j < CLASS_RULES.length; j++){
          if (CLASS_RULES[j][0].test(allCls)) return CLASS_RULES[j][1];
        }
        return null;
      }
      function classifyByField(f){
        for (var i = 0; i < FIELD_RULES.length; i++){
          if (FIELD_RULES[i][0].test(f)) return FIELD_RULES[i][1];
        }
        return null;
      }
      function classifyByHeuristic(fs, cx, cy, canvasW, canvasH){
        if (fs >= 100) return 'headline';
        if (fs >= 48)  return 'stat_value';
        if (fs <= 18 && cy < canvasH * 0.35 && cx > canvasW * 0.5) return 'badge_float';
        return 'subtext';
      }

      const reel = reelRoot();
      const rRect = reel.getBoundingClientRect();
      const fields = {};
      document.querySelectorAll('[data-field]').forEach(el => {
        const f = el.getAttribute('data-field'); if (!f) return;
        const rc = el.getBoundingClientRect();
        const cs = window.getComputedStyle(el);
        // Coords relative to .reel top-left
        const x = rc.left - rRect.left;
        const y = rc.top  - rRect.top;
        const w = rc.width;
        const h = rc.height;
        // Anchor inferred from text-align + position
        const align = cs.textAlign || 'left';
        let anchor = 'left';
        let cx = x;
        const cy = y + h / 2;
        if (align === 'center') { anchor = 'center'; cx = x + w / 2; }
        else if (align === 'right') { anchor = 'right';  cx = x + w; }
        // Font family fallback: read the first listed that browser accepted
        const famRaw = cs.fontFamily || '';
        const fam = trim(famRaw);
        // element_type: data-attribute → class → field-name → heuristic
        const fs = parseFloat(cs.fontSize) || 16;
        var elType =
          el.getAttribute('data-element-type') ||
          classifyByClass(el) ||
          classifyByField(f) ||
          classifyByHeuristic(fs, cx, cy, rRect.width, rRect.height);
        fields[f] = {
          x: Math.round(cx), y: Math.round(cy),
          bbox: { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) },
          font_size:   fs,
          font_weight: cs.fontWeight || '400',
          font_family: fam,
          color:       cs.color,
          text_align:  align,
          anchor:      anchor,
          line_height: parseFloat(cs.lineHeight) || null,
          letter_spacing: cs.letterSpacing,
          content:     (el.textContent || '').trim(),
          tag:         el.tagName,
          element_type: elType,
          has_bg:      cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent',
        };
      });

      // Animation timing hints — for each data-field element, find the CSS
      // animation and report its delay + duration
      document.querySelectorAll('[data-field]').forEach(el => {
        const f = el.getAttribute('data-field'); if (!fields[f]) return;
        // Walk up DOM looking for an element with animation
        let node = el;
        for (let i = 0; i < 4 && node; i++, node = node.parentElement){
          const cs = window.getComputedStyle(node);
          const dur = cs.animationDuration; const delay = cs.animationDelay;
          if (dur && dur !== '0s' && dur !== ''){
            fields[f].anim_duration = parseFloat(dur) || 0.5;
            fields[f].anim_delay    = parseFloat(delay) || 0;
            break;
          }
        }
      });

      return { canvas_w: Math.round(rRect.width), canvas_h: Math.round(rRect.height), fields };
    });

    process.stdout.write(JSON.stringify(result, null, 2));
    if (out){ fs.writeFileSync(out, JSON.stringify(result, null, 2)); }
  } finally {
    try { await browser.close(); } catch(_){}
  }
})();
