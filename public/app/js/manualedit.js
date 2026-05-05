/**
 * MANUALEDIT888 — Studio tab switcher.
 * Registers window.manualeditLoad. Provides two tabs:
 *   "Image Design" → loads manualedit-fabric.js (Fabric.js canvas editor, Sessions 1-3)
 *   "Video Editor"  → loads manualedit-video.js (original DOM-based timeline editor)
 * Both are lazy-loaded on first tab click.
 */
(function(){
'use strict';

let root = null;
let activeTab = null;
const loaded = { fabric: false, video: false };

window.manualeditLoad = function(el) {
  root = el;
  renderTabs();
};

function renderTabs() {
  root.innerHTML =
    '<div style="display:flex;border-bottom:1px solid var(--bd,rgba(255,255,255,.07));flex-shrink:0">' +
      '<button id="me-tab-img" onclick="meTabSwitch(\'fabric\')" style="flex:1;padding:12px;background:var(--pg,rgba(108,92,231,.22));border:none;color:var(--p,#6C5CE7);cursor:pointer;font-size:13px;font-weight:600;border-bottom:2px solid var(--p,#6C5CE7)">🖼 Image Design</button>' +
      '<button id="me-tab-vid" onclick="meTabSwitch(\'video\')" style="flex:1;padding:12px;background:transparent;border:none;color:var(--t3,#4A566B);cursor:pointer;font-size:13px;font-weight:600;border-bottom:2px solid transparent">🎬 Video Editor</button>' +
    '</div>' +
    '<div id="me-tab-content" style="flex:1;overflow:hidden;display:flex;flex-direction:column"></div>';
  meTabSwitch('fabric');
}

window.meTabSwitch = async function(tab) {
  if (activeTab === tab) return;
  activeTab = tab;

  // Update tab styling
  const imgBtn = document.getElementById('me-tab-img');
  const vidBtn = document.getElementById('me-tab-vid');
  if (tab === 'fabric') {
    if(imgBtn){ imgBtn.style.background='var(--pg)'; imgBtn.style.color='var(--p)'; imgBtn.style.borderBottom='2px solid var(--p)'; }
    if(vidBtn){ vidBtn.style.background='transparent'; vidBtn.style.color='var(--t3)'; vidBtn.style.borderBottom='2px solid transparent'; }
  } else {
    if(vidBtn){ vidBtn.style.background='var(--pg)'; vidBtn.style.color='var(--p)'; vidBtn.style.borderBottom='2px solid var(--p)'; }
    if(imgBtn){ imgBtn.style.background='transparent'; imgBtn.style.color='var(--t3)'; imgBtn.style.borderBottom='2px solid transparent'; }
  }

  var content = document.getElementById('me-tab-content');
  if (!content) return;
  content.innerHTML = '<div style="padding:24px;color:var(--t2,#8B97B0);font-size:13px">Loading...</div>';

  if (tab === 'fabric') {
    if (!loaded.fabric) {
      await loadScript('/app/js/manualedit-fabric.js');
      loaded.fabric = true;
    }
    content.innerHTML = '<div id="manualedit-fabric-root" style="flex:1;overflow:hidden"></div>';
    if (typeof window.manualeditLoad_fabric === 'function') {
      window.manualeditLoad_fabric(document.getElementById('manualedit-fabric-root'));
    }
  } else {
    if (!loaded.video) {
      await loadScript('/app/js/manualedit-video.js');
      loaded.video = true;
    }
    content.innerHTML = '<div id="manualedit-video-root" style="flex:1;overflow:auto;padding:24px"></div>';
    if (typeof window.manualeditVideoLoad === 'function') {
      window.manualeditVideoLoad(document.getElementById('manualedit-video-root'));
    }
  }
};

function loadScript(src) {
  return new Promise(function(ok, fail) {
    var s = document.createElement('script');
    s.src = src + '?v=' + (window.LU_CFG ? window.LU_CFG.version : '1');
    s.onload = ok;
    s.onerror = function() { fail(new Error('Failed to load ' + src)); };
    document.head.appendChild(s);
  });
}

})();
