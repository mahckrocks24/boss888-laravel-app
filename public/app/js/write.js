/**
 * WRITE888 — Content Engine SPA v1.0.0
 * Mounts into #write-root when nav('write') is called by Core.
 *
 * Views: dashboard | editor
 * Phase 1 scope: DB CRUD, editor (textarea), versioning, AI draft generation
 *
 * Architecture mirrors Creative888 SPA patterns exactly.
 */

// ── Claim engine slot ──────────────────────────────────────────────────────────
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['write'] = true;
console.log('[LuWrite] v2.0.0 — engine slot claimed');

// ═══════════════════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════════════════

var _wr = {
    view:         'dashboard',   // 'dashboard' | 'editor'
    items:        [],            // content item list
    currentItem:  null,         // item being edited
    versions:     [],           // versions for current item
    showVersions: false,        // toggle versions panel
    generating:   false,        // AI draft in flight
    saving:       false,        // save in flight
    savingVersion:false,        // version save in flight
    isDirty:      false,        // true when textarea/title has unsaved changes
    wsId:         1,            // workspace_id
    rootEl:       null,
    filterStatus: '',
    filterType:   '',
    _outlineTimer: null,        // debounce handle for outline refresh
};

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function _wrUrl(p) {
    var b = (window.LU_API_BASE || '/api');
    return b + '/api/write' + p;
}
// Streaming endpoints live in Core (lu/v1), not the Write Engine plugin (luwrite/v1)
function _luUrl(p) {
    var b = (window.LU_API_BASE || '/api');
    return b + '/api' + p;
}
function _wrNonce() { return (window.LU_CFG && '') || ''; }
function _wrHeaders() {
    return { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')};
}
function _e(s) {
    return (s || '').toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
function _fmtDate(dt) {
    if (!dt) return '—';
    var d = new Date((dt + '').replace(' ', 'T'));
    if (isNaN(d.getTime())) return dt;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function _fmtTime(dt) {
    if (!dt) return '—';
    var d = new Date((dt + '').replace(' ', 'T'));
    if (isNaN(d.getTime())) return dt;
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

async function _wrGet(path) {
    var res = await fetch(_wrUrl(path), { method: 'GET', headers: _wrHeaders() });
    var body;
    try { body = await res.json(); } catch(e) { body = {}; }
    // Throw on HTTP error OR on explicit success:false from backend
    if (!res.ok || (body && body.success === false)) {
        throw new Error((body && body.message) || 'HTTP ' + res.status);
    }
    return body;
}
async function _wrPost(path, data) {
    // AI generation can take 30-60s via DeepSeek. 95s matches PHP lu_runtime_q timeout.
    var timeout = path.includes('/draft') || path.includes('/ai/improve') || path.includes('/ai/improve') ? 95000 : 20000;
    var ctrl    = new AbortController();
    var timer   = setTimeout(function() { ctrl.abort(); }, timeout);
    try {
        var res  = await fetch(_wrUrl(path), { method:'POST', headers:_wrHeaders(), body:JSON.stringify(data), signal:ctrl.signal });
        var body; try { body = await res.json(); } catch(e) { body = {}; }
        return body;
    } catch(e) {
        if (e.name === 'AbortError') return { success:false, error:'Request timed out — AI generation is taking longer than expected. Please retry.' };
        return { success:false, error:e.message };
    } finally {
        clearTimeout(timer);
    }
}
async function _wrPut(path, data) {
    var res = await fetch(_wrUrl(path), {
        method: 'PUT', headers: _wrHeaders(), body: JSON.stringify(data)
    });
    var body;
    try { body = await res.json(); } catch(e) { body = {}; }
    return body;
}
async function _wrDelete(path) {
    var res = await fetch(_wrUrl(path), { method: 'DELETE', headers: _wrHeaders() });
    var body;
    try { body = await res.json(); } catch(e) { body = {}; }
    return body;
}

// ── Toast ──────────────────────────────────────────────────────────────────────
function _wrToast(msg, type) {
    type = type || 'info';
    var el = document.getElementById('wr-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'wr-toast';
        el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;';
        document.body.appendChild(el);
    }
    var colors = { success: 'var(--ac)', error: 'var(--rd,#e74c3c)', info: 'var(--p)', warning: 'var(--am,#f39c12)' };
    var t = document.createElement('div');
    t.style.cssText = 'background:var(--s2,#1e2030);border:1px solid ' + (colors[type] || colors.info) +
        ';border-radius:10px;padding:10px 16px;color:var(--t1,#e0e0e0);font-size:13px;' +
        'box-shadow:0 4px 16px rgba(0,0,0,.4);max-width:340px;pointer-events:auto;';
    t.textContent = msg;
    el.appendChild(t);
    setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 3500);
}

// ── Content type labels ────────────────────────────────────────────────────────
var _WR_TYPES = {
    'blog_article':          'Blog Article',
    'service_page':          'Service Page',
    'location_page':         'Location Page',
    'landing_page_copy':     'Landing Page Copy',
    'website_section_copy':  'Website Section Copy',
    'email_campaign':        'Email Campaign',
    'email_sequence':        'Email Sequence',
    'social_caption':        'Social Caption',
    'ad_copy':               'Ad Copy',
    'product_description':   'Product Description',
    'case_study':            'Case Study',
    'testimonial_block':     'Testimonial Block',
    'faq_set':               'FAQ Set',
    'sales_script':          'Sales Script',
    'crm_message_template':  'CRM Message Template',
};

var _WR_STATUS_COLORS = {
    'draft':     'var(--t3,#888)',
    'review':    'var(--am,#f39c12)',
    'approved':  'var(--ac,#00E5A8)',
    'published': 'var(--p,#6C5CE7)',
    'archived':  'var(--t3,#666)',
};

// ═══════════════════════════════════════════════════════════════════════════════
// ENTRY POINT — called by Core nav('write')
// ═══════════════════════════════════════════════════════════════════════════════

window.writeLoad = async function(el) {
    if (!el) { console.error('[LuWrite] writeLoad: no element'); return; }
    _wr.rootEl = el;
    _wr.view   = 'dashboard';
    _wr.currentItem = null;
    console.log('[LuWrite] writeLoad() → #' + el.id);
    el.innerHTML = _wrLoadingHTML();
    try {
        await _wrBootstrap();
        _wrRender();
    } catch(e) {
        el.innerHTML = '<div style="padding:40px;text-align:center;color:var(--rd,#e74c3c)">' +
            'Write Engine failed to load: ' + _e(e.message) + '</div>';
        console.error('[LuWrite]', e);
    }
};

function _wrLoadingHTML() {
    return '<div style="display:flex;align-items:center;justify-content:center;height:300px;color:var(--t2,#aaa);gap:12px;">' +
        '<span style="font-size:22px;animation:spin 1s linear infinite;display:inline-block;">⟳</span>' +
        '<span style="font-size:14px;">Loading Write Engine…</span>' +
        '</div>' +
        '<style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
}

async function _wrBootstrap() {
    var params = '?workspace_id=' + encodeURIComponent(_wr.wsId) + '&limit=50';
    if (_wr.filterStatus) params += '&status='       + encodeURIComponent(_wr.filterStatus);
    if (_wr.filterType)   params += '&content_type=' + encodeURIComponent(_wr.filterType);
    var data = await _wrGet('/articles' + params).catch(function() { return { items: [] }; });
    _wr.items = data.items || [];
}

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER ORCHESTRATOR
// ═══════════════════════════════════════════════════════════════════════════════

function _wrRender() {
    if (!_wr.rootEl) return;
    _wr.rootEl.innerHTML = _wrShell();
    var content = document.getElementById('wr-main');
    if (!content) return;
    switch (_wr.view) {
        case 'dashboard': content.innerHTML = _wrViewDashboard(); _wrBindDashboard(); break;
        case 'editor':    content.innerHTML = _wrViewEditor();    _wrBindEditor();    break;
        default:          content.innerHTML = _wrViewDashboard(); _wrBindDashboard();
    }
}

// ── Shell: top bar + content area ─────────────────────────────────────────────
function _wrShell() {
    return '<div id="wr-wrap" style="display:flex;flex-direction:column;height:100%;min-height:0;background:var(--bg,#0F1117);color:var(--t1,#e0e0e0);font-family:\'DM Sans\',sans-serif;">' +
        '<div id="wr-topbar" style="display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid var(--bd,#2a2d3e);flex-shrink:0;">' +
            '<span style="font-size:18px;">'+window.icon("edit",14)+'</span>' +
            '<span style="font-weight:700;font-size:15px;color:var(--t1,#e0e0e0);">Write</span>' +
            '<span style="margin-left:auto;font-size:12px;color:var(--t3,#666);">WRITE888 v2.0.0</span>' +
        '</div>' +
        '<div id="wr-main" style="flex:1;min-height:0;overflow:auto;"></div>' +
    '</div>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════

function _wrViewDashboard() {
    var filterBar = '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">' +
        // Status filter
        '<select id="wr-filter-status" style="' + _wrSelectStyle() + '">' +
            '<option value="">All Statuses</option>' +
            '<option value="draft"' + (_wr.filterStatus === 'draft' ? ' selected' : '') + '>Draft</option>' +
            '<option value="review"' + (_wr.filterStatus === 'review' ? ' selected' : '') + '>In Review</option>' +
            '<option value="approved"' + (_wr.filterStatus === 'approved' ? ' selected' : '') + '>Approved</option>' +
            '<option value="published"' + (_wr.filterStatus === 'published' ? ' selected' : '') + '>Published</option>' +
        '</select>' +
        // Type filter
        '<select id="wr-filter-type" style="' + _wrSelectStyle() + '">' +
            '<option value="">All Types</option>' +
            Object.keys(_WR_TYPES).map(function(k) {
                return '<option value="' + k + '"' + (_wr.filterType === k ? ' selected' : '') + '>' + _WR_TYPES[k] + '</option>';
            }).join('') +
        '</select>' +
        '<button id="wr-filter-btn" style="' + _wrBtnStyle('secondary') + '">Filter</button>' +
        '<button id="wr-create-btn" style="' + _wrBtnStyle('primary') + ';margin-left:auto;">' +
            '+ New Content' +
        '</button>' +
    '</div>';

    var stats = _wrDashStats();

    var tableRows = _wr.items.length === 0
        ? '<tr><td colspan="6" style="text-align:center;padding:48px;color:var(--t3,#666);">' +
            'No content yet. Click <strong>+ New Content</strong> to get started.' +
          '</td></tr>'
        : _wr.items.map(function(item) {
            var statusColor = _WR_STATUS_COLORS[item.status] || 'var(--t3,#888)';
            return '<tr class="wr-row" data-id="' + item.id + '" style="cursor:pointer;border-bottom:1px solid var(--bd,#2a2d3e);transition:background .15s;">' +
                '<td style="padding:12px 14px;font-weight:600;color:var(--t1,#e0e0e0);">' + _e(item.title || 'Untitled') + '</td>' +
                '<td style="padding:12px 14px;font-size:12px;color:var(--t2,#aaa);">' + _e(_WR_TYPES[item.content_type] || item.content_type) + '</td>' +
                '<td style="padding:12px 14px;">' +
                    '<span style="font-size:11px;padding:3px 8px;border-radius:20px;background:' + statusColor + '22;color:' + statusColor + ';border:1px solid ' + statusColor + '44;">' +
                        (item.status || 'draft') +
                    '</span>' +
                '</td>' +
                '<td style="padding:12px 14px;font-size:12px;color:var(--t2,#aaa);">' + _e(item.language || 'en') + '</td>' +
                '<td style="padding:12px 14px;font-size:12px;color:var(--t3,#777);">' + _fmtDate(item.updated_at) + '</td>' +
                '<td style="padding:12px 14px;text-align:right;">' +
                    '<button class="wr-edit-btn" data-id="' + item.id + '" style="' + _wrBtnStyle('ghost') + ';padding:5px 10px;font-size:12px;">Edit</button>' +
                    '<button class="wr-delete-btn" data-id="' + item.id + '" style="' + _wrBtnStyle('danger') + ';padding:5px 10px;font-size:12px;margin-left:6px;">✕</button>' +
                '</td>' +
            '</tr>';
        }).join('');

    return '<div style="padding:24px;max-width:1200px;">' +
        // Header
        '<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">' +
            '<div>' +
                '<h2 style="margin:0;font-size:20px;font-weight:700;color:var(--t1,#e0e0e0);">Content Dashboard</h2>' +
                '<p style="margin:4px 0 0;font-size:13px;color:var(--t3,#777);">Create and manage all your written content</p>' +
            '</div>' +
        '</div>' +
        // Stats row
        stats +
        // Filter bar
        '<div style="margin:20px 0 16px;">' + filterBar + '</div>' +
        // Table
        '<div style="background:var(--s1,#161927);border:1px solid var(--bd,#2a2d3e);border-radius:12px;overflow:hidden;">' +
            '<table style="width:100%;border-collapse:collapse;">' +
                '<thead>' +
                    '<tr style="background:var(--s2,#1e2030);border-bottom:1px solid var(--bd,#2a2d3e);">' +
                        '<th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;">Title</th>' +
                        '<th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;">Type</th>' +
                        '<th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;">Status</th>' +
                        '<th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;">Lang</th>' +
                        '<th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;">Updated</th>' +
                        '<th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;">Actions</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody id="wr-items-tbody">' + tableRows + '</tbody>' +
            '</table>' +
        '</div>' +
        // Create modal (hidden)
        _wrCreateModal() +
    '</div>';
}

function _wrDashStats() {
    var total    = _wr.items.length;
    var drafts   = _wr.items.filter(function(i){ return i.status === 'draft'; }).length;
    var approved = _wr.items.filter(function(i){ return i.status === 'approved'; }).length;
    var published= _wr.items.filter(function(i){ return i.status === 'published'; }).length;
    var stats = [
        { label: 'Total Items',  value: total,     color: 'var(--p,#6C5CE7)' },
        { label: 'Drafts',       value: drafts,    color: 'var(--t3,#888)' },
        { label: 'Approved',     value: approved,  color: 'var(--ac,#00E5A8)' },
        { label: 'Published',    value: published, color: 'var(--am,#f39c12)' },
    ];
    return '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:4px;">' +
        stats.map(function(s) {
            return '<div style="background:var(--s1,#161927);border:1px solid var(--bd,#2a2d3e);border-radius:10px;padding:14px 16px;">' +
                '<div style="font-size:22px;font-weight:700;color:' + s.color + ';">' + s.value + '</div>' +
                '<div style="font-size:12px;color:var(--t3,#777);margin-top:4px;">' + s.label + '</div>' +
            '</div>';
        }).join('') +
    '</div>';
}

function _wrCreateModal() {
    return '<div id="wr-create-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:none;align-items:center;justify-content:center;">' +
        '<div style="background:var(--s1,#161927);border:1px solid var(--bd,#2a2d3e);border-radius:16px;padding:28px;width:480px;max-width:95vw;">' +
            '<h3 style="margin:0 0 20px;font-size:17px;font-weight:700;color:var(--t1,#e0e0e0);">Create New Content</h3>' +
            '<div style="margin-bottom:14px;">' +
                '<label style="' + _wrLabelStyle() + '">Title</label>' +
                '<input id="wr-new-title" type="text" placeholder="e.g. Top 10 SEO Tips for 2025" style="' + _wrInputStyle() + '">' +
            '</div>' +
            '<div style="margin-bottom:14px;">' +
                '<label style="' + _wrLabelStyle() + '">Content Type</label>' +
                '<select id="wr-new-type" style="' + _wrSelectStyle() + ';width:100%;">' +
                    Object.keys(_WR_TYPES).map(function(k) {
                        return '<option value="' + k + '">' + _WR_TYPES[k] + '</option>';
                    }).join('') +
                '</select>' +
            '</div>' +
            '<div style="margin-bottom:14px;">' +
                '<label style="' + _wrLabelStyle() + '">Target Audience <span style="color:var(--t3,#777);">(optional)</span></label>' +
                '<input id="wr-new-audience" type="text" placeholder="e.g. Small business owners" style="' + _wrInputStyle() + '">' +
            '</div>' +
            '<div style="margin-bottom:20px;">' +
                '<label style="' + _wrLabelStyle() + '">Language</label>' +
                '<select id="wr-new-lang" style="' + _wrSelectStyle() + ';width:100%;">' +
                    '<option value="en">English</option>' +
                    '<option value="ar">Arabic</option>' +
                    '<option value="fr">French</option>' +
                    '<option value="es">Spanish</option>' +
                '</select>' +
            '</div>' +
            '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
                '<button id="wr-modal-cancel" style="' + _wrBtnStyle('secondary') + '">Cancel</button>' +
                '<button id="wr-modal-create" style="' + _wrBtnStyle('primary') + '">Create & Open Editor</button>' +
            '</div>' +
        '</div>' +
    '</div>';
}

function _wrBindDashboard() {
    // Create button
    var createBtn = document.getElementById('wr-create-btn');
    if (createBtn) createBtn.addEventListener('click', function() {
        var modal = document.getElementById('wr-create-modal');
        if (modal) { modal.style.display = 'flex'; }
        setTimeout(function() {
            var t = document.getElementById('wr-new-title');
            if (t) t.focus();
        }, 50);
    });

    // Modal cancel
    var cancelBtn = document.getElementById('wr-modal-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
        var modal = document.getElementById('wr-create-modal');
        if (modal) modal.style.display = 'none';
    });

    // Modal backdrop close
    var modal = document.getElementById('wr-create-modal');
    if (modal) modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.style.display = 'none';
    });

    // Modal create confirm
    var createConfirm = document.getElementById('wr-modal-create');
    if (createConfirm) createConfirm.addEventListener('click', async function() {
        var title    = (document.getElementById('wr-new-title')    || {}).value || '';
        var ctype    = (document.getElementById('wr-new-type')     || {}).value || 'blog_article';
        var audience = (document.getElementById('wr-new-audience') || {}).value || '';
        var lang     = (document.getElementById('wr-new-lang')     || {}).value || 'en';

        if (!title.trim()) {
            _wrToast('Please enter a title.', 'error');
            return;
        }

        createConfirm.textContent = 'Creating…';
        createConfirm.disabled = true;

        var res = await _wrPost('/articles', {
            title:        title.trim(),
            content_type: ctype,
            audience:     audience.trim(),
            language:     lang,
            workspace_id: _wr.wsId,
        });

        createConfirm.textContent = 'Create & Open Editor';
        createConfirm.disabled = false;

        if (res && res.success && res.item) {
            modal.style.display = 'none';
            _wrToast('Content created!', 'success');
            _wrOpenEditor(res.item);
        } else {
            _wrToast((res && res.message) || 'Failed to create content.', 'error');
        }
    });

    // Enter key in title field
    var titleInput = document.getElementById('wr-new-title');
    if (titleInput) titleInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var btn = document.getElementById('wr-modal-create');
            if (btn) btn.click();
        }
    });

    // Edit buttons
    document.querySelectorAll('.wr-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            _wrLoadAndOpenEditor(parseInt(btn.dataset.id, 10));
        });
    });

    // Delete buttons
    document.querySelectorAll('.wr-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', async function(e) {
            e.stopPropagation();
            if (!confirm('Delete this content item? This cannot be undone.')) return;
            var res = await _wrDelete('/articles/' + btn.dataset.id + '?workspace_id=' + encodeURIComponent(_wr.wsId));
            if (res && res.success) {
                _wrToast('Content deleted.', 'success');
                _wr.items = _wr.items.filter(function(i) { return i.id !== parseInt(btn.dataset.id, 10); });
                var tbody = document.getElementById('wr-items-tbody');
                if (tbody) {
                    var row = tbody.querySelector('[data-id="' + btn.dataset.id + '"]');
                    if (row) row.remove();
                }
                // Re-render stats
                var statsEl = _wr.rootEl ? _wr.rootEl.querySelector('#wr-stats-block') : null;
                if (statsEl) statsEl.innerHTML = _wrDashStats();
            } else {
                _wrToast((res && res.message) || 'Delete failed.', 'error');
            }
        });
    });

    // Row hover effect
    document.querySelectorAll('.wr-row').forEach(function(row) {
        row.addEventListener('mouseenter', function() {
            row.style.background = 'var(--s2,#1e2030)';
        });
        row.addEventListener('mouseleave', function() {
            row.style.background = '';
        });
        row.addEventListener('click', function(e) {
            if (e.target.closest('.wr-edit-btn') || e.target.closest('.wr-delete-btn')) return;
            _wrLoadAndOpenEditor(parseInt(row.dataset.id, 10));
        });
    });

    // Filter
    var filterBtn = document.getElementById('wr-filter-btn');
    if (filterBtn) filterBtn.addEventListener('click', async function() {
        _wr.filterStatus = (document.getElementById('wr-filter-status') || {}).value || '';
        _wr.filterType   = (document.getElementById('wr-filter-type')   || {}).value || '';
        filterBtn.textContent = 'Loading…';
        filterBtn.disabled = true;
        await _wrBootstrap();
        _wrRender();
    });
}

