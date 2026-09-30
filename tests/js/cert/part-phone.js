  await shot('ph-00');
  rec('PH toolbar', await p.evaluate(() => { const vw = innerWidth; return Array.from(document.querySelectorAll('#template-editor-view .pe-bar button')).filter(b => !b.hidden && b.getBoundingClientRect().width).map(b => { const r = b.getBoundingClientRect(); return (b.innerText.trim() || b.getAttribute('aria-label') || '?').replace(/\s+/g, ' ').slice(0, 12) + '@' + Math.round(r.left) + (r.right > vw ? '(OFF)' : ''); }); }));
  rec('PH arthur panel / preview sizes', await p.evaluate(() => { const s = document.querySelector('#template-editor-view .pe-side'), st = document.querySelector('#template-editor-view .pe-stage'); const R = e => e ? (({ x, y, width, height }) => ({ x: Math.round(x), y: Math.round(y), w: Math.round(width), h: Math.round(height) }))(e.getBoundingClientRect()) : null; return { side: R(s), stage: R(st) }; }));
  const f = fr.locator('[data-field="hero_title"]').first(); await f.scrollIntoViewIfNeeded().catch(() => {});
  const fb = await f.boundingBox(); rec('PH hero_title box', fb);
  let n0 = net.length;
  if (fb) { await p.touchscreen.tap(fb.x + 20, fb.y + fb.height / 2); await p.waitForTimeout(120); await p.touchscreen.tap(fb.x + 20, fb.y + fb.height / 2); await p.waitForTimeout(1500); }
  rec('PH editable after double-tap', await f.evaluate(e => e.isContentEditable).catch(() => 'n/a'));
  await shot('ph-01-editing');
  await p.keyboard.press('Control+A'); await p.keyboard.type('Fresh kicks, fair prices', { delay: 8 });
  const kb = await p.evaluate(() => ({ vv: window.visualViewport ? Math.round(visualViewport.height) : null }));
  await fr.locator('body').tap({ position: { x: 3, y: 3 } }).catch(() => {}); await p.waitForTimeout(4000);
  rec('PH save', since(n0)); rec('PH live', ((await liveField('hero_title')) || '').slice(0, 80));
  await shot('ph-02-after');
  // picture tap
  const im = fr.locator('[data-field="hero_image"], [data-field="story_image"]').first(); await im.scrollIntoViewIfNeeded().catch(() => {}); const ib = await im.boundingBox();
  if (ib) { await p.touchscreen.tap(ib.x + ib.width / 2, ib.y + ib.height / 2); await p.waitForTimeout(1800); }
  rec('PH picture panel', await p.evaluate(() => { const x = document.getElementById('t3-img-panel'); if (!x) return null; const r = x.getBoundingClientRect(); return { text: x.innerText.replace(/\s+/g, ' '), left: Math.round(r.left), right: Math.round(r.right), vw: innerWidth }; }));
  await shot('ph-03-picture');
  await p.evaluate(() => { const c = document.getElementById('t3-img-close'); c && c.click(); }); await p.waitForTimeout(600);
  // can the owner reach Publish?
  const pub = await p.evaluate(() => { const b = Array.from(document.querySelectorAll('#template-editor-view .pe-bar button')).find(b => /Publish/.test(b.innerText)); if (!b) return null; b.scrollIntoView({ inline: 'end' }); const r = b.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), vw: innerWidth }; });
  rec('PH publish after scrolling the bar', pub); await shot('ph-04-publish');
  await b.close();
})();
