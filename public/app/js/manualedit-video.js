/**
 * MANUALEDIT888 — Creative Studio SPA v1.0.0
 * Canvas engine: drag / resize / rotate / z-order / snap
 * Timeline: trim handles / scrubber / track reorder
 * Inspector: per-type property panels
 * Assets: media browser + drag-to-canvas
 * Export: PNG/JPG via Canvas API; video via worker
 * Agent mode: all mutations via apply_operation() dispatcher
 */

// ══════════════════════════════════════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════════════════════════════════════

var _edit = {
    rootEl:       null,
    wsId:         1,
    project:      null,   // current project meta
    layers:       [],     // current layer objects
    timeline:     [],     // clip timings
    assets:       [],     // media asset library
    selectedId:   null,   // selected layer id
    mode:         'select', // select | text | shape | pan
    zoom:         1.0,
    currentTime:  0,
    playing:      false,
    playTimer:    null,
    snapEnabled:  true,
    GRID:         10,
    history:      [],     // for undo (last 30 states)
    saving:       false,
    view:         'dashboard', // dashboard | editor
    drag:         null,   // active drag state
    resize:       null,   // active resize state
    rotate:       null,   // active rotate state
    trimming:     null,   // active timeline trim
    scrubbing:    false,
};

// API helpers
function _editUrl(p) {
    var b = (window.LU_API_BASE || '/api');
    return b + '/api/manualedit' + p;
}
function _editNonce() { return (window.LU_CFG && '') || ''; }
function _editHeaders() { return {'Content-Type':'application/json','Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}; }

async function _editGet(path) {
    var r = await fetch(_editUrl(path), {headers:_editHeaders()});
    return r.json();
}
async function _editPost(path, data) {
    var r = await fetch(_editUrl(path), {method:'POST',headers:_editHeaders(),body:JSON.stringify(data)});
    return r.json();
}

function _e(s) { return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function _editToast(msg, type) {
    var d = document.createElement('div');
    var bg = type==='error' ? '#f87171' : type==='success' ? '#00E5A8' : '#6C5CE7';
    d.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:'+bg+';color:#000;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;font-family:DM Sans,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.4);max-width:320px;';
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(function(){d.remove();}, 3500);
}

// ══════════════════════════════════════════════════════════════════════════════
// ENTRY POINT
// ══════════════════════════════════════════════════════════════════════════════

window.manualeditVideoLoad = function(rootEl) {
    _edit.rootEl = rootEl;
    console.log('[MANUALEDIT888] v1.0.0 — engine slot claimed');
    _editRender();
};

function _editRender() {
    if (!_edit.rootEl) return;
    if (_edit.view === 'dashboard') {
        _edit.rootEl.innerHTML = _editDashboardHtml();
        _editBindDashboard();
    } else if (_edit.view === 'editor') {
        _edit.rootEl.innerHTML = _editEditorHtml();
        _editBindEditor();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DASHBOARD — project grid
// ══════════════════════════════════════════════════════════════════════════════

function _editDashboardHtml() {
    var cards = _edit.projects && _edit.projects.length ? _edit.projects.map(function(p) {
        var icon = p.type === 'video' ? '🎬' : '🖼️';
        var dim  = p.width + '×' + p.height;
        return '<div class="edit-project-card" data-id="'+p.id+'" style="background:var(--s2,#1E2230);border:1px solid var(--bd,#2a2d3e);border-radius:10px;padding:0;cursor:pointer;overflow:hidden;transition:border .2s;" onmouseenter="this.style.borderColor=\'var(--p,#6C5CE7)\'" onmouseleave="this.style.borderColor=\'var(--bd,#2a2d3e)\'">' +
            '<div style="height:120px;background:var(--s3,#252A3A);display:flex;align-items:center;justify-content:center;font-size:36px;">' + (p.thumbnail_url ? '<img src="'+_e(p.thumbnail_url)+'" style="width:100%;height:100%;object-fit:cover;">' : icon) + '</div>' +
            '<div style="padding:12px;">' +
                '<div style="font-size:13px;font-weight:600;color:var(--t1,#fff);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + _e(p.name) + '</div>' +
                '<div style="font-size:11px;color:var(--t3,#666);">' + _e(dim) + (p.type==='video' ? ' · '+p.duration+'s' : '') + '</div>' +
            '</div>' +
        '</div>';
    }).join('') : '<div style="color:var(--t3,#666);font-size:14px;text-align:center;padding:40px;grid-column:1/-1;">No projects yet. Create your first one!</div>';

    return '<div style="height:100%;display:flex;flex-direction:column;background:var(--bg,#0F1117);">' +
        '<div style="padding:24px 28px 16px;border-bottom:1px solid var(--bd,#2a2d3e);display:flex;align-items:center;gap:12px;">' +
            '<span style="font-size:20px;font-weight:700;color:var(--t1,#fff);font-family:Syne,sans-serif;">Creative Studio</span>' +
            '<span style="font-size:11px;background:var(--p,#6C5CE7);color:#fff;padding:2px 8px;border-radius:20px;font-weight:600;">MANUALEDIT888 v1.0.0</span>' +
            '<div style="flex:1;"></div>' +
            '<button id="edit-new-image" style="background:var(--s3,#252A3A);border:1px solid var(--bd,#2a2d3e);color:var(--t2,#ccc);border-radius:7px;padding:8px 14px;font-size:12px;cursor:pointer;">🖼️ New Image</button>' +
            '<button id="edit-new-video" style="background:var(--p,#6C5CE7);border:none;color:#fff;border-radius:7px;padding:8px 14px;font-size:12px;cursor:pointer;font-weight:600;">🎬 New Video</button>' +
        '</div>' +
        '<div style="flex:1;overflow-y:auto;padding:24px 28px;">' +
            '<div id="edit-project-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;">' +
                cards +
            '</div>' +
        '</div>' +
    '</div>';
}

function _editBindDashboard() {
    // Load projects
    _editGet('/project/list').then(function(r) {
        if (r && r.success) {
            _edit.projects = r.projects || [];
            var grid = _edit.rootEl ? _edit.rootEl.querySelector('#edit-project-grid') : null;
            if (grid) {
                var cards = _edit.projects.length ? _edit.projects.map(function(p) {
                    var icon = p.type === 'video' ? '🎬' : '🖼️';
                    var dim  = p.width + '×' + p.height;
                    return '<div class="edit-project-card" data-id="'+p.id+'" style="background:var(--s2,#1E2230);border:1px solid var(--bd,#2a2d3e);border-radius:10px;cursor:pointer;overflow:hidden;transition:border .2s;" onmouseenter="this.style.borderColor=\'var(--p,#6C5CE7)\'" onmouseleave="this.style.borderColor=\'var(--bd,#2a2d3e)\'">' +
                        '<div style="height:120px;background:var(--s3,#252A3A);display:flex;align-items:center;justify-content:center;font-size:36px;">' + icon + '</div>' +
                        '<div style="padding:12px;">' +
                            '<div style="font-size:13px;font-weight:600;color:var(--t1,#fff);margin-bottom:4px;">' + _e(p.name) + '</div>' +
                            '<div style="font-size:11px;color:var(--t3,#666);">' + _e(dim) + (p.type==='video' ? ' · '+p.duration+'s' : '') + '</div>' +
                        '</div>' +
                    '</div>';
                }).join('') : '<div style="color:var(--t3,#666);font-size:14px;text-align:center;padding:40px;grid-column:1/-1;">No projects yet.</div>';
                grid.innerHTML = cards;
            }
        }
    });

    var R = _edit.rootEl;
    if (!R) return;

    R.addEventListener('click', function(e) {
        var card = e.target.closest('.edit-project-card');
        if (card) { _editOpenProject(parseInt(card.dataset.id)); return; }

        if (e.target.id === 'edit-new-image') { _editCreateProject('image'); }
        if (e.target.id === 'edit-new-video') { _editCreateProject('video'); }
    });
}

async function _editCreateProject(type) {
    var sizes = {image:{w:1080,h:1080,dur:0}, video:{w:1920,h:1080,dur:30}};
    var s = sizes[type] || sizes.image;
    var name = prompt('Project name:', type === 'video' ? 'New Video' : 'New Design');
    if (!name) return;
    var r = await _editPost('/project/create', {name:name, type:type, width:s.w, height:s.h, duration:s.dur});
    if (r && r.success) { _editOpenProject(r.project.id); }
    else { _editToast('Failed to create project', 'error'); }
}

async function _editOpenProject(id) {
    var r = await _editGet('/project/' + id);
    if (!r || !r.success) { _editToast('Failed to load project', 'error'); return; }
    _edit.project  = r.project;
    _edit.layers   = r.layers || [];
    _edit.timeline = r.timeline || [];
    _edit.selectedId = null;
    _edit.currentTime = 0;
    _edit.view = 'editor';
    _editRender();
    // Load assets
    _editGet('/assets').then(function(ar){ if(ar&&ar.success) _edit.assets = ar.assets||[]; _editRenderAssets(); });
}

// ══════════════════════════════════════════════════════════════════════════════
// EDITOR SHELL
// ══════════════════════════════════════════════════════════════════════════════

function _editEditorHtml() {
    var p = _edit.project || {};
    var isVideo = (p.type === 'video');
    return '<div id="edit-editor-shell" style="height:100%;display:flex;flex-direction:column;background:var(--bg,#0F1117);font-family:DM Sans,sans-serif;">' +
        // Toolbar
        '<div id="edit-toolbar" style="height:44px;background:var(--s1,#171A21);border-bottom:1px solid var(--bd,#2a2d3e);display:flex;align-items:center;padding:0 12px;gap:8px;flex-shrink:0;">' +
            '<button id="edit-back" style="background:none;border:none;color:var(--t2,#aaa);cursor:pointer;font-size:18px;padding:4px 8px;" title="Back">←</button>' +
            '<span id="edit-project-name" style="font-size:13px;font-weight:600;color:var(--t1,#fff);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + _e(p.name||'Project') + '</span>' +
            '<div style="width:1px;height:20px;background:var(--bd,#2a2d3e);margin:0 4px;"></div>' +
            '<button class="edit-tool-btn" data-tool="select" title="Select (V)" style="background:var(--p,#6C5CE7);border:none;border-radius:5px;color:#fff;padding:5px 10px;font-size:11px;cursor:pointer;">↖ Select</button>' +
            '<button class="edit-tool-btn" data-tool="text"   title="Text (T)" style="background:var(--s3,#252A3A);border:none;border-radius:5px;color:var(--t2,#aaa);padding:5px 10px;font-size:11px;cursor:pointer;">T Text</button>' +
            '<button class="edit-tool-btn" data-tool="shape"  title="Shape (R)" style="background:var(--s3,#252A3A);border:none;border-radius:5px;color:var(--t2,#aaa);padding:5px 10px;font-size:11px;cursor:pointer;">▭ Shape</button>' +
            '<div style="flex:1;"></div>' +
            '<span style="font-size:11px;color:var(--t3,#666);">Zoom:</span>' +
            '<button id="edit-zoom-out" style="background:var(--s3,#252A3A);border:none;border-radius:4px;color:var(--t2,#aaa);padding:3px 8px;font-size:13px;cursor:pointer;">−</button>' +
            '<span id="edit-zoom-label" style="font-size:11px;color:var(--t2,#aaa);min-width:36px;text-align:center;">' + Math.round(_edit.zoom*100) + '%</span>' +
            '<button id="edit-zoom-in"  style="background:var(--s3,#252A3A);border:none;border-radius:4px;color:var(--t2,#aaa);padding:3px 8px;font-size:13px;cursor:pointer;">+</button>' +
            '<div style="width:1px;height:20px;background:var(--bd,#2a2d3e);margin:0 4px;"></div>' +
            '<button id="edit-undo" style="background:var(--s3,#252A3A);border:none;border-radius:4px;color:var(--t2,#aaa);padding:4px 8px;font-size:11px;cursor:pointer;" title="Undo (Ctrl+Z)">↩ Undo</button>' +
            '<button id="edit-export-btn" style="background:var(--ac,#00E5A8);border:none;border-radius:6px;color:#000;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">↑ Export</button>' +
        '</div>' +
        // Main area
        '<div style="flex:1;display:flex;overflow:hidden;">' +
            // Left: Assets + Layers
            '<div id="edit-left-panel" style="width:220px;flex-shrink:0;background:var(--s1,#171A21);border-right:1px solid var(--bd,#2a2d3e);display:flex;flex-direction:column;">' +
                '<div id="edit-panel-tabs" style="display:flex;border-bottom:1px solid var(--bd,#2a2d3e);">' +
                    '<button class="edit-ptab active" data-tab="layers" style="flex:1;background:none;border:none;border-bottom:2px solid var(--p,#6C5CE7);color:var(--t1,#fff);padding:8px 0;font-size:11px;font-weight:600;cursor:pointer;">Layers</button>' +
                    '<button class="edit-ptab" data-tab="assets" style="flex:1;background:none;border:none;border-bottom:2px solid transparent;color:var(--t3,#666);padding:8px 0;font-size:11px;cursor:pointer;">Assets</button>' +
                '</div>' +
                '<div id="edit-tab-layers" style="flex:1;overflow-y:auto;padding:8px;">' +
                    '<div id="edit-layers-list"></div>' +
                    '<button id="edit-add-text-btn" style="width:100%;margin-top:8px;background:none;border:1px dashed var(--bd,#2a2d3e);color:var(--t3,#666);border-radius:5px;padding:6px;font-size:11px;cursor:pointer;" onclick="_editAddLayer(\'text\')">+ Add Text</button>' +
                    '<button id="edit-add-shape-btn" style="width:100%;margin-top:4px;background:none;border:1px dashed var(--bd,#2a2d3e);color:var(--t3,#666);border-radius:5px;padding:6px;font-size:11px;cursor:pointer;" onclick="_editAddLayer(\'shape\')">+ Add Shape</button>' +
                '</div>' +
                '<div id="edit-tab-assets" style="flex:1;overflow-y:auto;padding:8px;display:none;">' +
                    '<div id="edit-assets-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;"></div>' +
                '</div>' +
            '</div>' +
            // Centre: Canvas
            '<div id="edit-canvas-area" style="flex:1;overflow:auto;background:#111;display:flex;align-items:center;justify-content:center;position:relative;">' +
                '<div id="edit-canvas-wrapper" style="position:relative;transform-origin:center center;">' +
                    '<div id="edit-canvas" style="position:relative;background:#fff;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.6);" data-w="'+(p.width||1080)+'" data-h="'+(p.height||1080)+'">' +
                    '</div>' +
                '</div>' +
            '</div>' +
            // Right: Inspector
            '<div id="edit-inspector" style="width:230px;flex-shrink:0;background:var(--s1,#171A21);border-left:1px solid var(--bd,#2a2d3e);overflow-y:auto;padding:12px;">' +
                '<div style="font-size:10px;font-weight:700;color:var(--t3,#666);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Inspector</div>' +
                '<div id="edit-inspector-content">' +
                    '<div style="color:var(--t3,#666);font-size:12px;text-align:center;padding:30px 0;">Select a layer to edit</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        // Timeline (video only)
        (isVideo ? '<div id="edit-timeline-panel" style="height:160px;flex-shrink:0;background:var(--s1,#171A21);border-top:1px solid var(--bd,#2a2d3e);">' +
            '<div style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-bottom:1px solid var(--bd,#2a2d3e);">' +
                '<button id="edit-play-btn" style="background:var(--p,#6C5CE7);border:none;border-radius:5px;color:#fff;padding:4px 12px;font-size:11px;cursor:pointer;font-weight:700;">▶ Play</button>' +
                '<span id="edit-time-label" style="font-size:11px;color:var(--t2,#aaa);min-width:60px;">0.0s / '+p.duration+'s</span>' +
                '<div id="edit-timeline-scrubber-track" style="flex:1;height:4px;background:var(--s3,#252A3A);border-radius:2px;position:relative;cursor:pointer;">' +
                    '<div id="edit-scrubber-pos" style="position:absolute;left:0;top:-4px;width:2px;height:12px;background:var(--rd,#f87171);border-radius:1px;"></div>' +
                '</div>' +
            '</div>' +
            '<div id="edit-timeline-body" style="overflow-x:auto;overflow-y:auto;height:calc(100% - 36px);position:relative;">' +
                '<div id="edit-timeline-tracks" style="position:relative;padding:4px 0;min-height:100px;"></div>' +
            '</div>' +
        '</div>' : '') +
    '</div>';
}

// ══════════════════════════════════════════════════════════════════════════════
// EDITOR BINDING
// ══════════════════════════════════════════════════════════════════════════════

function _editBindEditor() {
    var R = _edit.rootEl;
    if (!R) return;

    // Set canvas size
    var canvas = R.querySelector('#edit-canvas');
    var wrapper = R.querySelector('#edit-canvas-wrapper');
    var p = _edit.project || {};
    if (canvas) {
        canvas.style.width  = (p.width  || 1080) + 'px';
        canvas.style.height = (p.height || 1080) + 'px';
    }
    _editApplyZoom();
    _editRenderLayers();
    _editRenderTimeline();

    // Toolbar buttons
    R.querySelector('#edit-back') && R.querySelector('#edit-back').addEventListener('click', function() {
        _edit.view = 'dashboard'; _editRender();
    });
    R.querySelector('#edit-zoom-in') && R.querySelector('#edit-zoom-in').addEventListener('click', function() {
        _edit.zoom = Math.min(4, _edit.zoom + 0.1); _editApplyZoom();
    });
    R.querySelector('#edit-zoom-out') && R.querySelector('#edit-zoom-out').addEventListener('click', function() {
        _edit.zoom = Math.max(0.1, _edit.zoom - 0.1); _editApplyZoom();
    });
    R.querySelector('#edit-undo') && R.querySelector('#edit-undo').addEventListener('click', _editUndo);
    R.querySelector('#edit-export-btn') && R.querySelector('#edit-export-btn').addEventListener('click', _editExport);

    // Tool buttons
    R.querySelectorAll('.edit-tool-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            _edit.mode = btn.dataset.tool;
            R.querySelectorAll('.edit-tool-btn').forEach(function(b) {
                b.style.background = 'var(--s3,#252A3A)'; b.style.color = 'var(--t2,#aaa)';
            });
            btn.style.background = 'var(--p,#6C5CE7)'; btn.style.color = '#fff';
            if (_edit.mode === 'text')  { _editAddLayer('text'); }
            if (_edit.mode === 'shape') { _editAddLayer('shape'); }
        });
    });

    // Panel tabs
    R.querySelectorAll('.edit-ptab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            R.querySelectorAll('.edit-ptab').forEach(function(t) {
                t.style.borderBottomColor = 'transparent'; t.style.color = 'var(--t3,#666)';
                var tc = t.dataset.tab;
                var tv = R.querySelector('#edit-tab-'+tc);
                if (tv) tv.style.display = 'none';
            });
            tab.style.borderBottomColor = 'var(--p,#6C5CE7)'; tab.style.color = 'var(--t1,#fff)';
            var tv = R.querySelector('#edit-tab-'+tab.dataset.tab);
            if (tv) tv.style.display = 'block';
            if (tab.dataset.tab === 'assets') _editRenderAssets();
        });
    });

    // Canvas click on empty area = deselect
    var canvasEl = R.querySelector('#edit-canvas');
    if (canvasEl) {
        canvasEl.addEventListener('mousedown', function(e) {
            if (e.target === canvasEl) { _edit.selectedId = null; _editRenderLayers(); _editRenderInspector(); }
        });
    }

    // Play button (video)
    var playBtn = R.querySelector('#edit-play-btn');
    if (playBtn) {
        playBtn.addEventListener('click', function() {
            if (_edit.playing) { _editPause(); } else { _editPlay(); }
        });
    }

    // Scrubber track click
    var scrubTrack = R.querySelector('#edit-timeline-scrubber-track');
    if (scrubTrack) {
        scrubTrack.addEventListener('click', function(e) {
            var rect = scrubTrack.getBoundingClientRect();
            var pct  = (e.clientX - rect.left) / rect.width;
            _edit.currentTime = pct * (_edit.project.duration || 30);
            _editUpdateScrubber();
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', _editOnKey);
}

function _editOnKey(e) {
    if (!_edit.project) return;
    if (e.key === 'Delete' || e.key === 'Backspace') {
        if (_edit.selectedId && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            _editDeleteLayer(_edit.selectedId);
        }
    }
    if (e.ctrlKey && e.key === 'z') { e.preventDefault(); _editUndo(); }
    if (e.ctrlKey && e.key === 'd') { e.preventDefault(); if (_edit.selectedId) _editDuplicateLayer(_edit.selectedId); }
}

function _editApplyZoom() {
    var R = _edit.rootEl;
    if (!R) return;
    var wrapper = R.querySelector('#edit-canvas-wrapper');
    if (wrapper) wrapper.style.transform = 'scale(' + _edit.zoom + ')';
    var label = R.querySelector('#edit-zoom-label');
    if (label) label.textContent = Math.round(_edit.zoom * 100) + '%';
}

// ══════════════════════════════════════════════════════════════════════════════
// LAYER RENDERING & INTERACTION
// ══════════════════════════════════════════════════════════════════════════════

function _editRenderLayers() {
    var R = _edit.rootEl;
    if (!R) return;
    var canvas = R.querySelector('#edit-canvas');
    if (!canvas) return;

    // Preserve non-layer children (if any)
    canvas.innerHTML = '';

    // Sort by z_index ascending (bottom to top)
    var sorted = _edit.layers.slice().sort(function(a,b) { return (a.z_index||0)-(b.z_index||0); });

    sorted.forEach(function(layer) {
        if (!layer.visible) return;
        var el = _editBuildLayerEl(layer);
        canvas.appendChild(el);
    });

    _editRenderLayersList();
}

function _editBuildLayerEl(layer) {
    var isSelected = (layer.id == _edit.selectedId);
    var style = layer.style || {};

    var el = document.createElement('div');
    el.id = 'edit-layer-' + layer.id;
    el.dataset.id = layer.id;
    el.dataset.type = layer.type;

    var css = [
        'position:absolute',
        'left:'   + (layer.position_x||0) + 'px',
        'top:'    + (layer.position_y||0) + 'px',
        'width:'  + (layer.width||200)    + 'px',
        'height:' + (layer.height||200)   + 'px',
        'transform:rotate(' + (layer.rotation||0) + 'deg)',
        'box-sizing:border-box',
        'cursor:' + (layer.locked ? 'not-allowed' : 'move'),
        'user-select:none',
    ];

    // CSS filters for image/video
    var filters = [];
    if (style.opacity !== undefined)    { css.push('opacity:' + (style.opacity/100)); }
    if (style.brightness !== undefined) filters.push('brightness(' + style.brightness + '%)');
    if (style.contrast !== undefined)   filters.push('contrast('   + style.contrast   + '%)');
    if (style.blur !== undefined)       filters.push('blur('       + style.blur       + 'px)');
    if (filters.length) css.push('filter:' + filters.join(' '));

    if (isSelected) {
        css.push('outline:2px solid #6C5CE7');
        css.push('outline-offset:1px');
    }

    el.style.cssText = css.join(';');

    // Layer content by type
    if (layer.type === 'image' && layer.content) {
        var img = document.createElement('img');
        img.src = layer.content;
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;pointer-events:none;display:block;';
        el.appendChild(img);
    } else if (layer.type === 'video' && layer.content) {
        var vid = document.createElement('video');
        vid.src = layer.content;
        vid.style.cssText = 'width:100%;height:100%;object-fit:cover;pointer-events:none;display:block;';
        el.appendChild(vid);
    } else if (layer.type === 'text') {
        var txt = document.createElement('div');
        txt.style.cssText = [
            'width:100%',
            'height:100%',
            'display:flex',
            'align-items:center',
            'justify-content:' + (style.textAlign === 'right' ? 'flex-end' : style.textAlign === 'center' ? 'center' : 'flex-start'),
            'font-size:' + (style.fontSize || 32) + 'px',
            'font-family:' + (style.fontFamily || 'Syne,sans-serif'),
            'font-weight:' + (style.fontWeight || '700'),
            'color:' + (style.color || '#ffffff'),
            'line-height:' + (style.lineHeight || 1.3),
            'text-align:' + (style.textAlign || 'left'),
            'padding:8px',
            'word-wrap:break-word',
            'overflow:hidden',
            'box-sizing:border-box',
        ].join(';');
        txt.textContent = layer.content || 'Double-click to edit';
        el.appendChild(txt);
    } else if (layer.type === 'shape') {
        el.style.background   = style.fill       || '#6C5CE7';
        el.style.borderRadius = (style.borderRadius || 0) + 'px';
        if (style.borderWidth) {
            el.style.border = style.borderWidth + 'px ' + (style.borderStyle || 'solid') + ' ' + (style.borderColor || '#000');
        }
    }

    // Selection handles
    if (isSelected && !layer.locked) {
        _editAddSelectionHandles(el);
    }

    // Mouse events
    if (!layer.locked) {
        el.addEventListener('mousedown', function(e) { e.stopPropagation(); _editStartDrag(e, layer); });
        el.addEventListener('dblclick',  function(e) { e.stopPropagation(); _editStartInlineEdit(layer); });
    }
    el.addEventListener('mousedown', function(e) {
        if (e.target === el || e.target.closest('[data-id]') === el) {
            _edit.selectedId = layer.id;
            _editRenderLayers();
            _editRenderInspector();
        }
    });

    return el;
}

function _editAddSelectionHandles(el) {
    var handles = ['tl','t','tr','r','br','b','bl','l','rotate'];
    handles.forEach(function(h) {
        var handle = document.createElement('div');
        handle.dataset.handle = h;

        if (h === 'rotate') {
            handle.style.cssText = 'position:absolute;width:10px;height:10px;background:#6C5CE7;border-radius:50%;left:50%;top:-22px;transform:translateX(-50%);cursor:grab;z-index:100;border:2px solid #fff;';
            handle.addEventListener('mousedown', function(e) { e.stopPropagation(); _editStartRotate(e); });
        } else {
            var pos = _editHandlePos(h);
            handle.style.cssText = 'position:absolute;width:8px;height:8px;background:#fff;border:1.5px solid #6C5CE7;border-radius:2px;z-index:100;' + pos.css + ';cursor:' + pos.cursor + ';box-sizing:border-box;';
            handle.addEventListener('mousedown', function(e) { e.stopPropagation(); _editStartResize(e, h); });
        }
        el.appendChild(handle);
    });
}

function _editHandlePos(h) {
    var cursors = {tl:'nwse-resize',t:'ns-resize',tr:'nesw-resize',r:'ew-resize',br:'nwse-resize',b:'ns-resize',bl:'nesw-resize',l:'ew-resize'};
    var pos = {
        tl: 'top:-4px;left:-4px',   t: 'top:-4px;left:50%;transform:translateX(-50%)',  tr: 'top:-4px;right:-4px',
        r:  'top:50%;right:-4px;transform:translateY(-50%)',  br: 'bottom:-4px;right:-4px',
        b:  'bottom:-4px;left:50%;transform:translateX(-50%)', bl: 'bottom:-4px;left:-4px',
        l:  'top:50%;left:-4px;transform:translateY(-50%)',
    };
    return {css: pos[h], cursor: cursors[h]};
}

// ══════════════════════════════════════════════════════════════════════════════
// DRAG / RESIZE / ROTATE
// ══════════════════════════════════════════════════════════════════════════════

function _editStartDrag(e, layer) {
    if (e.target.dataset.handle) return; // let handle handler take over
    _edit.drag = {
        id:      layer.id,
        startMX: e.clientX,
        startMY: e.clientY,
        startX:  parseFloat(layer.position_x) || 0,
        startY:  parseFloat(layer.position_y) || 0,
    };
    document.addEventListener('mousemove', _editOnDragMove);
    document.addEventListener('mouseup',   _editOnDragUp);
    e.preventDefault();
}

function _editOnDragMove(e) {
    if (!_edit.drag) return;
    var z    = _edit.zoom;
    var dx   = (e.clientX - _edit.drag.startMX) / z;
    var dy   = (e.clientY - _edit.drag.startMY) / z;
    var newX = _edit.drag.startX + dx;
    var newY = _edit.drag.startY + dy;

    // Snap to grid
    if (_edit.snapEnabled) {
        newX = Math.round(newX / _edit.GRID) * _edit.GRID;
        newY = Math.round(newY / _edit.GRID) * _edit.GRID;
    }

    var layer = _editGetLayer(_edit.drag.id);
    if (!layer) return;
    layer.position_x = newX;
    layer.position_y = newY;

    // Live update DOM
    var el = _edit.rootEl ? _edit.rootEl.querySelector('#edit-layer-' + _edit.drag.id) : null;
    if (el) { el.style.left = newX + 'px'; el.style.top = newY + 'px'; }
    _editRenderInspector();
}

async function _editOnDragUp(e) {
    if (!_edit.drag) return;
    var id = _edit.drag.id;
    _edit.drag = null;
    document.removeEventListener('mousemove', _editOnDragMove);
    document.removeEventListener('mouseup',   _editOnDragUp);
    var layer = _editGetLayer(id);
    if (layer) { await _editSaveLayer(layer); }
}

function _editStartResize(e, handle) {
    var layer = _editGetLayer(_edit.selectedId);
    if (!layer) return;
    _edit.resize = {
        handle:  handle,
        id:      layer.id,
        startMX: e.clientX,
        startMY: e.clientY,
        startW:  parseFloat(layer.width)      || 200,
        startH:  parseFloat(layer.height)     || 200,
        startX:  parseFloat(layer.position_x) || 0,
        startY:  parseFloat(layer.position_y) || 0,
    };
    document.addEventListener('mousemove', _editOnResizeMove);
    document.addEventListener('mouseup',   _editOnResizeUp);
    e.preventDefault();
}

function _editOnResizeMove(e) {
    if (!_edit.resize) return;
    var r    = _edit.resize;
    var z    = _edit.zoom;
    var dx   = (e.clientX - r.startMX) / z;
    var dy   = (e.clientY - r.startMY) / z;
    var newW = r.startW, newH = r.startH, newX = r.startX, newY = r.startY;
    var h    = r.handle;

    if (h.includes('r'))  newW = Math.max(10, r.startW + dx);
    if (h.includes('l'))  { newW = Math.max(10, r.startW - dx); newX = r.startX + (r.startW - newW); }
    if (h.includes('b'))  newH = Math.max(10, r.startH + dy);
    if (h.includes('t'))  { newH = Math.max(10, r.startH - dy); newY = r.startY + (r.startH - newH); }

    if (_edit.snapEnabled) {
        newW = Math.round(newW / _edit.GRID) * _edit.GRID;
        newH = Math.round(newH / _edit.GRID) * _edit.GRID;
    }

    var layer = _editGetLayer(r.id);
    if (!layer) return;
    layer.width = newW; layer.height = newH; layer.position_x = newX; layer.position_y = newY;

    var el = _edit.rootEl ? _edit.rootEl.querySelector('#edit-layer-' + r.id) : null;
    if (el) {
        el.style.width = newW + 'px'; el.style.height = newH + 'px';
        el.style.left  = newX + 'px'; el.style.top    = newY + 'px';
    }
    _editRenderInspector();
}

async function _editOnResizeUp() {
    if (!_edit.resize) return;
    var id = _edit.resize.id;
    _edit.resize = null;
    document.removeEventListener('mousemove', _editOnResizeMove);
    document.removeEventListener('mouseup',   _editOnResizeUp);
    var layer = _editGetLayer(id);
    if (layer) { await _editSaveLayer(layer); }
}

function _editStartRotate(e) {
    var layer = _editGetLayer(_edit.selectedId);
    if (!layer) return;
    var el   = _edit.rootEl ? _edit.rootEl.querySelector('#edit-layer-' + layer.id) : null;
    if (!el) return;
    var rect = el.getBoundingClientRect();
    var cx   = rect.left + rect.width  / 2;
    var cy   = rect.top  + rect.height / 2;
    var startAngle = Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI;
    var startRot   = parseFloat(layer.rotation) || 0;

    _edit.rotate = { id: layer.id, cx, cy, startAngle, startRot };
    document.addEventListener('mousemove', _editOnRotateMove);
    document.addEventListener('mouseup',   _editOnRotateUp);
    e.preventDefault();
}

function _editOnRotateMove(e) {
    if (!_edit.rotate) return;
    var r    = _edit.rotate;
    var angle = Math.atan2(e.clientY - r.cy, e.clientX - r.cx) * 180 / Math.PI;
    var delta = angle - r.startAngle;
    var newRot = (r.startRot + delta) % 360;
    if (_edit.snapEnabled) newRot = Math.round(newRot / 15) * 15; // 15° snap

    var layer = _editGetLayer(r.id);
    if (!layer) return;
    layer.rotation = newRot;
    var el = _edit.rootEl ? _edit.rootEl.querySelector('#edit-layer-' + r.id) : null;
    if (el) el.style.transform = 'rotate(' + newRot + 'deg)';
    _editRenderInspector();
}

async function _editOnRotateUp() {
    if (!_edit.rotate) return;
    var id = _edit.rotate.id;
    _edit.rotate = null;
    document.removeEventListener('mousemove', _editOnRotateMove);
    document.removeEventListener('mouseup',   _editOnRotateUp);
    var layer = _editGetLayer(id);
    if (layer) { await _editSaveLayer(layer); }
}

// ══════════════════════════════════════════════════════════════════════════════
// LAYERS LIST (left panel)
// ══════════════════════════════════════════════════════════════════════════════

function _editRenderLayersList() {
    var R = _edit.rootEl;
    if (!R) return;
    var list = R.querySelector('#edit-layers-list');
    if (!list) return;

    var sorted = _edit.layers.slice().sort(function(a,b) { return (b.z_index||0) - (a.z_index||0); });
    list.innerHTML = sorted.map(function(layer) {
        var icons = {image:'🖼️', video:'🎬', text:'T', shape:'▭'};
        var icon  = icons[layer.type] || '▭';
        var isSelected = (layer.id == _edit.selectedId);
        return '<div class="edit-layer-item" data-id="'+layer.id+'" style="display:flex;align-items:center;gap:6px;padding:5px 6px;border-radius:5px;cursor:pointer;margin-bottom:2px;background:'+(isSelected?'var(--s3,#252A3A)':'transparent')+';color:var(--t2,#aaa);">' +
            '<span style="font-size:13px;flex-shrink:0;">' + icon + '</span>' +
            '<span style="flex:1;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:'+(isSelected?'var(--t1,#fff)':'var(--t2,#aaa)') +';">' + _e(layer.name) + '</span>' +
            '<span data-action="toggle-vis" data-id="'+layer.id+'" style="cursor:pointer;opacity:'+(layer.visible?1:0.3)+';font-size:12px;" title="Toggle visibility">👁</span>' +
            '<span data-action="toggle-lock" data-id="'+layer.id+'" style="cursor:pointer;opacity:'+(layer.locked?1:0.3)+';font-size:12px;" title="Toggle lock">' + (layer.locked?'🔒':'🔓') + '</span>' +
            '<span data-action="del-layer" data-id="'+layer.id+'" style="cursor:pointer;font-size:11px;color:var(--rd,#f87171);" title="Delete">✕</span>' +
        '</div>';
    }).join('');

    // Bind layer list events
    list.addEventListener('click', function(e) {
        var item = e.target.closest('.edit-layer-item');
        var action = e.target.dataset.action;
        var id = parseInt(e.target.dataset.id || (item && item.dataset.id));
        if (!id) return;

        if (action === 'toggle-vis')  { _editToggleLayerProp(id, 'visible'); return; }
        if (action === 'toggle-lock') { _editToggleLayerProp(id, 'locked');  return; }
        if (action === 'del-layer')   { _editDeleteLayer(id); return; }

        _edit.selectedId = id;
        _editRenderLayers();
        _editRenderInspector();
    });
}

// ══════════════════════════════════════════════════════════════════════════════
// INSPECTOR PANEL
// ══════════════════════════════════════════════════════════════════════════════

function _editRenderInspector() {
    var R = _edit.rootEl;
    if (!R) return;
    var ic = R.querySelector('#edit-inspector-content');
    if (!ic) return;

    var layer = _editGetLayer(_edit.selectedId);
    if (!layer) {
        ic.innerHTML = '<div style="color:var(--t3,#666);font-size:12px;text-align:center;padding:30px 0;">Select a layer to edit</div>';
        return;
    }

    var style   = layer.style   || {};
    var effects = Array.isArray(layer.effects) ? (layer.effects[0] || {}) : (layer.effects || {});

    var fields = _editInspectorSection('Transform', [
        _editField('position_x', 'X',  layer.position_x || 0, 'number'),
        _editField('position_y', 'Y',  layer.position_y || 0, 'number'),
        _editField('width',      'W',  layer.width  || 200,   'number'),
        _editField('height',     'H',  layer.height || 200,   'number'),
        _editField('rotation',   'Rotation', layer.rotation || 0, 'number'),
    ]);

    var typeFields = '';
    if (layer.type === 'image' || layer.type === 'video') {
        typeFields = _editInspectorSection('Appearance', [
            _editField('opacity',    'Opacity',    style.opacity    !== undefined ? style.opacity    : 100, 'range', 0, 100),
            _editField('brightness', 'Brightness', style.brightness !== undefined ? style.brightness : 100, 'range', 0, 200),
            _editField('contrast',   'Contrast',   style.contrast   !== undefined ? style.contrast   : 100, 'range', 0, 200),
            _editField('blur',       'Blur',       style.blur       !== undefined ? style.blur       : 0,   'range', 0, 20),
        ]);
    } else if (layer.type === 'text') {
        typeFields = _editInspectorSection('Text', [
            _editField('content',     'Content',     layer.content || '', 'textarea'),
            _editField('fontSize',    'Font Size',   style.fontSize    || 32,  'number'),
            _editField('color',       'Color',       style.color       || '#ffffff', 'color'),
            _editField('fontWeight',  'Bold',        style.fontWeight  === '700' ? true : false, 'checkbox'),
            _editField('lineHeight',  'Line Height', style.lineHeight  || 1.3, 'number'),
            _editField('textAlign',   'Align',       style.textAlign   || 'left', 'select', null, null, ['left','center','right']),
        ]);
    } else if (layer.type === 'shape') {
        typeFields = _editInspectorSection('Shape', [
            _editField('fill',         'Fill',          style.fill         || '#6C5CE7', 'color'),
            _editField('borderRadius', 'Corner Radius', style.borderRadius || 0,         'range', 0, 200),
            _editField('opacity',      'Opacity',       style.opacity      !== undefined ? style.opacity : 100, 'range', 0, 100),
        ]);
    }

    var effectField = _editInspectorSection('Effect', [
        _editField('effect_type', 'Type', effects.type || 'none', 'select', null, null, ['none','fade_in','fade_out','slide_in','slide_out','zoom_in','zoom_out']),
        _editField('effect_duration', 'Duration (s)', effects.duration || 1.0, 'number'),
    ]);

    ic.innerHTML = '<div style="font-size:11px;font-weight:700;color:var(--t1,#fff);margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;">' + _e(layer.name) + ' <span style="opacity:.5;font-weight:400;">[' + layer.type + ']</span></div>' +
        fields + typeFields + effectField;

    // Bind inspector changes
    ic.querySelectorAll('input,select,textarea').forEach(function(input) {
        input.addEventListener('change', function() { _editInspectorChanged(layer, input); });
        if (input.tagName === 'INPUT' && input.type === 'range') {
            input.addEventListener('input', function() {
                var vLabel = input.nextElementSibling;
                if (vLabel && vLabel.classList.contains('edit-range-val')) vLabel.textContent = input.value;
            });
        }
    });
}

function _editInspectorSection(title, fields) {
    return '<div style="margin-bottom:14px;">' +
        '<div style="font-size:10px;font-weight:700;color:var(--t3,#666);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">' + title + '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">' + fields.join('') + '</div>' +
    '</div>';
}

function _editField(key, label, value, type, min, max, options) {
    var id = 'edit-field-' + key;
    var style = 'width:100%;box-sizing:border-box;background:var(--s3,#252A3A);border:1px solid var(--bd,#2a2d3e);border-radius:4px;color:var(--t1,#fff);font-size:11px;padding:4px 6px;';
    var input = '';

    if (type === 'number') {
        input = '<input id="'+id+'" data-key="'+key+'" type="number" value="'+value+'" style="'+style+'">';
    } else if (type === 'range') {
        input = '<div style="display:flex;align-items:center;gap:4px;grid-column:1/-1;"><input id="'+id+'" data-key="'+key+'" type="range" min="'+(min||0)+'" max="'+(max||100)+'" value="'+value+'" style="flex:1;accent-color:var(--p,#6C5CE7);">' +
                '<span class="edit-range-val" style="font-size:10px;color:var(--t2,#aaa);min-width:28px;text-align:right;">'+value+'</span></div>';
    } else if (type === 'color') {
        input = '<input id="'+id+'" data-key="'+key+'" type="color" value="'+value+'" style="width:100%;height:28px;border:none;border-radius:4px;background:none;cursor:pointer;">';
    } else if (type === 'checkbox') {
        input = '<label style="display:flex;align-items:center;gap:4px;grid-column:1/-1;"><input id="'+id+'" data-key="'+key+'" type="checkbox"'+(value?' checked':'')+'> <span style="font-size:11px;color:var(--t2,#aaa);">'+label+'</span></label>';
        return input;
    } else if (type === 'select') {
        var opts = (options||[]).map(function(o) { return '<option value="'+o+'"'+(o===value?' selected':'')+'>'+o+'</option>'; }).join('');
        input = '<select id="'+id+'" data-key="'+key+'" style="'+style+'">'+opts+'</select>';
    } else if (type === 'textarea') {
        input = '<textarea id="'+id+'" data-key="'+key+'" rows="3" style="'+style+';grid-column:1/-1;resize:vertical;">'+_e(value)+'</textarea>';
        return '<div style="grid-column:1/-1;"><label style="font-size:10px;color:var(--t3,#666);display:block;margin-bottom:3px;">'+label+'</label>'+input+'</div>';
    }

    if (type === 'range') return input;
    return '<div><label style="font-size:10px;color:var(--t3,#666);display:block;margin-bottom:3px;">'+label+'</label>'+input+'</div>';
}

async function _editInspectorChanged(layer, input) {
    var key   = input.dataset.key;
    var value = input.type === 'checkbox' ? input.checked : (input.type === 'number' || input.type === 'range' ? parseFloat(input.value) : input.value);

    // Transform fields
    if (['position_x','position_y','width','height','rotation'].includes(key)) {
        layer[key] = value;
        _editRenderLayers();
        await _editSaveLayer(layer);
        return;
    }
    // Content field (text)
    if (key === 'content') {
        layer.content = value;
        _editRenderLayers();
        await _editSaveLayer(layer);
        return;
    }
    // Effect fields
    if (key === 'effect_type' || key === 'effect_duration') {
        var effects = Array.isArray(layer.effects) ? layer.effects : [];
        var ef = effects[0] || {};
        if (key === 'effect_type')     ef.type     = value;
        if (key === 'effect_duration') ef.duration = value;
        layer.effects = [ef];
        await _editPost('/layer/update', {id: layer.id, effects: layer.effects});
        return;
    }
    // Style fields
    layer.style = layer.style || {};
    if (key === 'fontWeight') {
        layer.style.fontWeight = value ? '700' : '400';
    } else {
        layer.style[key] = value;
    }
    _editRenderLayers();
    await _editPost('/layer/update', {id: layer.id, style: layer.style});
}

// ══════════════════════════════════════════════════════════════════════════════
// LAYER OPERATIONS
// ══════════════════════════════════════════════════════════════════════════════

async function _editAddLayer(type) {
    if (!_edit.project) return;
    _editPushHistory();
    var defaults = {
        image: { width:300, height:200, content:'', style:{opacity:100}, name:'Image Layer' },
        video: { width:400, height:225, content:'', style:{opacity:100}, name:'Video Layer' },
        text:  { width:300, height:80,  content:'Your text here', style:{fontSize:32,color:'#ffffff',fontWeight:'700'}, name:'Text Layer' },
        shape: { width:200, height:200, content:'', style:{fill:'#6C5CE7',borderRadius:0,opacity:100}, name:'Shape Layer' },
    };
    var def = defaults[type] || defaults.shape;
    var p   = _edit.project;
    var r   = await _editPost('/layer/add', Object.assign({}, def, {
        project_id: p.id,
        type:       type,
        position_x: Math.round((p.width  || 1080) / 2 - def.width  / 2),
        position_y: Math.round((p.height || 1080) / 2 - def.height / 2),
    }));
    if (r && r.success) {
        _edit.layers.push(r.layer);
        _edit.selectedId = r.layer.id;
        _editRenderLayers();
        _editRenderInspector();
        _editRenderTimeline();
        _edit.mode = 'select';
    }
}

async function _editDeleteLayer(id) {
    if (!confirm('Delete this layer?')) return;
    _editPushHistory();
    await _editPost('/layer/delete', {id: id});
    _edit.layers = _edit.layers.filter(function(l) { return l.id != id; });
    _edit.timeline = _edit.timeline.filter(function(t) { return t.layer_id != id; });
    if (_edit.selectedId == id) _edit.selectedId = null;
    _editRenderLayers();
    _editRenderInspector();
    _editRenderTimeline();
}

async function _editDuplicateLayer(id) {
    var layer = _editGetLayer(id);
    if (!layer || !_edit.project) return;
    _editPushHistory();
    var r = await _editPost('/layer/add', {
        project_id: _edit.project.id,
        type:       layer.type,
        name:       layer.name + ' copy',
        position_x: (layer.position_x || 0) + 20,
        position_y: (layer.position_y || 0) + 20,
        width:      layer.width,
        height:     layer.height,
        rotation:   layer.rotation,
        content:    layer.content,
        style:      layer.style   || {},
        effects:    layer.effects || [],
    });
    if (r && r.success) {
        _edit.layers.push(r.layer);
        _edit.selectedId = r.layer.id;
        _editRenderLayers();
        _editRenderInspector();
    }
}

async function _editToggleLayerProp(id, prop) {
    var layer = _editGetLayer(id);
    if (!layer) return;
    layer[prop] = layer[prop] ? 0 : 1;
    var up = {}; up[prop] = layer[prop];
    await _editPost('/layer/update', Object.assign({id: id}, up));
    _editRenderLayers();
}

async function _editSaveLayer(layer) {
    await _editPost('/layer/update', {
        id:         layer.id,
        position_x: layer.position_x,
        position_y: layer.position_y,
        width:      layer.width,
        height:     layer.height,
        rotation:   layer.rotation,
        content:    layer.content,
        style:      layer.style   || {},
        effects:    layer.effects || [],
    });
}

function _editStartInlineEdit(layer) {
    if (layer.type !== 'text') return;
    var el = _edit.rootEl ? _edit.rootEl.querySelector('#edit-layer-' + layer.id) : null;
    if (!el) return;
    var txt = el.firstChild;
    if (!txt) return;
    txt.contentEditable = 'true';
    txt.focus();
    txt.addEventListener('blur', async function() {
        txt.contentEditable = 'false';
        layer.content = txt.textContent;
        await _editSaveLayer(layer);
    }, {once: true});
}

// ══════════════════════════════════════════════════════════════════════════════
// TIMELINE (VIDEO)
// ══════════════════════════════════════════════════════════════════════════════

function _editRenderTimeline() {
    var R = _edit.rootEl;
    if (!R || !_edit.project || _edit.project.type !== 'video') return;
    var tracks = R.querySelector('#edit-timeline-tracks');
    if (!tracks) return;

    var duration = parseFloat(_edit.project.duration) || 30;
    var PX_PER_S = 60;
    var totalW   = duration * PX_PER_S + 80;
    tracks.style.width = totalW + 'px';

    // Time ruler
    var rulerHtml = '<div style="height:20px;position:sticky;top:0;background:var(--s2,#1E2230);border-bottom:1px solid var(--bd,#2a2d3e);display:flex;">' +
        '<div style="width:80px;flex-shrink:0;font-size:9px;color:var(--t3,#666);padding:4px 6px;">Layer</div>' +
        '<div style="flex:1;position:relative;overflow:hidden;">';
    for (var t = 0; t <= duration; t += (duration > 20 ? 5 : 1)) {
        rulerHtml += '<span style="position:absolute;left:'+(t*PX_PER_S)+'px;font-size:9px;color:var(--t3,#666);transform:translateX(-50%);">'+t+'s</span>';
    }
    rulerHtml += '</div></div>';

    // Tracks
    var sortedLayers = _edit.layers.slice().sort(function(a,b) { return (a.z_index||0)-(b.z_index||0); });
    var tracksHtml = sortedLayers.map(function(layer) {
        var clip = _edit.timeline.find(function(t) { return t.layer_id == layer.id; });
        var start = clip ? parseFloat(clip.start_time) : 0;
        var end   = clip ? parseFloat(clip.end_time)   : Math.min(5, duration);
        var clipLeft  = start * PX_PER_S;
        var clipWidth = Math.max(10, (end - start) * PX_PER_S);
        var isSelected = (layer.id == _edit.selectedId);

        return '<div class="edit-track-row" data-layer-id="'+layer.id+'" style="height:32px;display:flex;border-bottom:1px solid var(--bd,#2a2d3e);background:'+(isSelected?'rgba(108,92,231,.08)':'transparent')+'">' +
            '<div style="width:80px;flex-shrink:0;font-size:10px;color:var(--t2,#aaa);padding:8px 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;" data-select="'+layer.id+'">' + _e(layer.name) + '</div>' +
            '<div style="flex:1;position:relative;">' +
                '<div class="edit-clip" data-layer-id="'+layer.id+'" data-start="'+start+'" data-end="'+end+'" style="position:absolute;left:'+clipLeft+'px;width:'+clipWidth+'px;top:4px;height:24px;background:'+(isSelected?'var(--p,#6C5CE7)':'var(--bl,#3B8BF5)')+';border-radius:4px;cursor:move;display:flex;align-items:center;padding:0 6px;">' +
                    '<span style="font-size:9px;color:#fff;white-space:nowrap;overflow:hidden;">' + _e(layer.name) + '</span>' +
                    '<div class="edit-trim-left"  data-layer-id="'+layer.id+'" style="position:absolute;left:0;top:0;width:8px;height:100%;cursor:ew-resize;background:rgba(255,255,255,.3);border-radius:4px 0 0 4px;"></div>' +
                    '<div class="edit-trim-right" data-layer-id="'+layer.id+'" style="position:absolute;right:0;top:0;width:8px;height:100%;cursor:ew-resize;background:rgba(255,255,255,.3);border-radius:0 4px 4px 0;"></div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');

    tracks.innerHTML = rulerHtml + tracksHtml;

    // Bind timeline interactions
    tracks.addEventListener('click', function(e) {
        var sel = e.target.closest('[data-select]');
        if (sel) { _edit.selectedId = parseInt(sel.dataset.select); _editRenderLayers(); _editRenderInspector(); _editRenderTimeline(); }
    });

    tracks.querySelectorAll('.edit-trim-left, .edit-trim-right').forEach(function(handle) {
        handle.addEventListener('mousedown', function(e) { e.stopPropagation(); _editStartTrim(e, handle); });
    });

    tracks.querySelectorAll('.edit-clip').forEach(function(clip) {
        clip.addEventListener('mousedown', function(e) {
            if (e.target.classList.contains('edit-trim-left') || e.target.classList.contains('edit-trim-right')) return;
            _editStartClipDrag(e, clip);
        });
    });
}

function _editStartTrim(e, handle) {
    var isLeft = handle.classList.contains('edit-trim-left');
    var clip   = handle.closest('.edit-clip');
    if (!clip) return;
    var layerId = parseInt(clip.dataset.layerId);
    var timeClip = _edit.timeline.find(function(t) { return t.layer_id == layerId; });

    _edit.trimming = {
        isLeft: isLeft,
        layerId: layerId,
        startMX: e.clientX,
        startTime: isLeft ? parseFloat(clip.dataset.start) : parseFloat(clip.dataset.end),
        otherTime: isLeft ? parseFloat(clip.dataset.end) : parseFloat(clip.dataset.start),
    };
    document.addEventListener('mousemove', _editOnTrimMove);
    document.addEventListener('mouseup',   _editOnTrimUp);
    e.preventDefault();
}

function _editOnTrimMove(e) {
    if (!_edit.trimming) return;
    var PX_PER_S = 60;
    var dx = (e.clientX - _edit.trimming.startMX) / PX_PER_S;
    var newTime = Math.max(0, _edit.trimming.startTime + dx);
    newTime = Math.min(newTime, parseFloat(_edit.project.duration) || 30);
    newTime = Math.round(newTime * 10) / 10; // 0.1s precision

    var tc = _edit.timeline.find(function(t) { return t.layer_id == _edit.trimming.layerId; });
    if (!tc) return;
    if (_edit.trimming.isLeft) {
        tc.start_time = Math.min(newTime, _edit.trimming.otherTime - 0.1);
    } else {
        tc.end_time = Math.max(newTime, _edit.trimming.otherTime + 0.1);
    }
    _editRenderTimeline();
}

async function _editOnTrimUp() {
    if (!_edit.trimming) return;
    var layerId = _edit.trimming.layerId;
    _edit.trimming = null;
    document.removeEventListener('mousemove', _editOnTrimMove);
    document.removeEventListener('mouseup',   _editOnTrimUp);
    var tc = _edit.timeline.find(function(t) { return t.layer_id == layerId; });
    if (tc && _edit.project) {
        await _editPost('/timeline/update', { project_id: _edit.project.id, layer_id: layerId, start_time: tc.start_time, end_time: tc.end_time });
    }
}

function _editStartClipDrag(e, clip) {
    var layerId = parseInt(clip.dataset.layerId);
    var startMX = e.clientX;
    var origStart = parseFloat(clip.dataset.start);
    var origEnd   = parseFloat(clip.dataset.end);

    function onMove(ev) {
        var PX_PER_S = 60;
        var dx    = (ev.clientX - startMX) / PX_PER_S;
        var dur   = parseFloat(_edit.project.duration) || 30;
        var len   = origEnd - origStart;
        var start = Math.max(0, Math.min(dur - len, origStart + dx));
        var tc = _edit.timeline.find(function(t) { return t.layer_id == layerId; });
        if (tc) { tc.start_time = Math.round(start * 10)/10; tc.end_time = Math.round((start+len)*10)/10; }
        _editRenderTimeline();
    }

    async function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        var tc = _edit.timeline.find(function(t) { return t.layer_id == layerId; });
        if (tc && _edit.project) {
            await _editPost('/timeline/update', {project_id: _edit.project.id, layer_id: layerId, start_time: tc.start_time, end_time: tc.end_time});
        }
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    e.preventDefault();
}

// Timeline playback
function _editPlay() {
    _edit.playing = true;
    var R = _edit.rootEl;
    if (R) { var btn = R.querySelector('#edit-play-btn'); if (btn) btn.textContent = '⏸ Pause'; }
    var dur = parseFloat((_edit.project && _edit.project.duration) || 30);
    var step = 0.1;
    _edit.playTimer = setInterval(function() {
        _edit.currentTime = Math.min(dur, _edit.currentTime + step);
        _editUpdateScrubber();
        if (_edit.currentTime >= dur) { _editPause(); _edit.currentTime = 0; _editUpdateScrubber(); }
    }, step * 1000);
}

function _editPause() {
    _edit.playing = false;
    if (_edit.playTimer) { clearInterval(_edit.playTimer); _edit.playTimer = null; }
    var R = _edit.rootEl;
    if (R) { var btn = R.querySelector('#edit-play-btn'); if (btn) btn.textContent = '▶ Play'; }
}

function _editUpdateScrubber() {
    var R = _edit.rootEl;
    if (!R || !_edit.project) return;
    var dur  = parseFloat(_edit.project.duration) || 30;
    var pct  = Math.min(1, _edit.currentTime / dur);
    var scrub = R.querySelector('#edit-scrubber-pos');
    var track = R.querySelector('#edit-timeline-scrubber-track');
    if (scrub && track) { scrub.style.left = (pct * track.offsetWidth) + 'px'; }
    var label = R.querySelector('#edit-time-label');
    if (label) label.textContent = _edit.currentTime.toFixed(1) + 's / ' + dur + 's';
}

// ══════════════════════════════════════════════════════════════════════════════
// ASSETS PANEL
// ══════════════════════════════════════════════════════════════════════════════

function _editRenderAssets() {
    var R = _edit.rootEl;
    if (!R) return;
    var grid = R.querySelector('#edit-assets-grid');
    if (!grid) return;

    if (!_edit.assets.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;color:var(--t3,#666);font-size:11px;text-align:center;padding:20px;">No assets yet.</div>';
        return;
    }

    grid.innerHTML = _edit.assets.map(function(asset) {
        var isImg = (asset.type === 'image');
        var thumb = asset.thumbnail_url || asset.url;
        return '<div class="edit-asset-item" data-id="'+asset.id+'" data-url="'+_e(asset.url)+'" data-type="'+_e(asset.type)+'" data-name="'+_e(asset.name)+'" style="border:1px solid var(--bd,#2a2d3e);border-radius:6px;overflow:hidden;cursor:pointer;aspect-ratio:1;" title="'+_e(asset.name)+'">' +
            (thumb && isImg ? '<img src="'+_e(thumb)+'" style="width:100%;height:100%;object-fit:cover;">' : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--s3,#252A3A);font-size:24px;">' + (asset.type==='video' ? '🎬' : '📄') + '</div>') +
        '</div>';
    }).join('');

    grid.addEventListener('click', function(e) {
        var item = e.target.closest('.edit-asset-item');
        if (!item || !_edit.project) return;
        _editPost('/layer/add', {
            project_id: _edit.project.id,
            type:       item.dataset.type,
            name:       item.dataset.name,
            content:    item.dataset.url,
            width:      300,
            height:     200,
            position_x: Math.round((_edit.project.width||1080)/2 - 150),
            position_y: Math.round((_edit.project.height||1080)/2 - 100),
            style:      {opacity:100},
        }).then(function(r) {
            if (r && r.success) {
                _edit.layers.push(r.layer);
                _edit.selectedId = r.layer.id;
                _editRenderLayers();
                _editRenderInspector();
            }
        });
    });
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORT
// ══════════════════════════════════════════════════════════════════════════════

async function _editExport() {
    if (!_edit.project) return;
    if (_edit.project.type === 'video') {
        _editToast('Queuing video export to worker…', 'info');
        var r = await _editPost('/export', {project_id: _edit.project.id, format: 'mp4'});
        if (r && r.success) {
            _editToast('Video export queued! Check exports when ready.', 'success');
        } else {
            _editToast((r && r.error) || 'Video export failed.', 'error');
        }
        return;
    }

    // Image export: draw layers onto an HTML canvas and download
    var p = _edit.project;
    var exportCanvas = document.createElement('canvas');
    exportCanvas.width  = p.width  || 1080;
    exportCanvas.height = p.height || 1080;
    var ctx = exportCanvas.getContext('2d');

    // White background
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, exportCanvas.width, exportCanvas.height);

    var sorted = _edit.layers.slice().sort(function(a,b){ return (a.z_index||0)-(b.z_index||0); });

    for (var i = 0; i < sorted.length; i++) {
        var layer = sorted[i];
        if (!layer.visible) continue;
        var style = layer.style || {};
        var x = parseFloat(layer.position_x) || 0;
        var y = parseFloat(layer.position_y) || 0;
        var w = parseFloat(layer.width)      || 200;
        var h = parseFloat(layer.height)     || 200;
        var rot = (parseFloat(layer.rotation) || 0) * Math.PI / 180;

        ctx.save();
        ctx.translate(x + w/2, y + h/2);
        ctx.rotate(rot);
        ctx.globalAlpha = (style.opacity !== undefined) ? style.opacity / 100 : 1;

        if (layer.type === 'shape') {
            ctx.fillStyle = style.fill || '#6C5CE7';
            var r = parseFloat(style.borderRadius) || 0;
            ctx.beginPath();
            if (r > 0) {
                ctx.roundRect(-w/2, -h/2, w, h, r);
            } else {
                ctx.rect(-w/2, -h/2, w, h);
            }
            ctx.fill();
        } else if (layer.type === 'text') {
            var fontSize = parseFloat(style.fontSize) || 32;
            ctx.font = (style.fontWeight||'700') + ' ' + fontSize + 'px ' + (style.fontFamily || 'sans-serif');
            ctx.fillStyle = style.color || '#000000';
            ctx.textAlign = style.textAlign || 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(layer.content || '', -w/2 + 8, 0);
        } else if (layer.type === 'image' && layer.content) {
            // Note: CORS must allow the image origin for canvas tainting
            try {
                var img = new Image();
                img.crossOrigin = 'anonymous';
                await new Promise(function(resolve) {
                    img.onload = resolve; img.onerror = resolve;
                    img.src = layer.content;
                });
                ctx.drawImage(img, -w/2, -h/2, w, h);
            } catch(e) { /* skip if CORS blocked */ }
        }

        ctx.restore();
    }

    // Download
    var dataUrl = exportCanvas.toDataURL('image/png');
    var a = document.createElement('a');
    a.href = dataUrl;
    a.download = (_edit.project.name || 'export') + '.png';
    a.click();
    _editToast('Image exported!', 'success');
}

// ══════════════════════════════════════════════════════════════════════════════
// HISTORY (UNDO)
// ══════════════════════════════════════════════════════════════════════════════

function _editPushHistory() {
    _edit.history.push(JSON.stringify({layers: _edit.layers, timeline: _edit.timeline}));
    if (_edit.history.length > 30) _edit.history.shift();
}

function _editUndo() {
    if (!_edit.history.length) { _editToast('Nothing to undo', 'info'); return; }
    var state = JSON.parse(_edit.history.pop());
    _edit.layers   = state.layers   || [];
    _edit.timeline = state.timeline || [];
    _edit.selectedId = null;
    _editRenderLayers();
    _editRenderInspector();
    _editRenderTimeline();
    _editToast('Undone', 'success');
}

// ══════════════════════════════════════════════════════════════════════════════
// UTILS
// ══════════════════════════════════════════════════════════════════════════════

function _editGetLayer(id) {
    if (!id) return null;
    return _edit.layers.find(function(l) { return l.id == id; }) || null;
}

console.log('[MANUALEDIT888] v1.0.0 SPA loaded');
