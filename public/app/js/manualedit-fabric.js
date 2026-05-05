/**
 * MANUALEDIT888 — Image Editor (Fabric.js canvas).
 * Lazy-loaded via luLoadEngine('manualedit'). Registers global manualeditLoad().
 *
 * Session 1: canvas foundation + core tools (select, text, shapes, image, export).
 */
(function(){
'use strict';

const API = window.location.origin + '/api/manualedit';
const CREATIVE_API = window.location.origin + '/api/creative';
const auth = () => ({'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Content-Type':'application/json','Accept':'application/json'});
async function api(method, path, body, base) {
  const opts = { method, headers: auth(), cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  return (await fetch((base||API) + path, opts)).json();
}

// ── Presets ───────────────────────────────────────────────────────────────
const PRESETS = [
  { name: 'Instagram Square', w: 1080, h: 1080 },
  { name: 'Instagram Story', w: 1080, h: 1920 },
  { name: 'Facebook Post', w: 1200, h: 630 },
  { name: 'Facebook Cover', w: 820, h: 312 },
  { name: 'Twitter/X Post', w: 1600, h: 900 },
  { name: 'LinkedIn Post', w: 1200, h: 627 },
  { name: 'TikTok', w: 1080, h: 1920 },
  { name: 'Custom', w: 1200, h: 800 },
];
const FONTS = ['Inter','Poppins','Roboto','Arial','Georgia','Courier New'];

let root = null;
let canvas = null;
let currentProjectId = null;
let undoStack = [];
let redoStack = [];
let zoomLevel = 1;

// ── Fabric.js loader ─────────────────────────────────────────────────────
function loadFabric() {
  return new Promise((resolve, reject) => {
    if (window.fabric) return resolve();
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js';
    s.onload = resolve;
    s.onerror = () => reject(new Error('Failed to load Fabric.js'));
    document.head.appendChild(s);
  });
}

// ── Entry point ──────────────────────────────────────────────────────────
window.manualeditLoad_fabric = function(el) {
  root = el;
  renderProjectsList();
};

// ── Projects list ────────────────────────────────────────────────────────
async function renderProjectsList() {
  root.innerHTML = '<div style="padding:24px"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">' +
    '<div style="font-size:18px;font-weight:700;color:var(--t1,#E8EDF5)">Design Studio</div>' +
    '<button onclick="meNewProject()" style="background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer">+ New Design</button>' +
    '</div><div id="me-projects" style="color:var(--t2);font-size:13px">Loading...</div></div>';

  try {
    const d = await api('GET', '/canvases');
    const items = d.canvases || d.data || d || [];
    const el = document.getElementById('me-projects');
    if (!Array.isArray(items) || items.length === 0) {
      el.innerHTML = '<div style="padding:60px;text-align:center;color:var(--t3,#4A566B)"><div style="font-size:48px;margin-bottom:12px">🖌️</div><div style="font-size:14px">No designs yet. Click "+ New Design" to start.</div></div>';
      return;
    }
    el.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">' +
      items.map(p => {
        const name = (p.name || p.title || 'Untitled').replace(/</g,'&lt;').slice(0,30);
        const preset = p.preset || '';
        const date = p.updated_at ? new Date(p.updated_at).toLocaleDateString() : '';
        return '<div onclick="meOpenProject(' + p.id + ')" style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden;cursor:pointer;transition:border-color .15s" onmouseover="this.style.borderColor=\'var(--p)\'" onmouseout="this.style.borderColor=\'var(--bd)\'">' +
          '<div style="width:100%;aspect-ratio:16/9;background:var(--s2);display:flex;align-items:center;justify-content:center;font-size:32px;color:var(--t3)">🖼</div>' +
          '<div style="padding:10px"><div style="font-size:12px;font-weight:600;color:var(--t1)">' + name + '</div>' +
          '<div style="font-size:10px;color:var(--t3);margin-top:2px">' + preset + ' · ' + date + '</div></div></div>';
      }).join('') + '</div>';
  } catch(e) {
    document.getElementById('me-projects').innerHTML = '<div style="color:var(--t3)">Could not load projects.</div>';
  }
}

// ── New project modal ────────────────────────────────────────────────────
window.meNewProject = function() {
  root.innerHTML = '<div style="padding:24px"><div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">' +
    '<button onclick="manualeditLoad(root)" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px 12px;color:var(--t1);cursor:pointer;font-size:12px">← Back</button>' +
    '<div style="font-size:18px;font-weight:700;color:var(--t1)">New Design</div></div>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;max-width:800px">' +
    PRESETS.map((p,i) =>
      '<div onclick="meCreateCanvas(' + i + ')" style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;cursor:pointer;text-align:center;transition:border-color .15s" onmouseover="this.style.borderColor=\'var(--p)\'" onmouseout="this.style.borderColor=\'var(--bd)\'">' +
      '<div style="font-size:24px;margin-bottom:8px">' + (p.w===p.h?'◻':'▭') + '</div>' +
      '<div style="font-size:13px;font-weight:600;color:var(--t1)">' + p.name + '</div>' +
      '<div style="font-size:11px;color:var(--t3);margin-top:4px">' + p.w + ' × ' + p.h + '</div></div>'
    ).join('') + '</div></div>';
};

window.meCreateCanvas = async function(presetIdx) {
  const p = PRESETS[presetIdx] || PRESETS[0];
  try {
    const d = await api('POST', '/canvases', { name: p.name + ' Design', preset: p.name, width: p.w, height: p.h });
    const id = d.data?.canvas_id || d.canvas_id || d.entity_id;
    if (id) { meOpenEditor(id, p.w, p.h, p.name); }
    else { meOpenEditor(null, p.w, p.h, p.name); }
  } catch(e) {
    meOpenEditor(null, p.w, p.h, p.name);
  }
};

// ── Open existing project ────────────────────────────────────────────────
window.meOpenProject = async function(id) {
  try {
    const d = await api('GET', '/canvases/' + id);
    const c = d.canvas || d.data || d;
    const state = c.state_json ? (typeof c.state_json === 'string' ? JSON.parse(c.state_json) : c.state_json) : null;
    const w = c.width || 1200;
    const h = c.height || 800;
    meOpenEditor(id, w, h, c.preset || 'Custom', state);
  } catch(e) {
    meOpenEditor(id, 1200, 800, 'Custom');
  }
};

// ── Editor ───────────────────────────────────────────────────────────────
async function meOpenEditor(projectId, cw, ch, presetName, existingState) {
  currentProjectId = projectId;
  undoStack = [];
  redoStack = [];
  zoomLevel = 1;

  await loadFabric();

  // Calculate zoom to fit canvas in viewport
  const viewW = root.clientWidth - 280 - 200; // minus sidebars
  const viewH = root.clientHeight - 48; // minus toolbar
  zoomLevel = Math.min(viewW / cw, viewH / ch, 1) * 0.9;

  root.innerHTML =
    // Top toolbar
    '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--s1);border-bottom:1px solid var(--bd);flex-shrink:0">' +
      '<button onclick="manualeditLoad(root)" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 10px;color:var(--t1);cursor:pointer;font-size:11px">← Projects</button>' +
      '<span style="font-size:12px;color:var(--t2);margin-right:auto">' + presetName + ' · ' + cw + '×' + ch + '</span>' +
      '<button onclick="meUndo()" title="Undo" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;color:var(--t1);cursor:pointer;font-size:13px">↩</button>' +
      '<button onclick="meRedo()" title="Redo" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;color:var(--t1);cursor:pointer;font-size:13px">↪</button>' +
      '<span style="width:1px;height:20px;background:var(--bd)"></span>' +
      '<button onclick="meZoom(0.1)" title="Zoom in" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;color:var(--t1);cursor:pointer;font-size:13px">+</button>' +
      '<span id="me-zoom-label" style="font-size:11px;color:var(--t3);min-width:40px;text-align:center">' + Math.round(zoomLevel*100) + '%</span>' +
      '<button onclick="meZoom(-0.1)" title="Zoom out" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;color:var(--t1);cursor:pointer;font-size:13px">−</button>' +
      '<span style="width:1px;height:20px;background:var(--bd)"></span>' +
      '<button onclick="meToggleGrid()" id="me-grid-btn" title="Toggle grid" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;color:var(--t1);cursor:pointer;font-size:11px">#</button>' +
      '<span style="width:1px;height:20px;background:var(--bd)"></span>' +
      '<button onclick="meSave()" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 12px;color:var(--t1);cursor:pointer;font-size:12px;font-weight:600">💾 Save</button>' +
      '<span id="me-save-indicator" style="font-size:10px;color:var(--t3)"></span>' +
      '<button onclick="meShowExportModal()" style="background:var(--p);color:#fff;border:none;border-radius:6px;padding:4px 12px;cursor:pointer;font-size:12px;font-weight:600">📥 Export</button>' +
    '</div>' +
    // Alignment bar (hidden by default, shown when 2+ objects selected)
    '<div id="me-align-bar" style="display:none;padding:4px 12px;background:var(--s2);border-bottom:1px solid var(--bd);gap:4px;align-items:center;flex-shrink:0">' +
      '<span style="font-size:10px;color:var(--t3);margin-right:4px">Align:</span>' +
      '<button onclick="meAlign(\'left\')" title="Align left" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:11px">⫷</button>' +
      '<button onclick="meAlign(\'centerH\')" title="Center H" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:11px">⫿</button>' +
      '<button onclick="meAlign(\'right\')" title="Align right" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:11px">⫸</button>' +
      '<button onclick="meAlign(\'top\')" title="Align top" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:11px">⊤</button>' +
      '<button onclick="meAlign(\'centerV\')" title="Center V" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:11px">⊞</button>' +
      '<button onclick="meAlign(\'bottom\')" title="Align bottom" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:11px">⊥</button>' +
      '<span style="width:1px;height:16px;background:var(--bd)"></span>' +
      '<button onclick="meDistribute(\'h\')" title="Distribute H" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:10px">⇔</button>' +
      '<button onclick="meDistribute(\'v\')" title="Distribute V" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:10px">⇕</button>' +
      '<span style="width:1px;height:16px;background:var(--bd)"></span>' +
      '<button onclick="meGroupSelected()" title="Group" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:10px">⊞ Group</button>' +
      '<button onclick="meUngroupSelected()" title="Ungroup" style="background:var(--s1);border:1px solid var(--bd);border-radius:4px;padding:2px 6px;color:var(--t1);cursor:pointer;font-size:10px">⊟ Ungroup</button>' +
    '</div>' +
    // Main body
    '<div style="display:flex;flex:1;overflow:hidden">' +
      // Left tools — 60px icon strip + expandable panel
      '<div style="display:flex;flex-shrink:0">' +
        '<div style="width:60px;background:var(--s1);border-right:1px solid var(--bd);display:flex;flex-direction:column;align-items:center;padding:8px 0;gap:4px">' +
          '<button onclick="meToolSelect()" class="me-tool active" id="me-t-select" title="Select" style="width:44px;height:44px;background:var(--pg,rgba(108,92,231,.22));border:1px solid var(--p);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">↖</button>' +
          '<button onclick="meToolText()" class="me-tool" id="me-t-text" title="Text" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">T</button>' +
          '<button onclick="meToolShape(\'rect\')" class="me-tool" id="me-t-rect" title="Rectangle" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">▭</button>' +
          '<button onclick="meToolShape(\'circle\')" class="me-tool" id="me-t-circle" title="Circle" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">○</button>' +
          '<button onclick="meToolShape(\'triangle\')" class="me-tool" id="me-t-tri" title="Triangle" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">△</button>' +
          '<button onclick="meShowShapes()" class="me-tool" id="me-t-shapes" title="More shapes" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">✦</button>' +
          '<button onclick="meToolImage()" class="me-tool" id="me-t-img" title="Image" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center">🖼</button>' +
          '<div style="height:1px;width:36px;background:var(--bd);margin:4px 0"></div>' +
          '<button onclick="meShowPanel(\'templates\')" class="me-tool" id="me-t-tpl" title="Templates" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center">📋</button>' +
          '<button onclick="meShowPanel(\'brand\')" class="me-tool" id="me-t-brand" title="Brand Kit" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center">🏷</button>' +
          '<button onclick="meShowPanel(\'ai\')" class="me-tool" id="me-t-ai" title="AI Generate" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center">✨</button>' +
          '<button onclick="meShowPanel(\'assets\')" class="me-tool" id="me-t-assets" title="Creative Assets" style="width:44px;height:44px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center">📁</button>' +
          '<div style="flex:1"></div>' +
          '<label title="Background color"><input type="color" id="me-bg-color" value="#ffffff" onchange="meSetBg(this.value)" style="width:44px;height:30px;border:1px solid var(--bd);border-radius:6px;cursor:pointer;background:var(--s2)"></label>' +
        '</div>' +
        // Expandable left panel (hidden by default)
        '<div id="me-left-panel" style="width:0;overflow:hidden;background:var(--s1);border-right:1px solid var(--bd);transition:width .2s;flex-shrink:0"><div id="me-left-panel-content" style="width:240px;padding:12px;overflow-y:auto;height:100%"></div></div>' +
      '</div>' +
      // Canvas area
      '<div style="flex:1;overflow:auto;display:flex;align-items:center;justify-content:center;background:#0a0a0f;position:relative" id="me-canvas-wrap">' +
        '<canvas id="me-canvas"></canvas>' +
      '</div>' +
      // Right panel — Properties + Layers tabs
      '<div style="width:220px;background:var(--s1);border-left:1px solid var(--bd);display:flex;flex-direction:column;flex-shrink:0;font-size:12px;color:var(--t2)">' +
        '<div style="display:flex;border-bottom:1px solid var(--bd);flex-shrink:0">' +
          '<button id="me-tab-props" onclick="meRightTab(\'props\')" style="flex:1;padding:8px;background:var(--pg);border:none;color:var(--p);cursor:pointer;font-size:11px;font-weight:600;border-bottom:2px solid var(--p)">Properties</button>' +
          '<button id="me-tab-layers" onclick="meRightTab(\'layers\')" style="flex:1;padding:8px;background:transparent;border:none;color:var(--t3);cursor:pointer;font-size:11px;font-weight:600;border-bottom:2px solid transparent">Layers</button>' +
        '</div>' +
        '<div id="me-props" style="flex:1;overflow-y:auto;padding:12px"><div style="font-weight:600;color:var(--t1);margin-bottom:8px">Properties</div><div style="color:var(--t3)">Select an element</div></div>' +
        '<div id="me-layers" style="flex:1;overflow-y:auto;padding:12px;display:none"></div>' +
      '</div>' +
    '</div>' +
    '<input type="file" id="me-file-input" accept="image/*" style="display:none" onchange="meFileSelected(this)">';

  // Init Fabric canvas
  canvas = new fabric.Canvas('me-canvas', {
    width: cw,
    height: ch,
    backgroundColor: '#ffffff',
    selection: true,
    preserveObjectStacking: true,
    selectionColor: 'rgba(124,58,237,.08)',
    selectionBorderColor: '#7c3aed',
    selectionLineWidth: 1.5,
  });

  // Apply zoom
  meApplyZoom();

  // Load existing state
  if (existingState) {
    canvas.loadFromJSON(existingState, () => canvas.renderAll());
  }

  // Session 3: dirty flag for auto-save
  meDirty = false;
  meStartAutoSave();

  // Session 3: snap to grid on move
  canvas.on('object:moving', function(e) {
    if (!meGridOn) return;
    const obj = e.target;
    obj.set({ left: Math.round(obj.left / 10) * 10, top: Math.round(obj.top / 10) * 10 });
  });

  // Events
  canvas.on('selection:created', meUpdateProps);
  canvas.on('selection:updated', meUpdateProps);
  canvas.on('selection:cleared', () => {
    document.getElementById('me-props').innerHTML = '<div style="font-weight:600;color:var(--t1);margin-bottom:8px">Properties</div><div style="color:var(--t3)">Select an element</div>';
    meHideAlignBar();
  });
  canvas.on('object:modified', function(){ meSaveUndo(); meDirty = true; meUpdateSaveIndicator(); });
  canvas.on('object:added', function(){ meDirty = true; meUpdateSaveIndicator(); });
}

// ── Tool activators ──────────────────────────────────────────────────────
function meActivateTool(id) {
  document.querySelectorAll('.me-tool').forEach(b => { b.style.background='var(--s2)'; b.style.borderColor='var(--bd)'; });
  const el = document.getElementById(id);
  if(el){ el.style.background='var(--pg,rgba(108,92,231,.22))'; el.style.borderColor='var(--p,#6C5CE7)'; }
}

window.meToolSelect = function() { meActivateTool('me-t-select'); canvas.isDrawingMode=false; };

window.meToolText = function() {
  meActivateTool('me-t-text');
  const text = new fabric.IText('Type here', {
    left: canvas.width/2 - 80, top: canvas.height/2 - 20,
    fontFamily: 'Inter', fontSize: 32, fill: '#333333',
    fontWeight: 'normal', textAlign: 'left',
  });
  canvas.add(text);
  canvas.setActiveObject(text);
  text.enterEditing();
  canvas.renderAll();
  meSaveUndo();
};

window.meToolShape = function(type) {
  meActivateTool('me-t-' + (type==='rect'?'rect':type==='circle'?'circle':'tri'));
  let shape;
  const cx = canvas.width/2, cy = canvas.height/2;
  if (type === 'rect') {
    shape = new fabric.Rect({ left:cx-75, top:cy-50, width:150, height:100, fill:'#6C5CE7', stroke:'', strokeWidth:0, rx:8, ry:8 });
  } else if (type === 'circle') {
    shape = new fabric.Circle({ left:cx-50, top:cy-50, radius:50, fill:'#00E5A8', stroke:'', strokeWidth:0 });
  } else {
    shape = new fabric.Triangle({ left:cx-50, top:cy-50, width:100, height:100, fill:'#F59E0B', stroke:'', strokeWidth:0 });
  }
  canvas.add(shape);
  canvas.setActiveObject(shape);
  canvas.renderAll();
  meSaveUndo();
};

window.meToolImage = function() {
  meActivateTool('me-t-img');
  document.getElementById('me-file-input').click();
};

window.meFileSelected = function(input) {
  const file = input.files && input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    fabric.Image.fromURL(e.target.result, function(img) {
      const scale = Math.min(canvas.width * 0.6 / img.width, canvas.height * 0.6 / img.height, 1);
      img.set({ left: canvas.width/2 - (img.width*scale)/2, top: canvas.height/2 - (img.height*scale)/2, scaleX: scale, scaleY: scale });
      canvas.add(img);
      canvas.setActiveObject(img);
      canvas.renderAll();
      meSaveUndo();
    });
  };
  reader.readAsDataURL(file);
  input.value = '';
};

// ── Background ───────────────────────────────────────────────────────────
window.meSetBg = function(color) {
  if (!canvas) return;
  canvas.backgroundColor = color;
  canvas.renderAll();
  meSaveUndo();
};

// ── Properties panel ─────────────────────────────────────────────────────
function meUpdateProps() {
  const obj = canvas.getActiveObject();
  if (!obj) return;
  const panel = document.getElementById('me-props');
  const isText = obj.type === 'i-text' || obj.type === 'text' || obj.type === 'textbox';

  let html = '<div style="font-weight:600;color:var(--t1);margin-bottom:10px">Properties</div>';

  // Position
  html += '<div style="margin-bottom:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:4px">Position</div>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">' +
    '<label style="font-size:10px;color:var(--t3)">X <input type="number" value="' + Math.round(obj.left||0) + '" onchange="mePropSet(\'left\',+this.value)" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px"></label>' +
    '<label style="font-size:10px;color:var(--t3)">Y <input type="number" value="' + Math.round(obj.top||0) + '" onchange="mePropSet(\'top\',+this.value)" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px"></label>' +
    '<label style="font-size:10px;color:var(--t3)">W <input type="number" value="' + Math.round((obj.width||0)*(obj.scaleX||1)) + '" onchange="mePropSetW(+this.value)" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px"></label>' +
    '<label style="font-size:10px;color:var(--t3)">H <input type="number" value="' + Math.round((obj.height||0)*(obj.scaleY||1)) + '" onchange="mePropSetH(+this.value)" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px"></label>' +
    '</div></div>';

  // Rotation + opacity
  html += '<div style="margin-bottom:8px"><div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">' +
    '<label style="font-size:10px;color:var(--t3)">Rotate <input type="number" value="' + Math.round(obj.angle||0) + '" onchange="mePropSet(\'angle\',+this.value)" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px"></label>' +
    '<label style="font-size:10px;color:var(--t3)">Opacity <input type="range" min="0" max="100" value="' + Math.round((obj.opacity||1)*100) + '" oninput="mePropSet(\'opacity\',this.value/100)" style="width:100%"></label>' +
    '</div></div>';

  // Text properties
  if (isText) {
    html += '<div style="margin-bottom:8px;border-top:1px solid var(--bd);padding-top:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:4px">Text</div>' +
      '<select onchange="mePropSet(\'fontFamily\',this.value)" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px;margin-bottom:4px">' +
      FONTS.map(f => '<option value="' + f + '"' + (obj.fontFamily===f?' selected':'') + '>' + f + '</option>').join('') + '</select>' +
      '<div style="display:flex;gap:4px;margin-bottom:4px">' +
      '<input type="number" value="' + (obj.fontSize||24) + '" onchange="mePropSet(\'fontSize\',+this.value)" style="width:60px;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px" title="Size">' +
      '<button onclick="mePropToggle(\'fontWeight\',\'bold\',\'normal\')" style="width:28px;height:28px;background:' + (obj.fontWeight==='bold'?'var(--pg)':'var(--s2)') + ';border:1px solid var(--bd);border-radius:4px;color:var(--t1);cursor:pointer;font-weight:bold;font-size:12px">B</button>' +
      '<button onclick="mePropToggle(\'fontStyle\',\'italic\',\'normal\')" style="width:28px;height:28px;background:' + (obj.fontStyle==='italic'?'var(--pg)':'var(--s2)') + ';border:1px solid var(--bd);border-radius:4px;color:var(--t1);cursor:pointer;font-style:italic;font-size:12px">I</button>' +
      '<button onclick="mePropToggle(\'underline\',true,false)" style="width:28px;height:28px;background:' + (obj.underline?'var(--pg)':'var(--s2)') + ';border:1px solid var(--bd);border-radius:4px;color:var(--t1);cursor:pointer;text-decoration:underline;font-size:12px">U</button></div>' +
      '<label style="font-size:10px;color:var(--t3)">Color <input type="color" value="' + (obj.fill||'#333333') + '" onchange="mePropSet(\'fill\',this.value)" style="width:100%;height:24px;border:1px solid var(--bd);border-radius:4px;cursor:pointer"></label>' +
      // Session 2: text effects — spacing, line height, shadow, outline
      '<div style="margin-top:6px;display:grid;grid-template-columns:1fr 1fr;gap:4px">' +
      '<label style="font-size:10px;color:var(--t3)">Spacing <input type="range" min="0" max="500" value="' + (obj.charSpacing||0) + '" oninput="mePropSet(\'charSpacing\',+this.value)" style="width:100%"></label>' +
      '<label style="font-size:10px;color:var(--t3)">Line H <input type="range" min="50" max="300" value="' + Math.round((obj.lineHeight||1.16)*100) + '" oninput="mePropSet(\'lineHeight\',this.value/100)" style="width:100%"></label></div>' +
      '<div style="margin-top:6px"><div style="font-size:10px;color:var(--t3);margin-bottom:2px">Text outline</div>' +
      '<div style="display:flex;gap:4px">' +
      '<input type="color" value="' + (obj.stroke||'#000000') + '" onchange="mePropSet(\'stroke\',this.value)" style="width:28px;height:24px;border:1px solid var(--bd);border-radius:4px;cursor:pointer">' +
      '<input type="number" min="0" max="10" value="' + (obj.strokeWidth||0) + '" onchange="mePropSet(\'strokeWidth\',+this.value)" style="width:50px;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px" placeholder="Width"></div></div>' +
      '<div style="margin-top:6px"><div style="font-size:10px;color:var(--t3);margin-bottom:2px">Shadow</div>' +
      '<div style="display:flex;gap:4px;align-items:center">' +
      '<input type="checkbox" id="me-shadow-on" ' + (obj.shadow?'checked':'') + ' onchange="meToggleShadow()">' +
      '<input type="color" value="' + ((obj.shadow?.color)||'#000000') + '" onchange="meShadowProp(\'color\',this.value)" style="width:24px;height:20px;border:1px solid var(--bd);border-radius:4px;cursor:pointer">' +
      '<input type="number" min="0" max="20" value="' + ((obj.shadow?.blur)||4) + '" onchange="meShadowProp(\'blur\',+this.value)" style="width:40px;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:2px;color:var(--t1);font-size:10px" title="Blur"></div></div>' +
      '</div>';
  }

  // Fill + stroke (non-text)
  if (!isText && obj.type !== 'image') {
    html += '<div style="margin-bottom:8px;border-top:1px solid var(--bd);padding-top:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:4px">Fill & Stroke</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">' +
      '<label style="font-size:10px;color:var(--t3)">Fill <input type="color" value="' + (obj.fill||'#6C5CE7') + '" onchange="mePropSet(\'fill\',this.value)" style="width:100%;height:24px;border:1px solid var(--bd);border-radius:4px;cursor:pointer"></label>' +
      '<label style="font-size:10px;color:var(--t3)">Stroke <input type="color" value="' + (obj.stroke||'#000000') + '" onchange="mePropSet(\'stroke\',this.value)" style="width:100%;height:24px;border:1px solid var(--bd);border-radius:4px;cursor:pointer"></label>' +
      '</div><label style="font-size:10px;color:var(--t3);margin-top:4px;display:block">Border width <input type="number" min="0" max="20" value="' + (obj.strokeWidth||0) + '" onchange="mePropSet(\'strokeWidth\',+this.value)" style="width:60px;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px"></label></div>';
  }

  // Layer order + delete
  html += '<div style="border-top:1px solid var(--bd);padding-top:8px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:4px">Layer</div>' +
    '<div style="display:flex;gap:4px;flex-wrap:wrap">' +
    '<button onclick="meLayer(\'front\')" style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);cursor:pointer;font-size:10px">Front</button>' +
    '<button onclick="meLayer(\'forward\')" style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);cursor:pointer;font-size:10px">↑</button>' +
    '<button onclick="meLayer(\'backward\')" style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);cursor:pointer;font-size:10px">↓</button>' +
    '<button onclick="meLayer(\'back\')" style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);cursor:pointer;font-size:10px">Back</button></div>' +
    '<button onclick="meDeleteSelected()" style="width:100%;margin-top:8px;background:rgba(248,113,113,.1);border:1px solid var(--rd,#F87171);border-radius:6px;padding:6px;color:var(--rd);cursor:pointer;font-size:12px;font-weight:600">🗑 Delete</button></div>';

  panel.innerHTML = html;
}

// ── Property setters ─────────────────────────────────────────────────────
window.mePropSet = function(prop, val) {
  const obj = canvas.getActiveObject(); if(!obj) return;
  obj.set(prop, val);
  canvas.renderAll();
  meSaveUndo();
  meUpdateProps();
};
window.mePropSetW = function(w) {
  const obj = canvas.getActiveObject(); if(!obj) return;
  obj.set('scaleX', w / obj.width);
  canvas.renderAll(); meSaveUndo();
};
window.mePropSetH = function(h) {
  const obj = canvas.getActiveObject(); if(!obj) return;
  obj.set('scaleY', h / obj.height);
  canvas.renderAll(); meSaveUndo();
};
window.mePropToggle = function(prop, onVal, offVal) {
  const obj = canvas.getActiveObject(); if(!obj) return;
  obj.set(prop, obj[prop] === onVal ? offVal : onVal);
  canvas.renderAll(); meSaveUndo(); meUpdateProps();
};

// ── Layer order ──────────────────────────────────────────────────────────
window.meLayer = function(dir) {
  const obj = canvas.getActiveObject(); if(!obj) return;
  if(dir==='front') canvas.bringToFront(obj);
  else if(dir==='forward') canvas.bringForward(obj);
  else if(dir==='backward') canvas.sendBackwards(obj);
  else canvas.sendToBack(obj);
  canvas.renderAll(); meSaveUndo();
};

window.meDeleteSelected = function() {
  const obj = canvas.getActiveObject(); if(!obj) return;
  canvas.remove(obj);
  canvas.renderAll(); meSaveUndo();
};

// ── Undo / Redo ──────────────────────────────────────────────────────────
function meSaveUndo() {
  undoStack.push(JSON.stringify(canvas.toJSON()));
  if (undoStack.length > 50) undoStack.shift();
  redoStack = [];
}

window.meUndo = function() {
  if (undoStack.length < 2) return;
  redoStack.push(undoStack.pop());
  const state = undoStack[undoStack.length - 1];
  canvas.loadFromJSON(state, () => canvas.renderAll());
};

window.meRedo = function() {
  if (redoStack.length === 0) return;
  const state = redoStack.pop();
  undoStack.push(state);
  canvas.loadFromJSON(state, () => canvas.renderAll());
};

// ── Zoom ─────────────────────────────────────────────────────────────────
window.meZoom = function(delta) {
  zoomLevel = Math.max(0.1, Math.min(3, zoomLevel + delta));
  meApplyZoom();
  const label = document.getElementById('me-zoom-label');
  if(label) label.textContent = Math.round(zoomLevel*100) + '%';
};

function meApplyZoom() {
  if (!canvas) return;
  canvas.setZoom(zoomLevel);
  canvas.setWidth(canvas.getWidth() / canvas.getZoom() * zoomLevel);
  canvas.setHeight(canvas.getHeight() / canvas.getZoom() * zoomLevel);
  const el = canvas.getElement();
  if(el){ el.style.width = Math.round(canvas.width * zoomLevel) + 'px'; el.style.height = Math.round(canvas.height * zoomLevel) + 'px'; }
}

// ── Save / Export ────────────────────────────────────────────────────────
window.meSave = async function() {
  if (!canvas) return;
  const state = canvas.toJSON();
  if (currentProjectId) {
    await api('PUT', '/canvases/' + currentProjectId, { state: state, operations: [] });
  }
  if(typeof showToast==='function') showToast('Design saved!','success');
};

window.meExport = function() {
  if (!canvas) return;
  const origZoom = canvas.getZoom();
  canvas.setZoom(1);
  canvas.renderAll();
  const dataUrl = canvas.toDataURL({ format: 'png', quality: 1, multiplier: 1 });
  canvas.setZoom(origZoom);
  canvas.renderAll();
  const a = document.createElement('a');
  a.href = dataUrl;
  a.download = 'design-' + (currentProjectId||'export') + '.png';
  a.click();
};

// ── Text shadow helpers ──────────────────────────────────────────────────
window.meToggleShadow = function() {
  const obj = canvas?.getActiveObject(); if(!obj) return;
  const on = document.getElementById('me-shadow-on')?.checked;
  obj.set('shadow', on ? new fabric.Shadow({color:'rgba(0,0,0,.5)',blur:4,offsetX:2,offsetY:2}) : null);
  canvas.renderAll(); meSaveUndo();
};

window.meShadowProp = function(prop, val) {
  const obj = canvas?.getActiveObject(); if(!obj || !obj.shadow) return;
  obj.shadow[prop] = val;
  canvas.renderAll(); meSaveUndo();
};

// ═══════════════════════════════════════════════════════════════════════════
// SESSION 2 — Templates, Brand Kit, AI Image, Assets, Text Effects, Shapes
// ═════════════════════════════════════════════════════════��═════════════════

let leftPanelOpen = '';

window.meShowPanel = function(panel) {
  const lp = document.getElementById('me-left-panel');
  const lpc = document.getElementById('me-left-panel-content');
  if (!lp || !lpc) return;
  if (leftPanelOpen === panel) { lp.style.width = '0'; leftPanelOpen = ''; return; }
  leftPanelOpen = panel;
  lp.style.width = '240px';
  if (panel === 'templates') meRenderTemplates(lpc);
  else if (panel === 'brand') meRenderBrandKit(lpc);
  else if (panel === 'ai') meRenderAiGen(lpc);
  else if (panel === 'assets') meRenderAssets(lpc);
  else if (panel === 'shapes') meRenderShapesPanel(lpc);
};

window.meShowShapes = function() { meShowPanel('shapes'); };

// ── Templates ────────────────────────────────────────────────────────────
const TEMPLATES = {
  Promotional: [
    { name: 'Bold Promo', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#7c3aed',selectable:false},{type:'i-text',left:w*.1,top:h*.25,text:'BIG SALE',fontFamily:'Poppins',fontSize:Math.round(h*.12),fill:'#ffffff',fontWeight:'bold'},{type:'i-text',left:w*.1,top:h*.5,text:'Up to 50% off everything',fontFamily:'Inter',fontSize:Math.round(h*.04),fill:'rgba(255,255,255,.8)'},{type:'i-text',left:w*.1,top:h*.7,text:'SHOP NOW →',fontFamily:'Poppins',fontSize:Math.round(h*.05),fill:'#F59E0B',fontWeight:'bold'}]})},
    { name: 'Gradient Card', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#1a1a2e'},{type:'rect',left:w*.05,top:h*.05,width:w*.9,height:h*.9,fill:'',stroke:'#7c3aed',strokeWidth:2,rx:16,ry:16},{type:'i-text',left:w*.12,top:h*.3,text:'NEW COLLECTION',fontFamily:'Poppins',fontSize:Math.round(h*.06),fill:'#7c3aed',fontWeight:'bold'},{type:'i-text',left:w*.12,top:h*.5,text:'Discover what\'s new this season',fontFamily:'Inter',fontSize:Math.round(h*.035),fill:'#E8EDF5'}]})},
    { name: 'Minimal CTA', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#ffffff'},{type:'i-text',left:w*.1,top:h*.35,text:'Limited Offer',fontFamily:'Poppins',fontSize:Math.round(h*.08),fill:'#1a1a2e',fontWeight:'bold'},{type:'rect',left:w*.1,top:h*.6,width:w*.35,height:h*.1,fill:'#7c3aed',rx:8,ry:8},{type:'i-text',left:w*.15,top:h*.615,text:'Get Started',fontFamily:'Inter',fontSize:Math.round(h*.04),fill:'#ffffff'}]})},
  ],
  Announcement: [
    { name: 'Spotlight', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#0F1117'},{type:'circle',left:w*.35,top:h*.15,radius:Math.min(w,h)*.15,fill:'rgba(124,58,237,.2)',stroke:'#7c3aed',strokeWidth:2},{type:'i-text',left:w*.1,top:h*.55,text:'ANNOUNCING',fontFamily:'Poppins',fontSize:Math.round(h*.04),fill:'#7c3aed',fontWeight:'bold'},{type:'i-text',left:w*.1,top:h*.65,text:'Something amazing is coming',fontFamily:'Inter',fontSize:Math.round(h*.06),fill:'#ffffff',fontWeight:'bold'}]})},
    { name: 'Clean Banner', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#7c3aed'},{type:'i-text',left:w*.1,top:h*.3,text:'📢 ANNOUNCEMENT',fontFamily:'Poppins',fontSize:Math.round(h*.05),fill:'rgba(255,255,255,.7)'},{type:'i-text',left:w*.1,top:h*.5,text:'We\'re launching!',fontFamily:'Poppins',fontSize:Math.round(h*.09),fill:'#ffffff',fontWeight:'bold'}]})},
  ],
  Quote: [
    { name: 'Elegant Quote', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#1a1a2e'},{type:'i-text',left:w*.08,top:h*.15,text:'"',fontFamily:'Georgia',fontSize:Math.round(h*.2),fill:'#7c3aed'},{type:'i-text',left:w*.12,top:h*.35,text:'Your inspirational\nquote goes here',fontFamily:'Georgia',fontSize:Math.round(h*.06),fill:'#E8EDF5',fontStyle:'italic'},{type:'i-text',left:w*.12,top:h*.75,text:'— Author Name',fontFamily:'Inter',fontSize:Math.round(h*.03),fill:'#8B97B0'}]})},
    { name: 'Bold Quote', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#ffffff'},{type:'rect',left:w*.05,top:h*.1,width:6,height:h*.8,fill:'#7c3aed'},{type:'i-text',left:w*.12,top:h*.25,text:'The best way to predict\nthe future is to create it.',fontFamily:'Poppins',fontSize:Math.round(h*.06),fill:'#1a1a2e',fontWeight:'bold'},{type:'i-text',left:w*.12,top:h*.7,text:'Peter Drucker',fontFamily:'Inter',fontSize:Math.round(h*.03),fill:'#7c3aed'}]})},
  ],
  Product: [
    { name: 'Product Showcase', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#f8f9fa'},{type:'rect',left:w*.5,top:0,width:w*.5,height:h,fill:'#7c3aed'},{type:'i-text',left:w*.05,top:h*.3,text:'NEW\nPRODUCT',fontFamily:'Poppins',fontSize:Math.round(h*.08),fill:'#1a1a2e',fontWeight:'bold'},{type:'i-text',left:w*.05,top:h*.65,text:'$99.00',fontFamily:'Inter',fontSize:Math.round(h*.06),fill:'#7c3aed',fontWeight:'bold'},{type:'i-text',left:w*.55,top:h*.8,text:'ORDER NOW',fontFamily:'Poppins',fontSize:Math.round(h*.035),fill:'#ffffff',fontWeight:'bold'}]})},
  ],
  Event: [
    { name: 'Event Card', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#0F1117'},{type:'rect',left:w*.05,top:h*.05,width:w*.9,height:h*.9,fill:'',stroke:'rgba(124,58,237,.4)',strokeWidth:1,rx:12,ry:12},{type:'i-text',left:w*.1,top:h*.15,text:'📅 UPCOMING EVENT',fontFamily:'Inter',fontSize:Math.round(h*.03),fill:'#7c3aed'},{type:'i-text',left:w*.1,top:h*.3,text:'Event Title Here',fontFamily:'Poppins',fontSize:Math.round(h*.07),fill:'#ffffff',fontWeight:'bold'},{type:'i-text',left:w*.1,top:h*.55,text:'Date: Month DD, YYYY\nTime: 7:00 PM\nVenue: Location Name',fontFamily:'Inter',fontSize:Math.round(h*.03),fill:'#8B97B0'},{type:'rect',left:w*.1,top:h*.78,width:w*.35,height:h*.08,fill:'#7c3aed',rx:6,ry:6},{type:'i-text',left:w*.17,top:h*.79,text:'REGISTER NOW',fontFamily:'Poppins',fontSize:Math.round(h*.03),fill:'#ffffff',fontWeight:'bold'}]})},
  ],
  Sale: [
    { name: 'Flash Sale', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#F59E0B'},{type:'i-text',left:w*.1,top:h*.15,text:'⚡ FLASH SALE ⚡',fontFamily:'Poppins',fontSize:Math.round(h*.06),fill:'#1a1a2e',fontWeight:'bold'},{type:'i-text',left:w*.15,top:h*.35,text:'50%\nOFF',fontFamily:'Poppins',fontSize:Math.round(h*.15),fill:'#1a1a2e',fontWeight:'bold'},{type:'i-text',left:w*.1,top:h*.75,text:'USE CODE: FLASH50',fontFamily:'Inter',fontSize:Math.round(h*.04),fill:'#1a1a2e'}]})},
    { name: 'Clearance', fn: (w,h) => ({ version:'5.3.0',objects:[{type:'rect',left:0,top:0,width:w,height:h,fill:'#EF4444'},{type:'i-text',left:w*.1,top:h*.2,text:'CLEARANCE',fontFamily:'Poppins',fontSize:Math.round(h*.1),fill:'#ffffff',fontWeight:'bold'},{type:'i-text',left:w*.1,top:h*.5,text:'Everything must go!',fontFamily:'Inter',fontSize:Math.round(h*.04),fill:'rgba(255,255,255,.9)'},{type:'rect',left:w*.1,top:h*.7,width:w*.4,height:h*.1,fill:'#ffffff',rx:8,ry:8},{type:'i-text',left:w*.17,top:h*.715,text:'SHOP NOW',fontFamily:'Poppins',fontSize:Math.round(h*.04),fill:'#EF4444',fontWeight:'bold'}]})},
  ],
};

function meRenderTemplates(el) {
  let html = '<div style="font-weight:600;color:var(--t1);margin-bottom:10px;font-size:13px">Templates</div>';
  Object.keys(TEMPLATES).forEach(cat => {
    html += '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin:8px 0 4px;letter-spacing:.05em">' + cat + '</div>';
    TEMPLATES[cat].forEach(t => {
      html += '<div onclick="meApplyTemplate(\'' + cat + '\',\'' + t.name + '\')" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:8px 10px;margin-bottom:4px;cursor:pointer;font-size:12px;color:var(--t1);transition:border-color .15s" onmouseover="this.style.borderColor=\'var(--p)\'" onmouseout="this.style.borderColor=\'var(--bd)\'">' + t.name + '</div>';
    });
  });
  el.innerHTML = html;
}

window.meApplyTemplate = function(cat, name) {
  if (!canvas) return;
  const tpl = TEMPLATES[cat]?.find(t => t.name === name);
  if (!tpl) return;
  if (canvas.getObjects().length > 0 && !confirm('Replace current canvas with this template?')) return;
  canvas.clear();
  const json = tpl.fn(canvas.width / canvas.getZoom(), canvas.height / canvas.getZoom());
  canvas.loadFromJSON(json, () => { canvas.renderAll(); meSaveUndo(); });
};

// ── Brand Kit ────────────────────────────────────────────────────────────
let brandColors = ['#7c3aed','#1a1a2e','#ffffff','#f59e0b','#10b981'];
let brandFonts = { heading: 'Poppins', body: 'Inter' };

async function meRenderBrandKit(el) {
  el.innerHTML = '<div style="font-weight:600;color:var(--t1);margin-bottom:10px;font-size:13px">Brand Kit</div>' +
    '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:6px">Colors (click to apply)</div>' +
    '<div id="me-brand-colors" style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">' +
    brandColors.map((c,i) => '<input type="color" value="' + c + '" onchange="meBrandColorChange(' + i + ',this.value)" onclick="meBrandApplyColor(\'' + c + '\')" style="width:36px;height:36px;border:2px solid var(--bd);border-radius:6px;cursor:pointer" title="Click=apply, change=update kit">').join('') +
    '</div>' +
    '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:6px">Fonts</div>' +
    '<div style="margin-bottom:6px"><label style="font-size:10px;color:var(--t3)">Heading</label><select id="me-brand-font-h" onchange="brandFonts.heading=this.value" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px">' +
    FONTS.map(f => '<option' + (f===brandFonts.heading?' selected':'') + '>' + f + '</option>').join('') + '</select></div>' +
    '<div style="margin-bottom:12px"><label style="font-size:10px;color:var(--t3)">Body</label><select id="me-brand-font-b" onchange="brandFonts.body=this.value" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px;color:var(--t1);font-size:11px">' +
    FONTS.map(f => '<option' + (f===brandFonts.body?' selected':'') + '>' + f + '</option>').join('') + '</select></div>' +
    '<button onclick="meBrandApplyFont()" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px;color:var(--t1);cursor:pointer;font-size:11px;margin-bottom:8px">Apply font to selected text</button>' +
    '<button onclick="meBrandSave()" style="width:100%;background:var(--p);color:#fff;border:none;border-radius:6px;padding:6px;cursor:pointer;font-size:11px;font-weight:600">Save Brand Kit</button>';
}

window.meBrandColorChange = function(i, color) { brandColors[i] = color; };
window.meBrandApplyColor = function(color) {
  const obj = canvas?.getActiveObject(); if(!obj) return;
  obj.set('fill', color); canvas.renderAll(); meSaveUndo();
};
window.meBrandApplyFont = function() {
  const obj = canvas?.getActiveObject(); if(!obj || !obj.fontFamily) return;
  obj.set('fontFamily', brandFonts.heading); canvas.renderAll(); meSaveUndo(); meUpdateProps();
};
window.meBrandSave = async function() {
  try {
    await fetch(window.location.origin + '/api/workspace/settings', {
      method: 'POST', headers: auth(),
      body: JSON.stringify({ brand_colors: brandColors, brand_fonts: brandFonts }),
    });
    if(typeof showToast==='function') showToast('Brand kit saved!','success');
  } catch(e) { if(typeof showToast==='function') showToast('Save failed','error'); }
};

// ── AI Image Generate ────────────────────────────────────────────────────
function meRenderAiGen(el) {
  el.innerHTML = '<div style="font-weight:600;color:var(--t1);margin-bottom:10px;font-size:13px">✨ AI Generate</div>' +
    '<textarea id="me-ai-prompt" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px;color:var(--t1);font-size:12px;min-height:80px;resize:vertical" placeholder="Describe the image you want..."></textarea>' +
    '<button id="me-ai-gen-btn" onclick="meAiGenerate()" style="width:100%;margin-top:8px;background:var(--p);color:#fff;border:none;border-radius:8px;padding:8px;cursor:pointer;font-size:12px;font-weight:600">🎨 Generate & Place on Canvas</button>' +
    '<div id="me-ai-status" style="margin-top:8px;font-size:11px;color:var(--t2)"></div>';
}

window.meAiGenerate = async function() {
  const prompt = document.getElementById('me-ai-prompt')?.value.trim();
  if (!prompt || !canvas) return;
  const btn = document.getElementById('me-ai-gen-btn');
  const status = document.getElementById('me-ai-status');
  btn.disabled = true; btn.textContent = 'Generating...';
  status.textContent = 'Calling DALL-E 3 — 10-30 seconds...';
  try {
    const d = await api('POST', '/generate', { action:'generate_image', prompt }, CREATIVE_API);
    const url = d.data?.url || d.url;
    if (url) {
      fabric.Image.fromURL(url, function(img) {
        const scale = Math.min(canvas.width*.5/img.width, canvas.height*.5/img.height, 1);
        img.set({ left:canvas.width/2-(img.width*scale)/2, top:canvas.height/2-(img.height*scale)/2, scaleX:scale, scaleY:scale });
        canvas.add(img); canvas.setActiveObject(img); canvas.renderAll(); meSaveUndo();
      }, { crossOrigin: 'anonymous' });
      status.innerHTML = '<span style="color:#10B981">✓ Added to canvas!</span>';
    } else {
      status.innerHTML = '<span style="color:var(--am)">Generated but no URL returned.</span>';
    }
  } catch(e) { status.innerHTML = '<span style="color:var(--rd)">Error: ' + (e.message||'') + '</span>'; }
  btn.textContent = '🎨 Generate & Place on Canvas'; btn.disabled = false;
};

// ── Creative Assets Picker ───────────────────────────────────────────────
async function meRenderAssets(el) {
  el.innerHTML = '<div style="font-weight:600;color:var(--t1);margin-bottom:8px;font-size:13px">📁 Assets</div>' +
    '<input id="me-assets-search" oninput="meFilterAssets()" placeholder="Search..." style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px;color:var(--t1);font-size:11px;margin-bottom:8px">' +
    '<div id="me-assets-grid" style="font-size:11px;color:var(--t3)">Loading...</div>';
  try {
    const d = await api('GET', '/assets', null, CREATIVE_API);
    const items = (d.data||d.assets||d||[]).filter(a => a.type === 'image' && a.url);
    window._meAssets = items;
    meRenderAssetsGrid(items);
  } catch(e) { document.getElementById('me-assets-grid').textContent = 'Failed to load.'; }
}

function meRenderAssetsGrid(items) {
  const grid = document.getElementById('me-assets-grid');
  if (!grid) return;
  if (items.length === 0) { grid.innerHTML = '<div style="color:var(--t3);padding:12px;text-align:center">No images found</div>'; return; }
  grid.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">' +
    items.map(a => '<div onclick="meAddAssetToCanvas(\'' + a.url.replace(/'/g,"\\'") + '\')" style="cursor:pointer;border-radius:6px;overflow:hidden;border:1px solid var(--bd);transition:border-color .15s" onmouseover="this.style.borderColor=\'var(--p)\'" onmouseout="this.style.borderColor=\'var(--bd)\'">' +
      '<img src="' + a.url + '" style="width:100%;aspect-ratio:1;object-fit:cover" loading="lazy">' +
      '<div style="padding:4px;font-size:9px;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + ((a.title||a.prompt||'').replace(/</g,'&lt;').slice(0,25)) + '</div></div>'
    ).join('') + '</div>';
}

window.meFilterAssets = function() {
  const q = (document.getElementById('me-assets-search')?.value||'').toLowerCase();
  const items = (window._meAssets||[]).filter(a => !q || (a.title||a.prompt||'').toLowerCase().includes(q));
  meRenderAssetsGrid(items);
};

window.meAddAssetToCanvas = function(url) {
  if (!canvas) return;
  fabric.Image.fromURL(url, function(img) {
    const scale = Math.min(canvas.width*.5/img.width, canvas.height*.5/img.height, 1);
    img.set({ left:canvas.width/2-(img.width*scale)/2, top:canvas.height/2-(img.height*scale)/2, scaleX:scale, scaleY:scale });
    canvas.add(img); canvas.setActiveObject(img); canvas.renderAll(); meSaveUndo();
  }, { crossOrigin: 'anonymous' });
};

// ── More Shapes + Emoji ──────────────────────────────────────────────────
const EMOJIS = ['😀','😍','🔥','💯','✅','❌','⭐','💡','🎯','📢','💰','🏆','📊','🚀','💪','👏','🎉','❤️','💜','🌟'];

function meRenderShapesPanel(el) {
  el.innerHTML = '<div style="font-weight:600;color:var(--t1);margin-bottom:10px;font-size:13px">Shapes & Icons</div>' +
    '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:6px">Shapes</div>' +
    '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:4px;margin-bottom:12px">' +
    '<button onclick="meAddStar()" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:8px;cursor:pointer;color:var(--t1);font-size:16px" title="Star">★</button>' +
    '<button onclick="meAddArrow()" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:8px;cursor:pointer;color:var(--t1);font-size:16px" title="Arrow">→</button>' +
    '<button onclick="meAddBubble()" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:8px;cursor:pointer;color:var(--t1);font-size:16px" title="Speech">💬</button>' +
    '<button onclick="meAddLine()" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:8px;cursor:pointer;color:var(--t1);font-size:16px" title="Line">─</button>' +
    '</div>' +
    '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;margin-bottom:6px">Emoji</div>' +
    '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:4px">' +
    EMOJIS.map(e => '<button onclick="meAddEmoji(\'' + e + '\')" style="background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px;cursor:pointer;font-size:18px">' + e + '</button>').join('') +
    '</div>';
}

window.meAddStar = function() {
  if (!canvas) return;
  const cx=canvas.width/2, cy=canvas.height/2, r=60;
  const pts = [];
  for(let i=0;i<10;i++){const a=Math.PI/2+i*Math.PI/5;const ri=i%2===0?r:r*.4;pts.push({x:cx+ri*Math.cos(a),y:cy-ri*Math.sin(a)});}
  const star = new fabric.Polygon(pts,{left:cx-r,top:cy-r,fill:'#F59E0B',stroke:'',strokeWidth:0});
  canvas.add(star); canvas.setActiveObject(star); canvas.renderAll(); meSaveUndo();
};

window.meAddArrow = function() {
  if (!canvas) return;
  const cx=canvas.width/2,cy=canvas.height/2;
  const pts=[{x:0,y:20},{x:80,y:20},{x:80,y:0},{x:120,y:30},{x:80,y:60},{x:80,y:40},{x:0,y:40}];
  const arrow=new fabric.Polygon(pts,{left:cx-60,top:cy-30,fill:'#7c3aed',stroke:'',strokeWidth:0});
  canvas.add(arrow); canvas.setActiveObject(arrow); canvas.renderAll(); meSaveUndo();
};

window.meAddBubble = function() {
  if (!canvas) return;
  const cx=canvas.width/2,cy=canvas.height/2;
  const g=new fabric.Group([
    new fabric.Rect({width:200,height:100,rx:20,ry:20,fill:'#ffffff',stroke:'#7c3aed',strokeWidth:2,originX:'center',originY:'center'}),
    new fabric.Polygon([{x:-10,y:50},{x:10,y:50},{x:-20,y:80}],{fill:'#ffffff',stroke:'#7c3aed',strokeWidth:2,originX:'center',originY:'center',top:30}),
    new fabric.IText('Type here',{fontFamily:'Inter',fontSize:16,fill:'#333',originX:'center',originY:'center',top:-5}),
  ],{left:cx-100,top:cy-60});
  canvas.add(g); canvas.setActiveObject(g); canvas.renderAll(); meSaveUndo();
};

window.meAddLine = function() {
  if (!canvas) return;
  const cx=canvas.width/2,cy=canvas.height/2;
  const line = new fabric.Line([cx-100,cy,cx+100,cy],{stroke:'#8B97B0',strokeWidth:2});
  canvas.add(line); canvas.setActiveObject(line); canvas.renderAll(); meSaveUndo();
};

window.meAddEmoji = function(emoji) {
  if (!canvas) return;
  const text = new fabric.IText(emoji,{left:canvas.width/2-30,top:canvas.height/2-30,fontSize:60,fontFamily:'Arial'});
  canvas.add(text); canvas.setActiveObject(text); canvas.renderAll(); meSaveUndo();
};

// ═══════════════════════════════════════════════════════════════════════════
// SESSION 3 — Multi-select, alignment, grid, layers, export modal, auto-save
// ═══════════════════════════════════════════════════════════════════════════

// ── Multi-select props (shown when activeSelection) ──────────────────────
// The existing meUpdateProps is extended: detect ActiveSelection for multi
function meCheckMultiSelect() {
  const sel = canvas?.getActiveObject();
  if (!sel || sel.type !== 'activeSelection') return false;
  const panel = document.getElementById('me-props');
  const count = sel.getObjects().length;
  panel.innerHTML = '<div style="font-weight:600;color:var(--t1);margin-bottom:8px">Multi-select</div>' +
    '<div style="color:var(--t2);margin-bottom:12px">' + count + ' objects selected</div>' +
    '<button onclick="meGroupSelected()" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px;color:var(--t1);cursor:pointer;font-size:11px;margin-bottom:4px">⊞ Group</button>' +
    '<button onclick="meDeleteSelected()" style="width:100%;background:rgba(248,113,113,.1);border:1px solid var(--rd);border-radius:6px;padding:6px;color:var(--rd);cursor:pointer;font-size:11px">🗑 Delete All</button>';
  meShowAlignBar();
  return true;
}

// Patch meUpdateProps to check multi-select first
const _origUpdateProps = meUpdateProps;
meUpdateProps = function() {
  if (meCheckMultiSelect()) return;
  meHideAlignBar();
  _origUpdateProps();
};

window.meGroupSelected = function() {
  if (!canvas) return;
  const sel = canvas.getActiveObject();
  if (!sel || sel.type !== 'activeSelection') return;
  sel.toGroup();
  canvas.renderAll(); meSaveUndo();
};

window.meUngroupSelected = function() {
  if (!canvas) return;
  const obj = canvas.getActiveObject();
  if (!obj || obj.type !== 'group') return;
  obj.toActiveSelection();
  canvas.renderAll(); meSaveUndo();
};

// ── Alignment ────────────────────────────────────────────────────────────
function meShowAlignBar() {
  const bar = document.getElementById('me-align-bar');
  if (bar) bar.style.display = 'flex';
}
function meHideAlignBar() {
  const bar = document.getElementById('me-align-bar');
  if (bar) bar.style.display = 'none';
}

window.meAlign = function(dir) {
  const sel = canvas?.getActiveObject();
  if (!sel) return;
  const objs = sel.type === 'activeSelection' ? sel.getObjects() : [sel];
  if (objs.length < 2 && (dir === 'left'||dir === 'right'||dir === 'top'||dir === 'bottom'||dir === 'centerH'||dir === 'centerV')) {
    // Single object: align to canvas
    const obj = objs[0];
    if (dir === 'left') obj.set('left', 0);
    else if (dir === 'right') obj.set('left', canvas.width/canvas.getZoom() - obj.getScaledWidth());
    else if (dir === 'top') obj.set('top', 0);
    else if (dir === 'bottom') obj.set('top', canvas.height/canvas.getZoom() - obj.getScaledHeight());
    else if (dir === 'centerH') obj.set('left', (canvas.width/canvas.getZoom() - obj.getScaledWidth()) / 2);
    else if (dir === 'centerV') obj.set('top', (canvas.height/canvas.getZoom() - obj.getScaledHeight()) / 2);
    canvas.renderAll(); meSaveUndo(); return;
  }
  // Multi: align relative to group
  const bounds = { left: Infinity, right: -Infinity, top: Infinity, bottom: -Infinity };
  objs.forEach(o => {
    const b = o.getBoundingRect(true);
    bounds.left = Math.min(bounds.left, b.left);
    bounds.right = Math.max(bounds.right, b.left + b.width);
    bounds.top = Math.min(bounds.top, b.top);
    bounds.bottom = Math.max(bounds.bottom, b.top + b.height);
  });
  objs.forEach(o => {
    const b = o.getBoundingRect(true);
    if (dir === 'left') o.set('left', o.left + (bounds.left - b.left));
    else if (dir === 'right') o.set('left', o.left + (bounds.right - b.left - b.width));
    else if (dir === 'top') o.set('top', o.top + (bounds.top - b.top));
    else if (dir === 'bottom') o.set('top', o.top + (bounds.bottom - b.top - b.height));
    else if (dir === 'centerH') o.set('left', o.left + ((bounds.left + bounds.right) / 2 - b.left - b.width / 2));
    else if (dir === 'centerV') o.set('top', o.top + ((bounds.top + bounds.bottom) / 2 - b.top - b.height / 2));
  });
  canvas.renderAll(); meSaveUndo();
};

window.meDistribute = function(axis) {
  const sel = canvas?.getActiveObject();
  if (!sel || sel.type !== 'activeSelection') return;
  const objs = sel.getObjects();
  if (objs.length < 3) return;
  if (axis === 'h') {
    objs.sort((a, b) => a.left - b.left);
    const totalWidth = objs.reduce((s, o) => s + o.getScaledWidth(), 0);
    const space = (objs[objs.length - 1].left + objs[objs.length - 1].getScaledWidth() - objs[0].left - totalWidth) / (objs.length - 1);
    let x = objs[0].left + objs[0].getScaledWidth() + space;
    for (let i = 1; i < objs.length - 1; i++) { objs[i].set('left', x); x += objs[i].getScaledWidth() + space; }
  } else {
    objs.sort((a, b) => a.top - b.top);
    const totalHeight = objs.reduce((s, o) => s + o.getScaledHeight(), 0);
    const space = (objs[objs.length - 1].top + objs[objs.length - 1].getScaledHeight() - objs[0].top - totalHeight) / (objs.length - 1);
    let y = objs[0].top + objs[0].getScaledHeight() + space;
    for (let i = 1; i < objs.length - 1; i++) { objs[i].set('top', y); y += objs[i].getScaledHeight() + space; }
  }
  canvas.renderAll(); meSaveUndo();
};

// ── Grid overlay ─────────────────────────────────────────────────────────
let meGridOn = false;
let meGridLines = [];

window.meToggleGrid = function() {
  meGridOn = !meGridOn;
  const btn = document.getElementById('me-grid-btn');
  if (btn) btn.style.color = meGridOn ? 'var(--p)' : 'var(--t1)';
  meDrawGrid();
};

function meDrawGrid() {
  // Remove existing grid lines
  meGridLines.forEach(l => canvas.remove(l));
  meGridLines = [];
  if (!meGridOn || !canvas) return;
  const step = 20;
  const w = canvas.width / canvas.getZoom();
  const h = canvas.height / canvas.getZoom();
  for (let x = step; x < w; x += step) {
    const l = new fabric.Line([x, 0, x, h], { stroke: 'rgba(124,58,237,.08)', strokeWidth: 0.5, selectable: false, evented: false, excludeFromExport: true });
    meGridLines.push(l); canvas.add(l); canvas.sendToBack(l);
  }
  for (let y = step; y < h; y += step) {
    const l = new fabric.Line([0, y, w, y], { stroke: 'rgba(124,58,237,.08)', strokeWidth: 0.5, selectable: false, evented: false, excludeFromExport: true });
    meGridLines.push(l); canvas.add(l); canvas.sendToBack(l);
  }
  canvas.renderAll();
}

// ── Right panel tabs ─────────────────────────────────────────────────────
window.meRightTab = function(tab) {
  const propsEl = document.getElementById('me-props');
  const layersEl = document.getElementById('me-layers');
  const propsBtn = document.getElementById('me-tab-props');
  const layersBtn = document.getElementById('me-tab-layers');
  if (tab === 'props') {
    if (propsEl) propsEl.style.display = 'block';
    if (layersEl) layersEl.style.display = 'none';
    if (propsBtn) { propsBtn.style.background = 'var(--pg)'; propsBtn.style.color = 'var(--p)'; propsBtn.style.borderBottom = '2px solid var(--p)'; }
    if (layersBtn) { layersBtn.style.background = 'transparent'; layersBtn.style.color = 'var(--t3)'; layersBtn.style.borderBottom = '2px solid transparent'; }
  } else {
    if (propsEl) propsEl.style.display = 'none';
    if (layersEl) layersEl.style.display = 'block';
    if (layersBtn) { layersBtn.style.background = 'var(--pg)'; layersBtn.style.color = 'var(--p)'; layersBtn.style.borderBottom = '2px solid var(--p)'; }
    if (propsBtn) { propsBtn.style.background = 'transparent'; propsBtn.style.color = 'var(--t3)'; propsBtn.style.borderBottom = '2px solid transparent'; }
    meRenderLayers();
  }
};

// ── Layers panel ─────────────────────────────────────────────────────────
function meRenderLayers() {
  const el = document.getElementById('me-layers');
  if (!el || !canvas) return;
  const objs = canvas.getObjects().filter(o => !o.excludeFromExport);
  if (objs.length === 0) { el.innerHTML = '<div style="color:var(--t3);padding:8px;font-size:11px">No elements</div>'; return; }
  // Reverse so top layer is first
  const reversed = [...objs].reverse();
  el.innerHTML = reversed.map((o, i) => {
    const idx = objs.length - 1 - i;
    const name = o._meName || o.type || 'element';
    const icon = o.type === 'i-text' ? 'T' : o.type === 'image' ? '🖼' : o.type === 'rect' ? '▭' : o.type === 'circle' ? '○' : o.type === 'group' ? '⊞' : '◇';
    const active = canvas.getActiveObject() === o;
    const locked = o.lockMovementX && o.lockMovementY;
    return '<div onclick="meSelectLayer(' + idx + ')" style="display:flex;align-items:center;gap:6px;padding:6px 4px;border-bottom:1px solid var(--bd);cursor:pointer;background:' + (active ? 'var(--pg)' : '') + ';border-radius:4px;margin-bottom:2px" onmouseover="this.style.background=\'var(--s2)\'" onmouseout="this.style.background=\'' + (active ? 'var(--pg)' : '') + '\'">' +
      '<span style="font-size:14px;width:20px;text-align:center">' + icon + '</span>' +
      '<span style="flex:1;font-size:11px;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + name.replace(/</g,'&lt;').slice(0,20) + '</span>' +
      '<button onclick="event.stopPropagation();meToggleLock(' + idx + ')" style="background:none;border:none;cursor:pointer;font-size:12px;color:' + (locked ? 'var(--am)' : 'var(--t3)') + '" title="Lock">' + (locked ? '🔒' : '🔓') + '</button>' +
      '<button onclick="event.stopPropagation();meToggleVisible(' + idx + ')" style="background:none;border:none;cursor:pointer;font-size:12px;color:' + (o.visible === false ? 'var(--rd)' : 'var(--t3)') + '" title="Visibility">' + (o.visible === false ? '👁‍🗨' : '👁') + '</button>' +
    '</div>';
  }).join('');
}

window.meSelectLayer = function(idx) {
  if (!canvas) return;
  const objs = canvas.getObjects().filter(o => !o.excludeFromExport);
  if (objs[idx]) { canvas.setActiveObject(objs[idx]); canvas.renderAll(); meUpdateProps(); }
};

window.meToggleLock = function(idx) {
  const objs = canvas.getObjects().filter(o => !o.excludeFromExport);
  const o = objs[idx]; if (!o) return;
  const locked = !(o.lockMovementX && o.lockMovementY);
  o.set({ lockMovementX: locked, lockMovementY: locked, lockScalingX: locked, lockScalingY: locked, lockRotation: locked, hasControls: !locked, selectable: !locked });
  canvas.renderAll(); meRenderLayers();
};

window.meToggleVisible = function(idx) {
  const objs = canvas.getObjects().filter(o => !o.excludeFromExport);
  const o = objs[idx]; if (!o) return;
  o.set('visible', !o.visible);
  canvas.renderAll(); meRenderLayers();
};

// ── Duplicate / Copy / Paste ─────────────────────────────────────────────
let meClipboard = null;

window.meDuplicate = function() {
  const obj = canvas?.getActiveObject(); if (!obj) return;
  obj.clone(function(cloned) {
    cloned.set({ left: obj.left + 10, top: obj.top + 10 });
    canvas.add(cloned); canvas.setActiveObject(cloned); canvas.renderAll(); meSaveUndo();
  });
};

window.meCopy = function() {
  const obj = canvas?.getActiveObject(); if (!obj) return;
  obj.clone(function(cloned) { meClipboard = cloned; });
};

window.mePaste = function() {
  if (!meClipboard || !canvas) return;
  meClipboard.clone(function(cloned) {
    cloned.set({ left: cloned.left + 10, top: cloned.top + 10 });
    canvas.add(cloned); canvas.setActiveObject(cloned); canvas.renderAll(); meSaveUndo();
  });
};

// ── Export modal ──────────────────────────────────────────────────────────
window.meShowExportModal = function() {
  if (!canvas) return;
  const cw = Math.round(canvas.width / canvas.getZoom());
  const ch = Math.round(canvas.height / canvas.getZoom());
  const wrap = document.getElementById('me-canvas-wrap');
  if (!wrap) return;
  // Overlay modal on the canvas area
  const existing = document.getElementById('me-export-modal');
  if (existing) { existing.remove(); return; }
  const modal = document.createElement('div');
  modal.id = 'me-export-modal';
  modal.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--s1,#171A21);border:1px solid var(--bd,rgba(255,255,255,.07));border-radius:12px;padding:20px;z-index:10;min-width:280px;box-shadow:0 8px 32px rgba(0,0,0,.5)';
  modal.innerHTML =
    '<div style="font-weight:600;color:var(--t1);margin-bottom:12px;font-size:14px">Export Design</div>' +
    '<div style="margin-bottom:8px"><label style="font-size:10px;color:var(--t3);text-transform:uppercase;display:block;margin-bottom:4px">Format</label>' +
    '<select id="me-exp-fmt" onchange="meExpPreview()" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px;color:var(--t1);font-size:12px"><option value="png">PNG (lossless)</option><option value="jpeg">JPG</option><option value="webp">WebP</option></select></div>' +
    '<div style="margin-bottom:8px"><label style="font-size:10px;color:var(--t3);text-transform:uppercase;display:block;margin-bottom:4px">Quality <span id="me-exp-q-label">100%</span></label>' +
    '<input type="range" id="me-exp-quality" min="60" max="100" value="100" oninput="document.getElementById(\'me-exp-q-label\').textContent=this.value+\'%\'" style="width:100%"></div>' +
    '<div style="margin-bottom:8px"><label style="font-size:10px;color:var(--t3);text-transform:uppercase;display:block;margin-bottom:4px">Scale</label>' +
    '<select id="me-exp-scale" onchange="meExpPreview()" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px;color:var(--t1);font-size:12px"><option value="1">1x (' + cw + '×' + ch + ')</option><option value="2">2x (' + cw*2 + '×' + ch*2 + ')</option><option value="3">3x (' + cw*3 + '×' + ch*3 + ')</option></select></div>' +
    '<div style="margin-bottom:12px;font-size:10px;color:var(--t3);text-transform:uppercase">Platform presets</div>' +
    '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px">' +
    ['Instagram','Facebook','LinkedIn','TikTok'].map(p => '<button onclick="meExpPreset(\'' + p + '\')" style="background:var(--s2);border:1px solid var(--bd);border-radius:4px;padding:4px 8px;color:var(--t1);cursor:pointer;font-size:10px">' + p + '</button>').join('') + '</div>' +
    '<div style="display:flex;gap:8px">' +
    '<button onclick="meDoExport()" style="flex:1;background:var(--p);color:#fff;border:none;border-radius:8px;padding:8px;cursor:pointer;font-size:13px;font-weight:600">⬇ Download</button>' +
    '<button onclick="document.getElementById(\'me-export-modal\').remove()" style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px 12px;color:var(--t1);cursor:pointer;font-size:12px">Cancel</button></div>';
  wrap.appendChild(modal);
};

window.meExpPreset = function(platform) {
  const fmt = document.getElementById('me-exp-fmt');
  const scale = document.getElementById('me-exp-scale');
  if (platform === 'Instagram') { fmt.value = 'jpeg'; scale.value = '2'; }
  else if (platform === 'Facebook') { fmt.value = 'png'; scale.value = '1'; }
  else if (platform === 'LinkedIn') { fmt.value = 'png'; scale.value = '1'; }
  else if (platform === 'TikTok') { fmt.value = 'jpeg'; scale.value = '2'; }
};

window.meDoExport = function() {
  if (!canvas) return;
  const fmt = document.getElementById('me-exp-fmt')?.value || 'png';
  const quality = (+document.getElementById('me-exp-quality')?.value || 100) / 100;
  const scale = +document.getElementById('me-exp-scale')?.value || 1;
  // Remove grid lines before export
  meGridLines.forEach(l => canvas.remove(l));
  const origZoom = canvas.getZoom();
  canvas.setZoom(1);
  canvas.renderAll();
  const dataUrl = canvas.toDataURL({ format: fmt, quality, multiplier: scale });
  canvas.setZoom(origZoom);
  canvas.renderAll();
  if (meGridOn) meDrawGrid();
  const a = document.createElement('a');
  a.href = dataUrl;
  a.download = 'design-' + (currentProjectId || 'export') + '.' + (fmt === 'jpeg' ? 'jpg' : fmt);
  a.click();
  const modal = document.getElementById('me-export-modal');
  if (modal) modal.remove();
};

// ── Auto-save ────────────────────────────────────────────────────────────
let meDirty = false;
let meAutoSaveInterval = null;

function meStartAutoSave() {
  if (meAutoSaveInterval) clearInterval(meAutoSaveInterval);
  meAutoSaveInterval = setInterval(function() {
    if (meDirty && canvas && currentProjectId) {
      meSave().then(function() {
        meDirty = false;
        meUpdateSaveIndicator();
      });
    }
  }, 60000); // every 60 seconds
}

function meUpdateSaveIndicator() {
  const el = document.getElementById('me-save-indicator');
  if (!el) return;
  el.textContent = meDirty ? '● Unsaved' : '✓ Saved';
  el.style.color = meDirty ? 'var(--am)' : 'var(--ac,#10B981)';
}

// Warn before navigating away with unsaved changes
window.addEventListener('beforeunload', function(e) {
  if (meDirty && canvas) { e.preventDefault(); e.returnValue = ''; }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  if (!canvas) return;
  if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); meUndo(); }
  if ((e.ctrlKey || e.metaKey) && e.key === 'y') { e.preventDefault(); meRedo(); }
  if ((e.ctrlKey || e.metaKey) && e.key === 'd') { e.preventDefault(); meDuplicate(); }
  if ((e.ctrlKey || e.metaKey) && e.key === 'c') { if (!canvas.getActiveObject()?.isEditing) { e.preventDefault(); meCopy(); } }
  if ((e.ctrlKey || e.metaKey) && e.key === 'v') { if (meClipboard) { e.preventDefault(); mePaste(); } }
  if (e.key === 'Delete' || e.key === 'Backspace') {
    const obj = canvas.getActiveObject();
    if (obj && !(obj.isEditing)) { e.preventDefault(); meDeleteSelected(); }
  }
});

})();