async function _wrLoadAndOpenEditor(id) {
    var res = await _wrGet('/articles/' + id).catch(function(e) {
        _wrToast('Failed to load item: ' + e.message, 'error');
        return null;
    });
    if (res && res.success && res.item) {
        _wrOpenEditor(res.item);
    } else {
        _wrToast('Could not load content.', 'error');
    }
}

function _wrOpenEditor(item) {
    // Full state reset before mounting a new item — prevents ghost data
    // from a previously-viewed item bleeding into the new editor session.
    _wr.currentItem   = item;
    _wr.view          = 'editor';
    _wr.versions      = [];
    _wr.showVersions  = false;
    _wr.isDirty       = false;   // fresh item — nothing unsaved yet
    _wr.generating    = false;   // clear any in-flight flag from previous item
    _wr.saving        = false;
    _wr.savingVersion = false;
    // Cancel any pending outline debounce from previous textarea session
    clearTimeout(_wr._outlineTimer);
    _wr._outlineTimer = null;
    _wrRender();
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: EDITOR
// ═══════════════════════════════════════════════════════════════════════════════

function _wrViewEditor() {
    if (!_wr.currentItem) {
        return '<div style="padding:40px;color:var(--t3,#777);">No item loaded. <a href="#" id="wr-back-dash" style="color:var(--p,#6C5CE7);">← Go back</a></div>';
    }
    var item = _wr.currentItem;
    var plain = item.plain_text || '';

    // Parse outline from content_json
    var outline = _wrParseOutline(item.content_json);

    // S8/S9: Render stored blocks immediately after item loads
    // (runs after the HTML is inserted into DOM via setTimeout 0)

    // Left panel - outline
    var leftPanel = '<div id="wr-outline-panel" style="width:200px;flex-shrink:0;border-right:1px solid var(--bd,#2a2d3e);padding:16px;overflow-y:auto;">' +
        '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--t3,#777);margin-bottom:12px;">Outline</div>' +
        (outline.length === 0
            ? '<div style="font-size:12px;color:var(--t3,#666);">No sections yet.<br>Generate a draft or start writing.</div>'
            : outline.map(function(s, i) {
                return '<div style="padding:6px 8px;border-radius:6px;font-size:12px;color:var(--t2,#aaa);cursor:pointer;margin-bottom:2px;border-left:2px solid var(--bd,#2a2d3e);" ' +
                    'class="wr-outline-item" data-idx="' + i + '">' +
                    '<span style="color:var(--t3,#666);font-size:10px;text-transform:uppercase;">' + _e(s.type) + '</span><br>' +
                    _e((s.text || '').substring(0, 40) + ((s.text || '').length > 40 ? '…' : '')) +
                '</div>';
            }).join('')
        ) +
    '</div>';

    // Right panel - actions
    var rightPanel = '<div id="wr-actions-panel" style="width:220px;flex-shrink:0;border-left:1px solid var(--bd,#2a2d3e);padding:16px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;">' +
        // Status badge
        '<div style="background:var(--s2,#1e2030);border-radius:8px;padding:12px;">' +
            '<div style="font-size:11px;color:var(--t3,#777);margin-bottom:6px;">STATUS</div>' +
            '<select id="wr-status-sel" style="' + _wrSelectStyle() + ';width:100%;">' +
                '<option value="draft"' + (item.status === 'draft' ? ' selected' : '') + '>Draft</option>' +
                '<option value="review"' + (item.status === 'review' ? ' selected' : '') + '>In Review</option>' +
                '<option value="approved"' + (item.status === 'approved' ? ' selected' : '') + '>Approved</option>' +
                '<option value="published"' + (item.status === 'published' ? ' selected' : '') + '>Published</option>' +
            '</select>' +
        '</div>' +
        // Generate Draft button
        '<div style="background:var(--s2,#1e2030);border-radius:8px;padding:12px;">' +
            '<div style="font-size:11px;color:var(--t3,#777);margin-bottom:8px;">AI DRAFT</div>' +
            '<select id="wr-gen-tone" style="' + _wrSelectStyle() + ';width:100%;margin-bottom:8px;">' +
                '<option value="professional">Professional</option>' +
                '<option value="conversational">Conversational</option>' +
                '<option value="persuasive">Persuasive</option>' +
                '<option value="educational">Educational</option>' +
                '<option value="premium">Premium / Luxury</option>' +
            '</select>' +
            '<textarea id="wr-brief-input" placeholder="Brief / context (optional)…" ' +
                'style="width:100%;box-sizing:border-box;background:var(--s3,#252a3a);border:1px solid var(--s3,#252a3a);border-radius:6px;color:var(--t1,#fff);font-size:12px;padding:6px 8px;resize:vertical;min-height:44px;margin-bottom:8px;outline:none;" ' +
                'title="Describe what the content should cover. This is passed to the AI as context."></textarea>' +
            '<button id="wr-generate-btn" style="' + _wrBtnStyle('accent') + ';width:100%;justify-content:center;">' +
                '✦ Generate Draft' +
            '</button>' +
            '<div id="wr-gen-status" style="font-size:11px;color:var(--t3,#777);margin-top:6px;min-height:16px;"></div>' +
        '</div>' +
        // Media attachments (T2 2026-04-20) — referenced by AI generation prompt
        '<div style="background:var(--s2,#1e2030);border-radius:8px;padding:12px;">' +
            '<div style="font-size:11px;color:var(--t3,#777);margin-bottom:8px;">'+window.icon('attach',18)+' ATTACHED IMAGES</div>' +
            '<div id="wr-attachment-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px"></div>' +
            '<button type="button" onclick="_wrAttachImage()" style="' + _wrBtnStyle('ghost') + ';width:100%;justify-content:center;font-size:12px;padding:6px 10px">+ Add Image</button>' +
            '<div style="font-size:11px;color:var(--t3,#666);margin-top:8px;line-height:1.4">Attached images are referenced in the generated article.</div>' +
        '</div>' +
        // AI Improve / Rewrite panel
        '<div style="background:var(--s2,#1e2030);border-radius:8px;padding:12px;">' +
            '<div style="font-size:11px;color:var(--t3,#777);margin-bottom:8px;">AI EDIT</div>' +
            '<textarea id="wr-ai-instruction" placeholder="Instruction (optional)… e.g. make more concise" ' +
                'style="width:100%;box-sizing:border-box;background:var(--s3,#252a3a);border:1px solid var(--s3,#252a3a);border-radius:6px;color:var(--t1,#fff);font-size:12px;padding:6px 8px;resize:vertical;min-height:40px;margin-bottom:6px;outline:none;"></textarea>' +
            '<div style="display:flex;gap:6px;">' +
                '<button id="wr-improve-btn" style="' + _wrBtnStyle('secondary') + ';flex:1;justify-content:center;font-size:12px;">✎ Improve</button>' +
                '<button id="wr-rewrite-btn" style="' + _wrBtnStyle('secondary') + ';flex:1;justify-content:center;font-size:12px;">↺ Rewrite</button>' +
            '</div>' +
            '<div id="wr-edit-status" style="font-size:11px;color:var(--t3,#777);margin-top:6px;min-height:16px;"></div>' +
        '</div>' +
        // Save buttons
        '<div style="display:flex;flex-direction:column;gap:8px;">' +
            '<button id="wr-save-btn" style="' + _wrBtnStyle('primary') + ';width:100%;justify-content:center;">' +
                ''+window.icon("save",14)+' Save Content' +
            '</button>' +
            '<button id="wr-version-btn" style="' + _wrBtnStyle('secondary') + ';width:100%;justify-content:center;">' +
                ''+window.icon("tag",14)+' Save Version' +
            '</button>' +
        '</div>' +
        // Versions panel toggle
        '<div>' +
            '<button id="wr-versions-toggle" style="' + _wrBtnStyle('ghost') + ';width:100%;justify-content:center;font-size:12px;">' +
                (_wr.showVersions ? '▾ Hide Versions' : '▸ View Versions') +
            '</button>' +
            '<div id="wr-versions-list" style="' + (_wr.showVersions ? 'display:block;' : 'display:none;') + '">' +
                _wrVersionsHTML() +
            '</div>' +
        '</div>' +
        // Content info
        '<div style="background:var(--s2,#1e2030);border-radius:8px;padding:12px;font-size:11px;color:var(--t3,#777);">' +
            '<div style="margin-bottom:4px;"><strong style="color:var(--t2,#aaa);">Type:</strong> ' + _e(_WR_TYPES[item.content_type] || item.content_type) + '</div>' +
            '<div style="margin-bottom:4px;"><strong style="color:var(--t2,#aaa);">Lang:</strong> ' + _e(item.language || 'en') + '</div>' +
            (item.audience ? '<div style="margin-bottom:4px;"><strong style="color:var(--t2,#aaa);">Audience:</strong> ' + _e(item.audience) + '</div>' : '') +
            '<div><strong style="color:var(--t2,#aaa);">Updated:</strong> ' + _fmtTime(item.updated_at) + '</div>' +
        '</div>' +
    '</div>';

    // Center - editor
    var centerPanel = '<div id="wr-center-panel" style="flex:1;display:flex;flex-direction:column;min-width:0;">' +
        // Title bar
        '<div style="padding:14px 20px;border-bottom:1px solid var(--bd,#2a2d3e);display:flex;align-items:center;gap:12px;">' +
            '<button id="wr-back-btn" style="' + _wrBtnStyle('ghost') + ';padding:5px 10px;font-size:13px;">← Back</button>' +
            '<input id="wr-title-input" type="text" value="' + _e(item.title || '') + '" ' +
                'style="flex:1;background:transparent;border:none;outline:none;font-size:18px;font-weight:700;color:var(--t1,#e0e0e0);font-family:\'DM Sans\',sans-serif;">' +
            '<div id="wr-save-indicator" style="font-size:12px;color:var(--t3,#666);min-width:80px;text-align:right;"></div>' +
        '</div>' +
        // Writing canvas
        '<div style="flex:1;padding:20px;display:flex;flex-direction:column;min-height:0;">' +
            // Media toolbar (T2 2026-04-20)
            '<div id="wr-media-toolbar" style="display:flex;gap:8px;align-items:center;padding:8px 0 12px;border-bottom:1px solid var(--bd,#2a2d3e);margin-bottom:12px">' +
                '<button type="button" onclick="_wrInsertImage()" ' +
                    'style="background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);color:var(--t1,#e0e0e0);padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-family:var(--fb,system-ui);display:inline-flex;align-items:center;gap:6px"' +
                    ' title="Insert an image block at the end of the article">' +
                    '\uD83D\uDDBC Insert Image' +
                '</button>' +
                '<span style="font-size:11px;color:var(--t3,#666);margin-left:auto">Inserts as a new block</span>' +
            '</div>' +
            '<div id="wr-block-renderer" style="flex:1;overflow-y:auto;min-height:400px;padding:0;"></div>' +
            '<textarea id="wr-editor-textarea" ' +
                'placeholder="Start writing, or click \'Generate Draft\' to create AI-powered content…" ' +
                'style="display:none;flex:1;width:100%;box-sizing:border-box;background:transparent;border:none;outline:none;' +
                'resize:none;font-size:15px;line-height:1.7;color:var(--t1,#e0e0e0);font-family:\'DM Sans\',sans-serif;min-height:400px;">' +
                _e(plain) +
            '</textarea>' +
            '<div style="margin-top:8px;display:flex;align-items:center;gap:16px;padding-top:8px;border-top:1px solid var(--bd,#2a2d3e);">' +
                '<span id="wr-word-count" style="font-size:12px;color:var(--t3,#666);">' + _wrWordCount(plain) + ' words</span>' +
                '<span id="wr-char-count" style="font-size:12px;color:var(--t3,#666);">' + plain.length + ' chars</span>' +
            '</div>' +
        '</div>' +
    '</div>';

    return '<div style="display:flex;height:100%;min-height:0;">' +
        leftPanel + centerPanel + rightPanel +
    '</div>';
}

function _wrParseOutline(content_json_str) {
    if (!content_json_str) return [];
    try {
        var obj = JSON.parse(content_json_str);
        return Array.isArray(obj.sections) ? obj.sections : [];
    } catch(e) {
        return [];
    }
}

function _wrWordCount(text) {
    if (!text || !text.trim()) return 0;
    return text.trim().split(/\s+/).length;
}

function _wrVersionsHTML() {
    if (_wr.versions.length === 0) {
        return '<div style="padding:8px 0;font-size:11px;color:var(--t3,#666);">No versions saved yet.</div>';
    }
    return _wr.versions.map(function(v) {
        return '<div style="padding:8px 0;border-bottom:1px solid var(--bd,#2a2d3e);font-size:11px;">' +
            '<div style="color:var(--p,#6C5CE7);font-weight:600;">v' + v.version_number + '</div>' +
            '<div style="color:var(--t3,#777);">' + _fmtTime(v.created_at) + '</div>' +
            (v.change_summary ? '<div style="color:var(--t2,#aaa);margin-top:2px;">' + _e(v.change_summary) + '</div>' : '') +
        '</div>';
    }).join('');
}

function _wrBindEditor() {
    // S9: Render stored blocks (or placeholder) immediately after editor mounts
    _wrRenderStoredBlocks(_wr.currentItem);
    // T2 2026-04-20: reset + render attachment list for this editor session.
    if (!_wr.attachments) _wr.attachments = [];
    if (typeof _wrRenderAttachmentList === 'function') _wrRenderAttachmentList();

    // Back button — NO silent auto-save.
    // If user has unsaved changes, show a native confirm dialog.
    // They must explicitly click Save first, or consciously discard.
    var backBtn = document.getElementById('wr-back-btn');
    if (backBtn) backBtn.addEventListener('click', async function() {
        if (_wr.isDirty) {
            var confirmed = confirm(
                'You have unsaved changes.\n\n' +
                'Click OK to discard them and go back.\n' +
                'Click Cancel to stay and save your work.'
            );
            if (!confirmed) return;  // user chose to stay — do nothing
        }
        _wr.isDirty = false;
        _wr.view = 'dashboard';
        _wr.currentItem = null;
        await _wrBootstrap();
        _wrRender();
    });

    // Title input — mark dirty on change
    var titleInput = document.getElementById('wr-title-input');
    if (titleInput) {
        titleInput.addEventListener('input', function() {
            _wr.isDirty = true;
        });
    }

    // Textarea - live word/char count + mark dirty
    var textarea = document.getElementById('wr-editor-textarea');
    if (textarea) {
        textarea.addEventListener('input', function() {
            _wr.isDirty = true;
            var val = textarea.value;
            var wc = document.getElementById('wr-word-count');
            var cc = document.getElementById('wr-char-count');
            if (wc) wc.textContent = _wrWordCount(val) + ' words';
            if (cc) cc.textContent = val.length + ' chars';
            // Update outline in left panel from textarea content
            _wrUpdateOutlineFromText(val);
        });
    }

    // Save Content button
    var saveBtn = document.getElementById('wr-save-btn');
    if (saveBtn) saveBtn.addEventListener('click', async function() {
        await _wrSaveContent(false);
    });

    // Save Version button
    var versionBtn = document.getElementById('wr-version-btn');
    if (versionBtn) versionBtn.addEventListener('click', async function() {
        await _wrSaveVersionAction();
    });

    // Generate Draft button
    var genBtn = document.getElementById('wr-generate-btn');
    if (genBtn) genBtn.addEventListener('click', async function() {
        await _wrGenerateDraftAction();
    });

    // Improve Draft button
    var improveBtn = document.getElementById('wr-improve-btn');
    if (improveBtn) improveBtn.addEventListener('click', async function() {
        await _wrImproveDraftAction();
    });

    // Rewrite Draft button
    var rewriteBtn = document.getElementById('wr-rewrite-btn');
    if (rewriteBtn) rewriteBtn.addEventListener('click', async function() {
        await _wrRewriteDraftAction();
    });

    // Versions toggle
    var versionsToggle = document.getElementById('wr-versions-toggle');
    if (versionsToggle) versionsToggle.addEventListener('click', async function() {
        _wr.showVersions = !_wr.showVersions;
        versionsToggle.textContent = _wr.showVersions ? '▾ Hide Versions' : '▸ View Versions';
        var list = document.getElementById('wr-versions-list');
        if (list) {
            if (_wr.showVersions) {
                list.style.display = 'block';
                if (_wr.versions.length === 0) {
                    // Load versions
                    await _wrLoadVersionsAction();
                }
                list.innerHTML = _wrVersionsHTML();
            } else {
                list.style.display = 'none';
            }
        }
    });

    // Status change - auto-update
    var statusSel = document.getElementById('wr-status-sel');
    if (statusSel) statusSel.addEventListener('change', function() {
        if (_wr.currentItem) _wr.currentItem._pendingStatus = statusSel.value;
    });

    // Outline item click — scroll textarea to section
    document.querySelectorAll('.wr-outline-item').forEach(function(item) {
        item.addEventListener('mouseenter', function() {
            item.style.background = 'var(--s2,#1e2030)';
            item.style.borderLeftColor = 'var(--p,#6C5CE7)';
        });
        item.addEventListener('mouseleave', function() {
            item.style.background = '';
            item.style.borderLeftColor = 'var(--bd,#2a2d3e)';
        });
    });

    // Back link for no-item fallback
    var backDash = document.getElementById('wr-back-dash');
    if (backDash) backDash.addEventListener('click', function(e) {
        e.preventDefault();
        _wr.view = 'dashboard';
        _wrRender();
    });
}

// ── Save Content (to DB) ──────────────────────────────────────────────────────
// _wrAutoSave() removed in v1.0.1 patch.
// Silent auto-save on navigation was replaced with an explicit unsaved-changes
// confirm dialog in _wrBindEditor(). All saves are now user-initiated only.

async function _wrSaveContent(showToast) {
    if (!_wr.currentItem || _wr.saving) return;
    _wr.saving = true;

    var saveBtn   = document.getElementById('wr-save-btn');
    var indicator = document.getElementById('wr-save-indicator');

    if (saveBtn) { saveBtn.textContent = 'Saving…'; saveBtn.disabled = true; }
    if (indicator) indicator.textContent = '⟳ Saving…';

    var textarea   = document.getElementById('wr-editor-textarea');
    var titleInput = document.getElementById('wr-title-input');
    var statusSel  = document.getElementById('wr-status-sel');

    // S8: Derive plain text from structured blocks if available, else from textarea
    var plain  = (_wr._blocks && _wr._blocks.length)
        ? _wrBlocksToPlain(_wr._blocks)
        : (textarea ? textarea.value : (_wr.currentItem.plain_text || ''));
    var title  = titleInput ? titleInput.value : (_wr.currentItem.title       || '');
    var status = statusSel  ? statusSel.value  : (_wr.currentItem.status      || 'draft');

    // Derive content_json from structured blocks (v3) or fallback to heuristic
    var content_json_str;
    if (_wr._blocks && _wr._blocks.length) {
        content_json_str = JSON.stringify({ version: 3, blocks: _wr._blocks });
    } else {
        var structuredJson = _wrTextToStructuredJson(plain);
        content_json_str = JSON.stringify(structuredJson);
    }

    var res = await _wrPut('/articles/' + _wr.currentItem.id, {
        title:        title,
        status:       status,
        plain_text:   plain,
        content_json: content_json_str,
        workspace_id: _wr.wsId,
    });

    _wr.saving = false;

    if (saveBtn) { saveBtn.textContent = ''+window.icon("save",14)+' Save Content'; saveBtn.disabled = false; }

    if (res && res.success) {
        _wr.currentItem = res.item;
        _wr.isDirty = false;  // ← clear dirty flag on confirmed save
        if (indicator) {
            indicator.textContent = '✓ Saved';
            indicator.style.color = 'var(--ac,#00E5A8)';
            setTimeout(function() {
                if (indicator) { indicator.textContent = ''; indicator.style.color = ''; }
            }, 2000);
        }
        if (showToast !== false) _wrToast('Content saved!', 'success');
        // Refresh outline
        _wrRefreshOutline(plain);
    } else {
        if (indicator) { indicator.textContent = '✕ Error'; indicator.style.color = 'var(--rd,#e74c3c)'; }
        _wrToast((res && res.message) || 'Save failed.', 'error');
    }
}

// ── Save Version ──────────────────────────────────────────────────────────────
async function _wrSaveVersionAction() {
    if (!_wr.currentItem || _wr.savingVersion) return;
    _wr.savingVersion = true;

    // First save content to DB
    await _wrSaveContent(false);

    var versionBtn = document.getElementById('wr-version-btn');
    if (versionBtn) { versionBtn.textContent = 'Saving…'; versionBtn.disabled = true; }

    var textarea   = document.getElementById('wr-editor-textarea');
    var plain      = textarea ? textarea.value : (_wr.currentItem.plain_text || '');
    // Always derive structured JSON from current textarea state before snapshotting
    var structured = _wrTextToStructuredJson(plain);

    var res = await _wrPost('/articles/' + _wr.currentItem.id + '/version/save', {
        plain_text:    plain,
        content_json:  JSON.stringify(structured),
        change_summary:'Manual save',
        workspace_id:  _wr.wsId,
    });

    _wr.savingVersion = false;
    if (versionBtn) { versionBtn.textContent = ''+window.icon("tag",14)+' Save Version'; versionBtn.disabled = false; }

    if (res && res.success) {
        if (res.version) _wr.versions.unshift(res.version);
        _wrToast('Version v' + (res.version ? res.version.version_number : '') + ' saved!', 'success');
        // Update versions list if visible
        if (_wr.showVersions) {
            var list = document.getElementById('wr-versions-list');
            if (list) list.innerHTML = _wrVersionsHTML();
        }
    } else {
        _wrToast((res && res.message) || 'Version save failed.', 'error');
    }
}

// ── Load Versions ─────────────────────────────────────────────────────────────
async function _wrLoadVersionsAction() {
    if (!_wr.currentItem) return;
    var res = await _wrGet('/articles/' + _wr.currentItem.id + '/versions')
        .catch(function() { return { versions: [] }; });
    _wr.versions = (res && res.versions) || [];
}

// ── Generate Draft ─────────────────────────────────────────────────────────────
async function _wrGenerateDraftAction() {
    if (!_wr.currentItem || _wr.generating) return;
    _wr.generating = true;

    var genBtn     = document.getElementById('wr-generate-btn');
    var genStatus  = document.getElementById('wr-gen-status');
    var toneSel    = document.getElementById('wr-gen-tone');
    var textarea   = document.getElementById('wr-editor-textarea');
    var titleInput = document.getElementById('wr-title-input');

    var tone   = toneSel    ? toneSel.value    : 'professional';
    var title  = titleInput ? titleInput.value.trim() : (_wr.currentItem.title || '');
    var ctype  = _wr.currentItem.content_type || 'blog_article';
    var intent = 'write_draft';
    var length = 'medium';

    // ── Terminal helper — resets all state ────────────────────────────────
    var stop = function(statusMsg, toastMsg, toastType) {
        _wr.generating = false;
        if (genBtn)    { genBtn.textContent = '✦ Generate Draft'; genBtn.disabled = false; }
        if (genStatus) {
            genStatus.textContent = statusMsg || '';
            if (statusMsg) setTimeout(function() { if (genStatus) genStatus.textContent = ''; }, 3000);
        }
        if (toastMsg) _wrToast(toastMsg, toastType || 'error');
    };

    if (!title) { stop('', 'Please enter a title first.', 'error'); return; }

    if (genBtn)    { genBtn.textContent = '⧧ Generating…'; genBtn.disabled = true; }
    if (genStatus) genStatus.textContent = 'Starting AI…';

    console.log('[WRITE888] generate clicked | title:', title, '| type:', ctype, '| tone:', tone);

    // FIX 3: Read brief from input before stream-init — brief was previously never set
    var briefInput = document.getElementById('wr-brief-input');
    window._wr_brief = briefInput ? briefInput.value.trim() : '';
    // T2 2026-04-20: append attached image URLs so the AI references them.
    if (_wr.attachments && _wr.attachments.length) {
        var urls = _wr.attachments.map(function(a){ return a.url; }).filter(Boolean);
        if (urls.length) {
            var ref = 'Reference these images in the article where relevant: ' + urls.join(', ');
            window._wr_brief = window._wr_brief ? (window._wr_brief + '\n\n' + ref) : ref;
        }
    }
    console.log('[WRITE888] brief:', window._wr_brief ? window._wr_brief.slice(0, 60) : '(empty)');

    // ── Patch 9: Single stream-init call to WP proxy ──────────────────────
    // WP adds WP auth + proxies to runtime /internal/write/stream-init.
    // Returns stream_token (HMAC-signed jti) + poll_url (runtime base).
    // Browser polls runtime DIRECTLY — WP is NOT in the streaming hot path.
    var initRes = await (async function() {
        try {
            var r = await fetch(_luUrl('/write/stream-init'), {
                method: 'POST', headers: _wrHeaders(),
                body: JSON.stringify({
                    title:        title,
                    brief:        (window._wr_brief || intent || ''),
                    tone:         tone,
                    length:       length,
                    content_type: ctype,
                    intent:       intent,
                    seo_brief:    _wr._seoBrief || {},
                }),
            });
            if (r.status === 401) return { success:false, error:'Session expired — please refresh the page.' };
            if (r.status === 429) return { success:false, error:'Rate limited — please wait before retrying.' };
            var body = await r.json();
            console.log('[WRITE888] stream-init HTTP', r.status, '| jti:', body.jti);
            return body;
        } catch(e) { console.error('[WRITE888] stream-init error:', e.message); return { success:false, error:e.message }; }
    })();

    if (!initRes || !initRes.success || !initRes.stream_token) {
        stop('✕ Failed', initRes.error || 'Could not start generation.', 'error');
        return;
    }

    var streamToken = initRes.stream_token;
    var jti         = initRes.jti;
    var pollBase    = (initRes.poll_url || '').replace(/\/+$/, '');

    if (!pollBase) {
        stop('✕ Config error', 'Runtime URL not configured — contact support.', 'error');
        return;
    }

    if (initRes.seo_brief) _wr._seoBrief = initRes.seo_brief;

    // Clear editor before stream begins
    if (textarea) { textarea.value = ''; textarea.dispatchEvent(new Event('input')); }
    if (genStatus) genStatus.textContent = 'Writing…';

    // ── State ──────────────────────────────────────────────────────────────────────
    var offset       = 0;
    var fullText     = '';
    var chunkBuffer  = [];
    var pollHandle   = null;
    var domHandle    = null;
    var timeoutHandle= null;
    var done         = false;
    var pollInterval = 350;
    var cancelBtn    = null;
    var retryCount   = 0;
    var MAX_RETRIES  = 3;

    var cleanup = function() {
        if (pollHandle)    { clearTimeout(pollHandle);    pollHandle   = null; }
        if (domHandle)     { clearInterval(domHandle);    domHandle    = null; }
        if (timeoutHandle) { clearTimeout(timeoutHandle); timeoutHandle= null; }
        if (cancelBtn)     { cancelBtn.remove();          cancelBtn    = null; }
    };

    // Cancel — POSTs to runtime /stream/cancel/:token (no WP secret needed)
    var genBtnParent = genBtn ? genBtn.parentNode : null;
    if (genBtnParent) {
        cancelBtn = document.createElement('button');
        cancelBtn.textContent = '✕ Cancel';
        cancelBtn.style.cssText = 'margin-top:6px;width:100%;background:transparent;border:1px solid var(--rd,#f87171);color:var(--rd,#f87171);border-radius:6px;padding:6px 0;cursor:pointer;font-size:12px;';
        cancelBtn.onclick = async function() {
            if (done) return;
            done = true; cleanup();
            stop('✕ Cancelled', 'Generation cancelled.', 'error');
            try { await fetch(pollBase + '/stream/cancel/' + encodeURIComponent(streamToken), { method:'POST' }); } catch(e) {}
        };
        genBtnParent.appendChild(cancelBtn);
    }

    // Hard 90-second timeout
    timeoutHandle = setTimeout(function() {
        if (done) return;
        done = true; cleanup();
        stop('✕ Timed out', 'Generation timed out — please retry.', 'error');
    }, 90000);

    // Batched DOM flush — every 500ms, independent of poll rate
    domHandle = setInterval(function() {
        if (chunkBuffer.length === 0) return;
        fullText += chunkBuffer.join('');
        chunkBuffer = [];
        if (textarea) {
            textarea.value = fullText;
            textarea.scrollTop = textarea.scrollHeight;
            textarea.dispatchEvent(new Event('input'));
        }
    }, 500);

    // ── Adaptive poll loop — polls runtime directly, no WP ──────────────────
    var doPoll = async function() {
        if (done) return;
        try {
            // Poll runtime directly. No auth header — HMAC token is in the URL path.
            var pollUrl = pollBase + '/stream/poll/' + encodeURIComponent(streamToken) +
                          '?offset=' + offset + '&_t=' + Date.now();
            var r = await fetch(pollUrl, { cache: 'no-store' });

            // Patch 10: explicit error codes from runtime
            if (r.status === 401) {
                done = true; cleanup();
                stop('✕ Expired', 'Stream session expired — please retry.', 'error'); return;
            }
            if (r.status === 404) {
                done = true; cleanup();
                stop('✕ Lost', 'Generation session not found — please retry.', 'error'); return;
            }
            if (r.status >= 500) {
                retryCount++;
                console.warn('[WRITE888] runtime', r.status, '| retry', retryCount, '/', MAX_RETRIES);
                if (retryCount >= MAX_RETRIES) { done = true; cleanup(); stop('✕ Error', 'Server error — please retry.', 'error'); return; }
                if (!done) pollHandle = setTimeout(doPoll, 1000);
                return;
            }

            retryCount = 0;
            var data = await r.json();

            var gotChunks = data.chunks && data.chunks.length > 0;
            if (gotChunks) {
                console.log('[WRITE888] poll got', data.chunks.length, 'chunks | offset:', data.offset, '| status:', data.status);
                for (var i = 0; i < data.chunks.length; i++) { chunkBuffer.push(data.chunks[i]); }
                offset = data.offset;
            } else {
                if (offset === 0) console.log('[WRITE888] poll: 0 chunks | status:', data.status);
            }

            pollInterval = gotChunks ? 350 : 600;

            if (data.complete || data.status === 'done') {

                done = true; cleanup();
                // Flush any remaining buffer immediately on done
                if (chunkBuffer.length > 0) {
                    fullText += chunkBuffer.join(''); chunkBuffer = [];
                }
                // Show raw text in textarea during structuring (fallback)
                if (textarea) { textarea.value = fullText; textarea.dispatchEvent(new Event('input')); }
                _wr.isDirty = true;
                _wrRefreshOutline(fullText);
                stop('✦ Structuring…', null, null);

                // S8: Structure plain text → JSON blocks (non-blocking, post-stream)
                (async function() {
                    var structRes = null;
                    try {
                        var sr = await fetch(_luUrl('/write/structure'), {
                            method:'POST', headers:_wrHeaders(),
                            body: JSON.stringify({
                                plain_text:   fullText,
                                title:        _wr.currentItem ? _wr.currentItem.title : '',
                                content_type: _wr.currentItem ? (_wr.currentItem.content_type || 'blog_article') : 'blog_article',
                                seo_brief:    _wr._seoBrief || {},
                                item_id:      _wr.currentItem ? _wr.currentItem.id : 0,
                            }),
                        });
                        structRes = await sr.json();
                    } catch(e) { console.warn('[WRITE888] structure call failed:', e.message); }

                    var blocks = (structRes && structRes.success && structRes.blocks) ? structRes.blocks : null;
                    _wr._blocks = blocks;

                    // Render blocks in main view
                    var renderer = _wr.rootEl ? _wr.rootEl.querySelector('#wr-block-renderer') : null;
                    if (blocks && renderer) {
                        _wrRenderBlocks(blocks, renderer);
                        renderer.style.display = 'block';
                        if (textarea) textarea.style.display = 'none';
                        // Update plain text in textarea from blocks
                        var updatedPlain = _wrBlocksToPlain(blocks);
                        if (textarea) textarea.value = updatedPlain;
                        fullText = updatedPlain;
                        _wrRefreshOutline(updatedPlain);
                        // S7: Show copy buttons toolbar
                        _wrShowCopyToolbar(blocks, renderer);
                    } else if (renderer) {
                        // Fallback: show plain text in block wrapper
                        renderer.innerHTML = '<div style="padding:20px;white-space:pre-wrap;color:var(--t2,#ccc);font-size:14px;">' + _e(fullText) + '</div>';
                        renderer.style.display = 'block';
                        if (textarea) textarea.style.display = 'none';
                    }

                    // S7: Run block-level repair loop after structuring (non-blocking)
                    if (blocks) {
                        (async function() {
                            try {
                                var repairRes = await fetch(_luUrl('/write/repair-run'), {
                                    method: 'POST', headers: _wrHeaders(),
                                    body: JSON.stringify({
                                        blocks:    blocks,
                                        seo_brief: _wr._seoBrief || {},
                                        item_id:   _wr.currentItem ? _wr.currentItem.id : 0,
                                        tone:      'professional',
                                    }),
                                });
                                var rd = await repairRes.json();
                                if (rd && rd.success && rd.repaired && Array.isArray(rd.blocks)) {
                                    // Update blocks and re-render
                                    _wr._blocks = rd.blocks;
                                    var renderer2 = _wr.rootEl ? _wr.rootEl.querySelector('#wr-block-renderer') : null;
                                    if (renderer2) {
                                        _wrRenderBlocks(rd.blocks, renderer2);
                                        _wrShowCopyToolbar(rd.blocks, renderer2);
                                    }
                                    // Update textarea plain text
                                    var ta2 = _wr.rootEl ? _wr.rootEl.querySelector('#wr-editor-textarea') : null;
                                    if (ta2) ta2.value = _wrBlocksToPlain(rd.blocks);
                                    // Score delta toast
                                    var badge = rd.score >= 85 ? ''+window.icon("info",14)+'' : rd.score >= 70 ? ''+window.icon("info",14)+'' : ''+window.icon("info",14)+'';
                                    var delta = rd.score_delta > 0 ? ' (+' + rd.score_delta + ' improved)' : '';
                                    _wrToast(badge + ' SEO ' + (rd.repaired ? 'Optimized' : 'Score') + ': ' + rd.score + '/100 (Grade ' + rd.grade + ')' + delta, 'success');
                                    console.log('[WRITE888] Repair complete | score:', rd.score, '| delta:', rd.score_delta, '| changes:', rd.changes);
                                } else if (rd && rd.success) {
                                    // Not repaired — show score only
                                    var badge = rd.score >= 85 ? ''+window.icon("info",14)+'' : rd.score >= 70 ? ''+window.icon("info",14)+'' : ''+window.icon("info",14)+'';
                                    _wrToast(badge + ' SEO Score: ' + rd.score + '/100 (Grade ' + rd.grade + ')', 'success');
                                }
                            } catch(e) {
                                console.warn('[WRITE888] repair-run failed (non-fatal):', e.message);
                            }
                        })();
                    }

                    stop('✦ Draft ready!', '✦ AI draft ready!', 'success');
                })();

                // S6: Run SEO score after generation completes (non-blocking)
                (async function() {
                    try {
                        var scoreRes = await fetch(_luUrl('/write/score'), {
                            method: 'POST', headers: _wrHeaders(),
                            body: JSON.stringify({
                                content:   fullText,
                                seo_brief: _wr._seoBrief || {},
                                item_id:   _wr.currentItem ? _wr.currentItem.id : 0,
                            }),
                        });
                        var scoreData = await scoreRes.json();
                        if (scoreData && scoreData.success && scoreData.data) {
                            var d = scoreData.data;
                            // S7: If repaired content returned, update editor silently
                            if (d.repaired && d.repaired_content) {
                                fullText = d.repaired_content;
                                _wr.isDirty = true;
                            }
                            // SEO toast
                            var badge = d.score >= 85 ? ''+window.icon("info",14)+'' : d.score >= 70 ? ''+window.icon("info",14)+'' : ''+window.icon("info",14)+'';
                            var toastMsg = d.repaired && d.repairs_applied && d.repairs_applied.length
                                ? badge + ' SEO Auto-optimized → ' + d.score + '/100 (Grade ' + d.grade + ')'
                                : badge + ' SEO Score: ' + d.score + '/100 (Grade ' + d.grade + ')';
                            _wrToast(toastMsg, 'success');
                            console.log('[WRITE888] SEO Score:', d.score, '| Grade:', d.grade, '| Repaired:', d.repaired);
                            if (_wr.currentItem) {
                                _wr.currentItem.seo_score    = d.score;
                                _wr.currentItem.seo_repaired = d.repaired;
                            }
                        }
                    } catch(e) { /* non-fatal — score is informational only */ }
                })();

            } else if (data.status === 'failed') {
                done = true; cleanup();
                var errMsg = (data.error_message ? ': ' + data.error_message : '');
                stop('✕ Failed', 'AI generation failed' + errMsg + ' — please retry.', 'error');

            } else if (data.status === 'timeout') {
                done = true; cleanup();
                stop('✕ Timed out', 'Generation timed out — AI took too long. Please retry.', 'error');

            } else if (data.status === 'aborted') {
                done = true; cleanup();
                stop('✕ Cancelled', 'Generation was cancelled.', 'error');
            }

        } catch (e) {
            console.error('[WRITE888] poll fetch error:', e.message);
            // Non-fatal — keep polling; transient network hiccup
        }

        // Reschedule with current (possibly adapted) interval
        if (!done) pollHandle = setTimeout(doPoll, pollInterval);
    };

    // Start first poll after short delay
    console.log('[WRITE888] polling starts in 400ms | jti:', jti);
    pollHandle = setTimeout(doPoll, 400);
}

async function _wrImproveDraftAction() {
    var textarea   = document.getElementById('wr-editor-textarea');
    var statusEl   = document.getElementById('wr-edit-status');
    var instruction = (document.getElementById('wr-ai-instruction') || {}).value || '';
    var toneSel    = document.getElementById('wr-gen-tone');
    var content    = textarea ? textarea.value.trim() : '';

    if (!content) { _wrToast('Nothing to improve — write or generate content first.', 'error'); return; }

    var btn = document.getElementById('wr-improve-btn');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    if (statusEl) statusEl.textContent = 'Improving…';

    var res = await _wrPost('/ai/improve', {
        content:     content,
        instruction: instruction,
        tone:        toneSel ? toneSel.value : 'professional',
    });

    if (btn) { btn.disabled = false; btn.textContent = '✎ Improve'; }
    if (res && res.success && (res.content || res.plain_text)) {
        var improved = res.content || res.plain_text;
        textarea.value = improved;
        _wr.isDirty = true;
        textarea.dispatchEvent(new Event('input'));
        _wrRefreshOutline(improved);
        if (statusEl) { statusEl.textContent = '✓ Improved'; setTimeout(function(){ statusEl.textContent=''; }, 2500); }
        _wrToast('Content improved!', 'success');
    } else {
        if (statusEl) { statusEl.textContent = '✕ Failed'; setTimeout(function(){ statusEl.textContent=''; }, 2500); }
        _wrToast((res && res.error) || 'Improve failed.', 'error');
    }
}

async function _wrRewriteDraftAction() {
    var textarea    = document.getElementById('wr-editor-textarea');
    var statusEl    = document.getElementById('wr-edit-status');
    var instruction = (document.getElementById('wr-ai-instruction') || {}).value || '';
    var toneSel     = document.getElementById('wr-gen-tone');
    var content     = textarea ? textarea.value.trim() : '';

    if (!content) { _wrToast('Nothing to rewrite — write or generate content first.', 'error'); return; }

    var btn = document.getElementById('wr-rewrite-btn');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    if (statusEl) statusEl.textContent = 'Rewriting…';

    var res = await _wrPost('/ai/improve', {
        content:     content,
        instruction: instruction,
        tone:        toneSel ? toneSel.value : 'professional',
    });

    if (btn) { btn.disabled = false; btn.textContent = '↺ Rewrite'; }
    if (res && res.success && (res.content || res.plain_text)) {
        var rewritten = res.content || res.plain_text;
        textarea.value = rewritten;
        _wr.isDirty = true;
        textarea.dispatchEvent(new Event('input'));
        _wrRefreshOutline(rewritten);
        if (statusEl) { statusEl.textContent = '✓ Rewritten'; setTimeout(function(){ statusEl.textContent=''; }, 2500); }
        _wrToast('Content rewritten!', 'success');
    } else {
        if (statusEl) { statusEl.textContent = '✕ Failed'; setTimeout(function(){ statusEl.textContent=''; }, 2500); }
        _wrToast((res && res.error) || 'Rewrite failed.', 'error');
    }
}

// ── Helpers: Text ↔ JSON ──────────────────────────────────────────────────────

/**
 * Convert plain text to structured content_json.
 *
 * Detection rules (applied per paragraph block, split on double newlines):
 *
 *   headline    — first block AND (≤ 120 chars, no sentence-ending punctuation,
 *                  no internal newline) OR ALL-CAPS short line
 *   subheadline — short (≤ 100 chars), ends with nothing or ":", no period,
 *                  appears after headline
 *   cta         — contains CTA keywords (Get, Start, Book, Buy, Call, Contact,
 *                  Subscribe, Download, Learn More, Sign Up, Try, etc.)
 *                  AND is short (≤ 120 chars)
 *   list        — block contains 3+ lines starting with bullet chars or numbers
 *   email_field — block starts with "Subject:", "Preheader:", "To:", "From:"
 *   label       — single short line ending with ":"  (e.g. "The Challenge:")
 *   intro       — second block when first is headline and block is ≤ 2 sentences
 *   conclusion  — last block, starts with conclusion keywords
 *   paragraph   — everything else
 *
 * This function is the single source of truth for JSON structure on save.
 * All saves derive content_json from this — plain_text is the user's canonical text.
 */
function _wrTextToStructuredJson(text) {
    if (!text || !text.trim()) {
        return { sections: [{ type: 'paragraph', text: '' }] };
    }

    var blocks = text.split(/\n\n+/).map(function(b) { return b.trim(); }).filter(Boolean);
    if (!blocks.length) {
        return { sections: [{ type: 'paragraph', text: text.trim() }] };
    }

    // CTA detection — two-tier approach to eliminate false positives:
    //
    // CTA_STRONG: multi-word phrases that are unambiguously CTAs regardless of position.
    //   "get started", "sign up", "call us", "book your..." etc.
    //   These fire wherever they appear in the block.
    //
    // CTA_WEAK: single verbs (start, try, join, view...) that are only CTAs when they
    //   open the block in imperative position.
    //   "Start now." → CTA ${window.icon('check',14)}
    //   "We recommend you start today." → paragraph ${window.icon('check',14)} (doesn't match CTA_WEAK.match)
    var CTA_STRONG = /\b(get started|buy now|add to cart|call us|contact us|subscribe|learn more|sign up|get a quote|request a quote|register now|claim your|schedule a|book (a|your|now)|download (now|free|your))\b/i;
    var CTA_WEAK   = /^(start|try|join|view|shop|apply|get|download|call|book|contact|discover|explore|find out|reach out)\b/i;
    var CONCLUSION_START = /^(in (conclusion|summary|closing)|to (summarize|wrap up|sum up)|finally,|overall,|key takeaway|conclusion:|summary:)/i;
    var EMAIL_FIELD = /^(subject:|preheader:|to:|from:|reply-to:|bcc:|cc:)/i;
    var LABEL_ONLY  = /^[^.!?\n]{3,80}:\s*$/;  // single line ending with colon

    function _isBulletList(block) {
        var lines = block.split('\n');
        if (lines.length < 3) return false;
        var bulletLines = lines.filter(function(l) {
            return /^\s*([-*•·▪▸›»]|\d+[.)]) /.test(l.trim());
        });
        return bulletLines.length >= Math.ceil(lines.length * 0.6);
    }

    function _isHeadline(block, idx) {
        if (block.indexOf('\n') !== -1) return false; // multi-line = not headline
        if (block.length > 140) return false;
        // No sentence-ending punctuation (period or !) — questions OK for headlines
        if (/[.!]$/.test(block)) return false;
        if (idx === 0) return true; // first block is almost always the headline
        // All-caps short block = heading anywhere
        if (block === block.toUpperCase() && block.length < 80 && /[A-Z]/.test(block)) return true;
        return false;
    }

    function _isSubheadline(block, idx, prevType) {
        if (block.indexOf('\n') !== -1) return false;
        if (block.length > 120) return false;
        if (/[.!]$/.test(block)) return false;
        // Must follow a headline or another subheadline
        return (prevType === 'headline' || prevType === 'subheadline');
    }

    function _isCTA(block) {
        if (block.length > 160) return false;
        if (block.indexOf('\n') !== -1) return false;
        // Strong: unambiguous multi-word phrase anywhere in block
        if (CTA_STRONG.test(block)) return true;
        // Weak: imperative verb only fires when it opens the block (position 0)
        // Prevents "We recommend you start..." from false-firing
        if (CTA_WEAK.test(block.trim())) return true;
        return false;
    }

    var sections = [];
    var prevType = '';

    for (var i = 0; i < blocks.length; i++) {
        var b = blocks[i];
        var type = 'paragraph'; // default

        if (EMAIL_FIELD.test(b.split('\n')[0])) {
            // Email field (subject line, preheader, etc.)
            var fieldName = b.split(':')[0].toLowerCase().trim().replace(/[- ]/g, '_');
            type = fieldName || 'email_field';

        } else if (LABEL_ONLY.test(b)) {
            type = 'label';

        } else if (_isBulletList(b)) {
            type = 'list';

        } else if (_isHeadline(b, i)) {
            type = 'headline';

        } else if (_isSubheadline(b, i, prevType)) {
            type = 'subheadline';

        } else if (i === 1 && prevType === 'headline') {
            // Second block after headline: intro (if not already classified)
            var sentences = b.split(/[.!?]+\s/).filter(Boolean);
            type = sentences.length <= 3 ? 'intro' : 'paragraph';

        } else if (i === blocks.length - 1 && CONCLUSION_START.test(b)) {
            type = 'conclusion';

        } else if (_isCTA(b)) {
            type = 'cta';
        }

        sections.push({ type: type, text: b });
        prevType = type;
    }

    return { sections: sections };
}

