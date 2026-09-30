  // MV: move the second hero button with the toolbox
  const c2 = fr.locator('[data-field="hero_cta_2"]').first(); await c2.scrollIntoViewIfNeeded(); const cb = await c2.boundingBox(); await p.mouse.click(cb.x + cb.width / 2, cb.y + cb.height / 2); await p.waitForTimeout(1500);
  let n0 = net.length; const mv = await fr.evaluate(() => { const x = Array.from(document.querySelectorAll('button[title="Move up"], button[title="Move down"]')).find(b => b.getBoundingClientRect().width > 0); if (x) { const t = x.getAttribute('title'); x.click(); return t; } return false; }); await p.waitForTimeout(4000);
  rec('MV toolbox move on a button', { clicked: mv, calls: since(n0) }); await shot('mv');
  const bg = await fr.evaluate(() => { const x = Array.from(document.querySelectorAll('button[title^="Bigger"]')).find(b => b.getBoundingClientRect().width > 0); if (x) { x.click(); return true; } return false; }); await p.waitForTimeout(3500); rec('MV bigger', { clicked: bg, calls: since(n0).slice(-1) });
  n0 = net.length; await click('#t3-undo'); await p.waitForTimeout(4000); await click('#t3-undo'); await p.waitForTimeout(4000); rec('MV undo x2', since(n0));
  // ESC while typing
  const ft = fr.locator('[data-field="faq_title"]').first(); await ft.scrollIntoViewIfNeeded(); await ft.dblclick(); await p.waitForTimeout(700); await p.keyboard.press('End'); await p.keyboard.type(' ESC-TEST', { delay: 5 });
  n0 = net.length; await p.keyboard.press('Escape'); await p.waitForTimeout(3500);
  rec('ESC while typing', { editorStillOpen: !!(await p.$('#template-editor-view')), calls: since(n0), liveHasText: ((await liveField('faq_title')) || '').includes('ESC-TEST') }); await shot('esc');
  if (await p.$('#template-editor-view')) {
    // BACK mid-edit
    const f2 = await (await p.$('#t3-preview')).contentFrame(); const st = f2.locator('[data-field="steps_title"]').first(); await st.scrollIntoViewIfNeeded(); await st.dblclick(); await p.waitForTimeout(700); await p.keyboard.press('End'); await p.keyboard.type(' BACK-TEST', { delay: 5 });
    n0 = net.length; await click('.pe-bar button', '←'); await p.waitForTimeout(5000); await shot('back');
    rec('BACK mid-edit', { editorStillOpen: !!(await p.$('#template-editor-view')), calls: since(n0), prompt: await topDialog(), liveHasText: ((await liveField('steps_title')) || '').includes('BACK-TEST') });
  }
  // PUBLISH after edits: reopen and press Publish
  if (!(await p.$('#template-editor-view'))) { await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' }); await p.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(e => e.innerText.trim() === 'Edit' && e.offsetParent), null, { timeout: 60000 }).catch(() => {}); await click('button', 'Edit'); await p.waitForTimeout(15000); await click('button', 'Skip tour'); }
  n0 = net.length; await click('.pe-bar button', 'Publish'); await p.waitForTimeout(5000); await shot('publish');
  rec('PUBLISH dialog', await topDialog()); rec('PUBLISH calls', since(n0));
  await b.close();
})();