/**
 * Refresh the outline panel from plain text without full re-render.
 */

// ══════════════════════════════════════════════════════════════════════════════
// S8: STRUCTURED BLOCK RENDERER
// _wrRenderBlocks()  — renders JSON blocks into the editor panel DOM
// _wrBlocksToHtml()  — derives HTML string from blocks (for export/copy)
// _wrBlocksToPlain() — derives plain text from blocks (for clipboard)
// ══════════════════════════════════════════════════════════════════════════════

function _wrBlocksToHtml(blocks) {
    if (!Array.isArray(blocks)) return '';
    return blocks.map(function(b) {
        var t = b.type, esc = function(s) {
            return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        };
        if (t === 'h1') return '<h1>' + esc(b.text) + '</h1>';
        if (t === 'h2') return '<h2>' + esc(b.text) + '</h2>';
        if (t === 'h3') return '<h3>' + esc(b.text) + '</h3>';
        if (t === 'p')  return '<p>'  + esc(b.text) + '</p>';
        if (t === 'cta')return '<p class="cta">' + esc(b.text) + '</p>';
        if (t === 'ul' && Array.isArray(b.items))
            return '<ul>' + b.items.map(function(i){ return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
        if (t === 'ol' && Array.isArray(b.items))
            return '<ol>' + b.items.map(function(i){ return '<li>' + esc(i) + '</li>'; }).join('') + '</ol>';
        if (t === 'faq')
            return '<h3>' + esc(b.question) + '</h3><p>' + esc(b.answer) + '</p>';
        return '';
    }).filter(Boolean).join('\n');
}

function _wrBlocksToPlain(blocks) {
    if (!Array.isArray(blocks)) return '';
    return blocks.map(function(b) {
        var t = b.type;
        if (['h1','h2','h3','p','cta'].includes(t)) return b.text || '';
        if (['ul','ol'].includes(t) && Array.isArray(b.items))
            return b.items.map(function(i, idx) {
                return (t === 'ol' ? (idx+1) + '. ' : '• ') + i;
            }).join('\n');
        if (t === 'faq') return (b.question || '') + '\n' + (b.answer || '');
        return '';
    }).filter(Boolean).join('\n\n');
}

function _wrRenderBlocks(blocks, container) {
    if (!container || !Array.isArray(blocks)) return;
    var E = function(tag, attrs, text) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function(k) { el.setAttribute(k, attrs[k]); });
        if (text !== undefined) el.textContent = text;
        return el;
    };

    container.innerHTML = '';
    container.style.cssText = 'padding:20px;line-height:1.7;color:var(--t1,#fff);font-family:DM Sans,sans-serif;';

    blocks.forEach(function(block) {
        var t   = block.type;
        var el  = null;

        if (t === 'h1') {
            el = E('h1', {style:'font-size:26px;font-weight:700;margin:0 0 16px;color:var(--t1,#fff);font-family:Syne,sans-serif;'}, block.text);
        } else if (t === 'h2') {
            el = E('h2', {style:'font-size:20px;font-weight:600;margin:24px 0 10px;color:var(--ac,#00E5A8);'}, block.text);
        } else if (t === 'h3') {
            el = E('h3', {style:'font-size:16px;font-weight:600;margin:18px 0 8px;color:var(--t1,#fff);'}, block.text);
        } else if (t === 'p') {
            el = E('p', {style:'margin:0 0 14px;color:var(--t2,#ccc);font-size:14px;'}, block.text);
        } else if (t === 'cta') {
            el = E('p', {style:'margin:16px 0;padding:12px 16px;background:var(--p,#6C5CE7);border-radius:6px;color:#fff;font-weight:600;'}, block.text);
        } else if ((t === 'ul' || t === 'ol') && Array.isArray(block.items)) {
            el = document.createElement(t);
            el.style.cssText = 'margin:0 0 14px;padding-left:24px;color:var(--t2,#ccc);font-size:14px;';
            block.items.forEach(function(item) {
                var li = E('li', {style:'margin-bottom:4px;'}, item);
                el.appendChild(li);
            });
        } else if (t === 'faq') {
            var faqWrap = document.createElement('div');
            faqWrap.style.cssText = 'background:var(--s2,#1E2230);border-radius:6px;padding:12px 16px;margin:0 0 10px;border-left:3px solid var(--p,#6C5CE7);';
            var qEl = E('p', {style:'margin:0 0 6px;font-weight:600;color:var(--t1,#fff);font-size:14px;'}, block.question);
            var aEl = E('p', {style:'margin:0;color:var(--t2,#ccc);font-size:13px;'}, block.answer);
            faqWrap.appendChild(qEl);
            faqWrap.appendChild(aEl);
            container.appendChild(faqWrap);
            return;
        }
        if (el) container.appendChild(el);
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// S7: COPY / EXPORT TOOLBAR
// Shown after structured blocks are rendered. Three copy modes.
// ══════════════════════════════════════════════════════════════════════════════

function _wrShowCopyToolbar(blocks, anchorEl) {
    var existingToolbar = anchorEl ? anchorEl.previousElementSibling : null;
    if (existingToolbar && existingToolbar.id === 'wr-copy-toolbar') existingToolbar.remove();

    var toolbar = document.createElement('div');
    toolbar.id  = 'wr-copy-toolbar';
    toolbar.style.cssText = 'display:flex;gap:8px;padding:8px 12px;background:var(--s2,#1E2230);border-radius:6px;margin-bottom:8px;flex-wrap:wrap;';

    var makeBtn = function(label, onClick) {
        var btn = document.createElement('button');
        btn.textContent = label;
        btn.style.cssText = 'background:var(--s3,#252a3a);border:1px solid var(--bd,#2a2d3e);color:var(--t2,#ccc);border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer;';
        btn.onclick = onClick;
        return btn;
    };

    var copyToClip = function(text, btn, label) {
        navigator.clipboard.writeText(text).then(function() {
            btn.textContent = '✓ Copied!';
            btn.style.color = 'var(--ac,#00E5A8)';
            setTimeout(function() { btn.textContent = label; btn.style.color = ''; }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.textContent = '✓ Copied!';
            setTimeout(function() { btn.textContent = label; }, 2000);
        });
    };

    var htmlBtn   = makeBtn('⧉ Copy as HTML', null);
    var wpBtn     = makeBtn('🅦 Copy for WordPress', null);
    var plainBtn  = makeBtn(''+window.icon("more",14)+' Copy Plain Text', null);

    htmlBtn.onclick  = function() { copyToClip(_wrBlocksToHtml(blocks), htmlBtn,  '⧉ Copy as HTML'); };
    wpBtn.onclick    = function() { copyToClip(_wrBlocksToHtml(blocks), wpBtn,    '🅦 Copy for WordPress'); };
    plainBtn.onclick = function() { copyToClip(_wrBlocksToPlain(blocks), plainBtn,''+window.icon("more",14)+' Copy Plain Text'); };

    toolbar.appendChild(htmlBtn);
    toolbar.appendChild(wpBtn);
    toolbar.appendChild(plainBtn);

    if (anchorEl && anchorEl.parentNode) {
        anchorEl.parentNode.insertBefore(toolbar, anchorEl);
    }
}

// S9: Render stored structured JSON on item load (backward compat)
function _wrRenderStoredBlocks(item) {
    if (!item) return;
    var renderer = _wr.rootEl ? _wr.rootEl.querySelector('#wr-block-renderer') : null;
    if (!renderer) return;

    var blocks = null;

    // Try content_json v3 (structured blocks)
    if (item.content_json) {
        try {
            var parsed = JSON.parse(item.content_json);
            if (parsed && parsed.version === 3 && Array.isArray(parsed.blocks)) {
                blocks = parsed.blocks;
            }
        } catch(e) {}
    }

    // S9: Legacy fallback — convert existing plain_text to blocks for display
    if (!blocks && item.plain_text) {
        var fallback = _wrTextToStructuredJson(item.plain_text);
        // Convert from old sections format to new blocks format
        if (fallback && Array.isArray(fallback.sections)) {
            blocks = fallback.sections.map(function(s) {
                var typeMap = { headline: 'h1', subheadline: 'h2', paragraph: 'p', cta: 'cta' };
                return { type: typeMap[s.type] || 'p', text: s.text };
            });
        }
    }

    _wr._blocks = blocks;
    var textarea = _wr.rootEl ? _wr.rootEl.querySelector('#wr-editor-textarea') : null;

    if (blocks && blocks.length > 0) {
        _wrRenderBlocks(blocks, renderer);
        renderer.style.display = 'block';
        if (textarea) textarea.style.display = 'none';
        _wrShowCopyToolbar(blocks, renderer);
    } else {
        // Show placeholder
        renderer.innerHTML = '<p style="padding:40px;color:var(--t3,#777);text-align:center;">Start writing, or click \'Generate Draft\' to create AI-powered content…</p>';
        renderer.style.display = 'block';
        if (textarea) textarea.style.display = 'none';
    }
}

function _wrRefreshOutline(text) {
    var panel = document.getElementById('wr-outline-panel');
    if (!panel) return;
    var structured = _wrTextToStructuredJson(text);
    var outline = structured.sections || [];
    var html = '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--t3,#777);margin-bottom:12px;">Outline</div>';
    if (outline.length === 0) {
        html += '<div style="font-size:12px;color:var(--t3,#666);">No sections yet.</div>';
    } else {
        html += outline.slice(0, 20).map(function(s, i) {
            return '<div style="padding:6px 8px;border-radius:6px;font-size:12px;color:var(--t2,#aaa);margin-bottom:2px;border-left:2px solid var(--bd,#2a2d3e);">' +
                '<span style="color:var(--t3,#666);font-size:10px;text-transform:uppercase;">' + _e(s.type) + '</span><br>' +
                _e((s.text || '').substring(0, 40) + ((s.text || '').length > 40 ? '…' : '')) +
            '</div>';
        }).join('');
    }
    panel.innerHTML = html;
}

/**
 * Live outline update from textarea input (lightweight).
 */
function _wrUpdateOutlineFromText(val) {
    // Debounce — only update if a meaningful pause
    clearTimeout(_wr._outlineTimer);
    _wr._outlineTimer = setTimeout(function() {
        _wrRefreshOutline(val);
    }, 600);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SHARED STYLE HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function _wrBtnStyle(variant) {
    var base = 'display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;' +
        'font-size:13px;font-weight:600;cursor:pointer;border:none;transition:opacity .15s;font-family:inherit;';
    if (variant === 'primary') {
        return base + 'background:var(--p,#6C5CE7);color:#fff;';
    }
    if (variant === 'accent') {
        return base + 'background:var(--ac,#00E5A8);color:#0F1117;';
    }
    if (variant === 'secondary') {
        return base + 'background:var(--s2,#1e2030);color:var(--t1,#e0e0e0);border:1px solid var(--bd,#2a2d3e);';
    }
    if (variant === 'ghost') {
        return base + 'background:transparent;color:var(--t2,#aaa);';
    }
    if (variant === 'danger') {
        return base + 'background:rgba(231,76,60,.12);color:var(--rd,#e74c3c);border:1px solid rgba(231,76,60,.2);';
    }
    return base + 'background:var(--s2,#1e2030);color:var(--t1,#e0e0e0);';
}

function _wrInputStyle() {
    return 'width:100%;box-sizing:border-box;background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);' +
        'border-radius:8px;padding:9px 12px;color:var(--t1,#e0e0e0);font-size:13px;font-family:inherit;outline:none;';
}

function _wrSelectStyle() {
    return 'background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);border-radius:8px;' +
        'padding:7px 10px;color:var(--t1,#e0e0e0);font-size:13px;font-family:inherit;outline:none;cursor:pointer;';
}

function _wrLabelStyle() {
    return 'display:block;font-size:11px;font-weight:600;color:var(--t3,#777);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;';
}

// ── Media insertion (T2 2026-04-20) ────────────────────────────────
// _wr.attachments holds sidebar-attached images referenced by Generate Draft.
if (!_wr.attachments) _wr.attachments = [];

function _wrInsertImage() {
    if (typeof window.openMediaPicker !== 'function') {
        if (typeof showToast === 'function') showToast('Media picker unavailable', 'error');
        return;
    }
    window.openMediaPicker({ type: 'image', multiple: false, context: 'write' }, function (file) {
        if (!file) return;
        var url = file.file_url || file.url || file.src || '';
        if (!url) return;
        var block = {
            type: 'image',
            url: url,
            alt: file.alt_text || file.filename || '',
            caption: '',
            width:  file.width  || null,
            height: file.height || null,
            media_id: file.id || null
        };
        _wrAppendBlock(block);
    });
}

function _wrAppendBlock(block) {
    if (!_wr._blocks) _wr._blocks = [];
    _wr._blocks.push(block);
    _wr.isDirty = true;
    // Re-render via the same path used by _wrRenderStoredBlocks.
    var renderer = _wr.rootEl ? _wr.rootEl.querySelector('#wr-block-renderer') : document.getElementById('wr-block-renderer');
    if (renderer && typeof _wrRenderBlocks === 'function') {
        _wrRenderBlocks(_wr._blocks, renderer);
    }
    if (typeof showToast === 'function') showToast('Image inserted', 'success');
}

function _wrAttachImage() {
    if (typeof window.openMediaPicker !== 'function') {
        if (typeof showToast === 'function') showToast('Media picker unavailable', 'error');
        return;
    }
    window.openMediaPicker({ type: 'image', multiple: true, context: 'write' }, function (files) {
        if (!files) return;
        files = Array.isArray(files) ? files : [files];
        files.forEach(function (f) { _wrAddAttachment(f); });
    });
}

function _wrAddAttachment(file) {
    var url = file.file_url || file.url || file.src || '';
    if (!url) return;
    if (_wr.attachments.some(function (a) { return a.url === url; })) return; // dedupe
    _wr.attachments.push({
        url: url,
        filename: file.filename || file.name || 'image',
        media_id: file.id || null,
        width:  file.width  || null,
        height: file.height || null
    });
    _wrRenderAttachmentList();
}

function _wrRemoveAttachment(url) {
    _wr.attachments = (_wr.attachments || []).filter(function (a) { return a.url !== url; });
    _wrRenderAttachmentList();
}

function _wrRenderAttachmentList() {
    var list = document.getElementById('wr-attachment-list');
    if (!list) return;
    var items = _wr.attachments || [];
    if (!items.length) {
        list.innerHTML = '<div style="font-size:11px;color:var(--t3,#666);padding:6px 0">No attachments yet.</div>';
        return;
    }
    list.innerHTML = items.map(function (a) {
        var safeName = (a.filename || 'image').replace(/"/g, '&quot;').replace(/</g, '&lt;').slice(0, 40);
        var safeUrl = (a.url || '').replace(/"/g, '&quot;');
        return '<div style="display:flex;align-items:center;gap:8px;padding:6px;background:var(--s3,#252a3a);border-radius:4px;font-size:11px">' +
                 '<img src="' + safeUrl + '" style="width:28px;height:28px;object-fit:cover;border-radius:3px;flex-shrink:0" alt="">' +
                 '<span style="flex:1;color:var(--t2,#aaa);white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + safeName + '">' + safeName + '</span>' +
                 '<button type="button" onclick="_wrRemoveAttachment(\'' + safeUrl.replace(/'/g, '\\\'') + '\')" style="background:none;border:none;color:var(--t3,#777);cursor:pointer;font-size:14px;padding:2px 4px" title="Remove">\u2715</button>' +
               '</div>';
    }).join('');
}
