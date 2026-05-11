// LevelUp Core v3.2.2
// ═══════════════════════════════════════════════════════════════════
// LevelUp Core JS v3.0.2
// Engines: CRM, Marketing, Social, Calendar, SEO loaded on demand
// ═══════════════════════════════════════════════════════════════════
window.LU_LOADED_ENGINES = {};

// ── Missing global helpers (originally in WP core plugin) ──────────────────
function showPlanGate(message) {
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center';
  overlay.innerHTML = '<div style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:20px;padding:36px;max-width:440px;width:90%;text-align:center">'
    + '<div style="width:56px;height:56px;background:linear-gradient(135deg,#6C5CE7,#A78BFA);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:16px">\u2B50</div>'
    + '<div style="font-family:var(--fh,Syne),sans-serif;font-size:20px;font-weight:800;color:var(--t1,#E8EDF5);margin-bottom:8px">Upgrade Required</div>'
    + '<div style="font-size:14px;color:var(--t2,#8B97B0);line-height:1.6;margin-bottom:24px">' + message + '</div>'
    + '<div style="display:flex;gap:10px;justify-content:center">'
    + '<button onclick="this.closest(\u0027div[style*=fixed]\u0027).remove()" style="padding:10px 20px;border-radius:10px;border:1px solid var(--bd,#2a2d3e);background:transparent;color:var(--t2,#8B97B0);cursor:pointer;font-size:13px">Maybe Later</button>'
    + '<button onclick="window.open(\u0027/pricing\u0027,\u0027_blank\u0027);this.closest(\u0027div[style*=fixed]\u0027).remove()" style="padding:10px 24px;border-radius:10px;border:none;background:linear-gradient(135deg,#6C5CE7,#A78BFA);color:#fff;cursor:pointer;font-size:13px;font-weight:600">View Plans</button>'
    + '</div></div>';
  document.body.appendChild(overlay);
  overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
}

function loadingCard(h) {
  return '<div style="display:flex;align-items:center;justify-content:center;min-height:' + (h || 200) + 'px;color:var(--t2,#8B97B0)"><div style="text-align:center"><div style="font-size:24px;margin-bottom:8px;animation:spin 1s linear infinite">⟳</div><div style="font-size:13px">Loading…</div></div></div>';
}

function showToast(msg, type) {
  type = type || 'info';
  var colors = { success: '#00E5A8', error: '#F87171', warning: '#F59E0B', info: '#3B8BF5' };
  var bg = colors[type] || colors.info;
  var el = document.createElement('div');
  el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:' + bg + ';color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,.3);transition:opacity .3s;max-width:400px';
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 300); }, 3000);
}

// Alias for backwards compat — _luToast was referenced (~11 sites) but never defined.
// All approval / reject / bulk action handlers used `if (typeof _luToast === 'function') _luToast(msg)`
// which silently no-op'd. Now they route to showToast and the user sees feedback.
var _luToast = function(msg, type) {
  if (typeof showToast === 'function') showToast(msg, type || 'info');
};

function luConfirm(msg, title, confirmText, cancelText) {
  return new Promise(function(resolve) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = '<div style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:16px;padding:28px;max-width:400px;width:90%">' +
      '<div style="font-size:16px;font-weight:600;margin-bottom:12px;color:var(--t1,#E8EDF5)">' + (title || 'Confirm') + '</div>' +
      '<div style="font-size:13px;color:var(--t2,#8B97B0);margin-bottom:24px">' + msg + '</div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end">' +
      '<button id="_luc_cancel" style="padding:8px 16px;border-radius:8px;border:1px solid var(--bd,#2a2d3e);background:transparent;color:var(--t2,#8B97B0);cursor:pointer;font-size:13px">' + (cancelText || 'Cancel') + '</button>' +
      '<button id="_luc_ok" style="padding:8px 16px;border-radius:8px;border:none;background:var(--p,#6C5CE7);color:#fff;cursor:pointer;font-size:13px;font-weight:600">' + (confirmText || 'Confirm') + '</button>' +
      '</div></div>';
    document.body.appendChild(overlay);
    overlay.querySelector('#_luc_ok').onclick = function() { overlay.remove(); resolve(true); };
    overlay.querySelector('#_luc_cancel').onclick = function() { overlay.remove(); resolve(false); };
  });
}


function luPrompt(msg, title, defaultVal) {
  return new Promise(function(resolve) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = '<div style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:16px;padding:28px;max-width:440px;width:90%">' +
      '<div style="font-size:16px;font-weight:600;margin-bottom:12px;color:var(--t1,#E8EDF5)">' + (title || 'Input') + '</div>' +
      '<div style="font-size:13px;color:var(--t2,#8B97B0);margin-bottom:16px">' + msg + '</div>' +
      '<input id="_lup_input" style="width:100%;padding:10px 14px;border-radius:8px;border:1px solid var(--bd,#2a2d3e);background:var(--s2,#1E2230);color:var(--t1,#E8EDF5);font-size:13px;margin-bottom:20px;outline:none;box-sizing:border-box" value="' + (defaultVal || '') + '">' +
      '<div style="display:flex;gap:10px;justify-content:flex-end">' +
      '<button id="_lup_cancel" style="padding:8px 16px;border-radius:8px;border:1px solid var(--bd,#2a2d3e);background:transparent;color:var(--t2,#8B97B0);cursor:pointer;font-size:13px">Cancel</button>' +
      '<button id="_lup_ok" style="padding:8px 16px;border-radius:8px;border:none;background:var(--p,#6C5CE7);color:#fff;cursor:pointer;font-size:13px;font-weight:600">OK</button>' +
      '</div></div>';
    document.body.appendChild(overlay);
    var inp = overlay.querySelector('#_lup_input');
    setTimeout(function(){ inp.focus(); }, 50);
    overlay.querySelector('#_lup_ok').onclick = function(){ overlay.remove(); resolve(inp.value); };
    overlay.querySelector('#_lup_cancel').onclick = function(){ overlay.remove(); resolve(null); };
    inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ overlay.remove(); resolve(inp.value); } });
  });
}

// ESCAPE FUNCTIONS (6 variants across files -- consolidation needed):
// core.js: _luEsc(s) -- full escape with &quot; | _escB(s) -- was missing &quot;, now fixed | _bldSafeText(t) -- no-op String()
// crm.js: _e(s) -- full escape with &quot; (CANNOT rename -- const in crm.js global scope)
// calendar.js: _escCal(s) -- full escape with &quot;
// marketing.js: _esc(s) -- full escape
function _bldSafeText(t) { return String(t || ''); }
function friendlyError(e) { return (e && e.message) ? e.message : String(e || "Unknown error"); }


// ── Missing function stubs (WP features not yet migrated) ──────────────
function loadCampaignsView() {
  var el = document.getElementById('view-campaigns');
  if (!el) return;
  var inner = el.querySelector('div') || el;
  inner.innerHTML = loadingCard(300);
  _luFetch('GET', '/marketing/campaigns').then(function(r) { return r.json(); }).then(function(data) {
    var campaigns = data.campaigns || [];
    if (campaigns.length === 0) {
      inner.innerHTML = '<div style="padding:60px;text-align:center;color:var(--t3)"><div style="margin-bottom:14px;color:var(--t2)">' + window.icon('rocket', 40) + '</div><h3 style="color:var(--t1);margin:0 0 8px">No campaigns yet</h3><p style="font-size:13px">Create your first campaign from the Marketing engine.</p></div>';
    } else {
      var h = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Campaigns</h2>';
      campaigns.forEach(function(c) { h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;margin-bottom:10px;font-size:13px"><strong style="color:var(--t1)">' + (c.name || c.title || 'Campaign') + '</strong><div style="color:var(--t2);margin-top:4px">' + (c.status || 'draft') + '</div></div>'; });
      h += '</div>';
      inner.innerHTML = h;
    }
  }).catch(function(e) { inner.innerHTML = '<div style="padding:40px;text-align:center;color:var(--rd)">Failed to load campaigns</div>'; });
}

async function saveApiKeys() {
  var msg = document.getElementById('st-ai-keys-msg');
  if (msg) msg.textContent = 'Saving...';
  var keys = {};
  var deepseek = document.getElementById('st-deepseek-key');
  var openai = document.getElementById('st-openai-key');
  var minimax = document.getElementById('st-minimax-key');
  var stability = document.getElementById('st-stability-key');
  if (deepseek && deepseek.value && !deepseek.value.startsWith('*')) keys['deepseek_key'] = deepseek.value;
  if (openai && openai.value && !openai.value.startsWith('*')) keys['openai_key'] = openai.value;
  if (minimax && minimax.value && !minimax.value.startsWith('*')) keys['minimax_key'] = minimax.value;
  if (stability && stability.value && !stability.value.startsWith('*')) keys['stability_key'] = stability.value;
  if (Object.keys(keys).length === 0) { if (msg) msg.textContent = 'No changes to save.'; return; }
  try {
    var r = await _luFetch('POST', '/admin/config', keys);
    var d = await r.json();
    if (d.success !== false) {
      showToast('AI keys saved!', 'success');
      if (msg) msg.textContent = 'Saved!';
      // Mask the fields after saving
      if (deepseek && deepseek.value) deepseek.value = '••••••••';
      if (openai && openai.value) openai.value = '••••••••';
      if (minimax && minimax.value) minimax.value = '••••••••';
      if (stability && stability.value) stability.value = '••••••••';
    } else {
      if (msg) msg.textContent = 'Save failed.';
      showToast('Failed to save keys.', 'error');
    }
  } catch(e) {
    if (msg) msg.textContent = 'Error: ' + e.message;
    showToast('Error saving keys.', 'error');
  }
}

// ── Team Management ──────────────────────────────────────────────
async function loadTeam() {
  var list = document.getElementById('team-members-list');
  var invites = document.getElementById('team-invites-list');
  var seats = document.getElementById('team-seats-info');
  if (!list) return;

  list.innerHTML = loadingCard(200);
  try {
    var r = await _luFetch('GET', '/team/members');
    var d = await r.json();
    var members = d.members || d || [];

    if (members.length === 0) {
      list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--t3)"><div style="font-size:32px;margin-bottom:8px">\U0001f465</div><p>No team members yet. Invite your first teammate!</p></div>';
    } else {
      var h = '<div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px">Members (' + members.length + ')</div>';
      h += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
      h += '<thead><tr><th style="text-align:left;padding:10px 12px;color:var(--t3);font-size:10px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--bd)">Name</th><th style="text-align:left;padding:10px 12px;color:var(--t3);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--bd)">Email</th><th style="padding:10px 12px;color:var(--t3);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--bd)">Role</th><th style="padding:10px 12px;border-bottom:1px solid var(--bd)"></th></tr></thead><tbody>';
      members.forEach(function(m) {
        var isOwner = m.role === 'owner';
        h += '<tr><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04)">' + (m.name || m.user_name || '\u2014') + '</td>';
        h += '<td style="padding:10px 12px;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (m.email || m.user_email || '') + '</td>';
        h += '<td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)"><span style="font-size:11px;font-weight:600;color:' + (isOwner ? 'var(--p)' : 'var(--t2)') + ';text-transform:uppercase">' + m.role + '</span></td>';
        h += '<td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)">';
        if (!isOwner) h += '<button class="btn btn-outline btn-sm" style="font-size:10px;color:var(--rd)" onclick="removeMember(' + (m.user_id || m.id) + ')">Remove</button>';
        h += '</td></tr>';
      });
      h += '</tbody></table>';
      list.innerHTML = h;
    }

    // Load pending invites
    if (invites) {
      var ir = await _luFetch('GET', '/team/invites');
      var id = await ir.json();
      var inv = id.invites || id || [];
      if (inv.length > 0) {
        var ih = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:20px"><div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px">Pending Invites (' + inv.length + ')</div>';
        inv.forEach(function(i) {
          ih += '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px"><span style="color:var(--t2)">' + (i.email || '') + ' \u2014 ' + (i.role || 'member') + '</span><button class="btn btn-outline btn-sm" style="font-size:10px" onclick="cancelInvite(' + i.id + ')">Cancel</button></div>';
        });
        ih += '</div>';
        invites.innerHTML = ih;
      } else {
        invites.innerHTML = '';
      }
    }

    // Load seat info
    if (seats) {
      var sr = await _luFetch('GET', '/team/seats');
      var sd = await sr.json();
      seats.textContent = 'Seats: ' + (sd.used || members.length) + ' / ' + (sd.limit || 'unlimited');
    }
  } catch(e) {
    list.innerHTML = '<div style="padding:20px;color:var(--rd)">Failed to load team: ' + e.message + '</div>';
  }
}

function showInviteForm() {
  var form = document.getElementById('team-invite-form');
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

async function sendInvite() {
  var email = document.getElementById('invite-email');
  var role = document.getElementById('invite-role');
  var msg = document.getElementById('invite-msg');
  if (!email || !email.value) { showToast('Enter an email.', 'error'); return; }
  try {
    var r = await _luFetch('POST', '/team/invite', { email: email.value, role: role ? role.value : 'member' });
    var d = await r.json();
    if (r.ok) {
      showToast('Invite sent!', 'success');
      email.value = '';
      if (msg) msg.textContent = '';
      document.getElementById('team-invite-form').style.display = 'none';
      loadTeam();
    } else {
      if (msg) msg.textContent = d.message || d.error || 'Failed';
      showToast(d.message || 'Invite failed.', 'error');
    }
  } catch(e) { showToast('Error: ' + e.message, 'error'); }
}

async function removeMember(userId) {
  var ok = await luConfirm('Remove this member from your workspace?', 'Remove Member', 'Remove', 'Cancel');
  if (!ok) return;
  try {
    var r = await _luFetch('DELETE', '/team/members/' + userId);
    if (r.ok) { showToast('Member removed.', 'success'); loadTeam(); }
    else { var d = await r.json(); showToast(d.message || 'Failed.', 'error'); }
  } catch(e) { showToast('Error: ' + e.message, 'error'); }
}

async function cancelInvite(id) {
  try {
    var r = await _luFetch('DELETE', '/team/invites/' + id);
    if (r.ok) { showToast('Invite cancelled.', 'success'); loadTeam(); }
    else { showToast('Failed.', 'error'); }
  } catch(e) { showToast('Error.', 'error'); }
}

function luLogout() {
  localStorage.removeItem('lu_token');
  localStorage.removeItem('lu_refresh_token');
  localStorage.removeItem('lu_user_id');
  localStorage.removeItem('lu_user_name');
  localStorage.removeItem('lu_workspace_id');
  localStorage.removeItem('lu_onboarded');
  // Strip any hash (e.g. #signup) so _appBootstrap routes to login, not signup.
  // Using location.replace so the logged-in URL isn't kept in history.
  window.location.replace(window.location.pathname + window.location.search);
}

function loadSettings() {
  var el = document.getElementById("view-settings");
  if (!el) return;
  // Populate profile fields from cached user data
  _populateProfileFields();
}

async function _populateProfileFields() {
  try {
    var r = await _luFetch("GET", "/auth/me");
    var d = await r.json();
    if (d && d.user) {
      var nameEl = document.getElementById("prof-name");
      var emailEl = document.getElementById("prof-email");
      if (nameEl) nameEl.value = d.user.name || "";
      if (emailEl) emailEl.value = d.user.email || "";
    }
  } catch(e) { console.warn("[LU] Failed to load profile:", e); }
}

async function saveProfile() {
  var msg = document.getElementById("prof-save-msg");
  var nameVal = (document.getElementById("prof-name") || {}).value;
  var emailVal = (document.getElementById("prof-email") || {}).value;
  if (!nameVal && !emailVal) { if (msg) msg.textContent = "Nothing to save."; return; }
  var body = {};
  if (nameVal) body.name = nameVal;
  if (emailVal) body.email = emailVal;
  if (msg) msg.textContent = "Saving...";
  try {
    var r = await _luFetch("PUT", "/auth/profile", body);
    var d = await r.json();
    if (r.ok) {
      showToast("Profile updated!", "success");
      if (msg) msg.textContent = "Saved!";
      // Update sidebar name if present
      var sn = document.getElementById("user-display-name");
      if (sn && body.name) sn.textContent = body.name;
    } else {
      if (msg) msg.textContent = d.message || "Save failed.";
      showToast(d.message || "Failed to save profile.", "error");
    }
  } catch(e) {
    if (msg) msg.textContent = "Error: " + e.message;
    showToast("Error saving profile.", "error");
  }
}

async function changePassword() {
  var msg = document.getElementById("prof-pw-msg");
  var curPw = (document.getElementById("prof-cur-pw") || {}).value;
  var newPw = (document.getElementById("prof-new-pw") || {}).value;
  var confPw = (document.getElementById("prof-conf-pw") || {}).value;
  if (!curPw || !newPw || !confPw) {
    if (msg) msg.textContent = "All password fields are required.";
    return;
  }
  if (newPw.length < 8) {
    if (msg) msg.textContent = "New password must be at least 8 characters.";
    return;
  }
  if (newPw !== confPw) {
    if (msg) msg.textContent = "New passwords do not match.";
    return;
  }
  if (msg) msg.textContent = "Changing...";
  try {
    var r = await _luFetch("PUT", "/auth/password", {
      current_password: curPw,
      password: newPw,
      password_confirmation: confPw
    });
    var d = await r.json();
    if (r.ok) {
      showToast("Password changed!", "success");
      if (msg) msg.textContent = "Password changed!";
      document.getElementById("prof-cur-pw").value = "";
      document.getElementById("prof-new-pw").value = "";
      document.getElementById("prof-conf-pw").value = "";
    } else {
      if (msg) msg.textContent = d.message || "Failed.";
      showToast(d.message || "Failed to change password.", "error");
    }
  } catch(e) {
    if (msg) msg.textContent = "Error: " + e.message;
    showToast("Error changing password.", "error");
  }
}

async function loadWorkerQueue() {
  var el = document.getElementById('view-queue');
  if (!el) return;
  el.innerHTML = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Worker Queue</h2><div style="text-align:center;padding:40px;color:var(--t3)"><div class="spinner"></div></div></div>';
  try {
    var qr = await _luFetch('GET', '/system/queue');
    var qd = await safeJson(qr);
    var tr = await _luFetch('GET', '/tasks?limit=50');
    var td = await safeJson(tr);
    var tasks = (td && td.tasks) ? td.tasks : (Array.isArray(td) ? td : []);
    var stats = qd || {};
    var pending = stats.pending || 0;
    var running = stats.running || 0;
    var completed = stats.completed || 0;
    var failed = stats.failed || 0;

    var statusIcons = {pending:'\ud83d\udfe0',queued:'\ud83d\udfe0',running:'\ud83d\udd35',in_progress:'\ud83d\udd35',completed:'\ud83d\udfe2',failed:'\u274c',cancelled:'\u26aa',blocked:'\ud83d\udfe1',degraded:'\ud83d\udfe1',awaiting_approval:'\ud83d\udfe1'};
    var h = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Worker Queue</h2>';
    h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--am)">' + pending + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Pending</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--ac)">' + running + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Running</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--gn)">' + completed + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Completed</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--rd)">' + failed + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Failed</div></div>';
    h += '</div>';

    h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><div style="font-size:13px;font-weight:600;color:var(--t1)">Recent Tasks (' + tasks.length + ')</div><button onclick="loadWorkerQueue()" style="background:none;border:1px solid var(--bd);color:var(--t3);border-radius:7px;padding:5px 12px;font-size:11px;cursor:pointer">\u21bb Refresh</button></div>';

    if (tasks.length === 0) {
      h += '<div style="text-align:center;padding:40px;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:10px"><div style="font-size:32px;margin-bottom:8px">'+window.icon('ai',18)+'</div><p>No tasks recorded yet.</p></div>';
    } else {
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Status</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Engine</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Action</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Credits</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Created</th>';
      h += '<th style="padding:10px 14px"></th>';
      h += '</tr></thead><tbody>';
      tasks.forEach(function(t) {
        var icon = statusIcons[t.status] || '\u26aa';
        var dur = '';
        if (t.started_at && t.completed_at) {
          var ms = new Date(t.completed_at) - new Date(t.started_at);
          dur = ms < 1000 ? ms + 'ms' : (ms/1000).toFixed(1) + 's';
        }
        var retryBtn = t.status === 'failed' ? '<button onclick="wqRetryTask(' + t.id + ')" style="background:none;border:1px solid var(--bd);color:var(--am);border-radius:5px;padding:3px 8px;font-size:10px;cursor:pointer">\u21bb Retry</button>' : (dur ? '<span style="color:var(--t3);font-size:10px">' + dur + '</span>' : '');
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 14px;white-space:nowrap">' + icon + ' ' + (t.status||'—') + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t2)">' + (t.engine||'—') + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t1);font-family:monospace;font-size:11px">' + (t.action||'—') + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t2)">' + (t.credit_cost||0) + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t3)">' + (t.created_at ? new Date(t.created_at).toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '—') + '</td>';
        h += '<td style="padding:9px 14px;text-align:right">' + retryBtn + '</td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</div>';
    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Worker Queue</h2><div style="text-align:center;padding:40px;color:var(--rd)">Failed to load queue: ' + e.message + '</div></div>';
  }
}
window.wqRetryTask = async function(id) {
  try {
    var r = await _luFetch('POST', '/tasks/' + id + '/retry');
    if (r.ok) { showToast('Task queued for retry.', 'success'); loadWorkerQueue(); }
    else { var d = await safeJson(r); showToast((d && d.message) || 'Retry failed.', 'error'); }
  } catch(e) { showToast('Retry failed: ' + e.message, 'error'); }
};

function drawTaskLines() { /* no-op — canvas connector lines for kanban */ }


var _luEngineLoading = {};
// PATCH 10 Fix 1 — Define LU_API_BASE BEFORE any engine JS loads.
// Engine helpers (write.js _wrUrl, creative.js _crUrl, crm.js, etc.) build URLs
// as `(window.LU_API_BASE || '/api') + '/api/<engine>' + path`. The fallback
// '/api' produces double-/api like /api/api/write/articles → 404. Set to ''
// so the helpers produce the correct /api/write/articles path (the helpers
// always concatenate '/api/<engine>' themselves).
window.LU_API_BASE = '';
// Engine bust — file mtime from server so LiteSpeed cache can never serve stale engine JS
window.LU_ENGINE_BUST = (window.LU_CFG && window.LU_CFG.engineBust) ? window.LU_CFG.engineBust : (window.LU_CFG ? window.LU_CFG.version : '3.0.4');

// ── nav() queue stub ────────────────────────────────────────────────────────
// Defined immediately (top of file) so onclick="nav('...')" handlers that fire
// before the full script loads don't throw ReferenceError.
// The real nav() below replaces window.nav and drains this queue on load.
;(function() {
  var _navQueue = [];
  if (typeof window.nav !== 'function') {
    window.nav = function(view) {
      console.warn('[LevelUp] nav() called before core.js fully loaded — queuing:', view);
      _navQueue.push(view);
    };
    window._navQueue = _navQueue;
  }
})();

async function luLoadEngine(engine) {
  if (window.LU_LOADED_ENGINES[engine]) return;
  if (_luEngineLoading[engine]) return;
  _luEngineLoading[engine] = true;
  var urls = (window.LU_CFG && window.LU_CFG.engineUrls) || {};
  var base = (window.LU_CFG && window.LU_CFG.pluginUrl) ? window.LU_CFG.pluginUrl + '/assets/js/' : '';
  var lazy = ['crm','marketing','social','calendar','seo','write','creative','manualedit','blog','studio','studio-video'];
  if (lazy.indexOf(engine) === -1) { _luEngineLoading[engine] = false; return; }
  var src = urls[engine] || (base + engine + '.js');
  var isFallback = !urls[engine] && base;
  try {
    await new Promise(function(ok, fail) {
      var s = document.createElement('script');
      var bust = window.LU_ENGINE_BUST || (window.LU_CFG ? window.LU_CFG.version : '3.0.4');
      s.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'v=' + bust;
      s.onload = function() {
        if (isFallback) console.warn('[LevelUp] Engine JS fallback active for "' + engine + '" — engine plugin may not be loaded');
        ok();
      };
      s.onerror = function() { fail(new Error(engine + '.js failed')); };
      document.head.appendChild(s);
    });
  } catch(e) { console.error('[LU]', e); }
  _luEngineLoading[engine] = false;
}

// ═══════════════════════════════════════════════════════════════════
// ═══ Engine Execution Panel globals (from Phase 5) ═══
var ENG_AGENTS = {
  sarah:  { name:'Sarah',  role:'DMM',         color:'var(--p)' },
  james:  { name:'James',  role:'SEO',          color:'var(--bl)' },
  priya:  { name:'Priya',  role:'Content',      color:'var(--pu)' },
  marcus: { name:'Marcus', role:'Social',       color:'var(--am)' },
  elena:  { name:'Elena',  role:'CRM',          color:'var(--rd)' },
  alex:   { name:'Alex',   role:'Tech SEO',     color:'var(--ac)' },
};
var ENG_TOOL_AGENTS = {
  // prefill tool options per agent selection
  sarah:  ['autonomous_goal','list_goals','create_campaign','schedule_campaign','pause_goal'],
  james:  ['serp_analysis','ai_report','deep_audit'],
  priya:  ['write_article','improve_draft','create_campaign','schedule_campaign'],
  marcus: ['create_post','schedule_post','publish_post'],
  elena:  ['create_lead','update_lead','list_leads','move_lead','log_activity'],
  alex:   ['insert_link','dismiss_link','outbound_links','check_outbound','deep_audit'],
};
let _eng_selected_tools = new Set();
let _eng_current_output = null;
let _eng_current_plan   = null;
// Engine execution panel globals
// (duplicate ENG_AGENTS removed)

// ── Nav hook ────────────────────────────────────────────────────────

// (calendar footer removed — belongs in calendar.js only)



window.LU_CORE_VERSION = '3.0.0';
console.log('%c[LevelUp Core v3.2.2 LOADED]','background:#6C5CE7;color:#fff;padding:4px 12px;border-radius:4px;font-weight:bold');
var API=window.LU_CFG.api, NONCE=window.LU_CFG.nonce, BN=window.LU_CFG.bn, BU=window.LU_CFG.bu;
// ── authHeader — unified auth helper used by all fetch() calls ──────────────
// WP Admin context:   sends X-WP-Nonce (same as get()/post() helpers)
// Standalone SPA:     sends X-LevelUp-Token if window.LEVELUP_TOKEN is set
// Both can coexist — the REST auth filter handles either header.
function authHeader() {
    var h = { 'Accept': 'application/json' };
    var token = localStorage.getItem('lu_token');
    if (token) {
        h['Authorization'] = 'Bearer ' + token;
    }
    if (typeof NONCE !== 'undefined' && NONCE) {
        h['X-WP-Nonce'] = NONCE;
    }
    return h;
}
// ── safeJson — safe fetch wrapper preventing "Unexpected token <" ────────────
// Use instead of res.json() when response may be HTML (401 redirect etc).
// Returns parsed JSON on success, null on non-OK status (logs to console).
async function safeJson(response) {
    if (!response.ok) {
        var txt = await response.text().catch(() => '');
        console.warn('[LevelUp] API error ' + response.status + ' on ' + response.url +
            (txt.trim().startsWith('<') ? ' (HTML response — check auth)' : ': ' + txt.slice(0, 120)));
        return null;
    }
    try { return await response.json(); }
    catch(e) { console.warn('[LevelUp] JSON parse error on ' + response.url + ':', e.message); return null; }
}
// ── Single API constant — all modules reference this ───────────────────
// luApi is set late by builder IIFE; pre-declare here so governance never hits TDZ
var luApi = API;   // overwritten by builder IIFE if needed (same value)
// ── Engine API namespace — var-hoisted so all loaders can reference bare LuAPI ──
var LuAPI = {};
// ── Icon SVGs used by engine view templates ──────────────────────────────
var icons = {
  plus:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
  search: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
  trash:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
  edit:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
  chart:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
  zap:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
  check:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>',
  target: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
};
var wpNonce_alias = NONCE; // alias used by some governance calls
var AGENTS={
  dmm: {name:'Sarah',role:'Digital Marketing Manager',emoji:'👩‍💼',color:'var(--p)',expertise:['Strategy','Growth','Analytics','Campaign Planning']},
  james:{name:'James',role:'SEO Strategist',emoji:'📊',color:'var(--bl)',expertise:['Keyword Research','Search Intent','Topical Authority','SERP Features','Local SEO']},
  priya:{name:'Priya',role:'Content Manager',emoji:'✍️',color:'var(--pu)',expertise:['Editorial Calendar','Brand Voice','Content Briefs','TOFU/MOFU/BOFU','Repurposing']},
  marcus:{name:'Marcus',role:'Social Media Manager',emoji:'📱',color:'var(--am)',expertise:['Instagram Reels','LinkedIn B2B','TikTok Strategy','Paid Social','Community']},
  elena:{name:'Elena',role:'CRM & Leads Specialist',emoji:'🎯',color:'var(--rd)',expertise:['Lead Capture','Email Nurture','CRM Segmentation','Lead Scoring','Attribution']},
  alex:{name:'Alex',role:'Technical SEO Engineer',emoji:'⚙️',color:'var(--ac)',expertise:['Core Web Vitals','Crawl Budget','Schema Markup','Site Architecture','Speed Optimisation']},
};
var PAIR_COLORS={'dmm-james':'var(--bl)','dmm-priya':'var(--pu)','dmm-marcus':'var(--am)','dmm-elena':'var(--rd)','dmm-alex':'var(--ac)','james-priya':'#06B6D4','james-marcus':'#8B5CF6','james-elena':'#EC4899','james-alex':'#10B981','priya-marcus':'#F97316','priya-elena':'#EAB308','priya-alex':'#84CC16','marcus-elena':'#FB923C','marcus-alex':'#22D3EE','elena-alex':'#818CF8'};
function pairColor(a,b){var k=[a,b].sort().join('-');return PAIR_COLORS[k]||'var(--t2)';}

let currentView='workspace',currentAgent=null,allTasks=[],pendingApprovalData=null,noteTargetConn=null;
let mid=null,pollT=null,seen=0,done=false,busy=false,lastSpk=null,lastPhase=null,selType='brainstorm';
let localUserMsgs=[]; // Persists user messages — runtime does NOT store them in Redis
var spoken=new Set(),phaseLog=new Set();
var intel={theme:'',seo:[],content:[],social:[],funnel:[]};
var PHASES=['briefing','idea_round','discussion_round','refinement_round','open','synthesis','complete'];
var THINK = {
    dmm:    ['Coordinating the team…','Synthesising insights…','Reviewing the strategy…','Connecting the dots…'],
    james:  ['Analysing keyword demand…','Checking search volume…','Mapping search intent…','Running competitor gap…','Pulling SERP features…','Checking keyword difficulty…','Reviewing topical authority…'],
    priya:  ['Drafting content brief…','Mapping the funnel stage…','Reviewing brand voice…','Structuring the content…','Checking repurpose formats…','Aligning with editorial calendar…'],
    marcus: ['Checking platform algorithms…','Reviewing Reel formats…','Pulling engagement data…','Mapping distribution channels…','Checking paid social options…','Reviewing audience targeting…'],
    elena:  ['Mapping the lead funnel…','Reviewing CRM triggers…','Building nurture sequence…','Checking lead scoring logic…','Reviewing conversion points…','Pulling attribution data…'],
    alex:   ['Running technical audit…','Checking Core Web Vitals…','Reviewing crawl budget…','Checking schema markup…','Auditing site architecture…','Reviewing canonical setup…'],
};

let agentActivityTimers = {};

function startAgentActivity(agentId) {
    stopAgentActivity(agentId);
    var el = document.getElementById('mthl-' + agentId);
    if (!el) return;
    var lines = THINK[agentId] || ['Working…'];
    let i = 0;
    el.textContent = lines[0];
    agentActivityTimers[agentId] = setInterval(() => {
        i = (i + 1) % lines.length;
        if (el) el.textContent = lines[i];
    }, 2000);
}

function stopAgentActivity(agentId) {
    if (agentActivityTimers[agentId]) {
        clearInterval(agentActivityTimers[agentId]);
        delete agentActivityTimers[agentId];
    }
}

// ── Routing ────────────────────────────────────────────────────────────────
async function nav(view){
  document.querySelectorAll('.view').forEach(v=>{
    v.classList.remove('active');
    // SEO view uses visibility (not display:none) to keep iframe alive
    if(v.id==='view-seo'){ v.style.visibility='hidden'; v.style.pointerEvents='none'; v.style.position='absolute'; }
    else { v.style.display='none'; }
  });
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  var el=document.getElementById('view-'+view);
  if(!el){ console.error('[LevelUp] nav: view not found →', view); return; }
  // SEO view restored via visibility (not display) to keep iframe alive
  if(view==='seo'){
    el.style.visibility='visible';
    el.style.pointerEvents='auto';
    el.style.position='relative';
    el.style.display='flex';
  } else {
    el.style.display='flex';
  }
  el.classList.add('active');
  var ni=document.getElementById('ni-'+view);if(ni)ni.classList.add('active');
  currentView=view;
  // Hide the Live Activity panel when in Strategy Room (it's a sibling of view-meeting, not a child of view-workspace)
  var _wsAct=document.getElementById('ws-activity-panel');
  if(_wsAct){ _wsAct.style.display = (view==='meeting') ? 'none' : ''; }
  if(view==='reports')    loadReports();
  if(view==='projects')   loadProjects();
  if(view==='tools')      { var _el=document.getElementById('tools-root'); if(_el) loadToolRegistry(_el); }
  if(view==='workspace')  {loadTasks();drawCanvas();drawZones();}
  if(view==='agents')     loadAgentStats();
  if(view==='governance') loadGovernance();
  if(view==='previews')   { loadPreviews(); _previewAutoRefreshStart(); } else { _previewAutoRefreshStop(); }
  if(view==='settings')   loadSettings();
  if(view==='builder') {
    // Builder engine loaded via builder-spa.js (injected by builder plugin)
    if (typeof _bldPrefetchDynamic === 'function') _bldPrefetchDynamic();
    var _bes = document.getElementById('bld-editor-state'); if(_bes) _bes.style.display = 'none';
    var _bls = document.getElementById('bld-list-state'); if(_bls) _bls.style.display = 'block';
    if (typeof arthurHide === 'function') arthurHide();
    if (typeof bldLoadPages === 'function') { try { bldLoadPages(); } catch(e) { console.error('[Builder]', e); } }
    else if (window._lu_engine_loaders && window._lu_engine_loaders['builder']) {
      var _el = document.getElementById('builder-root');
      if (_el) window._lu_engine_loaders['builder'](_el);
    }
  }
  if(view==='websites') {
    // Websites view — also handled by builder engine
    var _bes2 = document.getElementById('bld-editor-state'); if(_bes2) _bes2.style.display = 'none';
    var _bls2 = document.getElementById('bld-list-state'); if(_bls2) _bls2.style.display = 'block';
    var wsSitePages = document.getElementById('ws-site-pages');
    var wsSiteList = document.getElementById('ws-site-list');
    if (wsSitePages) wsSitePages.style.display = 'none';
    if (wsSiteList) wsSiteList.style.display = 'block';
    var _vb = document.getElementById('view-builder'); if(_vb) _vb.style.display = 'none';
    if (typeof wsLoadSites === 'function') wsLoadSites();
    else if (window._lu_engine_loaders && window._lu_engine_loaders['websites']) {
      var _el = document.getElementById('websites-root');
      if (_el) window._lu_engine_loaders['websites'](_el);
    }
  }
  // ── Engine modules — dynamic dispatch via loader registry ──────────────
  if(view==='crm')        { await luLoadEngine('crm'); var _el=document.getElementById('crm-root'); if(_el && typeof crmLoad==='function') crmLoad(_el); }
  if(view==='marketing')  { await luLoadEngine('marketing'); var _el=document.getElementById('marketing-root'); if(_el && typeof mktLoad==='function') mktLoad(_el); }
  if(view==='social')     { await luLoadEngine('social'); var _el=document.getElementById('social-root'); if(_el && typeof socialLoad==='function') socialLoad(_el); }
  if(view==='calendar')   { await luLoadEngine('calendar'); var _el=document.getElementById('calendar-root'); if(_el && typeof calLoad==='function') calLoad(_el); }
  if(view==='write')      { await luLoadEngine('write'); var _el=document.getElementById('write-root'); if(_el && typeof writeLoad==='function') writeLoad(_el); }
  if(view==='creative')   { await luLoadEngine('creative'); var _el=document.getElementById('creative-root'); if(_el && typeof creativeLoad==='function') creativeLoad(_el); }
  if(view==='manualedit') { await luLoadEngine('manualedit'); var _el=document.getElementById('manualedit-root'); if(_el && typeof manualeditLoad==='function') manualeditLoad(_el); }
  if(view==='automation') { var _el=document.getElementById('automation-root'); if(_el && typeof autoLoad==='function') autoLoad(_el); }
  if(view==='blog')       { await luLoadEngine('blog'); var _el=document.getElementById('blog-root'); if(_el && typeof blogLoad==='function') blogLoad(_el); }
  if(view==='studio')     { await luLoadEngine('studio'); var _el=document.getElementById('studio-root'); if(_el && typeof studioLoad==='function') studioLoad(_el); }
  if(view==='chatbot')    { var _el=document.getElementById('chatbot-root'); if(_el && typeof chatbotLoad==='function') chatbotLoad(_el); }
  if(view==='messages')   { var _el=document.getElementById('messages-root'); if(_el && typeof messagesLoad==='function') messagesLoad(_el); }
  // Generic engine dispatch — external plugins register via window._lu_engine_loaders
  if (window._lu_engine_loaders && window._lu_engine_loaders[view]) {
    var _el = document.getElementById(view + '-root');
    if (_el) window._lu_engine_loaders[view](_el);
  }
  // Always hide Arthur when navigating away from builder
  if(view!=='builder') { try{arthurHidePanel();}catch(e){} }
  if(view==='seo') {
    var _el = document.getElementById('seo-root');
    if (_el) {
      _el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
      await luLoadEngine('seo'); if(typeof seoLoad==='function') seoLoad(_el); // lazy — window._seoIframe guards re-init
    }
  }
  // NAV-REDIRECTS-5.5.2 — route deprecated sections to canonical ones
  if (view === 'governance' || view === 'previews') { return nav('approvals'); }
  if (view === 'campaigns') { return nav('marketing'); }
  if(view==='command')    loadCommandCenter();
  if(view==='approvals')  loadApprovals();
  if(view==='billing')    loadBilling();
  if(view==='queue')      { if(typeof loadWorkerQueue==='function') loadWorkerQueue(); }

  // Inject policy badges into module action buttons after render (Builder plugin)
  if(typeof _injectModuleBadges==='function') setTimeout(_injectModuleBadges, 500);
  if(view==='team')       loadTeam();
  if(view==='campaigns')  loadCampaignsView();
  if(typeof updateAiContext==='function') setTimeout(updateAiContext, 50);
}

// ── Expose nav() to global scope explicitly ────────────────────────────────
// nav() is declared as a function declaration (auto-global in non-module scripts)
// but we pin it to window explicitly to guarantee availability for onclick="nav()"
// handlers regardless of any future script-wrapping or bundling.
window.nav = nav;
console.log('[LevelUp] nav available:', typeof window.nav);

// Drain any nav() calls that were queued before this line executed
if (window._navQueue && window._navQueue.length) {
  console.log('[LevelUp] Draining nav queue:', window._navQueue);
  window._navQueue.forEach(function(view) { nav(view); });
  window._navQueue = [];
}

// ── Tool Registry ──────────────────────────────────────────────────────────

var TOOL_ENGINE_COLORS = {
  seo:'var(--bl)', crm:'var(--rd)', marketing:'var(--pu)',
  social:'var(--am)', calendar:'var(--ac)', builder:'var(--p)', core:'#4a5568',
};

async function loadToolRegistry(el) {
  if (!el) return;
  el.innerHTML = loadingCard(200);
  try {
    var res   = await fetch(window.luApi + 'tools', { headers: authHeader() });
    var tools = await safeJson(res);  // returns the registry object keyed by name
    var list  = Object.values(tools || {});

    // Group by engine
    var byEngine = {};
    list.forEach(t => {
      var eng = t.engine || 'unknown';
      if (!byEngine[eng]) byEngine[eng] = [];
      byEngine[eng].push(t);
    });

    var engines = Object.keys(byEngine).sort();
    var totalDynamic = list.length;

    el.innerHTML = `
<div style="padding-bottom:32px">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
    <div>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 4px;display:inline-flex;align-items:center;gap:8px">${window.icon('edit',20)} Tool Registry</h1>
      <p style="color:#8892a4;font-size:13px;margin:0">${totalDynamic} registered tool${totalDynamic!==1?'s':''} across ${engines.length} engine${engines.length!==1?'s':''}</p>
    </div>
    <button onclick="loadToolRegistry(document.getElementById('tools-root'))"
      style="background:none;border:1px solid #2a2f4a;color:#8892a4;border-radius:7px;padding:7px 14px;font-size:12px;cursor:pointer">↻ Refresh</button>
  </div>

  ${totalDynamic === 0 ? `
    <div style="background:#16192a;border:1px solid #2a2f4a;border-radius:12px;padding:60px;text-align:center;color:#4a5568">
      <div style="margin-bottom:12px;color:#6b7280">${window.icon('edit',36)}</div>
      <div style="font-size:14px;font-weight:600;margin-bottom:6px;color:#8892a4">No tools registered yet</div>
      <div style="font-size:12px">Engine plugins register tools via <code style="background:#0d1020;padding:2px 6px;border-radius:4px">add_action('lu_register_tools', ...)</code></div>
    </div>` : engines.map(eng => {
      var col   = TOOL_ENGINE_COLORS[eng] || 'var(--t2)';
      var eTools= byEngine[eng];
      return `
    <div style="margin-bottom:20px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <div style="width:10px;height:10px;border-radius:50%;background:${col};flex-shrink:0"></div>
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:${col}">${eng}</div>
        <div style="font-size:11px;color:#4a5568">${eTools.length} tool${eTools.length!==1?'s':''}</div>
      </div>
      <div style="background:#16192a;border:1px solid #2a2f4a;border-radius:10px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="border-bottom:1px solid #1e2230">
              <th style="text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">Tool</th>
              <th style="text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">Description</th>
              <th style="text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">Agents</th>
            </tr>
          </thead>
          <tbody>
            ${eTools.map((t, i) => {
              var agents = Array.isArray(t.agents) ? t.agents : [];
              var agentPills = agents.map(a => {
                var info = ENG_AGENTS[a] || { name: a, color: 'var(--t2)' };
                return `<span style="background:${info.color}22;color:${info.color};border-radius:20px;font-size:11px;font-weight:600;padding:2px 9px;white-space:nowrap">${info.name || a}</span>`;
              }).join(' ');
              var hasBorder = i < eTools.length - 1;
              return `<tr style="${hasBorder?'border-bottom:1px solid #1a1d2e':''}">
                <td style="padding:12px 16px;font-family:monospace;font-size:12px;color:#c8d0e0;white-space:nowrap">${t.name}</td>
                <td style="padding:12px 16px;font-size:12px;color:#8892a4;max-width:320px">${t.description || '—'}</td>
                <td style="padding:12px 16px">${agentPills || '<span style="color:#4a5568;font-size:11px">any</span>'}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
    }).join('')}

</div>`;

  } catch(e) {
    el.innerHTML = `<div style="padding:60px;text-align:center;color:#8892a4">
      <div style="margin-bottom:12px;color:var(--am,#F59E0B)">${window.icon('warning',32)}</div>
      <div>${e.message}</div>
    </div>`;
  }
}

// ── Governance ─────────────────────────────────────────────────────────────
var AGENT_COLORS = {dmm:'var(--p)',james:'var(--bl)',priya:'var(--pu)',marcus:'var(--am)',elena:'var(--rd)',alex:'var(--ac)'};
var AGENT_NAMES  = {dmm:'Sarah',james:'James',priya:'Priya',marcus:'Marcus',elena:'Elena',alex:'Alex'};
let govHistory = [];

async function loadGovernance(){
  try {
    var d = await get(API+'governance/pending');
    var pending = d.pending || [];
    renderGovPending(pending);
    renderGovHistory();
    updateGovBadge(pending.length);
  } catch(e){ console.error('[GOV]',e); }
}

function renderGovPending(actions){
  var el = document.getElementById('gov-pending-list');
  if(!el) return;
  if(!actions.length){
    el.innerHTML='<div style="color:var(--muted);font-size:13px;padding:24px;text-align:center;background:var(--s2);border-radius:10px">No pending actions.</div>';
    return;
  }
  el.innerHTML = actions.map(a=>{
    var agentName  = AGENT_NAMES[a.agent_id] || a.agent_id;
    var agentColor = AGENT_COLORS[a.agent_id] || '#888';
    var ts = new Date(a.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    return `<div class="gov-card" id="gov-${a.action_id}">
      <div class="gov-card-header">
        <div class="gov-agent-badge" style="background:${agentColor}20;color:${agentColor};border:1px solid ${agentColor}40">${agentName}</div>
        <div class="gov-tool-name">${a.tool_name}</div>
        <div class="gov-ts">${ts}</div>
      </div>
      <div class="gov-preview">${escHtml(a.preview)}</div>
      <div class="gov-actions">
        <button class="gov-btn gov-approve" onclick="govApprove('${a.action_id}',this)">✓ Approve &amp; Execute</button>
        <button class="gov-btn gov-reject"  onclick="govReject('${a.action_id}',this)">✕ Reject</button>
      </div>
    </div>`;
  }).join('');
}

function renderGovHistory(){
  var el = document.getElementById('gov-history-list');
  if(!el) return;
  if(!govHistory.length){
    el.innerHTML='<div style="color:var(--muted);font-size:13px;padding:24px;text-align:center;background:var(--s2);border-radius:10px">No action history yet.</div>';
    return;
  }
  el.innerHTML = govHistory.slice(0,20).map(h=>{
    var agentColor = AGENT_COLORS[h.agent_id] || '#888';
    var icon = h.status==='executed'?'✓':h.status==='rejected'?'✕':'✗';
    var col  = h.status==='executed'?'var(--ac)':h.status==='rejected'?'var(--muted)':'var(--rd)';
    return `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--s2);border-radius:8px;margin-bottom:6px;font-size:13px">
      <span style="color:${col};font-weight:700;font-size:15px">${icon}</span>
      <span style="color:${agentColor};font-weight:600">${AGENT_NAMES[h.agent_id]||h.agent_id}</span>
      <span style="color:var(--muted)">→</span>
      <span style="color:#fff">${escHtml(h.tool_name)}</span>
      <span style="color:var(--muted);flex:1;font-size:11px">${escHtml(h.preview||'')}</span>
      <span style="color:var(--muted);font-size:11px;text-transform:capitalize">${h.status}</span>
    </div>`;
  }).join('');
}

async function govApprove(actionId, btn){
  btn.disabled=true; btn.textContent='Approving…';
  try {
    var d = await post(API+'governance/approve',{action_id:actionId});
    if(d.success){
      var card = document.getElementById('gov-'+actionId);
      if(card){ card.style.opacity='0.4'; card.style.pointerEvents='none'; }
      govHistory.unshift({action_id:actionId,status:'executed',tool_name:'',preview:'Action executed.',agent_id:'',created_at:new Date().toISOString()});
      setTimeout(loadGovernance, 400);
    } else {
      btn.textContent='Error — retry'; btn.disabled=false;
      console.error('[GOV approve]', d);
    }
  } catch(e){ btn.textContent='Error'; btn.disabled=false; }
}

async function govReject(actionId, btn){
  btn.disabled=true; btn.textContent='Rejecting…';
  try {
    var d = await post(API+'governance/reject',{action_id:actionId});
    var card = document.getElementById('gov-'+actionId);
    if(card){ card.style.opacity='0.4'; card.style.pointerEvents='none'; }
    govHistory.unshift({action_id:actionId,status:'rejected',tool_name:'',preview:'Action rejected.',agent_id:'',created_at:new Date().toISOString()});
    setTimeout(loadGovernance, 400);
  } catch(e){ btn.textContent='Error'; btn.disabled=false; }
}

function updateGovBadge(count){
  var badge = document.getElementById('gov-badge');
  if(!badge) return;
  if(count>0){ badge.textContent=count; badge.style.display='block'; }
  else { badge.style.display='none'; }
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Poll governance every 30 seconds for badge updates (with fail-safe)
;(function(){
  if(window.__lu_poll_gov) return; window.__lu_poll_gov=true;
  let _govFails=0;
  var _govPoll=setInterval(async()=>{
    if(_govFails>=3){console.warn('[LU] Gov poll disabled after 3 failures');clearInterval(_govPoll);return;}
    try{var d=await get(API+'governance/pending');_govFails=0;updateGovBadge((d.pending||[]).length);if(currentView==='governance') renderGovPending(d.pending||[]);}catch(e){_govFails++;}
  }, 30000);
})();

// ══════════════════════════════════════════════════════════════════════════
// PHASE 6.5 — PREVIEW PANEL JS
// ══════════════════════════════════════════════════════════════════════════
let _previewTab = 'pending';

async function loadPreviews() {
  var wrap = document.getElementById('preview-list');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:24px;text-align:center;color:var(--t3)"><div class="spinner"></div></div>';
  try {
    var data = await get(API + 'previews?status=' + _previewTab);
    var items = Array.isArray(data) ? data : [];
    updatePreviewBadge(items.length);
    if (!items.length) {
      wrap.innerHTML = '<div style="color:var(--t3);font-size:13px;padding:24px;text-align:center;background:var(--s2);border-radius:10px">No ' + _previewTab + ' previews.</div>';
      return;
    }
    wrap.innerHTML = items.map(p => renderPreviewCard(p)).join('');
  } catch(e) {
    wrap.innerHTML = '<div style="color:var(--rd);font-size:13px;padding:24px;text-align:center">Failed to load previews: ' + (e.message||e) + '</div>';
  }
}
let _pvRefreshTimer = null;
function _previewAutoRefreshStart() { _previewAutoRefreshStop(); _pvRefreshTimer = setInterval(() => { if(currentView === 'previews') loadPreviews(); }, 15000); }
function _previewAutoRefreshStop() { if(_pvRefreshTimer) { clearInterval(_pvRefreshTimer); _pvRefreshTimer = null; } }

async function renderPreviewCard(p) {
  var preview = p.preview_output || {};
  var fields = preview.fields || [];
  var warnings = preview.warnings || [];
  var isPending = p.status === 'pending';
  var agent = AGENTS[p.agent_id] || {emoji:'🤖', name:p.agent_id, color:'var(--t2)'};
  var riskColors = {high:'var(--rd)', medium:'var(--am)', low:'var(--ac)'};
  var statusColors = {pending:'var(--am)', approved:'var(--ac)', rejected:'var(--rd)', executed:'var(--ac)', failed:'var(--rd)'};

  let html = `<div id="pv-${p.id}" style="background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:20px;margin-bottom:16px;transition:opacity .3s">`;

  // Header
  html += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <span style="font-size:22px">${agent.emoji}</span>
    <div style="flex:1">
      <div style="font-size:14px;font-weight:600;color:var(--t1)">${esc(preview.summary || p.tool_id)}</div>
      <div style="font-size:11px;color:var(--t3)">${agent.name} · ${preview.domain || ''} · ${new Date(p.created_at).toLocaleString()}</div>
    </div>
    <span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;background:${(riskColors[preview.risk]||'var(--t3)')}22;color:${riskColors[preview.risk]||'var(--t3)'}">${(preview.risk||'').toUpperCase()}</span>
    <span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;background:${(statusColors[p.status]||'var(--t3)')}22;color:${statusColors[p.status]||'var(--t3)'}">${p.status.toUpperCase()}</span>
  </div>`;

  // Warnings
  if (warnings.length) {
    html += warnings.map(w => `<div style="background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:var(--rd);display:flex;align-items:center;gap:6px">${window.icon('warning',14)} ${esc(w)}</div>`).join('');
  }

  // Editable fields
  if (fields.length) {
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">';
    fields.forEach(f => {
      var disabled = !isPending || !f.editable ? 'disabled' : '';
      var id = `pv-${p.id}-${f.key}`;
      if (f.multiline) {
        html += `<div style="grid-column:1/-1" class="form-group"><label class="form-label">${esc(f.label)}</label>
          <textarea class="form-input pv-field" data-key="${f.key}" id="${id}" ${disabled} style="min-height:80px;resize:vertical">${esc(f.value||'')}</textarea></div>`;
      } else if (f.options) {
        html += `<div class="form-group"><label class="form-label">${esc(f.label)}</label>
          <select class="form-select pv-field" data-key="${f.key}" id="${id}" ${disabled}>
            ${f.options.map(o => `<option value="${o}"${(f.value||'')=== o ? ' selected':''}>${o}</option>`).join('')}
          </select></div>`;
      } else {
        html += `<div class="form-group"><label class="form-label">${esc(f.label)}</label>
          <input class="form-input pv-field" data-key="${f.key}" id="${id}" value="${esc(f.value||'')}" ${disabled}></div>`;
      }
    });
    html += '</div>';
  }

  // Execution result (for executed/failed)
  if (p.execution_result) {
    var success = p.execution_result.success !== false;
    html += `<div style="background:${success?'rgba(0,229,168,.06)':'rgba(248,113,113,.06)'};border:1px solid ${success?'rgba(0,229,168,.15)':'rgba(248,113,113,.15)'};border-radius:8px;padding:10px 14px;font-size:12px;color:var(--t2);margin-bottom:10px">
      <strong style="color:${success?'var(--ac)':'var(--rd)'}">${success?'✓ Executed':'✗ Failed'}</strong>
      ${p.executed_at ? ' · ' + new Date(p.executed_at).toLocaleString() : ''}
    </div>`;
  }

  // Action buttons (pending only)
  if (isPending) {
    html += `<div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="previewReject(${p.id},this)">✕ Reject</button>
      <button class="btn btn-primary btn-sm" onclick="previewApprove(${p.id},this)">✓ Approve & Execute</button>
    </div>`;
  }

  html += '</div>';
  return html;
}

window.previewSetTab = function(tab) {
  _previewTab = tab;
  document.querySelectorAll('[data-ptab]').forEach(b => b.classList.toggle('active', b.dataset.ptab === tab));
  loadPreviews();
};

window.previewApprove = async function(id, btn) {
  btn.disabled = true; btn.textContent = 'Executing…';
  // Collect edited fields
  var card = document.getElementById('pv-' + id);
  var payload = {};
  if (card) {
    card.querySelectorAll('.pv-field').forEach(f => {
      var key = f.dataset.key;
      if (key) payload[key] = f.value;
    });
  }
  try {
    var r = await post(API + 'previews/' + id + '/approve', Object.keys(payload).length ? {payload} : {});
    if (r.success) {
      showToast('✓ Action executed!', 'success');
      if (card) { card.style.opacity = '0.3'; card.style.pointerEvents = 'none'; }
      setTimeout(loadPreviews, 500);
    } else {
      showToast('Execution failed: ' + (r.error || 'unknown'), 'error');
      btn.disabled = false; btn.textContent = '✓ Approve & Execute';
    }
  } catch(e) { showToast('Error: ' + e.message, 'error'); btn.disabled = false; btn.textContent = '✓ Approve & Execute'; }
};

window.previewReject = async function(id, btn) {
  var ok = await luConfirm('This action will be rejected and the agent notified.', 'Reject Action', 'Reject', 'Keep'); if (!ok) return;
  btn.disabled = true; btn.textContent = 'Rejecting…';
  try {
    await post(API + 'previews/' + id + '/reject', {});
    showToast('Action rejected.', 'info');
    var card = document.getElementById('pv-' + id);
    if (card) { card.style.opacity = '0.3'; card.style.pointerEvents = 'none'; }
    setTimeout(loadPreviews, 400);
  } catch(e) { showToast('Error: ' + e.message, 'error'); btn.disabled = false; btn.textContent = '✕ Reject'; }
};

function updatePreviewBadge(count) {
  var badge = document.getElementById('preview-badge');
  if (!badge) return;
  if (count > 0) { badge.textContent = count; badge.style.display = 'block'; }
  else { badge.style.display = 'none'; }
}

// Poll previews every 30 seconds (with fail-safe)
;(function(){
  if(window.__lu_poll_pv) return; window.__lu_poll_pv=true;
  let _pvFails=0;
  setInterval(async()=>{
    if(_pvFails>=3){return;} // silent stop
    try{
      var d=await get(API+'previews?status=pending');_pvFails=0;
      var items=Array.isArray(d)?d:[];
      updatePreviewBadge(items.length);
      if(currentView==='previews'&&_previewTab==='pending'){var wrap=document.getElementById('preview-list');if(wrap&&items.length) wrap.innerHTML=items.map(p=>renderPreviewCard(p)).join('');}
    }catch(e){_pvFails++;}
  }, 30000);
})();

// ── Canvas ─────────────────────────────────────────────────────────────────
var defaultPos={dmm:{x:450,y:280},james:{x:180,y:140},priya:{x:720,y:130},marcus:{x:860,y:300},elena:{x:640,y:460},alex:{x:240,y:440}};
var nodePos={...Object.fromEntries(Object.entries(defaultPos).map(([k,v])=>([k,{...v}])))};
// Task node positions — overrides centroid when user has dragged them
var taskNodePos = {};

// ── Zone layout ────────────────────────────────────────────────────────────
var ZONE_DEFS = {
  leadership: { agents:['dmm'],                 color:'rgba(108,92,231,.03)' },
  strategy:   { agents:['james','priya','marcus','sofia','jordan','vera','kai'], color:'rgba(59,139,245,.025)' },
  execution:  { agents:['alex','elena','diana','ryan','leo','maya','chris','nora','zara','tyler','aria','sam','max'], color:'rgba(0,229,168,.02)' },
};

function drawZones() {
  var canvas = document.getElementById('canvas-agents');
  if (!canvas) return;

  Object.entries(ZONE_DEFS).forEach(([zoneId, def]) => {
    let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
    def.agents.forEach(id => {
      if (!nodePos[id]) return;
      // Approx card size 160×120
      minX=Math.min(minX, nodePos[id].x-20);
      minY=Math.min(minY, nodePos[id].y-24);
      maxX=Math.max(maxX, nodePos[id].x+180);
      maxY=Math.max(maxY, nodePos[id].y+134);
    });
    if (minX===Infinity) return;
    var ov = document.getElementById('zone-'+zoneId);
    var lb = document.getElementById('zone-lbl-'+zoneId);
    if (ov) { ov.style.left=minX+'px'; ov.style.top=minY+'px'; ov.style.width=(maxX-minX)+'px'; ov.style.height=(maxY-minY)+'px'; }
    if (lb) { lb.style.left=(minX+8)+'px'; lb.style.top=(minY-16)+'px'; }
  });
}

// ── Selection state ────────────────────────────────────────────────────────
var selectedAgents = new Set();

function toggleAgentSelect(id, node) {
  if (selectedAgents.has(id)) { selectedAgents.delete(id); node.classList.remove('selected'); }
  else { selectedAgents.add(id); node.classList.add('selected'); }
  updateSelectionToolbar();
}
function clearSelection() {
  selectedAgents.clear();
  document.querySelectorAll('.agent-node.selected').forEach(n => n.classList.remove('selected'));
  updateSelectionToolbar();
}
function updateSelectionToolbar() {
  var tb = document.getElementById('selection-toolbar');
  var ct = document.getElementById('sel-count');
  if (!tb) return;
  if (selectedAgents.size > 0) {
    tb.classList.add('visible');
    if (ct) ct.textContent = selectedAgents.size;
  } else {
    tb.classList.remove('visible');
  }
}

// ── Drag + selection init ──────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 630-755)
function resetLayout(){
  // Radial layout from canvas center — Sarah at center, others in rings
  var canvas=document.getElementById('canvas-agents');
  var cx=1500;
  var cy=1000;
  var cw=160, ch=140;
  var nodes=document.querySelectorAll('.agent-node');
  var sarahNode=null, inner=[], outer=[];
  nodes.forEach(function(n){
    var id=n.dataset.agent;
    if(id==='dmm'||id==='sarah') sarahNode=n;
    else if(['james','priya','marcus','sofia','jordan','vera','kai'].indexOf(id)>=0) inner.push(n);
    else outer.push(n);
  });
  // Sarah at center
  if(sarahNode){
    var sx=cx-cw/2, sy=cy-ch/2;
    nodePos[sarahNode.dataset.agent]={x:sx,y:sy};
    sarahNode.style.left=sx+'px'; sarahNode.style.top=sy+'px';
  }
  // Inner ring (strategy)
  inner.forEach(function(n,i){
    var angle=(i/inner.length)*2*Math.PI-Math.PI/2;
    var x=Math.round(cx+280*Math.cos(angle)-cw/2);
    var y=Math.round(cy+280*Math.sin(angle)-ch/2);
    nodePos[n.dataset.agent]={x:x,y:y};
    n.style.left=x+'px'; n.style.top=y+'px';
  });
  // Outer ring (execution)
  outer.forEach(function(n,i){
    var angle=(i/outer.length)*2*Math.PI-Math.PI/2;
    var x=Math.round(cx+520*Math.cos(angle)-cw/2);
    var y=Math.round(cy+520*Math.sin(angle)-ch/2);
    nodePos[n.dataset.agent]={x:x,y:y};
    n.style.left=x+'px'; n.style.top=y+'px';
  });
  clearSelection();
  drawCanvas(); drawZones();
}

// Fast SVG line redraw for a single dragged task node
function _redrawTaskLines(taskId, tx, ty, agents) {
  var svg = document.getElementById('canvas-svg');
  if (!svg) return;
  // Remove only the lines belonging to this task node
  svg.querySelectorAll('[data-tnode="' + taskId + '"]').forEach(el => el.remove());
  var tcx = tx + 70; // task node center x
  var tcy = ty + 44; // task node center y
  agents.forEach(id => {
    if (!nodePos[id]) return;
    var ax = nodePos[id].x + 80;
    var ay = nodePos[id].y + 60;
    var line = document.createElementNS('http://www.w3.org/2000/svg','line');
    line.setAttribute('x1', tcx); line.setAttribute('y1', tcy);
    line.setAttribute('x2', ax);  line.setAttribute('y2', ay);
    var ag = AGENTS[id] || {};
    line.setAttribute('stroke', ag.color || 'var(--t2)');
    line.setAttribute('stroke-width', '1.5');
    line.setAttribute('stroke-dasharray', '4,3');
    line.setAttribute('opacity', '0.55');
    line.setAttribute('data-tnode', taskId);
    line.style.pointerEvents = 'none';
    svg.appendChild(line);
  });
}

function drawCanvas(){
  var svg=document.getElementById('canvas-svg');
  if(!svg){console.warn('[drawCanvas] no SVG element');return;}
  svg.innerHTML='';

  // Remove existing task nodes from canvas
  document.querySelectorAll('.task-node').forEach(n=>n.remove());

  // Build multi-assignee collision map from tasks
  var connMap={};

  var filtered=allTasks.filter(t=>['ongoing','upcoming','completed','in_progress'].includes(t.status));

  filtered.forEach(task=>{
    var involved = new Set();
    if (task.assignees && task.assignees.length) task.assignees.forEach(a=>involved.add(a));
    else if (task.assignee) involved.add(task.assignee);
    if (task.coordinator) involved.add(task.coordinator);

    var agents = [...involved];

    if (agents.length >= 2) {
      renderTaskNode(task, agents);
    }

    // Build all pairs for collaboration lines
    for (let i=0; i<agents.length; i++) {
      for (let j=i+1; j<agents.length; j++) {
        var pair=[agents[i],agents[j]].sort().join('-');
        if(!connMap[pair]) connMap[pair]=[];
        if(!connMap[pair].find(t=>t.id===task.id)) connMap[pair].push(task);
      }
    }
  });

  var canvasEl=document.getElementById('canvas-agents');
  if(!canvasEl) return;

  // Draw collaboration lines between agents
  Object.entries(connMap).forEach(([pair,tasks],pairIdx)=>{
    var [a,b]=pair.split('-');
    var nodeA=document.getElementById('node-'+a);
    var nodeB=document.getElementById('node-'+b);
    if(!nodeA||!nodeB) return;
    if(!nodePos[a]||!nodePos[b]) return;

    // Use nodePos (layout coords) for positions — works on virtual canvas
    var x1=nodePos[a].x+80, y1=nodePos[a].y+60;
    var x2=nodePos[b].x+80, y2=nodePos[b].y+60;
    var mx=(x1+x2)/2, my=(y1+y2)/2;
    var dx=x2-x1, dy=y2-y1, len=Math.sqrt(dx*dx+dy*dy)||1;
    var nx=-dy/len, ny=dx/len;
    var offsetAmt=50*(pairIdx%2===0?1:-1);
    var cx=mx+nx*offsetAmt, cy=my+ny*offsetAmt;

    var hasOngoing=tasks.some(t=>t.status==='ongoing'||t.status==='in_progress');
    var hasUpcoming=tasks.some(t=>t.status==='upcoming');
    var color=hasOngoing?'var(--ac)':hasUpcoming?'var(--am)':'var(--bl)';

    var path=document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('d',`M ${x1} ${y1} Q ${cx} ${cy} ${x2} ${y2}`);
    path.setAttribute('stroke',color);path.setAttribute('stroke-width','2');
    path.setAttribute('stroke-dasharray','7,4');path.setAttribute('fill','none');
    path.setAttribute('opacity','0.65');path.style.pointerEvents='stroke';path.style.cursor='pointer';
    path.addEventListener('mouseenter',e=>showConnTooltip(e,a,b,tasks));
    path.addEventListener('mouseleave',hideConnTooltip);
    svg.appendChild(path);

    // Count label at midpoint
    var bg=document.createElementNS('http://www.w3.org/2000/svg','rect');
    bg.setAttribute('x',cx-22);bg.setAttribute('y',cy-11);bg.setAttribute('width','44');bg.setAttribute('height','18');
    bg.setAttribute('rx','9');bg.setAttribute('fill','#171A21');bg.setAttribute('stroke',color);bg.setAttribute('stroke-width','1');bg.setAttribute('opacity','.9');
    svg.appendChild(bg);
    var lbl=document.createElementNS('http://www.w3.org/2000/svg','text');
    lbl.setAttribute('x',cx);lbl.setAttribute('y',cy+1);lbl.setAttribute('text-anchor','middle');
    lbl.setAttribute('dominant-baseline','middle');lbl.setAttribute('font-size','9');
    lbl.setAttribute('font-family','DM Sans,sans-serif');lbl.setAttribute('font-weight','700');
    lbl.setAttribute('fill',color);lbl.textContent=tasks.length+' task'+(tasks.length>1?'s':'');
    lbl.style.pointerEvents='none'; svg.appendChild(lbl);
  });
}

function renderTaskNode(task, agents) {
  var canvasEl = document.getElementById('canvas-agents');
  if (!canvasEl) return;

  // Compute centroid of assigned agent nodes
  let sumX=0, sumY=0, count=0;
  agents.forEach(id => {
    if (!nodePos[id]) return;
    sumX += nodePos[id].x + 80;
    sumY += nodePos[id].y + 60;
    count++;
  });
  if (!count) return;
  // Use stored position if user has dragged the task node
  var centX = sumX/count - 70;
  var centY = sumY/count - 44;
  var stored = taskNodePos[task.id];
  var cx = stored ? stored.x : centX;
  var cy = stored ? stored.y : centY;

  var ag = agents.map(id=>AGENTS[id]).filter(Boolean);
  var priCls = task.priority==='high'?'high':task.priority==='low'?'low':'medium';
  var stLabel = task.status==='in_progress'?'In Progress':task.status==='ongoing'?'Active':task.status==='completed'?'Done':'Planned';

  var node = document.createElement('div');
  node.className = 'task-node';
  node.id = 'tnode-'+task.id;
  node.style.left = cx+'px';
  node.style.top  = cy+'px';
  node.dataset.taskId = task.id;
  node.dataset.taskAgents = agents.join(',');
  node.innerHTML = `
    <div class="tn-title" title="${esc(task.title)}">${esc(task.title)}</div>
    <div class="tn-assignees">${ag.map(a=>`<div class="tn-av" style="background:${a.color}22;border-color:${a.color}44" title="${a.name}">${a.emoji}</div>`).join('')}</div>
    <div class="tn-meta">
      <span class="tn-pri ${priCls}">${task.priority||'medium'}</span>
      <span class="tn-status">${stLabel}</span>
    </div>`;
  node.addEventListener('click', () => openTaskDrawer && openTaskDrawer(task.id));
  canvasEl.appendChild(node);

  // Draw SVG lines from task node to each agent node
  var svg = document.getElementById('canvas-svg');
  if (!svg) return;
  agents.forEach(id => {
    if (!nodePos[id]) return;
    var ax = nodePos[id].x + 80;
    var ay = nodePos[id].y + 60;
    var tnp = taskNodePos[task.id] || {x: cx, y: cy};
    var tx = tnp.x + 70;
    var ty = tnp.y + 44;

    var line = document.createElementNS('http://www.w3.org/2000/svg','line');
    line.setAttribute('x1',tx); line.setAttribute('y1',ty);
    line.setAttribute('x2',ax); line.setAttribute('y2',ay);
    var ag = AGENTS[id]||{};
    line.setAttribute('stroke', ag.color||'var(--t2)');
    line.setAttribute('stroke-width','1.5');
    line.setAttribute('stroke-dasharray','4,3');
    line.setAttribute('opacity','0.55');
    line.setAttribute('data-tnode', task.id); // tag for surgical redraw
    line.style.pointerEvents = 'none';
    svg.appendChild(line);
  });
}

function showConnTooltip(e,agentA,agentB,tasks){
  noteTargetConn={agentA,agentB,tasks};
  var tt=document.getElementById('conn-tooltip');
  var aInfo=AGENTS[agentA]||{}, bInfo=AGENTS[agentB]||{};
  document.getElementById('ctt-agents').innerHTML=`<span class="ct-agent-av">${aInfo.emoji||'?'}</span><span style="color:${aInfo.color||'#fff'};font-family:var(--fh);font-size:12px;font-weight:700">${aInfo.name||agentA}</span><span class="ct-arrow">⟷</span><span class="ct-agent-av">${bInfo.emoji||'?'}</span><span style="color:${bInfo.color||'#fff'};font-family:var(--fh);font-size:12px;font-weight:700">${bInfo.name||agentB}</span>`;
  var list=document.getElementById('ctt-tasks');
  list.innerHTML=tasks.slice(0,3).map(t=>{
    var stClass=t.status==='ongoing'?'st-ongoing':t.status==='upcoming'?'st-upcoming':'st-completed';
    var timeInfo=t.status==='ongoing'?`${window.icon('clock',14)} ${t.estimated_time}min est`:t.status==='completed'?`✓ ${t.actual_time||t.estimated_time}min used`:t.status==='upcoming'?`Est. ${t.estimated_time}min`:'';
    var tokenInfo=t.status==='completed'?`${(t.actual_tokens||t.estimated_tokens/1000).toFixed(0)}k tokens used`:t.status==='upcoming'?`~${(t.estimated_tokens/1000).toFixed(0)}k tokens est`:`~${(t.estimated_tokens/1000).toFixed(0)}k tokens`;
    return `<div class="ct-task"><div class="ct-task-title">${esc(t.title)}</div><div class="ct-task-meta"><span class="ct-task-status ${stClass}">${t.status}</span><span class="ct-timer">${timeInfo}</span><span class="ct-timer">${tokenInfo}</span></div></div>`;
  }).join('');
  tt.style.left=(e.clientX+14)+'px'; tt.style.top=(e.clientY-20)+'px';
  tt.classList.add('visible');
}
function hideConnTooltip(){
  setTimeout(()=>{var tt=document.getElementById('conn-tooltip');if(tt)tt.classList.remove('visible');},200);
}

// ── Tasks ──────────────────────────────────────────────────────────────────
async function loadTasks(){
  try{
    var res=await get(API+'tasks');
    // Phase 5: DB response has {tasks:[], source:'db'|'legacy'}
    // Legacy response is a plain array
    var raw = Array.isArray(res) ? res : (res.tasks || []);
    // Normalise DB rows to SPA shape (DB uses agent_id, SPA uses assignee)
    allTasks = raw.map(t => ({
      ...t,
      id:       t.id || t.task_id,
      assignee: (function(){
        var a = t.assignee || t.agent_id || '';
        if(!a && t.assigned_agents_json){
          var p=t.assigned_agents_json;
          if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p=[]}}
          if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p=[]}}
          a=Array.isArray(p)&&p.length?p[0]:'';
        }
        return a==='sarah'?'dmm':a;
      })(),
      assignees: (function(){
        // Parse assignees from assigned_agents_json (may be string, double-encoded, or array)
        var raw = t.assignees || t.assigned_agents_json || (t.agent_id ? [t.agent_id] : []);
        if(typeof raw==='string'){try{raw=JSON.parse(raw)}catch(e){raw=[]}}
        if(typeof raw==='string'){try{raw=JSON.parse(raw)}catch(e){raw=[]}} // double-encoded
        if(!Array.isArray(raw)) raw=[];
        // Map sarah→dmm to match AGENTS map keys
        return raw.map(function(s){return s==='sarah'?'dmm':s;});
      })(),
      // Extract title/description/times from payload_json (stored as JSON in DB)
      title: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.title) || t.progress_message || t.action || '—';
      })(),
      description: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.description) || t.progress_message || '';
      })(),
      estimated_time: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.estimated_time) || 60;
      })(),
      estimated_tokens: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.estimated_tokens) || 4000;
      })(),
      success_metric: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.success_metric) || '';
      })(),
      coordinator: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        var co = (p&&p.coordinator) || '';
        return co==='sarah'?'dmm':co;
      })(),
      notes: (function(){
        var r = t.result_json;
        if(typeof r==='string'){try{r=JSON.parse(r)}catch(e){r={}}}
        return (r&&r.notes) || [];
      })(),
      // Map DB statuses to canvas statuses so connection lines render
      status: ({
        'in_progress':'ongoing', 'running':'ongoing',
        'pending':'upcoming', 'queued':'upcoming', 'awaiting_approval':'upcoming',
        'completed':'completed',
        'failed':'upcoming',       // failed tasks go back to backlog for retry
        'cancelled':'completed',   // cancelled = done
        'verifying':'ongoing', 'blocked':'upcoming', 'degraded':'ongoing',
      })[t.status] || t.status,
    }));
    updateNodeCounts();
    updateWorkloadIndicators();
    updateActivityLabels();
    drawCanvas();
    drawZones();
    loadAgentStats();
    // If agent nodes weren't ready on first draw, retry after they load
    if(!document.querySelector('.agent-node')){
      var _retryDraw=setInterval(function(){
        if(document.querySelector('.agent-node')){
          clearInterval(_retryDraw);
          drawCanvas(); drawZones(); updateNodeCounts(); updateWorkloadIndicators();
        }
      },500);
      setTimeout(function(){clearInterval(_retryDraw)},10000); // give up after 10s
    }
  }catch(e){console.error('loadTasks:',e);}
}

function updateNodeCounts(){
  Object.keys(AGENTS).forEach(id=>{
    var ongoing=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='ongoing'||t.status==='in_progress')).length;
    var upcoming=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&t.status==='upcoming').length;
    var completed=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&t.status==='completed').length;
    var tot=ongoing+upcoming+completed||1;
    setEl('tc-ongoing-'+id,ongoing);setEl('tc-upcoming-'+id,upcoming);setEl('tc-completed-'+id,completed);
    var onBar=document.getElementById('tb-ongoing-'+id);if(onBar)onBar.style.width=Math.min(ongoing/tot*100*3,100)+'%';
    setEl('av-ongoing-'+id,ongoing);setEl('av-upcoming-'+id,upcoming);setEl('av-completed-'+id,completed);
  });
}

function updateWorkloadIndicators(){
  Object.keys(AGENTS).forEach(id=>{
    var active=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='ongoing'||t.status==='in_progress'||t.status==='upcoming')).length;
    var dot=document.getElementById('wl-dot-'+id);
    var lbl=document.getElementById('wl-label-'+id);
    if(!dot||!lbl) return;
    dot.className='wl-dot';
    if(active===0){dot.classList.add('available');lbl.textContent='Available';}
    else if(active<=3){dot.classList.add('moderate');lbl.textContent=active+' task'+(active>1?'s':'');}
    else{dot.classList.add('overloaded');lbl.textContent='Overloaded ('+active+')';}
  });
}

function updateActivityLabels(){
  Object.keys(AGENTS).forEach(id=>{
    var inProgress=allTasks.find(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='in_progress'||t.status==='ongoing'));
    var el=document.getElementById('an-activity-'+id);
    if(!el) return;
    if(inProgress){
      el.textContent='▶ Working on: '+inProgress.title;
      el.classList.add('visible');
    } else {
      el.textContent='';
      el.classList.remove('visible');
    }
  });
}


// ── Task approval ──────────────────────────────────────────────────────────
async function checkPendingTasks(meetingId){
  // ── Safe tools that can auto-execute without user approval ────────
  var SAFE_AUTO_TOOLS = new Set([
    'serp_analysis','ai_report','deep_audit','ai_status','list_goals','agent_status',
    'list_leads','get_lead','list_campaigns','list_templates','list_posts','get_queue',
    'list_events','check_availability','list_builder_pages','get_builder_page',
    'link_suggestions','outbound_links','check_outbound','list_sequences',
    'get_site_pages','get_site_page','search_site_content','scan_site_url',
    'system_health_check','list_previews','proactive_status','memory_context',
    'analyze_funnel_structure','generate_funnel_blueprint',
  ]);

  try{
    var d=await get(API+'meeting/'+meetingId+'/pending-tasks');
    var links=document.getElementById('meeting-end-links');
    if(d&&d.tasks&&d.tasks.length){
      // Split into auto-approvable and needs-review
      var safeIds=[];
      var riskyTasks=[];
      d.tasks.forEach(t=>{
        var tools=(t.tools||[]).map(x=>typeof x==='string'?x:(x.tool_id||x.id||'')).filter(Boolean);
        var allSafe=tools.length>0&&tools.every(tid=>SAFE_AUTO_TOOLS.has(tid));
        if(allSafe) safeIds.push(t.id);
        else riskyTasks.push(t);
      });

      // Auto-approve safe tasks immediately
      if(safeIds.length>0){
        try{
          await post(API+'tasks/approve',{approved:safeIds,tasks:d.tasks.filter(t=>safeIds.includes(t.id)),meeting_id:meetingId});
        }catch(e){console.warn('Auto-approve safe tasks:',e);}
      }

      // Show modal only for risky tasks (or summary if all auto-approved)
      if(riskyTasks.length>0){
        pendingApprovalData={...d,tasks:riskyTasks,meeting_id:meetingId};
        if(links) links.innerHTML=`
          <div style="display:flex;flex-direction:column;gap:8px;align-items:center">
            ${safeIds.length>0?`<span style="font-size:12px;color:var(--ac);font-weight:600">✓ ${safeIds.length} safe tasks auto-dispatched</span>`:''}
            <span style="font-size:12px;font-weight:600;color:var(--am)">${riskyTasks.length} tasks need your approval</span>
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('approval-backdrop').classList.add('visible')" style="font-size:11px">Review & Approve Tasks</button>
          </div>
        `;
        showApprovalModal({...d,tasks:riskyTasks});
      } else {
        // All tasks were safe and auto-approved
        if(links) links.innerHTML=`
          <div style="display:flex;flex-direction:column;gap:8px;align-items:center">
            <span style="font-size:13px;font-weight:600;color:var(--ac)">✓ ${safeIds.length} tasks auto-dispatched to agents</span>
            <div style="display:flex;gap:8px">
              <button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} View Tasks</button>
              <button class="btn btn-outline btn-sm" onclick="nav('previews')" style="font-size:11px">${window.icon('eye',14)} Previews</button>
            </div>
          </div>
        `;
        showToast(`${safeIds.length} tasks auto-dispatched to agents!`, 'success');
      }
    } else {
      if(links) links.innerHTML=`
        <span style="font-size:12px;color:var(--t3)">No tasks generated this session.</span>
        <button class="btn btn-outline btn-sm" onclick="nav('previews')" style="font-size:11px">${window.icon('eye',14)} View Previews</button>
        <button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} View Tasks</button>
      `;
    }
  }catch(e){
    console.warn('checkPending:',e);
    var links=document.getElementById('meeting-end-links');
    if(links) links.innerHTML='<span style="font-size:11px;color:var(--t3)">Could not load pending tasks.</span>';
  }
}
function showApprovalModal(data){
  var list=document.getElementById('am-tasks-list');
  var p=document.getElementById('am-sub');
  p.textContent=`From "${data.topic||'Strategy Session'}" — select which tasks to assign to your team.`;
  list.innerHTML=data.tasks.map((t,i)=>{
    var ag=AGENTS[t.assignee]||{};
    var co=t.coordinator?AGENTS[t.coordinator]:null;
    var priCls=t.priority==='high'?'ab-high':t.priority==='medium'?'ab-med':'ab-low';
    return `<div class="am-task selected" id="amt-${i}" onclick="toggleTask(${i})">
      <div class="am-task-top">
        <div class="am-check" id="amch-${i}">✓</div>
        <div class="am-task-body">
          <div class="am-task-title">${esc(t.title)}</div>
          <div class="am-task-desc">${esc(t.description)}</div>
          ${t.success_metric?`<div style="font-size:10px;color:var(--ac);margin-bottom:8px;display:flex;align-items:center;gap:5px"><span style="opacity:.6">${window.icon('more',14)} Success:</span> ${esc(t.success_metric)}</div>`:''}
          <div class="am-task-meta">
            <span class="am-badge ab-agent">${ag.emoji||''} ${ag.name||t.assignee}</span>
            ${co?`<span class="am-badge ab-agent" style="opacity:.7">↔ ${co.emoji||''} ${co.name||t.coordinator}</span>`:''}
            <span class="am-badge ${priCls}">${t.priority}</span>
            <span class="am-badge" style="background:rgba(139,151,176,.08);color:var(--t2);border-color:rgba(139,151,176,.2)">${window.icon('clock',14)} ${t.estimated_time}min</span>
            <span class="am-badge" style="background:rgba(139,151,176,.08);color:var(--t2);border-color:rgba(139,151,176,.2)">~${(t.estimated_tokens/1000).toFixed(0)}k tokens</span>
          </div>
        </div>
      </div>
    </div>`;
  }).join('');
  updateApprovalCount();
  document.getElementById('approval-backdrop').classList.add('visible');
}
function toggleTask(i){
  var el=document.getElementById('amt-'+i);
  var ch=document.getElementById('amch-'+i);
  el.classList.toggle('selected');
  ch.textContent=el.classList.contains('selected')?'✓':'';
  updateApprovalCount();
}
function updateApprovalCount(){
  var sel=document.querySelectorAll('.am-task.selected').length;
  var tot=pendingApprovalData?.tasks?.length||0;
  setEl('am-count',`${sel} of ${tot} selected`);
}
async function approveSelected(){
  if(!pendingApprovalData) return;
  var approved=[];
  pendingApprovalData.tasks.forEach((t,i)=>{if(document.getElementById('amt-'+i)?.classList.contains('selected')) approved.push(t.id);});
  var btn=document.querySelector('#approval-backdrop .btn-primary');
  if(btn){btn.disabled=true;btn.textContent='Processing…';}
  try{
    var result=await post(API+'tasks/approve',{approved,tasks:pendingApprovalData.tasks,meeting_id:pendingApprovalData.meeting_id});
    document.getElementById('approval-backdrop').classList.remove('visible');
    pendingApprovalData=null;

    // Phase 6.5: Show execution summary
    var ti=result.tasks_imported||0;
    var pc=result.previews_created||0;
    var total=result.saved||approved.length;

    // Update meeting end banner
    var links=document.getElementById('meeting-end-links');
    if(links){
      let html='<div style="display:flex;flex-direction:column;gap:8px;align-items:center;width:100%">';
      html+=`<div style="display:flex;gap:16px;font-size:13px">`;
      html+=`<span style="color:var(--ac);font-weight:600">✓ ${total} tasks approved</span>`;
      if(ti>0) html+=`<span style="color:var(--bl);display:inline-flex;align-items:center;gap:4px">${window.icon('more',13)} ${ti} sent to agents</span>`;
      if(pc>0) html+=`<span style="color:var(--am);display:inline-flex;align-items:center;gap:4px">${window.icon('eye',13)} ${pc} queued for preview</span>`;
      html+=`</div>`;
      html+=`<div style="display:flex;gap:8px">`;
      if(pc>0) html+=`<button class="btn btn-primary btn-sm" onclick="nav('previews')" style="font-size:11px">${window.icon('eye',14)} Review Previews (${pc})</button>`;
      html+=`<button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} View All Tasks</button>`;
      html+=`</div></div>`;
      links.innerHTML=html;
    }

    // Toast summary
    if(pc>0) showToast(`${total} approved: ${ti} tasks started, ${pc} previews for your review`, 'success');
    else showToast(`${total} tasks approved and sent to agents!`, 'success');

    await loadTasks();
  }catch(e){
    showToast('Error: '+e.message, 'error');
    if(btn){btn.disabled=false;btn.textContent='Approve Selected';}
  }
}
async function dismissApproval(){
  if(!pendingApprovalData) return;
  try{await post(API+'tasks/approve',{approved:[],tasks:[],meeting_id:pendingApprovalData.meeting_id});}catch(e){}
  document.getElementById('approval-backdrop').classList.remove('visible');
  pendingApprovalData=null;
}

// ── Note modal ─────────────────────────────────────────────────────────────
function openNoteModal(){
  if(!noteTargetConn) return;
  var a=AGENTS[noteTargetConn.agentA]||{},b=AGENTS[noteTargetConn.agentB]||{};
  setEl('nm-sub',`Note for ${a.name||noteTargetConn.agentA} ↔ ${b.name||noteTargetConn.agentB} collaboration`);
  document.getElementById('note-ta').value='';
  document.getElementById('note-backdrop').classList.add('visible');
  document.getElementById('conn-tooltip').classList.remove('visible');
}
function closeNoteModal(){document.getElementById('note-backdrop').classList.remove('visible');}
async function saveNote(){
  var text=document.getElementById('note-ta').value.trim();
  if(!text||!noteTargetConn) return;
  var taskId=noteTargetConn.tasks[0]?.id;
  if(!taskId){closeNoteModal();return;}
  try{
    await post(API+'tasks/'+taskId+'/note',{text,author:'User'});
    closeNoteModal();
  }catch(e){showToast('Error: '+e.message,'error');}
}

// ── Agent drawer ───────────────────────────────────────────────────────────
function openAgentDrawer(id){
  currentAgent=id;
  window._agentDrawerOpen=id;
  var ag=AGENTS[id]||{};
  var col=ag.color||'var(--t2)';
  document.getElementById('ad-av').innerHTML=(typeof buildAgentOrb==='function')?buildAgentOrb(id,'lg','idle'):('<div style="width:52px;height:52px;font-size:26px">'+(ag.emoji||'?')+'</div>');
  var nameEl=document.getElementById('ad-name');nameEl.textContent=ag.name||id;nameEl.style.color=col;
  setEl('ad-role',ag.role||'');
  renderProfilePane(id,ag);
  renderDrawerTasks(id,'all');
  loadDrawerMessages(id);
  renderDocuments(id);
  drawerTab('profile');
  document.getElementById('drawer-bg').classList.add('visible');
  document.getElementById('agent-drawer').classList.add('open');
}
function closeAgentDrawer(){
  document.getElementById('drawer-bg').classList.remove('visible');
  document.getElementById('agent-drawer').classList.remove('open');
  currentAgent=null;
  window._agentDrawerOpen=null;
}
function drawerTab(tab){
  ['profile','tasks','messages','documents','board'].forEach(t=>{
    document.getElementById('dt-'+t)?.classList.toggle('active',t===tab);
    document.getElementById('dp-'+t)?.classList.toggle('active',t===tab);
  });
  if(tab==='board' && window._agentDrawerOpen) loadAgentStats();
}
function renderProfilePane(id,ag){
  var ongoing=allTasks.filter(t=>t.assignee===id&&t.status==='ongoing').length;
  var upcoming=allTasks.filter(t=>t.assignee===id&&t.status==='upcoming').length;
  var done=allTasks.filter(t=>t.assignee===id&&t.status==='completed').length;
  var col=ag.color||'var(--t2)';
  document.getElementById('dp-profile').innerHTML=`
    <div class="profile-section">
      <div class="profile-sec-title">Expertise</div>
      <div class="profile-expertise">${((ag.expertise&&ag.expertise.length)?ag.expertise:(ag.skills||ag.capabilities||[])).map(e=>`<span class="pe-tag">${esc(e)}</span>`).join('')||'<span style="color:var(--t3);font-size:11px">No expertise data</span>'}</div>
    </div>
    <div class="profile-section">
      <div class="profile-sec-title">Task Overview</div>
      <div class="profile-stat-grid">
        <div class="psg-item"><div class="psg-val" style="color:${col}">${ongoing}</div><div class="psg-lbl">Ongoing</div></div>
        <div class="psg-item"><div class="psg-val" style="color:var(--am)">${upcoming}</div><div class="psg-lbl">Upcoming</div></div>
        <div class="psg-item"><div class="psg-val" style="color:var(--bl)">${done}</div><div class="psg-lbl">Completed</div></div>
      </div>
    </div>`;
}
function renderDrawerTasks(id,filter){
  var tasks=allTasks.filter(t=>t.assignee===id&&(filter==='all'||t.status===filter));
  var list=document.getElementById('dp-tasks-list');
  if(!tasks.length){list.innerHTML=`<div style="text-align:center;padding:30px;font-size:12px;color:var(--t3)">No ${filter==='all'?'':filter+' '}tasks yet.</div>`;return;}
  var ag=AGENTS[id]||{};
  list.innerHTML=tasks.map(t=>{
    var stClass=t.status==='ongoing'?'st-ongoing':t.status==='upcoming'?'st-upcoming':'st-completed';
    var timeInfo=t.status==='completed'?`✓ ${t.actual_time||t.estimated_time}min used`:t.status==='upcoming'?`Est. ${t.estimated_time}min`:t.status==='ongoing'?`${window.icon('clock',14)} ${t.estimated_time}min est`:'';
    var tokenInfo=t.status==='completed'?`${((t.actual_tokens||t.estimated_tokens)/1000).toFixed(0)}k tokens`:t.status==='upcoming'?`~${(t.estimated_tokens/1000).toFixed(0)}k tokens est`:`~${(t.estimated_tokens/1000).toFixed(0)}k tokens`;
    var coord=t.coordinator?AGENTS[t.coordinator]:null;
    return `<div class="task-item">
      <div class="ti-title">${esc(t.title)}</div>
      <div class="ti-desc">${esc(t.description)}</div>
      <div class="ti-meta">
        <span class="ti-badge ${stClass}">${t.status}</span>
        ${coord?`<span class="ti-badge" style="background:rgba(108,92,231,.08);color:var(--pu);border-color:rgba(108,92,231,.2)">↔ ${coord.emoji||''} ${coord.name||t.coordinator}</span>`:''}
        <span class="ti-time">${window.icon('clock',14)} ${timeInfo}</span>
        <span class="ti-tokens">🪙 ${tokenInfo}</span>
      </div>
      ${(t.notes||[]).map(n=>`<div style="margin-top:8px;padding:7px 9px;background:var(--s3);border-radius:6px;font-size:10px;color:var(--t2)">${window.icon('edit',14)} ${esc(n.text)}</div>`).join('')}
    </div>`;
  }).join('');
}
function filterTasks(filter,btn){
  document.querySelectorAll('.tf-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  if(currentAgent) renderDrawerTasks(currentAgent,filter);
}
async function loadDrawerMessages(id){
  // Inject typing animation if not already done
  if(!document.getElementById('agent-chat-styles')){
    var s=document.createElement('style');s.id='agent-chat-styles';
    s.textContent='@keyframes typePulse{0%,100%{opacity:.3;transform:scale(.8)}50%{opacity:1;transform:scale(1.1)}}';
    document.head.appendChild(s);
  }
  try{
    var msgs=await get(API+'agents/'+id+'/messages');
    var feed=document.getElementById('msgs-feed-container');
    if(!msgs||!msgs.length){feed.innerHTML=`<div style="text-align:center;padding:24px;font-size:12px;color:var(--t3)">No messages yet. Send a direct message to ${AGENTS[id]?.name||id}.</div>`;return;}
    var ag=AGENTS[id]||{};
    feed.innerHTML=msgs.map(m=>{
      var isUser=m.from==='user'||m.from==='User';
      return `<div class="${isUser?'msg-from-user':'msg-from-agent'}" style="align-self:${isUser?'flex-end':'flex-start'}"><div style="font-size:9px;font-weight:700;color:${isUser?'var(--pu)':ag.color||'var(--t2)'};margin-bottom:3px">${isUser?'You':ag.name||id}</div>${esc(m.content)}<div class="msg-ts">${new Date(m.ts).toLocaleTimeString()}</div></div>`;
    }).join('');
    feed.scrollTop=feed.scrollHeight;
  }catch(e){console.error('loadMsgs:',e);}
}
async function sendAgentMessage(quickAction, overrideMessage){
  if(!currentAgent) return;
  var ta=document.getElementById('agent-msg-input');
  var content=overrideMessage||(!quickAction?ta.value.trim():'');
  if(!content&&!quickAction) return;
  if(ta){ta.value='';ta.style.height='auto';}

  var ag=AGENTS[currentAgent]||{};
  var feed=document.getElementById('msgs-feed-container');

  // Show user bubble immediately (unless quick action)
  if(content){
    var userDiv=document.createElement('div');
    userDiv.className='msg-from-user';
    userDiv.style.alignSelf='flex-end';
    userDiv.innerHTML='<div style="font-size:9px;font-weight:700;color:var(--pu);margin-bottom:3px">You</div>'+esc(content)+'<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';
    feed.appendChild(userDiv);
    feed.scrollTop=feed.scrollHeight;
  }

  // Show typing indicator
  var typingDiv=document.createElement('div');
  typingDiv.className='msg-from-agent';
  typingDiv.id='agent-typing-indicator';
  typingDiv.style.alignSelf='flex-start';
  typingDiv.innerHTML='<div style="font-size:9px;font-weight:700;color:'+(ag.color||'var(--t2)')+';margin-bottom:3px">'+(ag.name||currentAgent)+'</div><div style="display:flex;gap:4px;padding:8px 0"><div style="width:6px;height:6px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .6s ease-in-out infinite"></div><div style="width:6px;height:6px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .6s ease-in-out .15s infinite"></div><div style="width:6px;height:6px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .6s ease-in-out .3s infinite"></div></div>';
  feed.appendChild(typingDiv);
  feed.scrollTop=feed.scrollHeight;

  try{
    var payload={from:'User',content:content||quickAction};
    if(quickAction) payload.quick_action=quickAction;
    var d=await post(API+'agents/'+currentAgent+'/messages',payload);

    // Remove typing indicator
    var ti=document.getElementById('agent-typing-indicator');
    if(ti) ti.remove();

    // Show agent response
    if(d.reply){
      var agDiv=document.createElement('div');
      agDiv.className='msg-from-agent';
      agDiv.style.alignSelf='flex-start';
      var replyHtml='<div style="font-size:9px;font-weight:700;color:'+(ag.color||'var(--t2)')+';margin-bottom:3px">'+(d.agent_name||ag.name||currentAgent)+'</div>'+fmt(d.reply)+'<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';

      // Sarah redirect button
      if(d.requires_sarah&&currentAgent!=='dmm'){
        replyHtml+='<button onclick="openSarahFromRedirect(\''+esc(d.sarah_context||'')+'\')" style="margin-top:8px;background:var(--ps);border:1px solid var(--p);color:var(--pu);border-radius:var(--r);padding:6px 14px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px">'+window.icon('message',14)+' Chat with Sarah</button>';
      }
      agDiv.innerHTML=replyHtml;
      feed.appendChild(agDiv);
      feed.scrollTop=feed.scrollHeight;
    }
  }catch(e){
    var ti2=document.getElementById('agent-typing-indicator');
    if(ti2) ti2.remove();
    showToast('Error: '+e.message,'error');
  }
}

// Open Sarah's drawer with pre-filled context from agent redirect
window.openSarahFromRedirect=function(context){
  closeAgentDrawer();
  setTimeout(function(){
    openAgentDrawer('dmm');
    drawerTab('messages');
    setTimeout(function(){
      var ta=document.getElementById('agent-msg-input');
      if(ta&&context) ta.value=context;
    },200);
  },300);
};
async function renderDocuments(id){
  var list=document.getElementById('dp-docs-list');
  list.innerHTML='<div style="text-align:center;padding:20px;color:var(--t3);font-size:12px">Loading documents...</div>';
  try{
    var docs=await get(API+'agents/'+id+'/documents');
    var items=docs.documents||docs||[];
    if(!items.length){
      list.innerHTML=`<div style="text-align:center;padding:30px;font-size:12px;color:var(--t3)">No documents yet for ${AGENTS[id]?.name||id}.</div>`;
      return;
    }
    var typeIcons={image:'🖼',video:'🎬',document:'📄',presentation:'📊',audio:'🎵'};
    list.innerHTML=items.map(d=>{
      var icon=typeIcons[d.type]||'📁';
      var date=d.created_at?new Date(d.created_at).toLocaleDateString():'';
      return `<div style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--s2);border:1px solid var(--bd);border-radius:8px">
        <span style="font-size:20px">${icon}</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(d.title)}</div>
          <div style="font-size:10px;color:var(--t3)">${d.type||'file'} · ${date}</div>
        </div>
        ${d.url?`<a href="${d.url}" target="_blank" style="font-size:10px;color:var(--ac);text-decoration:none;font-weight:600">Open ↗</a>`:''}
      </div>`;
    }).join('');
  }catch(e){
    list.innerHTML=`<div style="text-align:center;padding:30px;font-size:12px;color:var(--t3)">Documents produced by ${AGENTS[id]?.name||id} will appear here.</div>`;
  }
}
let _agentStatsFails = 0;
async function loadAgentStats(){
  updateNodeCounts();
  if (_agentStatsFails >= 3) return; // stop retrying after 3 failures

  try {
    var d = await get(API + 'agents/dashboard');
    _agentStatsFails = 0;
    var agents = d.agents || [];

    agents.forEach(a => {
      var ag = AGENTS[a.agent_id] || {};
      // Update profile card stats
      var ongoing = document.getElementById('av-ongoing-' + a.agent_id);
      var upcoming = document.getElementById('av-upcoming-' + a.agent_id);
      var completed = document.getElementById('av-completed-' + a.agent_id);
      if (ongoing)   ongoing.textContent   = a.executing || 0;
      if (upcoming)  upcoming.textContent  = a.pending || 0;
      if (completed) completed.textContent = a.completed || 0;
    });

    // Build detailed task board in drawer (if open)
    if (window._agentDrawerOpen) _renderAgentTaskBoard(window._agentDrawerOpen, agents);

  } catch(e) { _agentStatsFails++; console.warn('[LU] loadAgentStats fail #'+_agentStatsFails+':', e.message); }
}

function _renderAgentTaskBoard(agentId, allAgents) {
  var data = allAgents.find(a => a.agent_id === agentId);
  if (!data) return;
  var ag = AGENTS[agentId] || {};
  var board = document.getElementById('agent-task-board');
  if (!board) return;

  var statusIcons = {pending:''+window.icon("info",14)+'',queued:'🟠',acknowledged:''+window.icon("info",14)+'',executing:''+window.icon("info",14)+'',in_progress:''+window.icon("info",14)+'',completed:''+window.icon("info",14)+'',failed:''+window.icon("close",14)+'',cancelled:''+window.icon("info",14)+''};

  let html = `<div style="margin-bottom:16px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--am)">${data.pending}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Pending</div>
      </div>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--bl)">${data.executing}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Executing</div>
      </div>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--ac)">${data.completed}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Completed</div>
      </div>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--rd)">${data.failed}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Failed</div>
      </div>
    </div>
  </div>`;

  // Recent tasks
  if (data.recent_tasks.length) {
    html += '<div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Recent Tasks</div>';
    html += '<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px">';
    data.recent_tasks.forEach(t => {
      var icon = statusIcons[t.status] || '⚪';
      var tools = t.tools ? (typeof t.tools === 'string' ? JSON.parse(t.tools) : t.tools) : [];
      var creator = t.created_by ? (AGENTS[t.created_by]?.name || t.created_by) : 'Sarah';
      var timeline = [
        t.created_at ? `${window.icon('more',14)} ${new Date(t.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})}` : '',
        t.acknowledged_at ? `${window.icon('info',14)} ${new Date(t.acknowledged_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})}` : '',
        t.started_at ? `${window.icon('edit',14)} ${new Date(t.started_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})}` : '',
        t.completed_at ? `${window.icon('check',14)} ${new Date(t.completed_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})}` : '',
      ].filter(Boolean).join(' → ');
      html += `<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:var(--s2);border:1px solid var(--bd);border-radius:8px">
        <span style="font-size:14px;margin-top:2px">${icon}</span>
        <div style="flex:1">
          <div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(t.title||'')}</div>
          <div style="font-size:10px;color:var(--t3)">Created by ${creator} · ${t.status} ${tools.length?'· '+tools.join(', '):''} ${t.duration_ms?'· '+t.duration_ms+'ms':''}</div>
          ${timeline?'<div style="font-size:9px;color:var(--t3);margin-top:2px">'+timeline+'</div>':''}
        </div>
        <div style="font-size:10px;color:var(--t3)">${t.created_at?new Date(t.created_at).toLocaleDateString():''}</div>
      </div>`;
    });
    html += '</div>';
  }

  // Recent executions
  if (data.recent_exec.length) {
    html += '<div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Recent Executions</div>';
    html += '<div style="display:flex;flex-direction:column;gap:6px">';
    data.recent_exec.forEach(e => {
      html += `<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--s1);border-radius:6px;font-size:11px">
        <span>${parseInt(e.success)?''+window.icon("check",14)+'':''+window.icon("close",14)+''}</span>
        <span style="font-weight:600;color:var(--t1)">${esc(e.tool_id)}</span>
        <span style="flex:1;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc((e.result_summary||'').slice(0,60))}</span>
        <span style="color:var(--t3)">${e.duration_ms||0}ms</span>
      </div>`;
    });
    html += '</div>';
  }

  if (!data.recent_tasks.length && !data.recent_exec.length) {
    html += '<div style="text-align:center;padding:24px;color:var(--t3);font-size:12px">No tasks or executions yet for this agent.</div>';
  }

  board.innerHTML = html;
}

// ── Projects ────────────────────────────────────────────────────────────────
let allProjectTasks = [];
let projectViewMode = 'kanban';
let dragTaskId = null;
let dragFromStatus = null;
let activeTaskDrawer = null;

var STATUS_COLORS = {
    backlog:'var(--t3)', planned:'var(--am)', in_progress:'var(--ac)',
    review:'var(--pu)', completed:'var(--bl)',
};
var STATUS_LABELS = {
    backlog:'Backlog', planned:'Planned', in_progress:'In Progress',
    review:'Review', completed:'Completed',
};

async function loadProjects() {
    try {
        var r = await get(API + 'projects/tasks');
        allProjectTasks = r.tasks || [];
        renderKanban();
        if (projectViewMode === 'timeline') renderTimeline();

        var empty = document.getElementById('proj-empty');
        var kanban = document.getElementById('proj-kanban');
        if (allProjectTasks.length === 0) {
            if (empty) empty.style.display = 'flex';
            if (kanban) kanban.style.display = 'none';
        } else {
            if (empty) empty.style.display = 'none';
            if (kanban && projectViewMode === 'kanban') kanban.style.display = 'flex';
        }
    } catch(e) { console.error('loadProjects:', e); }
}

function setProjectView(mode, btn) {
    projectViewMode = mode;
    document.querySelectorAll('.pvt-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('proj-kanban').style.display  = mode === 'kanban'   ? 'flex' : 'none';
    document.getElementById('proj-timeline').style.display = mode === 'timeline' ? 'block' : 'none';
    if (mode === 'timeline') renderTimeline();
}

function renderKanban() {
    var cols = ['backlog','planned','in_progress','review','completed'];
    cols.forEach(status => {
        var cards = document.getElementById('kbc-cards-' + status);
        var empty = document.getElementById('kbc-empty-' + status);
        var count = document.getElementById('kbc-count-' + status);
        var tasks = allProjectTasks.filter(t => t.status === status);
        if (count) count.textContent = tasks.length;
        if (!cards) return;
        // Clear existing cards
        cards.querySelectorAll('.kb-card').forEach(c => c.remove());
        if (empty) empty.style.display = tasks.length ? 'none' : 'block';
        tasks.forEach(task => cards.appendChild(makeKbCard(task)));
    });
}

function makeKbCard(task) {
    var a   = AGENTS[task.assignee] || { emoji:'👤', color:'var(--t2)', name: task.assignee };
    var div = document.createElement('div');
    div.className = 'kb-card';
    div.id = 'kbc-' + task.id;
    div.draggable = true;
    div.dataset.taskId = task.id;
    div.dataset.status = task.status;
    div.innerHTML = `
        <div class="kb-card-title">${esc(task.title)}</div>
        ${task.success_metric ? `<div style="font-size:9px;color:var(--ac);margin-bottom:6px;line-height:1.4">${window.icon('more',14)} ${esc(task.success_metric)}</div>` : ''}
        <div class="kb-card-footer">
            <div class="kb-card-agent" style="background:transparent;border:none" title="${esc(a.name)}">${typeof buildAgentOrb==="function"?buildAgentOrb(task.assignee,"sm","idle"):a.emoji}</div>
            <span class="kb-card-priority ${task.priority||'medium'}">${task.priority||'medium'}</span>
            <span class="kb-card-time">${task.estimated_time||60}m</span>
        </div>`;
    div.addEventListener('dragstart', e => kbDragStart(e, task.id, task.status));
    div.addEventListener('dragend',   e => div.classList.remove('dragging'));
    div.addEventListener('click',     () => openTaskDrawer(task.id));
    return div;
}

// ── Drag & Drop ────────────────────────────────────────────────────────────
function kbDragStart(e, taskId, status) {
    dragTaskId = taskId; dragFromStatus = status;
    setTimeout(() => document.getElementById('kbc-' + taskId)?.classList.add('dragging'), 0);
    e.dataTransfer.effectAllowed = 'move';
}
function kbDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}
async function kbDrop(e, newStatus) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    document.querySelectorAll('.kb-col').forEach(c => c.classList.remove('drag-over'));
    if (!dragTaskId || dragFromStatus === newStatus) return;

    var taskId = dragTaskId; var fromStatus = dragFromStatus;
    dragTaskId = null; dragFromStatus = null;

    // Optimistic update
    var task = allProjectTasks.find(t => t.id === taskId);
    if (!task) return;
    task.status = newStatus;
    renderKanban();

    try {
        await put(API + 'tasks/' + taskId + '/status', { status: newStatus });
        // If moved to in_progress — agent starts working (handled server-side)
    } catch(err) {
        // Animate bounce-back
        task.status = fromStatus;
        renderKanban();
        var card = document.getElementById('kbc-' + taskId);
        if (card) card.classList.add('bounce-back');
        setTimeout(() => card?.classList.remove('bounce-back'), 500);
        console.error('Status update failed:', err);
    }
}

// ── Timeline ───────────────────────────────────────────────────────────────
function renderTimeline() {
    var header = document.getElementById('tl-header');
    var body   = document.getElementById('tl-body');
    if (!header || !body) return;

    var today = new Date(); today.setHours(0,0,0,0);
    var days = 30;
    // Header days
    header.innerHTML = '';
    for (let i = 0; i < days; i++) {
        var d = new Date(today); d.setDate(d.getDate() + i);
        var el = document.createElement('div');
        el.className = 'tl-day' + (i === 0 ? ' today' : '');
        el.textContent = d.getDate() + '/' + (d.getMonth()+1);
        el.style.minWidth = '28px'; el.style.flex = '1';
        header.appendChild(el);
    }

    // Group by assignee
    var byAgent = {};
    allProjectTasks.filter(t => t.status !== 'completed' && t.status !== 'backlog').forEach(t => {
        if (!byAgent[t.assignee]) byAgent[t.assignee] = [];
        byAgent[t.assignee].push(t);
    });

    body.innerHTML = '';
    if (!Object.keys(byAgent).length) {
        body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--t3);font-size:12px">No active tasks to display</div>';
        return;
    }

    var colW = Math.max(28, (body.clientWidth - 120) / days);
    Object.entries(byAgent).forEach(([agentId, tasks]) => {
        var a = AGENTS[agentId] || { emoji:'👤', name: agentId, color:'var(--t2)' };
        var row = document.createElement('div');
        row.className = 'tl-row';
        var lbl = document.createElement('div');
        lbl.className = 'tl-row-lbl';
        lbl.innerHTML = `<span style="font-size:14px">${a.emoji}</span><span>${esc(a.name)}</span>`;
        row.appendChild(lbl);
        var grid = document.createElement('div');
        grid.className = 'tl-grid';
        grid.style.cssText = `flex:1;position:relative;height:32px`;

        tasks.forEach((task, idx) => {
            var startOffset = idx * 2; // stagger tasks
            var dur = Math.max(1, Math.ceil((task.estimated_time || 60) / (60 * 8))); // days
            var bar = document.createElement('div');
            bar.className = 'tl-bar';
            bar.style.cssText = `left:${startOffset * colW}px;width:${dur * colW - 2}px;background:${a.color};top:${6 + (idx % 2) * 0}px`;
            bar.textContent = task.title;
            bar.title = task.title;
            bar.onclick = () => openTaskDrawer(task.id);
            grid.appendChild(bar);
        });

        row.appendChild(grid);
        body.appendChild(row);
    });
}

// ── Task Drawer ───────────────────────────────────────────────────────────
async function openTaskDrawer(taskId) {
    activeTaskDrawer = taskId;
    var drawer = document.getElementById('task-drawer');
    drawer.style.display = 'flex';

    // Load fresh from runtime
    try {
        var task = await get(API + 'projects/tasks/' + taskId);
        renderTaskDrawer(task);
    } catch(e) {
        // Fallback to local
        var task = allProjectTasks.find(t => t.id === taskId);
        if (task) renderTaskDrawer(task);
    }
}

function renderTaskDrawer(task) {
    var a = AGENTS[task.assignee] || { emoji:'👤', name: task.assignee, color:'var(--t2)', title:'' };
    setEl('td-agent-em', a.emoji);
    setEl('td-title', task.title);
    document.getElementById('td-meta').innerHTML = `
        <span class="msg-badge" style="background:${STATUS_COLORS[task.status]}22;color:${STATUS_COLORS[task.status]};border:1px solid ${STATUS_COLORS[task.status]}44">${STATUS_LABELS[task.status]||task.status}</span>
        <span class="kb-card-priority ${task.priority||'medium'}">${task.priority||'medium'}</span>
        <span style="font-size:10px;color:var(--t3)">${task.estimated_time||60}m est</span>`;
    setEl('td-desc', task.description || '—');
    setEl('td-metric', task.success_metric || '—');
    setEl('td-assignee', `${a.emoji} ${a.name} — ${a.title}`);

    var coordWrap = document.getElementById('td-coord-wrap');
    if (task.coordinator && AGENTS[task.coordinator]) {
        var c = AGENTS[task.coordinator];
        setEl('td-coord', `${c.emoji} ${c.name} — ${c.title}`);
        coordWrap.style.display = 'block';
    } else { coordWrap.style.display = 'none'; }

    var mtgEl = document.getElementById('td-meeting');
    if (task.meeting_id) {
        mtgEl.innerHTML = `<span style="color:var(--ac);cursor:pointer" onclick="nav('reports')">${task.meeting_id}</span>`;
    } else { mtgEl.textContent = '—'; }

    // Deliverable
    var delEmpty   = document.getElementById('td-deliverable-empty');
    var delContent = document.getElementById('td-deliverable-content');
    if (task.deliverable) {
        delEmpty.style.display = 'none'; delContent.style.display = 'block';
        setEl('td-del-summary', task.deliverable.summary || '');
        var json = task.deliverable.deliverable || task.deliverable;
        setEl('td-del-json', JSON.stringify(json, null, 2));
    } else {
        delEmpty.style.display = 'block'; delContent.style.display = 'none';
    }

    // Notes
    var notesFeed = document.getElementById('td-notes-feed');
    notesFeed.innerHTML = '';
    (task.notes || []).forEach(n => {
        var div = document.createElement('div');
        div.className = `td-note-item type-${n.type||'user'}`;
        div.innerHTML = `<span class="tni-author">${esc(n.author_name||n.author)}</span>${esc(n.content)}<span class="tni-time" style="display:block;margin-top:3px">${n.at ? new Date(n.at).toLocaleString() : ''}</span>`;
        notesFeed.appendChild(div);
    });

    // History
    var histFeed = document.getElementById('td-history-feed');
    histFeed.innerHTML = '';
    (task.history || []).forEach(h => {
        var div = document.createElement('div');
        div.className = 'td-hist-item';
        div.innerHTML = `<div class="td-hist-dot" style="background:${STATUS_COLORS[h.status]||'var(--t3)'}"></div><div><div style="font-size:11px;color:var(--t1)">${STATUS_LABELS[h.status]||h.status}${h.from?` <span style="color:var(--t3)">from ${STATUS_LABELS[h.from]||h.from}</span>`:''}</div><div style="font-size:9px;color:var(--t3)">${h.at ? new Date(h.at).toLocaleString() : ''} · ${h.by||'system'}${h.note?` — ${esc(h.note)}`:''}</div></div>`;
        histFeed.appendChild(div);
    });

    tdTab('details', document.querySelector('.td-tab'));
}

function closeTaskDrawer() {
    document.getElementById('task-drawer').style.display = 'none';
    activeTaskDrawer = null;
    loadProjects(); // refresh kanban after drawer closes
}

function tdTab(pane, btn) {
    document.querySelectorAll('.td-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    document.querySelectorAll('.td-pane').forEach(p => { p.style.display='none'; p.classList.remove('active'); });
    var el = document.getElementById('tdp-' + pane);
    if (el) { el.style.display = 'block'; el.classList.add('active'); }
}

async function submitTaskNote() {
    if (!activeTaskDrawer) return;
    var ta = document.getElementById('td-note-ta');
    var content = ta.value.trim();
    if (!content) return;
    ta.value = '';
    try {
        await post(API + 'projects/tasks/' + activeTaskDrawer + '/note', { content });
        openTaskDrawer(activeTaskDrawer); // refresh drawer
    } catch(e) { console.error('note failed:', e); }
}

// Helper: PUT request
async function put(url, data) {
    var r = await fetch(url, { method:'PUT', headers:{'Content-Type':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}, body:JSON.stringify(data) });
    if (!r.ok) throw new Error('PUT failed: ' + r.status);
    return r.json();
}

// ── Reports ────────────────────────────────────────────────────────────────
let rptTab='meetings';
async function loadReports(){
  var grid=document.getElementById('rv-grid');
  grid.innerHTML=`
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;border-bottom:1px solid var(--bd);padding-bottom:1px">
      <button class="tab ${rptTab==='meetings'?'active':''}" onclick="rptTab='meetings';loadReports()" style="display:inline-flex;align-items:center;gap:6px">${window.icon('more',14)} Meetings</button>
      ${window._luIsAdmin?'<button class="tab ${rptTab===\'executions\'?\'active\':\'\'}" onclick="rptTab=\'executions\';loadReports()">'+window.icon("ai",14)+' Executions</button>':''}
      ${window._luIsAdmin?'<button class="tab ${rptTab===\'decisions\'?\'active\':\'\'}" onclick="rptTab=\'decisions\';loadReports()">'+window.icon("tag",14)+' Decisions</button>':''}
      <button class="tab ${rptTab==='tasks'?'active':''}" onclick="rptTab='tasks';loadReports()" style="display:inline-flex;align-items:center;gap:6px">${window.icon('more',14)} Tasks</button>
    </div>
    <div id="rpt-content" style="min-height:200px"><div style="text-align:center;padding:40px;color:var(--t3)">Loading…</div></div>
  `;
  var box=document.getElementById('rpt-content');
  try{
    if(rptTab==='meetings'){
      var h=await get(API+'history');
      if(!h||!h.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon" style="color:var(--t3)">'+window.icon('more',32)+'</div><div class="rv-empty-text">No meeting history yet.</div></div>';return;}
      box.innerHTML='<div class="rv-cards-grid">'+h.map(m=>`<div class="rv-card" onclick="showSummary('${esc(m.id)}','${esc(m.topic)}','${esc(m.date)}','${encodeURIComponent(m.summary||'')}')"><div class="rv-card-date">${new Date(m.date).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'})}</div><div class="rv-card-topic">${esc(m.topic)}</div><div class="rv-card-preview">${esc((m.summary||'').slice(0,200))}</div></div>`).join('')+'</div>';
    }
    else if(rptTab==='executions'){
      var d=await get(API+'exec/history?limit=50');
      var rows=d.history||[];
      if(!rows.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon">'+window.icon("ai",14)+'</div><div class="rv-empty-text">No executions recorded yet.</div></div>';return;}
      box.innerHTML='<div style="display:flex;flex-direction:column;gap:6px">'+rows.map(r=>{
        var ok=parseInt(r.success);var dt=new Date(r.created_at);
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s1);border:1px solid var(--bd);border-radius:8px">
          <div style="font-size:16px">${ok?''+window.icon("check",14)+'':''+window.icon("close",14)+''}</div>
          <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(r.tool_id)}</div><div style="font-size:10px;color:var(--t3)">${esc(r.agent_id)} · ${r.duration_ms||0}ms · ${r.mode||'?'}</div>${r.rationale?'<div style="font-size:10px;color:var(--t2);margin-top:2px">'+esc(r.rationale.slice(0,100))+'</div>':''}</div>
          <div style="font-size:10px;color:var(--t3);white-space:nowrap">${dt.toLocaleDateString()} ${dt.toLocaleTimeString()}</div>
          <div style="font-size:10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;color:var(--t2)">${esc((r.result_summary||'').slice(0,80))}</div>
        </div>`;
      }).join('')+'</div>';
    }
    else if(rptTab==='decisions'){
      var d=await get(API+'decisions?limit=50');
      var rows=d.decisions||[];
      if(!rows.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon">'+window.icon("tag",14)+'</div><div class="rv-empty-text">No decisions recorded yet.</div></div>';return;}
      box.innerHTML='<div style="display:flex;flex-direction:column;gap:6px">'+rows.map(r=>{
        var st=r.status;var sc=st==='approved'?'var(--ac)':st==='rejected'?'#F87171':st==='proposed'?'var(--am)':'var(--t3)';
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s1);border:1px solid var(--bd);border-radius:8px">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:${sc};min-width:60px">${st}</div>
          <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(r.title)}</div>${r.rationale?'<div style="font-size:10px;color:var(--t2);margin-top:2px">'+esc(r.rationale.slice(0,120))+'</div>':''}<div style="font-size:10px;color:var(--t3)">${esc(r.agent_id)} · ${r.decision_type}</div></div>
          <div style="font-size:10px;color:var(--t3);white-space:nowrap">${new Date(r.created_at).toLocaleDateString()}</div>
        </div>`;
      }).join('')+'</div>';
    }
    else if(rptTab==='tasks'){
      var d=await get(API+'tasks?wsId=1');
      var rows=(d.tasks||d||[]).slice(0,50);
      if(!rows.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon" style="color:var(--t3)">'+window.icon('more',32)+'</div><div class="rv-empty-text">No tasks yet.</div></div>';return;}
      box.innerHTML='<div style="display:flex;flex-direction:column;gap:6px">'+rows.map(r=>{
        var st=r.status||'pending';var sc=st==='completed'?'var(--ac)':st==='failed'?'#F87171':st==='in_progress'?'var(--am)':'var(--t3)';
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s1);border:1px solid var(--bd);border-radius:8px">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:${sc};min-width:70px">${st}</div>
          <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(r.title)}</div><div style="font-size:10px;color:var(--t3)">${esc(r.agent_id||'')} · ${r.duration_ms?r.duration_ms+'ms':''}</div></div>
          <div style="font-size:10px;color:var(--t3);white-space:nowrap">${r.created_at?new Date(r.created_at).toLocaleDateString():''}</div>
        </div>`;
      }).join('')+'</div>';
    }
  }catch(e){box.innerHTML='<div style="color:#F87171;padding:20px">Error loading: '+esc(e.message)+'</div>';}
}
function showSummary(id,topic,date,summaryEnc){
  setEl('sm-title',topic);
  setEl('sm-date',new Date(date).toLocaleString());
  document.getElementById('sm-body').innerHTML=fmt(decodeURIComponent(summaryEnc));
  document.getElementById('summary-backdrop').classList.add('visible');
}
function closeSummaryModal(){document.getElementById('summary-backdrop').classList.remove('visible');}

// ══ MEETING ROOM ═══════════════════════════════════════════════════════════
function setType(t,el){
  selType=t;
  document.querySelectorAll('#type-grid>div').forEach(b=>{b.style.border='1.5px solid var(--bd)';b.style.background='var(--s2)';});
  el.style.border='1.5px solid rgba(108,92,231,.5)';el.style.background='var(--ps)';
}
async function launchMeeting(){
  var topic=document.getElementById('topic-input').value.trim();
  if(!topic){document.getElementById('topic-input').focus();return;}
  document.getElementById('mtg-start-screen').style.display='none';
  document.getElementById('mtg-active').style.display='flex';
  document.getElementById('mtg-topic').textContent=topic.length>55?topic.slice(0,55)+'…':topic;
  document.getElementById('live-dot').classList.add('on');
  spoken.clear();phaseLog.clear();
  intel.theme='';intel.seo=[];intel.content=[];intel.social=[];intel.funnel=[];
  document.getElementById('disc-feed').innerHTML='';
  document.getElementById('mi-body').innerHTML='<div class="mi-ph" id="mi-ph"><div class="mi-ph-icon">'+window.icon("ai",14)+'</div><div class="mi-ph-txt">Intelligence builds as the team discusses.</div></div>';
  try{
    let r;
  try {
    r = await post(API+'meeting/start',{type:selType,topic,businessName:BN,website:BU});
    // Governance gate: if WP returns 422 (profile incomplete) redirect to Settings
    if (r && r.error === 'workspace_profile_incomplete') {
      showToast('Complete your Workspace Intelligence Profile in Settings before starting a meeting.', 'error');
      setTimeout(() => nav('settings'), 1200);
      return;
    }
  } catch(gateErr) {
    // 422 may throw in some fetch wrappers
    if (gateErr?.message?.includes('422') || gateErr?.message?.includes('workspace_profile')) {
      showToast('Complete your Workspace Intelligence Profile in Settings before starting a meeting.', 'error');
      setTimeout(() => nav('settings'), 1200);
      return;
    }
    throw gateErr;
  }
    mid=r.meeting_id;seen=0;done=false;_redisCount=0;localUserMsgs=[];
    document.getElementById('btn-wrap').disabled=false;
    pollT=setInterval(poll,4000);
  }catch(e){showMsgErr('Could not start: '+e.message);}
}
var _pollStaleCount=0;var _pollLastHash='';var _redisCount=0;var _pollFails=0;
async function poll(){
  if(!mid) return;
  if(_pollFails>=5){console.warn('[LU] Meeting poll disabled after 5 consecutive failures');if(pollT){clearInterval(pollT);pollT=null;}return;}
  try{
    var d=await get(API+'meeting/'+mid+'/status?_t='+Date.now());
    var redisMsgs=d.messages||[];
    _pollFails=0; // reset on success
    // Stale detection: if message count unchanged for 8+ consecutive polls, force re-fetch
    var curHash=redisMsgs.length+':'+(d.phase||'')+(d.status||'');
    if(curHash===_pollLastHash){_pollStaleCount++;}else{_pollStaleCount=0;}_pollLastHash=curHash;
    if(_pollStaleCount>8&&!done){console.warn('[LU] Poll stale for 8+ cycles, will retry with cache bust');_pollStaleCount=0;}

    // ── Merge Redis messages with local user messages ────────────────
    // Runtime does NOT store user messages in Redis. We merge them here
    // so they persist through polls and never disappear from the feed.
    var merged=[];
    let uidx=0;
    for(let i=0;i<redisMsgs.length;i++){
      // Insert any user messages that were sent AFTER the previous Redis message
      while(uidx<localUserMsgs.length && localUserMsgs[uidx]._afterRedis<=i){
        merged.push(localUserMsgs[uidx++]);
      }
      merged.push(redisMsgs[i]);
    }
    // Append remaining user messages (sent after all current Redis messages)
    while(uidx<localUserMsgs.length) merged.push(localUserMsgs[uidx++]);

    // Render new messages from the merged stream
    for(let i=seen;i<merged.length;i++) renderMsg(merged[i]);
    seen=merged.length;
    _redisCount=redisMsgs.length;

    // ── Defensive: verify all user messages are still in DOM ─────────
    // If any are missing (due to DOM manipulation, race condition, etc.),
    // re-inject them immediately.
    for(var um of localUserMsgs){
      if(um._umid && !document.querySelector('[data-umid="'+um._umid+'"]')){
        renderMsg(um);
      }
    }

    applyPhase(d.phase,d.status);
    applySpeaker(d.status,d.current_speaker,d.spokenAgents||[]);
    if(d.status==='complete'&&!done){
      done=true;clearInterval(pollT);removeTyping();
      document.getElementById('live-dot').classList.remove('on');
      document.getElementById('btn-wrap').disabled=true;
      document.getElementById('btn-wrap').textContent='✓ Meeting Ended';
      document.getElementById('btn-wrap').style.background='var(--ac)';
      document.getElementById('btn-wrap').style.borderColor='var(--ac)';
      document.getElementById('btn-wrap').style.color='#fff';
      // Save history
      var synMsg=redisMsgs.find(m=>m.role==='synthesis');
      if(synMsg) await post(API+'history',{meeting_id:mid,topic:d.topic||'',summary:synMsg.content});
      // Show meeting-ended banner in feed
      var feed=document.getElementById('disc-feed');
      if(feed){
        var banner=document.createElement('div');
        banner.style.cssText='margin:20px 0;padding:16px 20px;background:linear-gradient(135deg,rgba(108,92,231,.08),rgba(0,229,168,.06));border:1px solid rgba(108,92,231,.2);border-radius:12px;text-align:center';
        banner.innerHTML=`
          <div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:6px">🏁 Meeting Complete</div>
          <div style="font-size:12px;color:var(--t2);margin-bottom:12px">Sarah is generating tasks and action items…</div>
          <div id="meeting-end-links" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
            <span style="font-size:11px;color:var(--t3)">Loading tasks…</span>
          </div>
        `;
        feed.appendChild(banner);
        scrollFeed();
      }
      // Check for pending tasks
      setTimeout(()=>checkPendingTasks(mid),2500);
    }
    if(d.status==='error'){clearInterval(pollT);showMsgErr(d.error||'Error');}
  }catch(e){_pollFails++;console.error('poll:',e);}
}
function applyPhase(phase){
  if(!phase)return;
  var idx=PHASES.indexOf(phase);
  PHASES.forEach((p,i)=>{var el=document.getElementById('ps-'+p);if(!el)return;el.classList.remove('active','done');if(i<idx)el.classList.add('done');else if(i===idx)el.classList.add('active');});
  if(phase!==lastPhase&&lastPhase&&seen>0&&!phaseLog.has(phase)){
    phaseLog.add(phase);
    var lbl={idea_round:''+window.icon("ai",14)+' Ideas Round',discussion_round:''+window.icon("message",14)+' Discussion Round',refinement_round:''+window.icon("edit",14)+' Refinement Round',synthesis:''+window.icon("more",14)+' Action Plan'};
    var css={idea_round:'pd-idea',discussion_round:'pd-disc',refinement_round:'pd-ref',synthesis:'pd-syn'};
    if(lbl[phase]){var d=document.createElement('div');d.className='phase-div';d.innerHTML=`<div class="pd-line"></div><div class="pd-pill ${css[phase]}">${lbl[phase]}</div><div class="pd-line"></div>`;document.getElementById('disc-feed').appendChild(d);scrollFeed();}
  }
  lastPhase=phase;
}
function applySpeaker(status,spk,spokenArr){
  if(spk&&spk!==lastSpk){showTyping(spk);lastSpk=spk;}
  if(!spk){removeTyping();lastSpk=null;}
  spokenArr.forEach(id=>spoken.add(id));
  let active=0;
  Object.keys(AGENTS).forEach(id=>{
    var el=document.getElementById('mac-'+id),st=document.getElementById('mst-'+id);
    if(!el)return;el.classList.remove('speaking','done');
    if(id===spk){
      el.classList.add('speaking');active++;
      if(st){st.className='mac-st st-thinking';st.textContent='Working…';}
      startAgentActivity(id);
    } else {
      stopAgentActivity(id);
      if(spoken.has(id)){el.classList.add('done');if(st){st.className='mac-st st-done';st.textContent='Contributed';}}
      else if(st){st.className='mac-st st-idle';st.textContent='Waiting';}
    }
  });
  setEl('agents-active',spoken.size+' active');
}
// ── Phase 6.5: Structured synthesis panel renderer ───────────────────────
function _renderSynthesisPanel(text) {
  if (!text) return '';
  // Split into sections by ## or ### headers
  var lines = text.split('\n');
  let sections = [];
  let current = { title: 'Summary', lines: [] };

  lines.forEach(line => {
    var h2 = line.match(/^##\s+(.+)/);
    var h3 = line.match(/^###\s+(.+)/);
    if (h2 || h3) {
      if (current.lines.length || current.title !== 'Summary') sections.push(current);
      current = { title: (h2 || h3)[1].replace(/\*\*/g, '').trim(), lines: [], level: h2 ? 2 : 3 };
    } else {
      current.lines.push(line);
    }
  });
  if (current.lines.length) sections.push(current);

  if (sections.length <= 1) return fmt(text); // No structure detected — fallback

  var domainIcons = {seo:'📊',content:'✍️',social:'📱',email:'📧',crm:'👥',marketing:'📣',builder:'🏗',technical:'⚙️',website:'🌐',lead:'🎯',strategy:'🧭'};

  let html = '';
  sections.forEach(sec => {
    var icon = Object.entries(domainIcons).find(([k]) => sec.title.toLowerCase().includes(k))?.[1] || '▪';
    var body = sec.lines.join('\n').trim();
    if (!body) return;

    // Check if body contains action items (numbered or bulleted lines)
    var actionLines = body.split('\n').filter(l => l.match(/^\s*[\d]+\.|^\s*[-•*]|^\s*✓|^\s*→/));
    var hasActions = actionLines.length >= 2;

    html += `<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;margin-bottom:10px">`;
    html += `<div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:8px;display:flex;align-items:center;gap:8px">
      <span style="font-size:16px">${icon}</span>${esc(sec.title)}
    </div>`;

    if (hasActions) {
      html += '<div style="display:flex;flex-direction:column;gap:4px">';
      body.split('\n').forEach(line => {
        var trimmed = line.replace(/^\s*[\d]+\.\s*|^\s*[-•*]\s*|^\s*[✓→]\s*/, '').trim();
        if (!trimmed) return;
        var isAction = line.match(/^\s*[\d]+\.|^\s*[-•*]|^\s*✓|^\s*→/);
        if (isAction) {
          // Detect agent mentions
          let agentBadge = '';
          ['James','Priya','Marcus','Elena','Alex','Sarah'].forEach(n => {
            if (trimmed.includes(n)) {
              var id = n.toLowerCase();
              var ag = AGENTS[id] || AGENTS[n.toLowerCase().slice(0,1) + n.slice(1)];
              if (ag) agentBadge += `<span style="font-size:10px;background:${ag.color}15;color:${ag.color};padding:1px 6px;border-radius:3px;margin-left:6px">${ag.emoji} ${ag.name||n}</span>`;
            }
          });
          html += `<div style="font-size:12px;color:var(--t2);padding:5px 0;border-bottom:1px solid rgba(255,255,255,.03);display:flex;align-items:center;gap:6px">
            <span style="color:var(--ac);font-size:10px;flex-shrink:0">●</span>
            <span style="flex:1">${fmt(trimmed)}</span>${agentBadge}
          </div>`;
        } else {
          html += `<div style="font-size:12px;color:var(--t3);padding:3px 0">${fmt(trimmed)}</div>`;
        }
      });
      html += '</div>';
    } else {
      html += `<div style="font-size:12px;color:var(--t2);line-height:1.6">${fmt(body)}</div>`;
    }
    html += '</div>';
  });

  return html;
}

function renderMsg(msg){
  removeTyping();
  var feed=document.getElementById('disc-feed');
  var isU=msg.role==='user',isDMM=msg.agent_id==='dmm',isSyn=msg.role==='synthesis';
  var c=AGENTS[msg.agent_id]?.color||'var(--t2)',em=isU?'👤':(AGENTS[msg.agent_id]?.emoji||'🤖');
  if(!isU&&!spoken.has(msg.agent_id)){spoken.add(msg.agent_id);var jn=document.createElement('div');jn.className='joined-n';jn.innerHTML=`<div class="jn-chip" style="color:${c}">${em} ${msg.name} joined</div>`;feed.appendChild(jn);}
  if(!isU) extractIntel(msg);

  // Parse governance action metadata embedded by the runtime
  let govAction=null;
  let displayContent=msg.content||'';
  var govMatch=displayContent.match(/^__GOVERNANCE_ACTION__([\s\S]*?)__END_GOVERNANCE__\n?([\s\S]*)$/);
  if(govMatch){
    try{ govAction=JSON.parse(govMatch[1]); }catch(e){}
    displayContent=govMatch[2].trim();
  }

  var badge=msg.role==='opening'?'<span class="msg-badge b-opens">Opens</span>':msg.role==='checkin'?'<span class="msg-badge b-checkin">Check-in</span>':msg.role==='synthesis'?'<span class="msg-badge b-plan">Action Plan</span>':'';

  // Build governance approval card if present
  var govCard=govAction?`<div class="gov-inline-card" id="gov-${govAction.action_id}">
    <div style="font-size:10px;font-weight:700;color:var(--am);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:inline-flex;align-items:center;gap:5px">${window.icon('warning',12)} Action Requires Approval</div>
    <div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:4px">${escHtml(govAction.tool_name)}</div>
    <div style="font-size:12px;color:var(--t2);margin-bottom:10px">${escHtml(govAction.preview)}</div>
    <div style="display:flex;gap:8px">
      <button class="gov-btn gov-approve" style="font-size:11px;padding:6px 14px" onclick="govApprove('${govAction.action_id}',this)">✓ Approve</button>
      <button class="gov-btn gov-reject"  style="font-size:11px;padding:6px 14px" onclick="govReject('${govAction.action_id}',this)">✕ Reject</button>
    </div>
  </div>`:''

  var bubContent=isSyn
    ?`<div>${_renderSynthesisPanel(displayContent)}</div>
      <div style="display:flex;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--bd)">
        <button class="dl-btn" onclick="downloadTranscript()" style="font-size:11px">↓ Download</button>
        <button class="btn btn-outline btn-sm" onclick="nav('previews')" style="font-size:11px;border-color:var(--am)">${window.icon('eye',14)} Previews</button>
        <button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} Tasks</button>
      </div>`
    :`${fmt(displayContent)}${govCard}`;

  var div=document.createElement('div');
  div.className=`msg-card${isU?' is-user':''}${isDMM&&!isU?' is-dmm':''}${isSyn?' is-synthesis':''}`;
  if(isU&&msg._umid) div.setAttribute('data-umid',msg._umid);
  div.innerHTML=`<div class="msg-av" style="background:transparent;border:none">${(!isU&&typeof buildAgentOrb==="function")?buildAgentOrb(msg.agent_id,"sm",isSyn?"success":"idle"):em}</div><div class="msg-body"><div class="msg-meta"><span class="msg-name" style="color:${c}">${msg.name}</span>${msg.title&&!isU?`<span class="msg-title-lbl">${msg.title}</span>`:''} ${badge}</div><div class="msg-bubble">${bubContent}</div></div>`;
  feed.appendChild(div);scrollFeed();

  // ── Execution Controller: detect actionable intent → inject buttons ──
  if(!isU && !isSyn && msg.role !== 'opening') execCtrl(div, msg);

  // Update badge count if a governance action arrived
  if(govAction){
    get(API+'governance/pending').then(d=>updateGovBadge((d.pending||[]).length)).catch(()=>{});
  }
}
function showTyping(aid){
  removeTyping();
  var c=AGENTS[aid]?.color||'var(--t2)',em=AGENTS[aid]?.emoji||'🤖',name=AGENTS[aid]?.name||aid;
  var t=(THINK[aid]||['Thinking…'])[Math.floor(Math.random()*(THINK[aid]?.length||1))];
  var div=document.createElement('div');div.id='typing-ind';div.className='typing-card';
  div.innerHTML=`<div class="msg-av" style="background:transparent;border:none">${(!isU&&typeof buildAgentOrb==="function")?buildAgentOrb(msg.agent_id,"sm",isSyn?"success":"idle"):em}</div><div><div style="font-size:9px;color:${c};font-weight:700;margin-bottom:3px">${name}</div><div class="ty-bub"><div class="ty-dots"><div class="ty-dot" style="background:${c}"></div><div class="ty-dot" style="background:${c}"></div><div class="ty-dot" style="background:${c}"></div></div><span class="ty-lbl">${t}</span></div></div>`;
  document.getElementById('disc-feed').appendChild(div);scrollFeed();
}
function removeTyping(){document.getElementById('typing-ind')?.remove();}
function scrollFeed(){var f=document.getElementById('disc-feed');if(f)f.scrollTop=f.scrollHeight;}
function extractIntel(msg){
  var text=msg.content,role=msg.agent_id;let updated=false;
  if(role==='dmm'&&!intel.theme){var m=text.match(/(?:strategy|campaign|focus).*?(?:around|on|for)\s+[""]?([^.,""\n]{10,50})/i);if(m){intel.theme=m[1].trim();updated=true;}}
  if(role==='james'){var w=text.match(/[""]([^""]{5,40})[""]/g)||[];w.forEach(x=>{var c=x.replace(/[""]/g,'');if(!intel.seo.includes(c)&&intel.seo.length<6){intel.seo.push(c);updated=true;}});}
  if(role==='priya'){var m=text.match(/\b([A-Z][a-z]+(?: [A-Z][a-z]+)?)\b(?= content| strategy| guide)/g)||[];m.forEach(p=>{if(!intel.content.includes(p)&&intel.content.length<5){intel.content.push(p);updated=true;}});}
  if(role==='marcus'){['Instagram','LinkedIn','TikTok','Facebook','YouTube','Reels'].forEach(p=>{if(text.includes(p)&&!intel.social.includes(p)){intel.social.push(p);updated=true;}});}
  if(role==='elena'&&!intel.funnel.length){var s=[];if(/content|blog/i.test(text))s.push('Content');if(/landing page|form/i.test(text))s.push('Landing Page');if(/CRM|lead/i.test(text))s.push('CRM');if(/email|nurture/i.test(text))s.push('Email');if(s.length){intel.funnel=s;updated=true;}}
  if(updated)renderIntel();
}
function renderIntel(){
  var body=document.getElementById('mi-body');
  document.getElementById('mi-ph')?.remove();
  document.getElementById('mi-live')?.classList.add('on');
  body.innerHTML='';
  var mk=(icon,title,content,from)=>{var s=document.createElement('div');s.className='mi-sec';s.innerHTML=`<div class="mi-sec-title"><span>${icon}</span>${title}</div><div>${content}</div><div style="display:flex;align-items:center;gap:5px;font-size:9px;color:var(--t3);padding-top:5px;border-top:1px solid var(--bd);margin-top:6px"><div style="width:4px;height:4px;border-radius:50%;background:var(--ac)"></div>${from}</div>`;body.appendChild(s);};
  if(intel.theme) mk(''+window.icon("more",14)+'','Campaign Theme',`<div style="font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1)">${esc(intel.theme)}</div>`,'Live · Sarah');
  if(intel.seo.length) mk(''+window.icon("chart",14)+'','SEO Opportunities',intel.seo.map(k=>`<span class="mi-tag ac">${esc(k)}</span>`).join(''),'From James');
  if(intel.content.length) mk(''+window.icon("edit",14)+'','Content Pillars',intel.content.map(p=>`<span class="mi-tag">${esc(p)}</span>`).join(''),'From Priya');
  if(intel.social.length) mk(''+window.icon("message",14)+'','Social Channels',intel.social.map(p=>`<span class="mi-tag">${esc(p)}</span>`).join(''),'From Marcus');
  if(intel.funnel.length) mk(''+window.icon("more",14)+'','Lead Funnel',intel.funnel.map((st,i)=>`<span class="mi-tag">${esc(st)}</span>${i<intel.funnel.length-1?'<span style="color:var(--t3);font-size:10px;margin:0 2px">→</span>':''}`).join(''),'From Elena');
}
function prefill(text){var ta=document.getElementById('cmd-input');ta.value=text;ta.focus();ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,80)+'px';}

// ═══════════════════════════════════════════════════════════════════════════
// EXECUTION CONTROLLER v3 — APPROVAL-FIRST + AUTOPILOT + PERSISTENT MEMORY
// DEFAULT: Agents propose actions → user approves → then execute
// AUTOPILOT: Only when user explicitly says "go on autopilot"
// Every execution persisted to lu_exec_history. Every decision to lu_decision_log.
// ═══════════════════════════════════════════════════════════════════════════

let execMode = 'approval'; // 'approval' or 'autopilot'

// Fetch mode from server on load
;(async function(){try{var r=await get(API+'exec/mode');execMode=r.mode||'approval';_updateModeUI();}catch(e){}})();

function _updateModeUI(){
  // Badge removed per user request — mode still functional via Settings
  var ind=document.getElementById('exec-mode-ind');
  if(ind) ind.remove();
}
async function _setMode(mode){
  try{await post(API+'exec/mode',{mode});execMode=mode;_updateModeUI();
    showToast(mode==='autopilot'?''+window.icon("ai",14)+' Autopilot activated — safe tools will auto-execute':''+window.icon("lock",14)+' Approval mode — agents will ask before executing','success');
  }catch(e){showToast('Mode change failed','error');}
}

// ── Safe tools (can auto-run in autopilot) ───────────────────────────────
var EXEC_SAFE = new Set([
  'deep_audit','ai_report','ai_status','serp_analysis','scan_site_url',
  'outbound_links','check_outbound','link_suggestions',
  'list_leads','get_lead','list_campaigns','list_templates','list_posts',
  'get_queue','list_events','check_availability','list_builder_pages',
  'get_builder_page','list_goals','agent_status','list_sequences',
  'get_site_pages','get_site_page','search_site_content',
  'system_health_check','list_previews','proactive_status','memory_context',
  'analyze_funnel_structure','generate_funnel_blueprint','record_social_analytics',
  'record_metric','log_activity',
]);

// ── Full intent→tool mapping ─────────────────────────────────────────────
var EXEC_INTENTS = [
  {rx:/\b(?:run|need|do|perform|should|let me|i'?ll)\s+(?:a\s+)?(?:full\s+|site\s+)?(?:seo\s+)?audit/i, tool:'deep_audit',       label:'Run SEO Audit',       icon:''+window.icon("search",14)+'', paramFn:_pPostId},
  {rx:/\b(?:scan|check|crawl|analyze)\s+(?:the\s+|our\s+)?(?:site|website|homepage|url)/i,               tool:'scan_site_url',    label:'Scan Website',        icon:''+window.icon("globe",14)+'', paramFn:()=>({url:BU||location.origin})},
  {rx:/\b(?:analyze|research|check|find)\s+(?:the\s+)?(?:keyword|search\s+term|query|serp)/i,            tool:'serp_analysis',    label:'Analyze Keywords',    icon:''+window.icon("chart",14)+'', paramFn:_pKeyword},
  {rx:/\b(?:check|scan|find|fix)\s+(?:broken\s+)?(?:outbound|external)\s+link/i,                         tool:'outbound_links',   label:'Check Outbound Links',icon:''+window.icon("link",14)+'', paramFn:_pPostId},
  {rx:/\b(?:get|check|find|view)\s+(?:internal\s+)?link\s+(?:suggest|opportunit|recommend)/i,             tool:'link_suggestions', label:'Link Suggestions',    icon:''+window.icon("link",14)+'', paramFn:_pPostId},
  {rx:/\bgenerate\s+(?:a\s+)?(?:seo\s+)?report/i,                                                       tool:'ai_report',        label:'Generate SEO Report', icon:''+window.icon("more",14)+'', paramFn:_pPostId},
  {rx:/\b(?:add|insert|place)\s+(?:a\s+)?(?:internal\s+)?link/i,                                         tool:'insert_link',      label:'Insert Link',         icon:''+window.icon("link",14)+'', paramFn:_pId},
  {rx:/\b(?:write|create|generate|draft)\s+(?:a\s+)?(?:blog\s+|seo\s+)?(?:article|post|content)/i,      tool:'write_article',    label:'Generate Article',    icon:''+window.icon("edit",14)+'', paramFn:_pKeyword},
  {rx:/\b(?:improve|optimize|rewrite|enhance)\s+(?:the\s+)?(?:content|draft|copy|text)/i,                tool:'improve_draft',    label:'Improve Content',     icon:''+window.icon("edit",14)+'', paramFn:_pKeyword},
  {rx:/\b(?:create|launch|set up|build|start)\s+(?:a\s+)?(?:email\s+|marketing\s+)?campaign/i,          tool:'create_campaign',  label:'Create Campaign',     icon:''+window.icon("message",14)+'', paramFn:()=>({name:'New Campaign',type:'email'})},
  {rx:/\b(?:send|blast|dispatch)\s+(?:the\s+)?(?:email|campaign|newsletter)/i,                           tool:'send_campaign',    label:'Send Campaign',       icon:''+window.icon("message",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view|check)\s+(?:all\s+|the\s+)?campaign/i,                                       tool:'list_campaigns',   label:'View Campaigns',      icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:create|write|draft)\s+(?:a\s+)?(?:social\s+)?(?:media\s+)?post/i,                           tool:'create_post',      label:'Create Social Post',  icon:''+window.icon("message",14)+'', paramFn:()=>({content:'',platforms:['facebook']})},
  {rx:/\bschedule\s+(?:a\s+)?(?:social\s+)?post/i,                                                      tool:'schedule_post',    label:'Schedule Post',       icon:''+window.icon("calendar",14)+'', paramFn:()=>({})},
  {rx:/\bpublish\s+(?:the\s+|a\s+)?post/i,                                                              tool:'publish_post',     label:'Publish Post',        icon:''+window.icon("rocket",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view)\s+(?:all\s+)?posts/i,                                                       tool:'list_posts',       label:'View Posts',          icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:create|add)\s+(?:a\s+)?(?:new\s+)?lead/i,                                                   tool:'create_lead',      label:'Create Lead',         icon:''+window.icon("more",14)+'', paramFn:()=>({name:'',email:'',stage:'new'})},
  {rx:/\b(?:check|view|list|show)\s+(?:all\s+)?lead/i,                                                  tool:'list_leads',       label:'View Leads',          icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:create|add|schedule)\s+(?:a\s+)?event/i,                                                    tool:'create_event',     label:'Create Event',        icon:''+window.icon("calendar",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view)\s+(?:all\s+)?events/i,                                                      tool:'list_events',      label:'View Events',         icon:''+window.icon("calendar",14)+'', paramFn:()=>({})},
  {rx:/\b(?:build|create|generate)\s+(?:a\s+)?(?:landing\s+)?page/i,                                    tool:'generate_page_layout',label:'Generate Page',     icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view)\s+(?:builder\s+)?pages/i,                                                   tool:'list_builder_pages',label:'View Pages',          icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\bexport\s+(?:the\s+)?(?:web)?site/i,                                                            tool:'export_website',   label:'Export Website',      icon:''+window.icon("export",14)+'', paramFn:_pId},
  {rx:/\b(?:check|run)\s+(?:system\s+)?health/i,                                                        tool:'system_health_check',label:'System Health',      icon:''+window.icon("check",14)+'', paramFn:()=>({})},
  {rx:/\b(?:check|view)\s+proactive/i,                                                                  tool:'proactive_status', label:'Proactive Scan',      icon:''+window.icon("ai",14)+'', paramFn:()=>({})},
  {rx:/\b(?:analyze|check)\s+funnel/i,                                                                  tool:'analyze_funnel_structure',label:'Analyze Funnel', icon:''+window.icon("chart",14)+'', paramFn:()=>({})},
];

// ── Chained flows ────────────────────────────────────────────────────────
var EXEC_CHAINS = [
  {rx:/\b(?:improve|fix|boost|optimize)\s+(?:my\s+|our\s+|the\s+)?seo\b/i, label:'SEO Improvement Flow', icon:''+window.icon("rocket",14)+'',
   steps:[{tool:'deep_audit',label:'Audit site',paramFn:_pPostId},{tool:'serp_analysis',label:'Keyword analysis',paramFn:_pKeyword},{tool:'link_suggestions',label:'Find link opportunities',paramFn:_pPostId}]},
  {rx:/\b(?:create|write|produce)\s+(?:new\s+)?content\b/i, label:'Content Creation Flow', icon:''+window.icon("edit",14)+'',
   steps:[{tool:'serp_analysis',label:'Research keywords',paramFn:_pKeyword},{tool:'write_article',label:'Generate article',paramFn:_pKeyword}]},
  {rx:/\b(?:launch|start|run)\s+(?:a\s+)?(?:marketing\s+)?campaign\b/i, label:'Campaign Launch Flow', icon:''+window.icon("message",14)+'',
   steps:[{tool:'list_leads',label:'Check leads',paramFn:()=>({})},{tool:'create_campaign',label:'Create campaign',paramFn:()=>({name:'New Campaign',type:'email'})}]},
  {rx:/\b(?:fix|check|analyze)\s+(?:all\s+)?(?:broken\s+)?links\b/i, label:'Link Health Flow', icon:''+window.icon("link",14)+'',
   steps:[{tool:'outbound_links',label:'Check outbound links',paramFn:_pPostId},{tool:'link_suggestions',label:'Find link opportunities',paramFn:_pPostId}]},
];

// ── Parameter extractors ─────────────────────────────────────────────────
function _pPostId(text){var m=text.match(/post[_\s]?id[:\s=]*(\d+)/i);if(m) return {post_id:parseInt(m[1])};var all=document.getElementById('disc-feed')?.textContent||'';var pm=all.match(/post_id=(\d+)/);return pm?{post_id:parseInt(pm[1])}:{post_id:0};}
function _pKeyword(text){var m=text.match(/[""\u201C\u201D]([^"""\u201C\u201D]{3,60})[""\u201C\u201D]/);if(m) return {keyword:m[1]};var m2=text.match(/(?:for|about|on|targeting|keyword[s]?\s*[:=])\s*["']?([a-zA-Z][a-zA-Z\s]{3,40}?)["']?(?:\.|,|\s+(?:in|for|to|and)|$)/i);return m2?{keyword:m2[1].trim()}:{keyword:''};}
function _pId(text){var m=text.match(/(?:id|#)\s*[:=]?\s*(\d+)/i);return m?{id:parseInt(m[1])}:{};}

// ── EXECUTION CONTROLLER — called for every agent message ────────────────
function execCtrl(div, msg){
  var text = msg.content || '';
  if(text.length < 15) return;
  if(/<tool_call>/i.test(text)) return;

  var isDMM = msg.agent_id === 'dmm';

  // ── DMM messages: full proposal cards with approval flow ───────────
  if(isDMM){
    // Check for chains first
    for(var chain of EXEC_CHAINS){
      if(chain.rx.test(text)){_renderProposal(div, chain.steps.map(s=>({...s,isSafe:EXEC_SAFE.has(s.tool)})), text, msg, chain.label, chain.icon);return;}
    }
    // Individual intents from DMM
    var matched=[];
    for(var intent of EXEC_INTENTS){if(intent.rx.test(text)&&!matched.find(m=>m.tool===intent.tool)){matched.push({...intent,isSafe:EXEC_SAFE.has(intent.tool)});}if(matched.length>=4) break;}
    if(matched.length) _renderProposal(div, matched, text, msg);
    return;
  }

  // ── Specialist messages: lightweight manual-only buttons ────────────
  // Specialists cannot auto-execute. Buttons are for the USER to trigger manually.
  var matched=[];
  for(var intent of EXEC_INTENTS){if(intent.rx.test(text)&&!matched.find(m=>m.tool===intent.tool)){matched.push(intent);}if(matched.length>=3) break;}
  if(!matched.length) return;

  var bar = document.createElement('div');
  bar.className = 'exec-btns';
  bar.style.cssText += ';border-top:1px dashed var(--bd);padding-top:8px;margin-top:8px';
  var label = document.createElement('div');
  label.style.cssText = 'font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;width:100%';
  label.textContent = 'Quick actions (manual)';
  bar.appendChild(label);
  matched.forEach(intent => {
    var btn = document.createElement('button');
    btn.className = 'exec-btn';
    btn.style.cssText += ';opacity:.8';
    btn.innerHTML = `<span class="eb-icon">${intent.icon||''+window.icon("ai",14)+''}</span> ${intent.label}`;
    btn.onclick = () => _execBtn(btn, intent, text, msg);
    bar.appendChild(btn);
  });
  var bubble = div.querySelector('.msg-bubble');
  if(bubble) bubble.appendChild(bar);
}

// ── Render proposal card (APPROVAL-FIRST or AUTOPILOT) ───────────────────
function _renderProposal(div, actions, text, msg, chainLabel, chainIcon){
  var isAutopilot = execMode === 'autopilot';
  var bubble = div.querySelector('.msg-bubble');
  if(!bubble) return;

  var card = document.createElement('div');
  card.className = 'exec-proposal';
  card.style.cssText = 'margin-top:12px;padding:12px 14px;background:var(--s2);border:1px solid var(--bd2);border-radius:10px';

  // Header
  var hdr = document.createElement('div');
  hdr.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:8px';
  hdr.innerHTML = `<div style="font-size:11px;font-weight:700;color:${isAutopilot?'var(--ac)':'var(--p)'};text-transform:uppercase;letter-spacing:.06em">${isAutopilot?''+window.icon("ai",14)+' Sarah\'s Plan — Auto-Executing':'👩‍'+window.icon("more",14)+' Sarah\'s Plan — Awaiting Your Approval'}</div>${chainLabel?`<div style="font-size:10px;color:var(--t3)">${chainIcon||''} ${chainLabel}</div>`:''}`;
  card.appendChild(hdr);

  // Action list
  var list = document.createElement('div');
  list.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-bottom:10px';

  actions.forEach((a, i) => {
    var row = document.createElement('div');
    row.className = 'exec-action-row';
    row.dataset.idx = i;
    row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;background:var(--s1);border:1px solid var(--bd)';
    var isProtected = _PROTECTED && _PROTECTED.has(a.tool);
    var risk = isProtected ? '<span style="color:var(--rd);font-size:9px;font-weight:700">'+window.icon("lock",14)+' PROTECTED</span>' : a.isSafe ? '<span style="color:var(--ac);font-size:9px;font-weight:700">'+window.icon("ai",14)+' AUTO</span>' : '<span style="color:var(--am);font-size:9px;font-weight:700">'+window.icon("lock",14)+' REVIEW</span>';
    row.innerHTML = `<span style="font-size:14px">${a.icon||''+window.icon("ai",14)+''}</span><div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${a.label}</div><div style="font-size:10px;color:var(--t3)">Tool: ${a.tool} ${risk}</div></div><div class="exec-row-status" style="font-size:10px;color:var(--t3)">pending</div>`;
    list.appendChild(row);
  });
  card.appendChild(list);

  // Result container
  var resultBox = document.createElement('div');
  resultBox.className = 'exec-results-box';
  resultBox.style.cssText = 'display:none;flex-direction:column;gap:6px;margin-bottom:10px';
  card.appendChild(resultBox);

  if(isAutopilot){
    // AUTOPILOT: auto-execute safe, show button for risky
    var safeActions = actions.filter(a => a.isSafe);
    var riskyActions = actions.filter(a => !a.isSafe);

    if(safeActions.length){
      safeActions.forEach((a, idx) => {
        setTimeout(() => _execAction(a, text, msg, card, actions.indexOf(a), resultBox), idx * 2000);
      });
    }
    if(riskyActions.length){
      var riskBtns = document.createElement('div');
      riskBtns.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap';
      riskyActions.forEach(a => {
        var btn = document.createElement('button');
        btn.className = 'exec-btn';
        btn.innerHTML = `${a.icon||''+window.icon("ai",14)+''} Approve: ${a.label}`;
        btn.onclick = () => {btn.disabled=true;btn.textContent='Executing…';_execAction(a, text, msg, card, actions.indexOf(a), resultBox);};
        riskBtns.appendChild(btn);
      });
      card.appendChild(riskBtns);
    }
  } else {
    // APPROVAL-FIRST: show approve/reject buttons
    var btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap';

    var approveAll = document.createElement('button');
    approveAll.className = 'exec-btn';
    approveAll.style.cssText += ';background:var(--p);color:#fff;border-color:var(--p)';
    approveAll.innerHTML = '✓ Approve All';
    approveAll.onclick = async () => {
      approveAll.disabled=true;approveAll.textContent='Executing…';
      for(let i=0;i<actions.length;i++){await _execAction(actions[i], text, msg, card, i, resultBox);await new Promise(r=>setTimeout(r,1500));}
      btnRow.remove();
    };
    btnRow.appendChild(approveAll);

    var approveSafe = document.createElement('button');
    approveSafe.className = 'exec-btn';
    approveSafe.innerHTML = '✓ Approve Safe Only';
    approveSafe.onclick = async () => {
      approveSafe.disabled=true;approveSafe.textContent='Running safe…';
      for(let i=0;i<actions.length;i++){if(actions[i].isSafe){await _execAction(actions[i], text, msg, card, i, resultBox);await new Promise(r=>setTimeout(r,1500));}}
      approveSafe.textContent='✓ Safe done';approveSafe.style.color='var(--ac)';
    };
    btnRow.appendChild(approveSafe);

    var reject = document.createElement('button');
    reject.className = 'exec-btn';
    reject.style.color = '#F87171';
    reject.innerHTML = '✕ Reject';
    reject.onclick = async () => {
      card.style.opacity='.5';
      try{await post(API+'decisions',{type:'tool_proposal',agent_id:msg.agent_id||'system',title:'Rejected: '+actions.map(a=>a.label).join(', '),status:'rejected',tools:actions.map(a=>a.tool)});}catch(e){}
    };
    btnRow.appendChild(reject);

    card.appendChild(btnRow);
  }

  bubble.appendChild(card);
}

// ── Execute a single action (used by both modes) ─────────────────────────
async function _execAction(action, msgText, msg, card, idx, resultBox){
  var row = card.querySelector(`.exec-action-row[data-idx="${idx}"]`);
  var statusEl = row?.querySelector('.exec-row-status');
  if(statusEl){statusEl.textContent='running…';statusEl.style.color='var(--am)';}

  var params = action.paramFn ? action.paramFn(msgText) : {};
  try{
    var t0=Date.now();
    var result = await post(API+'tools/run', {tool_id:action.tool, params, agent_id:msg.agent_id||'user', rationale:msgText.slice(0,200), approval_status:'approved'});
    var dur=Date.now()-t0;

    // Log to decision log
    try{await post(API+'decisions',{type:'tool_execution',agent_id:msg.agent_id||'system',title:action.label,rationale:msgText.slice(0,200),status:'approved',tools:[action.tool]});}catch(e){}

    if(statusEl){
      if(result.status==='preview_created'){statusEl.textContent='preview';statusEl.style.color='var(--am)';}
      else if(result.success!==false){statusEl.textContent=''+window.icon("check",14)+' done';statusEl.style.color='var(--ac)';}
      else{statusEl.textContent=''+window.icon("close",14)+' failed';statusEl.style.color='#F87171';}
    }

    // Show result inline
    resultBox.style.display='flex';
    var res=document.createElement('div');
    res.className='exec-result';
    if(result.status==='preview_created'){
      res.innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px">${window.icon('eye',14)} <strong>${action.label}</strong></span> — <a href="#" onclick="nav('previews');return false" style="color:var(--p)">Review in Previews →</a>`;
    }else if(result.success!==false){
      res.innerHTML=_fmtResult(action.tool, result.data);
    }else{
      res.className='exec-result err';
      res.textContent=result.data?.error||'Failed';
    }
    resultBox.appendChild(res);
  }catch(e){
    if(statusEl){statusEl.textContent=''+window.icon("close",14)+' error';statusEl.style.color='#F87171';}
  }
}

// ── Format tool results ──────────────────────────────────────────────────
function _fmtResult(toolId, data){
  if(!data) return ''+window.icon("check",14)+' Done.';
  if(toolId==='deep_audit'||toolId==='ai_report'){var s=data.score??data.seo_score??data.overall_score;var m=data.message||'';if(s) return `<strong>SEO Score: ${s}/100</strong><br>${esc(m.slice(0,300))}`;if(m) return esc(m.slice(0,400));}
  if(toolId==='scan_site_url') return `${window.icon('check',14)} <strong>${esc(data.title||'')}</strong> — ${data.word_count||0} words, ${data.headers_found||0} headers, ${data.internal_links||0} links`;
  if(toolId==='serp_analysis'){var m=data.message||data.analysis||'';return m?esc(m.slice(0,400)):'SERP analysis complete.';}
  if(toolId==='outbound_links') return `Total: <strong>${data.total||0}</strong> | Broken: <strong style="color:#F87171">${data.broken||0}</strong> | Redirects: ${data.redirects||0}`;
  if(toolId==='link_suggestions'){var c=data.count??data.suggestions?.length??0;return `<strong>${c}</strong> link suggestions.`;}
  if(toolId==='list_leads'){var c=data.total??data.count??(data.leads?.length||0);return `<strong>${c}</strong> leads.`;}
  if(toolId==='list_campaigns'){return `<strong>${data.campaigns?.length||0}</strong> campaigns.`;}
  if(toolId==='list_posts'){return `<strong>${data.posts?.length||data.total||0}</strong> posts.`;}
  if(toolId==='list_events'){return `<strong>${data.events?.length||data.total||0}</strong> events.`;}
  if(toolId==='list_builder_pages'){return `<strong>${data.pages?.length||0}</strong> pages.`;}
  if(toolId==='system_health_check') return `Status: <strong style="color:var(--ac)">${data.status||'ok'}</strong> | Runtime: ${data.runtime_status||'?'}`;
  var keys=Object.keys(data).filter(k=>!k.startsWith('_')&&k!=='success').slice(0,4);
  return keys.map(k=>`<strong>${k}:</strong> ${typeof data[k]==='object'?JSON.stringify(data[k]).slice(0,80):String(data[k]).slice(0,80)}`).join(' | ')||'Done.';
}
// ── Off-topic messages — creative interstitial ───────────────────────────────
var OFF_TOPIC_MESSAGES = [
  "Your agents are laser-focused on the mission at hand. Questions outside the meeting topic might get lost in the strategy fog. "+window.icon('ai',14)+"",
  "Your team is deep in execution mode. Off-topic questions are like sending a chef a weather report mid-service. 👨‍🍳",
  "Your agents have their game faces on. They live and breathe this topic — unrelated questions might get a blank stare. "+window.icon('more',14)+"",
  "The team is in the zone! Think of them as surgeons mid-operation — best to keep it relevant. 🔬",
  "Your agents are fully locked in on the strategy. Side quests not currently supported! ⚔️",
  "Heads-down mode activated. Your team is built for this topic — anything else might bounce off their focus shields. "+window.icon('lock',14)+"",
];

function isOffTopicMessage(content, topic) {
  var lower = content.toLowerCase().trim();

  // Always allow: commands, @mentions, very short directing messages
  if (lower.length < 8) return false;
  if (/@\w/.test(lower)) return false;
  if (/^(stop|pause|continue|go|next|ok|yes|no|thanks|great|perfect|proceed)\b/.test(lower)) return false;

  // Clear off-topic patterns
  var OFF_TOPIC_PATTERNS = [
    /\brain\b|\bweather\b|\btemperature\b|\bsunny\b|\bcloudy\b|\bforecast\b/i,
    /\bfood\b|\bhungry\b|\blunch\b|\bdinner\b|\brestaurant\b/i,
    /\bjoke\b|\bfunny\b|\btell me a\b/i,
    /\bwhat time is it\b|\bwhat.*date\b|\bwhat.*day is/i,
    /\bhow are you\b|\bhow'?s it going\b/i,
    /\bdo you like\b|\bfavorite\b|\bfavourite\b/i,
    /\bsports\b|\bfootball\b|\bbasketball\b|\bsoccer\b/i,
    /\bmovie\b|\bfilm\b|\bnetflix\b|\bseries\b/i,
  ];

  // Check if it matches off-topic patterns
  for (var pattern of OFF_TOPIC_PATTERNS) {
    if (pattern.test(lower)) return true;
  }

  // Check relevance to meeting topic (if topic is set)
  if (topic) {
    var topicWords = topic.toLowerCase().split(/\W+/).filter(w => w.length > 3);
    var msgWords   = lower.split(/\W+/).filter(w => w.length > 3);
    // If message has zero overlap with topic and is a question, likely off-topic
    var hasTopicOverlap = topicWords.some(tw => msgWords.some(mw => mw.includes(tw) || tw.includes(mw)));
    var isQuestion = /\?$|^(what|who|where|when|why|how|is|are|can|will|does|did)\b/.test(lower);
    if (!hasTopicOverlap && isQuestion && lower.length > 20) return true;
  }

  return false;
}

function showOffTopicDialog(onProceed, onCancel) {
  var msg = OFF_TOPIC_MESSAGES[Math.floor(Math.random() * OFF_TOPIC_MESSAGES.length)];

  // Remove any existing dialog
  document.getElementById('off-topic-dialog')?.remove();

  var overlay = document.createElement('div');
  overlay.id = 'off-topic-dialog';
  overlay.style.cssText = `
    position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;
    display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease;
  `;

  overlay.innerHTML =
    '<div style="background:var(--s2);border:1px solid var(--bd2);border-radius:16px;padding:28px 32px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)">' +
      '<div style="font-size:40px;margin-bottom:12px">'+window.icon("more",14)+'</div>' +
      '<div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:10px">Heads in the Game</div>' +
      '<div style="font-size:13px;color:var(--t2);line-height:1.7;margin-bottom:22px">' + msg + '</div>' +
      '<div style="display:flex;gap:10px;justify-content:center">' +
        '<button id="offtopic-cancel" style="background:var(--s3);border:1px solid var(--bd2);color:var(--t2);border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;font-family:var(--fh)">Got it</button>' +
        '<button id="offtopic-proceed" style="background:var(--p);border:none;color:#fff;border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;font-family:var(--fh);font-weight:600">Send anyway</button>' +
      '</div>' +
    '</div>';

  document.body.appendChild(overlay);

  document.getElementById('offtopic-cancel').onclick  = () => { overlay.remove(); onCancel(); };
  document.getElementById('offtopic-proceed').onclick = () => { overlay.remove(); onProceed(); };
  overlay.onclick = (e) => { if (e.target === overlay) { overlay.remove(); onCancel(); } };
}

async function sendMessage(){
  var ta=document.getElementById('cmd-input');
  let content=ta.value.trim();
  if(!content||!mid||busy)return;

  // ── Autopilot mode detection ────────────────────────────────────────
  var lc = content.toLowerCase().trim();
  if(/\b(?:go\s+on\s+autopilot|enable\s+autopilot|autopilot\s+on|activate\s+autopilot)\b/i.test(lc)){
    _setMode('autopilot');
    // Still send the message so agents know
  }
  if(/\b(?:stop\s+autopilot|pause\s+autopilot|disable\s+autopilot|back\s+to\s+approval|approval\s+mode|wait\s+for\s+(?:my\s+)?approval)\b/i.test(lc)){
    _setMode('approval');
  }

  // ── Auto-detect mentions without @ prefix ──────────────────────────
  // Users naturally type "Hi everyone" or "James, what do you think?"
  // Runtime requires @everyone/@james — normalize before sending.
  var mentionNorm = [
    [/\beveryone\b/i, '@Everyone'],
    [/\ball agents?\b/i, '@Everyone'],
    [/\bteam\b(?!\s*(?:of|building|work|member|meeting))/i, '@Everyone'],
  ];
  var nameNorm = [
    [/\bsarah\b/i, '@Sarah'], [/\bjames\b/i, '@James'],
    [/\bpriya\b/i, '@Priya'], [/\bmarcus\b/i, '@Marcus'],
    [/\belena\b/i, '@Elena'], [/\balex\b/i, '@Alex'],
  ];
  // Only normalize if no @ already present
  if(!content.includes('@')){
    for(var [rx,repl] of mentionNorm){ if(rx.test(content)){ content=content.replace(rx,repl); break; } }
    // Name detection for direct address patterns: "James, ..." or "Hey Priya ..."
    if(!content.includes('@')){
      for(var [rx,repl] of nameNorm){ if(rx.test(content)){ content=content.replace(rx,repl); break; } }
    }
  }

  // Check for off-topic before sending
  var topic = document.getElementById('mtg-topic')?.textContent || '';
  if (isOffTopicMessage(content, topic)) {
    showOffTopicDialog(
      // "Send anyway" — proceed with original content
      async () => {
        ta.value='';ta.style.height='auto';busy=true;
        var umid='u_'+Date.now();
        var umsg={agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content,_umid:umid,timestamp:new Date().toISOString(),_afterRedis:_redisCount};
        localUserMsgs.push(umsg);
        renderMsg(umsg);
        try{await post(API+'meeting/'+mid+'/message',{content});}catch(e){showMsgErr('Send failed: '+e.message);}finally{busy=false;}
      },
      // "Got it" — clear the input, don't send
      () => { ta.focus(); }
    );
    return;
  }

  ta.value='';ta.style.height='auto';busy=true;
  var umid='u_'+Date.now();
  var umsg={agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content,_umid:umid,timestamp:new Date().toISOString(),_afterRedis:_redisCount};
  localUserMsgs.push(umsg);
  renderMsg(umsg);
  try{await post(API+'meeting/'+mid+'/message',{content});}catch(e){showMsgErr('Send failed: '+e.message);}finally{busy=false;}
}
async function wrapUp(){if(!mid)return;document.getElementById('btn-wrap').disabled=true;try{await post(API+'meeting/'+mid+'/wrap',{topic:document.getElementById('mtg-topic').textContent});}catch(e){showMsgErr(e.message);}}
async function downloadTranscript(){
  var d=await get(API+'meeting/'+mid+'/status');
  var lines=[`LevelUp Growth — Strategy Session\nTopic: ${d.topic}\nDate: ${new Date().toLocaleString()}\n\n${'─'.repeat(60)}\n\n`];
  (d.messages||[]).forEach(m=>{lines.push(`${m.name.toUpperCase()}\n${m.content}\n\n`);});
  var a=Object.assign(document.createElement('a'),{href:URL.createObjectURL(new Blob([lines.join('')],{type:'text/plain'})),download:`strategy-${mid}.txt`});a.click();
}
function exitMeeting(){
  clearInterval(pollT);mid=null;seen=0;done=false;busy=false;lastSpk=null;lastPhase=null;_redisCount=0;localUserMsgs=[];
  spoken.clear();phaseLog.clear();
  Object.keys(AGENTS).forEach(id=>stopAgentActivity(id));
  document.getElementById('mtg-start-screen').style.display='flex';
  document.getElementById('mtg-active').style.display='none';
  document.getElementById('disc-feed').innerHTML='';
  document.getElementById('btn-wrap').disabled=true;
  document.getElementById('live-dot').classList.remove('on');
  PHASES.forEach(p=>{var el=document.getElementById('ps-'+p);if(el)el.classList.remove('active','done');});
  Object.keys(AGENTS).forEach(id=>{var el=document.getElementById('mac-'+id),st=document.getElementById('mst-'+id);if(el)el.classList.remove('speaking','done');if(st){st.className='mac-st st-idle';st.textContent='Waiting';}});
  nav('workspace');
}
function showMsgErr(msg){var d=document.createElement('div');d.style.cssText='background:rgba(248,113,113,.06);border:1px solid rgba(248,113,113,.2);border-radius:var(--r);padding:10px 13px;color:#F87171;font-size:12px;animation:fadeUp .3s ease;display:flex;align-items:center;gap:6px';d.innerHTML=window.icon('warning',14)+'<span>'+esc(msg)+'</span>';document.getElementById('disc-feed').appendChild(d);scrollFeed();}

// ── Utils ──────────────────────────────────────────────────────────────────
function setEl(id,val){var e=document.getElementById(id);if(e)e.textContent=val;}

// ── Shared escape function (was in builder utils, needed by core views) ──
function esc(t){return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escH(t){return esc(t).replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
// removed duplicate fmt — see line 2525

// PATCH (chat-markdown, 2026-05-09) — fmt() now handles bullet points
// (lines starting with `- ` or `• `). Consecutive bullet lines collapse
// into a single <ul>. Italic via *single-asterisk*. Order matters:
// process bullets BEFORE \n -> <br> conversion so list items stay
// structured.
function fmt(t){
  var s = _bldSafeText(t)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
    .replace(/(^|[\s])\*([^*\n]+)\*/g,'$1<em>$2</em>')
    .replace(/^## (.+)$/gm,'<h2>$1</h2>')
    .replace(/^### (.+)$/gm,'<h3 style="font-size:11px;color:var(--bl);margin:10px 0 4px;font-weight:700">$1</h3>');
  // Bullet handling: convert each `- ` or `• ` line to <li>, then wrap
  // contiguous <li>...</li> runs in a <ul>.
  s = s.replace(/^[\-•]\s+(.+)$/gm,'<li>$1</li>');
  s = s.replace(/(<li>[\s\S]*?<\/li>)(\s*<li>[\s\S]*?<\/li>)*/g, function(m){
    return '<ul style="margin:6px 0 8px;padding-left:18px;line-height:1.55">' + m + '</ul>';
  });
  // Newlines AFTER bullets are converted (don't insert <br> inside <ul>)
  s = s.replace(/<\/ul>\n+/g,'</ul>')
       .replace(/\n\n/g,'<br><br>')
       .replace(/\n/g,'<br>');
  return s;
}
async function post(url,data){var r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')},body:JSON.stringify(data)});var d=await r.json();if(!r.ok){if(d.code==='PLAN_GATED'||d.code==='NO_CREDITS'){showPlanGate(d.error||d.message||'This feature requires a plan upgrade.');return d;}throw new Error(d.message||d.error||'Request failed');}return d;}
async function get(url){var r=await fetch(url,{cache:'no-store',headers:{'Accept':'application/json','Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}});return r.json();}

// ── File upload ────────────────────────────────────────────────────────────
let pendingAttachments = [];
function triggerUpload(){ document.getElementById('file-input')?.click(); }
async function handleFileUpload(e){
  var file = e.target.files[0]; if(!file||!mid) return;
  var prev = document.getElementById('upload-preview');
  prev.style.display='flex';
  prev.innerHTML=`<div class="spinner" style="width:14px;height:14px;border-width:1.5px"></div><span>Uploading ${esc(file.name)}…</span>`;
  try{
    var fd = new FormData(); fd.append('file', file);
    var r = await fetch(API+'meeting/'+mid+'/upload', {
      method:'POST', headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}, body:fd,
    });
    var d = await r.json();
    if(!r.ok) throw new Error(d.error||'Upload failed');
    pendingAttachments.push(d.file);
    var isImg = d.file.type?.startsWith('image/');
    prev.innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px">${isImg?window.icon('image',14):window.icon('attach',14)} <strong>${esc(d.file.name)}</strong></span> ready — ${isImg?'team will analyse this image':'file attached'} <span onclick="clearUpload()" style="cursor:pointer;opacity:.6;margin-left:8px">✕</span>`;
    // Show preview if image
    if(isImg){
      var img = document.createElement('img');
      img.src = d.file.url; img.style.cssText='max-width:120px;max-height:60px;border-radius:6px;margin-left:8px;border:1px solid var(--bd)';
      prev.appendChild(img);
    }
  }catch(err){
    prev.innerHTML=`<span style="color:var(--rd);display:inline-flex;align-items:center;gap:5px">${window.icon('warning',13)} ${esc(err.message)}</span>`;
    setTimeout(()=>{prev.style.display='none';},3000);
  }
  e.target.value=''; // reset
}
function clearUpload(){
  pendingAttachments=[];
  var p=document.getElementById('upload-preview');
  if(p){p.style.display='none';p.innerHTML='';}
}

// ── Vision analysis message render ─────────────────────────────────────────
// Handled by renderMsg — role=vision_analysis gets a special badge

// ── Patch sendMessage to include attachments ───────────────────────────────
var _origSend = sendMessage;
sendMessage = async function(){
  var ta=document.getElementById('cmd-input');
  var content=ta.value.trim();
  // If we have attachments and no text, use a default caption
  var caption = content || (pendingAttachments.length ? 'Analyse this' : '');
  if((!caption&&!pendingAttachments.length)||!mid||busy) return;
  ta.value=''; ta.style.height='auto'; busy=true;
  if(caption) renderMsg({agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content:caption,attachments:pendingAttachments});
  try{
    await post(API+'meeting/'+mid+'/message',{content:caption||'Analyse the uploaded file.',attachments:pendingAttachments});
    clearUpload();
  }catch(err){showMsgErr('Send failed: '+err.message);}finally{busy=false;}
};

// Patch renderMsg to show vision analysis badge and file attachments
var _origRender = renderMsg;
renderMsg = function(msg){
  // Add vision badge rendering
  if(msg.role==='vision_analysis'){
    var c=AGENTS[msg.agent_id]?.color||'var(--t2)',em=AGENTS[msg.agent_id]?.emoji||'🔍';
    var div=document.createElement('div');
    div.className='msg-card'; div.style.animation='fadeUp .3s ease';
    div.innerHTML=`<div class="msg-av" style="background:transparent;border:none">${(!isU&&typeof buildAgentOrb==="function")?buildAgentOrb(msg.agent_id,"sm",isSyn?"success":"idle"):em}</div><div class="msg-body"><div class="msg-meta"><span class="msg-name" style="color:${c}">${msg.name}</span><span class="msg-title-lbl">${msg.title}</span><span class="msg-badge" style="background:rgba(0,229,168,.1);color:var(--ac);border:1px solid rgba(0,229,168,.25)">Vision</span>${msg.analyzed_file?`<span class="msg-title-lbl" style="display:inline-flex;align-items:center;gap:4px">${window.icon('attach',12)} ${esc(msg.analyzed_file)}</span>`:''}</div><div class="msg-bubble">${fmt(msg.content)}</div></div>`;
    document.getElementById('disc-feed').appendChild(div); scrollFeed();
    return;
  }
  // Show attachments in user messages
  if(msg.attachments?.length && msg.role==='user'){
    msg.content += msg.attachments.map(a=>a.type?.startsWith('image/')?`\n<img src="${esc(a.url)}" style="max-width:200px;border-radius:6px;margin-top:6px;border:1px solid var(--bd);display:block">`:`\n<a href="${esc(a.url)}" target="_blank" style="color:var(--ac);font-size:10px;display:inline-flex;align-items:center;gap:4px">${window.icon('attach',12)} ${esc(a.name)}</a>`).join('');
  }
  _origRender(msg);
};

// ── DM Modal ────────────────────────────────────────────────────────────────
let dmAgentId = null;
let dmModal   = null;

function openDmModal(agentId, name, role, emoji, color, cardEl) {
    if (!mid) return; // only works inside an active meeting
    dmAgentId = agentId;
    var modal = document.getElementById('dm-modal');
    dmModal = modal;

    // Populate header
    var av = document.getElementById('dm-av');
    av.textContent  = emoji;
    av.style.cssText = `background:${color}22;border:1px solid ${color}44`;
    setEl('dm-name', name);
    setEl('dm-role', role);

    // Reset body
// Core shared functions restored from extraction
    var modal = document.getElementById('dm-modal');
    if (modal && !modal.contains(e.target) && !e.target.closest('.mac')) {
        closeDmModal();
    }
}

function closeDmModal() {
    var modal = document.getElementById('dm-modal');
    if (modal) modal.style.display = 'none';
    dmAgentId = null;
    document.removeEventListener('click', dmOutsideClick);
}

async function sendDm() {
    if (!dmAgentId || !mid) return;
    var ta  = document.getElementById('dm-ta');
    var btn = document.getElementById('dm-send');
    var content = ta?.value?.trim();
    if (!content) return;

    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    try {
        await post(API + 'meeting/' + mid + '/dm', { agentId: dmAgentId, content });
        // Show sent confirmation then close
        var body = document.getElementById('dm-body');
        if (body) body.innerHTML = `<div class="dm-sent">✓ Message sent to ${document.getElementById('dm-name')?.textContent || 'agent'}.<br><span style="color:var(--t3);font-size:9px">Reply will appear in the main feed.</span></div>`;
        setTimeout(closeDmModal, 1800);
    } catch(e) {
        if (btn) { btn.disabled = false; btn.textContent = 'Send →'; }
        console.error('DM failed:', e);
    }
}

// Close DM modal on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDmModal(); });

// ── Direct assign modal ─────────────────────────────────────────────────────
function openDirectAssign(){
  if(!selectedAgents.size) return;
  var chips=document.getElementById('da-chips');
  chips.innerHTML=[...selectedAgents].map(id=>{
    var a=AGENTS[id]||{};
    return `<div class="da-chip">${a.emoji||'👤'} ${a.name||id}</div>`;
  }).join('');
  var sub=document.getElementById('da-sub');
  if(sub) sub.textContent=`Assigning to: ${[...selectedAgents].map(id=>AGENTS[id]?.name||id).join(', ')} — created immediately.`;
  document.getElementById('da-title').value='';
  document.getElementById('da-desc').value='';
  document.getElementById('da-metric').value='';
  document.getElementById('da-priority').value='medium';
  document.getElementById('da-time').value='60';
  document.getElementById('da-backdrop').classList.add('visible');
}
function closeDirectAssign(){
  document.getElementById('da-backdrop').classList.remove('visible');
}
async function createDirectTask(){
  var title=document.getElementById('da-title').value.trim();
  if(!title||!selectedAgents.size){showToast('Task title and at least one agent are required.','warning');return;}
  var assignees=[...selectedAgents];
  var desc=document.getElementById('da-desc').value.trim();
  var priority=document.getElementById('da-priority').value;
  var estTime=parseInt(document.getElementById('da-time').value)||60;
  var metric=document.getElementById('da-metric').value.trim();

  // Build Sarah brief context
  var agentNames=assignees.map(function(id){return AGENTS[id]?.name||id;}).join(', ');
  var sarahBrief="TASK BRIEF:\n"+
    "Title: "+title+"\n"+
    "Description: "+(desc||'No description')+"\n"+
    "Assign to: "+agentNames+"\n"+
    "Priority: "+priority+"\n"+
    "Est. time: "+estTime+" minutes"+
    (metric?"\nSuccess metric: "+metric:"");

  // Close modal + clear selection
  closeDirectAssign();
  clearSelection();

  // Open Sarah's drawer → Messages tab → auto-send the brief
  openAgentDrawer('dmm');
  drawerTab('messages');

  // Wait for drawer to render then auto-send
  setTimeout(function(){
    sendAgentMessage(null, sarahBrief);
  }, 400);

}

function startMeetingWithSelected(){
  // Pre-populate meeting topic with selected agents, navigate to Strategy Room
  var names=[...selectedAgents].map(id=>AGENTS[id]?.name||id).join(', ');
  clearSelection();
  nav('meeting');
  var ti=document.getElementById('topic-input');
  if(ti && !ti.value) ti.focus();
}

function analyzeWorkload(){
  // Show workload summary in a simple alert for now (Sprint G: modal)
  var lines=[...selectedAgents].map(id=>{
    var a=AGENTS[id]||{};
    var active=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='ongoing'||t.status==='in_progress'||t.status==='upcoming')).length;
    var state=active===0?'Available':active<=3?'Moderate ('+active+' tasks)':'Overloaded ('+active+' tasks)';
    return `${a.emoji||''} ${a.name||id}: ${state}`;
  });
  luAlert(lines.join("\n"), "Workload Summary");
}

// ── Init ───────────────────────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 2754-2791)

// ══════════════════════════════════════════════════════════════
// GLOBAL AI ASSISTANT
// ══════════════════════════════════════════════════════════════

let aiOpen = false;
let aiHistory = [];
let aiBusy = false;
let aiUnread = 0;

var AI_QUICK_BY_VIEW = {
  workspace: [
    [''+window.icon("info",14)+' Who\'s overloaded?',       'Which agents are overloaded?'],
    [''+window.icon("more",14)+' Active tasks',             'What tasks are active right now?'],
    [''+window.icon("chart",14)+' Platform status',          'Give me a quick platform status summary'],
  ],
  meeting: [
    [''+window.icon("edit",14)+' Summarize meeting',        'Summarize this meeting so far'],
    [''+window.icon("check",14)+' What was agreed?',          'What has the team agreed on so far?'],
    [''+window.icon("ai",14)+' Action items',              'List the action items from this session'],
  ],
  projects: [
    [''+window.icon("warning",14)+' Behind schedule?',         'Which projects or tasks are behind schedule?'],
    [''+window.icon("more",14)+' In progress',              'List all tasks currently in progress'],
    [''+window.icon("back",14)+' High priority',            'Show me all high priority tasks'],
  ],
  agents: [
    [''+window.icon("more",14)+' Workload overview',        'Give me a workload overview of all agents'],
    [''+window.icon("star",14)+' Most active',              'Which agent has the most active tasks?'],
  ],
  reports: [
    [''+window.icon("chart",14)+' Performance summary',      'Summarize overall platform performance'],
    [''+window.icon("check",14)+' Completed work',           'What has been completed recently?'],
  ],
};

function toggleAssistant() {
  aiOpen = !aiOpen;
  document.getElementById('ai-panel').classList.toggle('open', aiOpen);
  document.getElementById('ai-fab').classList.toggle('open', aiOpen);
  if (aiOpen) {
    aiUnread = 0;
    var badge = document.getElementById('ai-fab-badge');
    if (badge) badge.classList.remove('visible');
    updateAiContext();
    document.getElementById('ai-input')?.focus();
  }
}

function updateAiContext() {
  var labels = {workspace:'Workspace',meeting:'Strategy Room',projects:'Projects',agents:'Agents',reports:'Reports & History'};
  var lbl = document.getElementById('ai-ctx-label');
  if (lbl) lbl.textContent = (labels[currentView]||currentView) + ' · ' + Object.keys(AGENTS).length + ' agents';

  // Update quick actions
  var quick = document.getElementById('ai-quick');
  if (!quick) return;
  var btns = AI_QUICK_BY_VIEW[currentView] || AI_QUICK_BY_VIEW.workspace;
  quick.innerHTML = btns.map(([label, prompt]) =>
    `<button class="ai-q-btn" onclick="aiQuick(${JSON.stringify(prompt)})">${label}</button>`
  ).join('');
}

function aiQuick(prompt) {
  var inp = document.getElementById('ai-input');
  if (inp) { inp.value = prompt; inp.style.height = 'auto'; }
  sendAssistant();
}

function buildAiContext() {
  var tasks = allTasks || [];
  var workload = Object.keys(AGENTS).map(id => {
    var active = tasks.filter(t =>
      (t.assignee === id || (t.assignees||[]).includes(id)) &&
      ['ongoing','in_progress','upcoming'].includes(t.status)
    ).length;
    var state = active === 0 ? 'available' : active <= 3 ? 'moderate' : 'overloaded';
    return { id, name: AGENTS[id]?.name||id, active, state };
  });
  var ctx = { view: currentView, workload };
  // Inject meeting context if in meeting view
  if (currentView === 'meeting' && mid) {
    ctx.meeting = { topic: document.getElementById('mtg-topic')?.textContent||'', message_count: document.querySelectorAll('.disc-msg').length };
  }
  return ctx;
}

function aiAddMsg(role, content, opts={}) {
  var feed = document.getElementById('ai-feed');
  if (!feed) return;

  var wrap = document.createElement('div');
  wrap.className = `ai-msg ai-msg-${role}`;

  if (role === 'assistant' || role === 'agent') {
    var name = opts.agentName ? `${opts.agentEmoji||'✦'} ${opts.agentName}` : '✦ Assistant';
    var color = opts.agentColor ? `style="color:${opts.agentColor}"` : '';
    wrap.innerHTML = `<span class="ai-msg-label" ${color}>${name}</span><div class="ai-msg-bubble" ${opts.agentColor?`style="border-color:${opts.agentColor}33"`:''}>${fmt(content)}</div>`;
  } else {
    wrap.innerHTML = `<span class="ai-msg-label">You</span><div class="ai-msg-bubble">${esc(content)}</div>`;
  }

  // If there's a tool action to show
  if (opts.toolCall) {
    var tc = opts.toolCall;
    var toolLabels = {
      assign_task:         ['＋','Assign Task',       `To: ${(tc.params?.assignees||[]).join(', ')} — "${tc.params?.title||''}"`],
      start_meeting:       [''+window.icon("more",14)+'','Start Meeting',      `Topic: "${tc.params?.topic||''}"`],
      navigate:            ['→', 'Navigate',           `Go to ${tc.params?.view||''}`],
      show_agent_workload: [''+window.icon("chart",14)+'','Show Workload',       'Viewing agent workload'],
      list_tasks:          [''+window.icon("more",14)+'','List Tasks',         'Filtering task list'],
      summarize_meeting:   [''+window.icon("edit",14)+'','Summarize Meeting',  'Pulling session summary'],
    };
    var [icon, label, desc] = toolLabels[tc.tool] || [''+window.icon("ai",14)+'', tc.tool, ''];
    var actionDiv = document.createElement('div');
    actionDiv.className = 'ai-tool-action';
    actionDiv.innerHTML = `<span class="ata-icon">${icon}</span><div><div class="ata-label">${label}</div><div class="ata-desc">${desc}</div></div>`;
    actionDiv.onclick = () => executeAiTool(tc);
    wrap.appendChild(actionDiv);
  }

  feed.appendChild(wrap);
  feed.scrollTop = feed.scrollHeight;
}

function aiShowTyping() {
  var feed = document.getElementById('ai-feed');
  if (!feed) return;
  var d = document.createElement('div');
  d.id = 'ai-typing';
  d.className = 'ai-msg ai-msg-assistant';
  d.innerHTML = `<span class="ai-msg-label">✦ Assistant</span><div class="ai-typing"><div class="ai-typing-d"></div><div class="ai-typing-d"></div><div class="ai-typing-d"></div></div>`;
  feed.appendChild(d);
  feed.scrollTop = feed.scrollHeight;
}
function aiHideTyping() { document.getElementById('ai-typing')?.remove(); }

async function sendAssistant() {
  var inp = document.getElementById('ai-input');
  var message = inp?.value?.trim();
  if (!message || aiBusy) return;

  inp.value = ''; inp.style.height = 'auto';
  aiBusy = true;
  document.getElementById('ai-send').disabled = true;

  aiAddMsg('user', message);
  aiHistory.push({ role:'user', content: message });
  aiShowTyping();

  // Open panel if closed (command via keyboard shortcut etc.)
  if (!aiOpen) toggleAssistant();

  try {
    var ctx = buildAiContext();
    var r = await post(API + 'assistant', { message, context: ctx, history: aiHistory.slice(-8) });
    aiHideTyping();

    var resp = r.response || '';
    var opts = {};

    if (r.agent_response) {
      opts.agentName  = r.agent_name;
      opts.agentEmoji = r.agent_emoji;
      opts.agentColor = r.agent_color;
    }
    if (r.tool_call) {
      opts.toolCall = r.tool_call;
      // Auto-execute non-destructive navigations silently
      if (r.tool_call.tool === 'navigate') executeAiTool(r.tool_call);
    }

    if (resp) {
      aiAddMsg(r.agent_response ? 'agent' : 'assistant', resp, opts);
      aiHistory.push({ role:'assistant', content: resp });
    } else if (r.tool_call && !resp) {
      aiAddMsg('assistant', `I'll ${(r.tool_call.tool||'').replace(/_/g,' ')} that for you.`, opts);
    }

    // Unread badge if panel closed
    if (!aiOpen) {
      aiUnread++;
      var badge = document.getElementById('ai-fab-badge');
      if (badge) { badge.textContent = aiUnread; badge.classList.add('visible'); }
    }
  } catch(e) {
    aiHideTyping();
    aiAddMsg('assistant', ''+window.icon("warning",14)+' ' + (e.message||'Something went wrong. Please try again.'));
  } finally {
    aiBusy = false;
    document.getElementById('ai-send').disabled = false;
  }
}

function executeAiTool(tc) {
  if (!tc?.tool) return;
  switch(tc.tool) {
    case 'assign_task': {
      var assignees = tc.params?.assignees || [];
      assignees.forEach(id => { selectedAgents.add(id); document.getElementById('node-'+id)?.classList.add('selected'); });
      updateSelectionToolbar();
      if (currentView !== 'workspace') nav('workspace');
      setTimeout(() => {
        openDirectAssign();
        if (tc.params?.title)       setTimeout(()=>{ var el=document.getElementById('da-title'); if(el) el.value=tc.params.title; },100);
        if (tc.params?.description) setTimeout(()=>{ var el=document.getElementById('da-desc');  if(el) el.value=tc.params.description; },100);
        if (tc.params?.priority)    setTimeout(()=>{ var el=document.getElementById('da-priority'); if(el) el.value=tc.params.priority; },100);
      }, currentView !== 'workspace' ? 400 : 50);
      break;
    }
    case 'start_meeting': {
      nav('meeting');
      if (tc.params?.topic) setTimeout(()=>{ var el=document.getElementById('topic-input'); if(el){el.value=tc.params.topic;el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';} },300);
      break;
    }
    case 'navigate': {
      var v = tc.params?.view;
      if (v && ['workspace','meeting','projects','agents','reports'].includes(v)) nav(v);
      break;
    }
    case 'show_agent_workload': {
      nav('workspace');
      break;
    }
    case 'list_tasks': {
      nav('projects');
      break;
    }
    case 'summarize_meeting': {
      if (currentView !== 'meeting') nav('meeting');
      break;
    }
  }
}
// ════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════
// BUILDER GLOBALS — must be declared BEFORE the builder IIFE runs
// ═══════════════════════════════════════════════════════════════════
var bldNonce = (window.LU_CFG ? window.LU_CFG.nonce : '');
var wpNonce = bldNonce;
// ── ensureArray: normalise API responses that should be arrays ──────────────
// Handles PHP→JSON object {} instead of array [] when array keys are non-sequential.
function ensureArray(val) {
  if (Array.isArray(val)) return val;
  if (val && typeof val === 'object') return Object.values(val);
  return [];
}

var bldPages = [];
var bldCurrentPageId = null;
var bldCurrentPage = null;
var bldSelectedComp = null;
var bldDevice = 'desktop';
var bldDirty = false;
var bldLibraryItems = [];
var bldSavedItems = [];
var bldFilter = 'all';
var _bldWebsitePages = [];
var _bldSelSec = null;
var _bldSelCmp = null;
var _bldPageSource = 'standalone';
var _arthurSelection = null;
var _bldRealtimeTimer = null;
var arthurContext = null;
// External engine plugins register their loader JS via:
//   GET /api/engines/{slug}/loader
// The loader JS must call:
//   window._lu_engine_loaders['slug'] = async function(rootEl) { ... }
// Core built-in engines (crm, marketing, social, calendar, automation)
// are already registered in nav() — this only fetches external ones.
window._lu_engine_loaders = {};

document.addEventListener('DOMContentLoaded', async function() {
  try {
    // Fix: use authHeader() spread so X-WP-Nonce is sent correctly in WP Admin context
    var res = await fetch(window.luApi + 'engines', { headers: authHeader() });
    // Fix: guard against HTML 401/403 responses before parsing JSON
    if (!res.ok) {
      console.warn('[LevelUp] Engine bootstrap: /api/engines returned ' + res.status + ' — check authentication.');
      return;
    }
    var data = await safeJson(res);
    if (!data) return;
    var engines = data.engines || [];
    var BUILTIN = new Set(['crm','marketing','social','calendar','automation','builder','websites','governance','seo']);

    for (var eng of engines) {
      if (BUILTIN.has(eng.slug)) continue;  // already handled by nav()
      // Fetch engine loader metadata and load via safe script tag injection
      try {
        var lr = await fetch(window.luApi + 'engines/' + eng.slug + '/loader', { headers: authHeader() });
        if (!lr.ok) { console.warn('[LevelUp] Engine loader ' + eng.slug + ' returned ' + lr.status); continue; }
        var ld = await safeJson(lr);
        if (!ld) continue;
        if (ld.loader_url && typeof ld.loader_url === 'string') {
          // Preferred: load engine JS from a URL via script tag (CSP-safe)
          await new Promise(function(ok, fail) {
            var s = document.createElement('script');
            s.src = ld.loader_url;
            s.onload = ok;
            s.onerror = function() { fail(new Error('Failed to load ' + ld.loader_url)); };
            document.head.appendChild(s);
          });
          console.log('[LevelUp] Engine loader registered (URL):', eng.slug);
        } else if (ld.js && typeof ld.js === 'string') {
          // Fallback: load inline JS via Blob URL script tag (no eval/new Function)
          var blob = new Blob([ld.js], { type: 'application/javascript' });
          var blobUrl = URL.createObjectURL(blob);
          await new Promise(function(ok, fail) {
            var s = document.createElement('script');
            s.src = blobUrl;
            s.onload = function() { URL.revokeObjectURL(blobUrl); ok(); };
            s.onerror = function() { URL.revokeObjectURL(blobUrl); fail(new Error('Blob script failed for ' + eng.slug)); };
            document.head.appendChild(s);
          });
          console.log('[LevelUp] Engine loader registered (inline):', eng.slug);
        }
      } catch (e) {
        console.warn('[LevelUp] Failed to load engine:', eng.slug, e.message);
      }
    }
  } catch (e) {
    console.warn('[LevelUp] Engine bootstrap failed:', e.message);
  }
});

// ═══════════════════════════════════════════════════════════════════
// PHASE 3A — TASK 3: DESIGN TOKEN CONSUMPTION
// Fetch /api/design-tokens and inject as CSS custom properties.
// Runs once on boot. Fail-safe: if fetch fails, existing hardcoded
// tokens continue to work (no visual change).
// ═══════════════════════════════════════════════════════════════════
;(async function _luBootTokens() {
  try {
    var r = await fetch(window.luApi + 'design-tokens', { headers: authHeader() });
    if (!r.ok) return;
    var payload = await r.json();
    var tokens = payload?.data || payload;
    if (!tokens?.colors) return;
    var root = document.documentElement;
    // Inject color tokens
    if (tokens.colors) {
      Object.entries(tokens.colors).forEach(([k,v]) => { root.style.setProperty('--lu-' + k, v); });
    }
    // Inject radii
    if (tokens.radii) {
      Object.entries(tokens.radii).forEach(([k,v]) => { root.style.setProperty('--lu-radius-' + k, v); });
    }
    // Inject typography
    if (tokens.typography) {
      if (tokens.typography.font_heading) root.style.setProperty('--lu-font-heading', tokens.typography.font_heading);
      if (tokens.typography.font_body) root.style.setProperty('--lu-font-body', tokens.typography.font_body);
    }
    console.log('[LevelUp] Design tokens applied from /api/design-tokens');
    window._luTokensData = tokens;
    window._luTokensLoaded = true;
  } catch(e) { /* fail-safe: tokens already hardcoded in CSS */ }
})();

// ═══════════════════════════════════════════════════════════════════
// PHASE 3A — TASK 1+2: RENDER MANIFEST + SEO BRIDGE DATA
// Caches render manifest on first fetch. Used by seoLoad() to show
// bridge data above the iframe when available.
// ═══════════════════════════════════════════════════════════════════
window._luRenderManifest = null;
window._luManifestLoaded = false;

async function _luFetchManifest() {
  if (window._luManifestLoaded) return window._luRenderManifest;
  try {
    var r = await fetch(window.luApi + 'tools/render-manifest', { headers: authHeader() });
    if (!r.ok) return null;
    var payload = await r.json();
    window._luRenderManifest = payload?.data || payload || [];
    window._luManifestLoaded = true;
    console.log('[LevelUp] Render manifest loaded:', window._luRenderManifest.length, 'tools');
    return window._luRenderManifest;
  } catch(e) { return null; }
}

async function _luFetchBridgeData(toolKey) {
  try {
    var r = await fetch(window.luApi + 'seo/bridge/' + toolKey, { headers: authHeader() });
    if (!r.ok) return null;
    var payload = await r.json();
    if (payload?.success && payload?.data) return payload;
    return null;
  } catch(e) { return null; }
}

function _luRenderBridgePanel(container, bridgeData, toolLabel) {
  if (!bridgeData || !bridgeData.data) return;
  var data = bridgeData.data;
  var toolKey = bridgeData.meta?.tool || '';

  // Remove old panel
  var old = container.querySelector('#seo-bridge-panel');
  if (old) old.remove();

  var panel = document.createElement('div');
  panel.id = 'seo-bridge-panel';
  panel.style.cssText = 'background:var(--s1);border-bottom:1px solid var(--bd);flex-shrink:0;overflow:hidden;transition:max-height .3s ease';

  // ── Header bar ──
  var hdr = `<div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--bd)">
    <span style="background:var(--ag);color:var(--ac);padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;flex-shrink:0">BRIDGE</span>
    <span style="font-size:12px;font-weight:600;color:var(--t1);flex:1">${_escB(toolLabel)}</span>
    <span style="font-size:10px;color:var(--t3)">${_escB(toolKey)}</span>
    <button onclick="document.getElementById('seo-bridge-panel')?.remove()" style="background:transparent;border:none;color:var(--t3);cursor:pointer;font-size:14px;padding:2px 4px;line-height:1" title="Close preview">✕</button>
  </div>`;

  let body = '';

  // ── Type-aware rendering ──
  if (Array.isArray(data)) {
    // Array of items → table (max 10 rows)
    body = _luBridgeTable(data);
  } else if (typeof data === 'object' && data !== null) {
    // Check if it looks like metrics (mostly numbers/strings at top level)
    var entries = Object.entries(data);
    var numericCount = entries.filter(([,v]) => typeof v === 'number' || (typeof v === 'string' && !isNaN(v) && v.length < 15)).length;
    var hasArrays = entries.some(([,v]) => Array.isArray(v));

    if (numericCount >= 3 && numericCount >= entries.length * 0.4) {
      // Mostly metrics → stat cards
      body = _luBridgeStatCards(entries);
    } else if (hasArrays) {
      // Mixed with arrays → stat cards for scalars + table for first array
      var scalars = entries.filter(([,v]) => !Array.isArray(v) && typeof v !== 'object');
      var firstArr = entries.find(([,v]) => Array.isArray(v));
      body = _luBridgeStatCards(scalars);
      if (firstArr) body += `<div style="padding:0 16px 4px;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em">${firstArr[0].replace(/_/g,' ')}</div>` + _luBridgeTable(firstArr[1]);
    } else {
      // General object → stat cards
      body = _luBridgeStatCards(entries);
    }
  } else if (typeof data === 'string') {
    body = `<div style="padding:12px 16px;font-size:12px;color:var(--t2);line-height:1.6">${_escB(data)}</div>`;
  }

  panel.innerHTML = hdr + `<div style="max-height:260px;overflow-y:auto">${body}</div>`;

  // Insert after nav bar
  var slot = container.querySelector('#seo-bridge-slot');
  if (slot) { slot.innerHTML = ''; slot.appendChild(panel); }
  else {
    var navBar = container.querySelector('#seo-embed-nav');
    if (navBar && navBar.nextSibling) container.insertBefore(panel, navBar.nextSibling);
    else container.prepend(panel);
  }
}

// ── Bridge render helpers ──
function _escB(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function _luBridgeStatCards(entries) {
  if (!entries.length) return '';
  var TS_KEYS = /created_at|updated_at|last_audit|last_scan|date|timestamp|_at$/i;
  var cards = entries.slice(0, 8).map(([k, v]) => {
    let display, color = 'var(--t1)';
    // Detect timestamp fields by key name
    if (TS_KEYS.test(k) && v) {
      var d = typeof v === 'number' ? new Date(v < 1e12 ? v * 1000 : v) : new Date(v);
      display = isNaN(d.getTime()) ? String(v) : d.toLocaleString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
      color = 'var(--t2)';
    } else if (typeof v === 'number') { display = v.toLocaleString(); }
    else if (typeof v === 'boolean') { display = v ? '✓' : '✗'; color = v ? 'var(--ac)' : 'var(--rd)'; }
    else if (v === null || v === undefined) { display = '—'; color = 'var(--t3)'; }
    else if (typeof v === 'object') { display = Array.isArray(v) ? v.length + ' items' : Object.keys(v).length + ' fields'; color = 'var(--t2)'; }
    else {
      // String that looks like a date/timestamp
      if (TS_KEYS.test(k) || /^\d{4}-\d{2}-\d{2}/.test(String(v))) {
        var d = new Date(v);
        if (!isNaN(d.getTime())) { display = d.toLocaleString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); color = 'var(--t2)'; }
        else { display = String(v).slice(0, 50); }
      } else { display = String(v).slice(0, 50); }
    }
    return `<div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;min-width:110px">
      <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">${_escB(k.replace(/_/g,' '))}</div>
      <div style="font-size:15px;font-weight:600;color:${color}">${_escB(display)}</div>
    </div>`;
  }).join('');
  return `<div style="display:flex;gap:8px;padding:10px 16px;overflow-x:auto;flex-wrap:wrap">${cards}</div>`;
}

function _luBridgeTable(arr) {
  if (!arr.length) return '<div style="padding:12px 16px;font-size:11px;color:var(--t3)">No items</div>';
  var rows = arr.slice(0, 10);
  // Get column headers from first item
  var first = rows[0];
  if (typeof first !== 'object' || first === null) {
    // Simple array of strings/numbers
    return `<div style="padding:8px 16px;display:flex;flex-direction:column;gap:4px">${rows.map(r => `<div style="font-size:12px;color:var(--t2);padding:4px 0;border-bottom:1px solid var(--bd)">${_escB(r)}</div>`).join('')}</div>`;
  }
  var cols = Object.keys(first).filter(k => typeof first[k] !== 'object').slice(0, 5);
  if (!cols.length) return '';
  var thead = cols.map(c => `<th style="font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.04em;padding:6px 10px;text-align:left;border-bottom:1px solid var(--bd);background:var(--s2)">${_escB(c.replace(/_/g,' '))}</th>`).join('');
  var tbody = rows.map(r => '<tr>' + cols.map(c => {
    var v = r[c]; let display;
    var isTs = /created_at|updated_at|last_audit|last_scan|date|timestamp|_at$/i.test(c);
    if (v === null || v === undefined) { display = '—'; }
    else if (isTs) {
      var d = typeof v === 'number' ? new Date(v < 1e12 ? v * 1000 : v) : new Date(v);
      display = isNaN(d.getTime()) ? String(v).slice(0,60) : d.toLocaleString(undefined, {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    } else { display = String(v).slice(0, 60); }
    return `<td style="font-size:11px;color:var(--t2);padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.04)">${_escB(display)}</td>`;
  }).join('') + '</tr>').join('');
  return `<table style="width:100%;border-collapse:collapse"><thead><tr>${thead}</tr></thead><tbody>${tbody}</tbody></table>` +
    (arr.length > 10 ? `<div style="padding:6px 16px;font-size:10px;color:var(--t3)">Showing 10 of ${arr.length}</div>` : '');
}

// [builder] extracted to builder.js (lines 3306-3313)

// ── COMPONENT TYPE DEFINITIONS ────────────────────────────────────────
// [builder] extracted to builder.js (lines 3316-3332)

// ── API STATE ─────────────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 3335-3335)

// ── LOAD PAGES (+ stats + library count) ──────────────────────────────
// [builder] extracted to builder.js (lines 3338-5263)
function arthurBindToComponent(cmp, si, ci, mi) {
  var typeLabels = {heading:'Heading',text:'Text Block',button:'Button',image:'Image',video:'Video',cta:'CTA Block',testimonial:'Testimonial',pricing:'Pricing Card',faq:'FAQ',form:'Form',list:'List',html:'Custom HTML',spacer:'Spacer',divider:'Divider',card:'Card'};
  var typeIcons  = {heading:'H',text:'¶',button:'⬜',image:'🖼',video:'▶',cta:'🚀',testimonial:'💬',pricing:'💰',faq:'❓',form:'📋',list:'≡',html:'<>',spacer:'↕',divider:'—',card:'🗂'};
  var cmpType = cmp.component_type || cmp.type || 'text';
  arthurContext = {si,ci,mi,type:cmpType,content:cmp.content||{},pageId:bldCurrentPageId};
  var bubble=document.getElementById('arthur-inline-panel');
  var typeEl=document.getElementById('arthur-el-type');
  var labelEl=document.getElementById('arthur-el-label');
  typeEl.textContent  = typeIcons[cmpType]||'?';
  labelEl.textContent = typeLabels[cmpType]||cmpType;
  var res=document.getElementById('arthur-result');
  res.classList.remove('visible'); res.innerHTML='';
  document.getElementById('arthur-input').value='';
  // Phase 6: show panel, then restore saved position or smart-position near component
  // FIX 2+3: Use individual style properties — cssText nukes transform (drag position)
  // and inline transitions (glow effect). Set only display/position, preserve transform.
  bubble.style.display   = 'flex';
  bubble.style.bottom    = '80px';
  bubble.style.right     = '20px';
  bubble.style.top       = 'auto';
  bubble.style.left      = 'auto';
  // Trigger open animation via class (not CSS animation property) so transform is preserved
  bubble.classList.add('arthur-opening');
  setTimeout(function() { bubble.classList.remove('arthur-opening'); }, 200);
  onArthurFinish(); // ensure no stale glow on open
  try {
    var saved = sessionStorage.getItem('lu_arthur_pos');
    if (saved) { _arthurPos = JSON.parse(saved); _arthurApplyPos(); }
    else { _arthurPositionNear(si, ci); }
  } catch(e) { _arthurPositionNear(si, ci); }
  setTimeout(()=>{
    var oh=function(e){if(!bubble.contains(e.target)&&!e.target.closest('.bld-cmp-wrap')){arthurHidePanel();document.removeEventListener('click',oh);}};
    document.addEventListener('click',oh);
  },200);
}

function arthurHidePanel(){
  var b=document.getElementById('arthur-inline-panel');
  if(b) b.style.display='none';
  arthurContext=null; arthurBusy=false;
}

function arthurQuickCommand(prompt){
  var inp=document.getElementById('arthur-input');
  if(inp) inp.value=prompt;
  arthurSendCommand();
}

async function arthurSendCommand(){
  if(!arthurContext||arthurBusy) return;
  var inp=document.getElementById('arthur-input');
  var command=inp.value.trim(); if(!command) return;
  arthurBusy=true;
  onArthurStart(); // Phase 6: enable glow
  var busyEl=document.getElementById('arthur-busy');
  var resEl=document.getElementById('arthur-result');
  var sendBtn=document.getElementById('arthur-send');
  busyEl.classList.add('visible'); resEl.classList.remove('visible');
  if(sendBtn) sendBtn.disabled=true;
  var instruction=`You are Arthur, the LevelUp AI builder assistant inside LevelUp Growth Platform. The user selected a "${arthurContext.type}" element with content: ${JSON.stringify(arthurContext.content)}. Business: ${BN} (${BU}). Request: "${command}". Return ONLY a raw JSON object — the updated content. No markdown, no explanation. Match the existing content structure. For heading/text: {"text":"..."}. For button: {"label":"...","href":"#","variant":"primary"}. For testimonial: {"quote":"...","author":"...","role":"..."}.`;
  try{
    var r = await fetch(API + 'builder/arthur', {
      method: 'POST',
      headers: Object.assign({'Content-Type': 'application/json'}, authHeader()),
      body: JSON.stringify({
        mode: 'inline',
        command: command,
        element_type: arthurContext.type,
        existing_content: arthurContext.content,
        site_name: BN
      })
    });
    var d = await safeJson(r);
    if (!d) { if(resEl){ resEl.innerHTML='<div style="color:#F87171">Arthur is temporarily unavailable. Please try again.</div>'; resEl.classList.add('visible'); } return; }
    if (d.content && typeof d.content === 'object') {
      // Apply the AI-generated content to the element
      if(resEl) resEl.innerHTML = '<strong style="color:var(--ac)">\u2713 Applied:</strong> <pre style="font-size:11px;margin-top:4px;color:var(--t2)">' + JSON.stringify(d.content, null, 2) + '</pre>';
    } else {
      if(resEl) resEl.innerHTML = '<div style="background:var(--s2);border-radius:6px;padding:8px;font-size:11px;line-height:1.5;color:var(--t2)">' + (d.raw || d.reply || 'No response from Arthur.') + '</div>';
    }
    if(resEl) resEl.classList.add('visible');
    inp.value = '';
  }catch(e){ console.error('[Arthur]', e); if(resEl){ resEl.innerHTML='<div style="color:#F87171">Error: '+e.message+'</div>'; resEl.classList.add('visible'); } }
  finally{ arthurBusy=false; onArthurEnd(); if(busyEl) busyEl.classList.remove('visible'); if(sendBtn) sendBtn.disabled=false; }
}

// ======================================================================
// BUILDER SPA — EXTRACTED TO levelup-builder-engine/builder-spa.js
// Builder loads via lu_platform_scripts hook or dynamic engine loader.
// ======================================================================


// ═══════════════════════════════════════════════════════════════════════════════
// CREATIVE888 — postMessage bridge for iframe-based engines (SEO Suite)
//
// SEO Suite iframe sends: window.parent.postMessage({ type:'lu:creative:request', ... })
// This listener opens the LuCreative bridge modal on the parent platform page.
// Result is posted back into the iframe via frame.contentWindow.postMessage.
// ═══════════════════════════════════════════════════════════════════════════════
;(function() {
    window.addEventListener('message', function(event) {
        // Verify it came from the SEO iframe
        if (!event.data || event.data.type !== 'lu:creative:request') return;

        // ── Origin hardening: only accept from same origin or known SEO iframe src ──
        var allowedOrigins = [window.location.origin];
        // If SEO iframe is loaded from a different subdomain (e.g. app.staging1...), add it
        if (window._seoIframe && window._seoIframe.src) {
            try {
                var seoOrigin = new URL(window._seoIframe.src).origin;
                allowedOrigins.push(seoOrigin);
            } catch(e) {}
        }
        if (allowedOrigins.indexOf(event.origin) === -1) {
            console.warn('[LuCreative Bridge] Blocked postMessage from untrusted origin:', event.origin);
            return;
        }

        var payload = event.data;
        var ctx     = payload.context || 'blog_featured';
        var hint    = payload.prompt_hint || '';
        var target  = event.source; // iframe window reference

        if (typeof window.LuCreative === 'undefined' || typeof window.LuCreative.pickModal !== 'function') {
            console.warn('[LuCreative Bridge] LuCreative not loaded yet');
            return;
        }

        window.LuCreative.pickModal({
            context:    ctx,
            title:      payload.title || 'Generate Featured Image',
            promptHint: hint,
            onInsert:   function(asset) {
                // Post result back to the SEO iframe
                if (target && target.postMessage) {
                    target.postMessage({
                        type:  'lu:creative:result',
                        asset: {
                            id:            asset.id,
                            public_url:    asset.public_url,
                            web_url:       asset.web_url,
                            thumbnail_url: asset.thumbnail_url,
                            prompt:        asset.prompt,
                        }
                    }, event.origin);
                }
            }
        });
    });

    // Also listen for the lu:creative:insert event dispatched by Social/Marketing engines
    window.addEventListener('lu:creative:insert', function(e) {
        if (!e.detail || !e.detail.asset) return;
        var ctx = e.detail.context || 'default';
        // If the current view is Social or Marketing, the engine JS handles it via event listener
        console.log('[LuCreative Bridge] insert event for context:', ctx, e.detail.asset.id);
    });
})();

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3.3.0 — BOOTSTRAP + AUTH + ONBOARDING + NOTIFICATIONS + ANALYTICS
// ════════════════════════════════════════════════════════════════════════════

// ── Helpers ──────────────────────────────────────────────────────────────────
// _luBase = bare origin (https://staging1.shukranuae.com)
// Use URL constructor to reliably extract origin from any rest_url() format:
//   https://example.com/api/          → https://example.com
//   https://example.com/?rest_route=/api/      → https://example.com
//   https://example.com/index.php?rest_route=... → https://example.com
var _luBase = (function() {
    if (window.LU_CFG && window.LU_CFG.api) {
        try { return new URL(window.LU_CFG.api).origin; } catch(_) {}
    }
    return window.location.origin;
})();

async function _luFetch(method, path, body) {
  var token  = localStorage.getItem('lu_token');
  var nonce  = (window.LU_CFG && window.LU_CFG.nonce) ? window.LU_CFG.nonce : '';
  var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  if (token)  headers['Authorization']  = 'Bearer ' + token;
  if (nonce)  headers['X-WP-Nonce']     = nonce;
  var opts = { method: method, headers: headers, cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  var r = await fetch(_luBase + '/api' + path, opts);
  if (r.status === 402 || r.status === 403) { try { var _pgd = await r.clone().json(); if (_pgd.code === 'PLAN_GATED' || _pgd.code === 'NO_CREDITS') { showPlanGate(_pgd.error || _pgd.message || 'This feature requires a plan upgrade.'); } } catch(_pge) {} }
  return r;
}

function _luEsc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── TASK 1.3: Bootstrap ───────────────────────────────────────────────────────
async function _appBootstrap() {
  // Skip entirely when running inside WordPress (WP Admin or WP-rendered page).
  // LU_CFG.nonce is injected by the PHP plugin only in WP context — never present
  // on the standalone SPA (app.levelupgrowth.ai) which has no PHP rendering.
  if (window.LU_CFG && window.LU_CFG.nonce) return; // WP-rendered context
  if (window.location.pathname.indexOf('/wp-admin') !== -1) return; // extra guard
  if (!document.getElementById('lu-auth-root')) return;

  var token = localStorage.getItem('lu_token');
  if (!token) { if (window.location.hash === "#signup") { _renderSignup(); } else { _renderLogin(); } return; }

  var refreshToken = localStorage.getItem('lu_refresh_token');
  if (!refreshToken) { _renderLogin(); return; }
  try {
    var r = await fetch(_luBase + '/api/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
      cache: 'no-store',
    });
    if (!r.ok) throw new Error('expired');
    var d = await r.json();
    if (d.access_token) localStorage.setItem('lu_token', d.access_token);
    if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
  } catch(_) {
    localStorage.removeItem('lu_token');
    _renderLogin();
    return;
  }

  // Check onboarding completion via new /onboarding/status endpoint.
  // Falls back to legacy /workspace/status path + lu_onboarded flag for back-compat.
  if (localStorage.getItem('lu_onboarded') === '1') {
    _appEnterDashboard(); return;
  }
  // PATCH (onboarding skip-existing): if workspace already has at least one
  // website, the wizard would just re-prompt for already-collected info.
  // Treat the existence of a website as "onboarded" and route straight to
  // dashboard. Caches the flag so subsequent loads skip the network call.
  try {
    var wsRes = await fetch(_luBase + '/api/builder/websites', {
      headers: { 'Authorization': 'Bearer ' + localStorage.getItem('lu_token'), 'Accept': 'application/json' },
      cache: 'no-store',
    });
    if (wsRes.ok) {
      var wsData = await wsRes.json();
      var sites = (wsData && (wsData.websites || wsData.data)) || (Array.isArray(wsData) ? wsData : []);
      if (Array.isArray(sites) && sites.length > 0) {
        localStorage.setItem('lu_onboarded', '1');
        _appEnterDashboard();
        return;
      }
    }
  } catch(_) { /* fall through to onboarding-status check */ }
  try {
    var sr = await fetch(_luBase + '/api/onboarding/status', {
      headers: { 'Authorization': 'Bearer ' + localStorage.getItem('lu_token'), 'Accept': 'application/json' },
      cache: 'no-store',
    });
    if (sr.ok) {
      var s = await sr.json();
      if (s.step === 'complete') {
        localStorage.setItem('lu_onboarded', '1');
        _appEnterDashboard();
        return;
      }
      if (s.step === 3) {
        _showOnboardingStep3();
        return;
      }
      // step 1 or 2 → render Step 2 (collect business info)
      _renderOnboardingStep2(s.workspace_data || {});
      return;
    }
  } catch(_) { /* fall through to legacy path */ }

  try {
    var ws = await _luFetch('GET', '/workspace/status').then(function(r){ return r.json(); });
    if (ws.industry && ws.website_count > 0) {
      localStorage.setItem('lu_onboarded', '1');
      _appEnterDashboard();
    } else {
      _renderOnboardingStep2({});
    }
  } catch(_) { _renderOnboardingStep2({}); }
}

function _appEnterDashboard() {
  // Show the SPA main app, hide auth overlay
  var authRoot = document.getElementById('lu-auth-root');
  if (authRoot) authRoot.style.display = 'none';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'flex';
  // Run trial check once
  _checkTrialStatus();
  // Start notification polling
  _notifStartPolling();
  // Signal that bootstrap is complete (consumers like the workspace canvas loader rely on this)
  document.dispatchEvent(new Event('lu:bootstrap-complete'));
}

// ── Onboarding + auth views ──────────────────────────────────────────
// Extracted to onboarding.js (Phase O1 — 2026-05-10)


// ── TASK 2.1–2.3: 5-step onboarding ──────────────────────────────────────────
var _ob = {
  step:     parseInt(localStorage.getItem('lu_ob_step')||'0'),
  name:     localStorage.getItem('lu_ob_name')||'',
  industry: localStorage.getItem('lu_ob_industry')||'',
  services: JSON.parse(localStorage.getItem('lu_ob_services')||'[]'),
  goal:     localStorage.getItem('lu_ob_goal')||'',
  location: localStorage.getItem('lu_ob_location')||'',
  website_id: localStorage.getItem('lu_ob_website_id')||null,
};

function _obSave(key, val) {
  _ob[key] = val;
  localStorage.setItem('lu_ob_' + key, typeof val === 'object' ? JSON.stringify(val) : String(val));
}

function _renderOnboarding() {
  var root = document.getElementById('lu-auth-root');
  if (!root) return;
  root.style.display = 'flex';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'none';

  var steps = ['Business', 'Industry', 'Goal', 'Generate', 'Done'];
  var pips = steps.map(function(s,i){ return '<div style="display:flex;flex-direction:column;align-items:center;gap:4px"><div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:' + (i<=_ob.step?'var(--p,#6C5CE7)':'var(--s2,#1E2230)') + ';color:' + (i<=_ob.step?'#fff':'var(--t3,#777)') + '">' + (i+1) + '</div><div style="font-size:10px;color:var(--t3,#777)">' + s + '</div></div>'; }).join('<div style="height:1px;background:var(--bd,#2a2d3e);flex:1;margin-top:14px"></div>');

  root.innerHTML = `
  <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg,#0F1117);padding:20px">
    <div style="width:100%;max-width:540px">
      <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:28px">${pips}</div>
      <div id="ob-step-area" style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:16px;padding:32px"></div>
    </div>
  </div>`;
  _obRenderStep(_ob.step);
}

function _obRenderStep(step) {
  var area = document.getElementById('ob-step-area');
  if (!area) return;
  _obSave('step', step);

  if (step === 0) {
    area.innerHTML = `
      <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 6px">Tell us about your business</h2>
      <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">Your AI team needs this context to deliver relevant work.</p>
      <div class="form-group" style="margin-bottom:14px"><label class="form-label">Business name *</label><input class="form-input" id="ob-name" value="${_luEsc(_ob.name)}" placeholder="e.g. Shukran Interiors"></div>
      <div class="form-group" style="margin-bottom:24px"><label class="form-label">Location <span style="font-size:10px;color:var(--t3)">optional</span></label><input class="form-input" id="ob-loc" value="${_luEsc(_ob.location)}" placeholder="e.g. Dubai, UAE"></div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="_obNext0()">Continue →</button>`;
  } else if (step === 1) {
    area.innerHTML = `
      <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 6px">What industry are you in?</h2>
      <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">This helps your AI team use the right language and strategy.</p>
      <div class="form-group" style="margin-bottom:14px"><label class="form-label">Industry *</label><input class="form-input" id="ob-ind" value="${_luEsc(_ob.industry)}" placeholder="e.g. Interior Design, SaaS, E-commerce"></div>
      <div class="form-group" style="margin-bottom:24px"><label class="form-label">Core services <span style="font-size:10px;color:var(--t3)">comma-separated</span></label><input class="form-input" id="ob-svc" value="${_luEsc(Array.isArray(_ob.services)?_ob.services.join(', '):_ob.services)}" placeholder="e.g. Interior design, Fit-out, Consultation"></div>
      <div style="display:flex;gap:10px"><button class="btn btn-outline" onclick="_obRenderStep(0)">← Back</button><button class="btn btn-primary" style="flex:1;justify-content:center" onclick="_obNext1()">Continue →</button></div>`;
  } else if (step === 2) {
    area.innerHTML = `
      <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 6px">What's your main goal?</h2>
      <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">We'll build your first website around this.</p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
        ${[['leads','Generate more leads',''+window.icon("chart",14)+''],['brand','Build brand credibility',''+window.icon("star",14)+''],['ecommerce','Sell products online','🛒'],['portfolio','Showcase my work',''+window.icon("ai",14)+'']].map(function(g){
          var sel = _ob.goal===g[0];
          return '<div onclick="_obSelectGoal(\''+g[0]+'\')" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border:2px solid '+(sel?'var(--p,#6C5CE7)':'var(--bd,#2a2d3e)')+';border-radius:10px;cursor:pointer;background:'+(sel?'rgba(108,92,231,.1)':'var(--s2,#1E2230)')+'"><span style="font-size:22px">'+g[2]+'</span><div><div style="font-size:13px;font-weight:600;color:var(--t1,#e0e0e0)">'+g[1]+'</div></div></div>';
        }).join('')}
      </div>
      <div style="display:flex;gap:10px"><button class="btn btn-outline" onclick="_obRenderStep(1)">← Back</button><button class="btn btn-primary" id="ob-g-next" style="flex:1;justify-content:center;'+((!_ob.goal)?'opacity:.5;cursor:not-allowed':'')+'" onclick="_obNext2()">Next: Choose Style →</button></div>`;
  } else if (step === 3) {
    // Brand identity step
    var industry = (_ob.industry||'').toLowerCase();
    var palettes = {
      'tech':     [{p:'#6C5CE7',s:'#00CEC9',a:'#F4F7FB',n:'Purple-Teal'},{p:'#0D1B2A',s:'#00E5A8',a:'#E8EDF5',n:'Dark-Cyan'},{p:'#3B82F6',s:'#EFF6FF',a:'#1E293B',n:'Blue-White'}],
      'interior': [{p:'#2D5016',s:'#F5F0E8',a:'#8B7355',n:'Green-Cream'},{p:'#1B2838',s:'#D4A843',a:'#F4F7FB',n:'Navy-Gold'},{p:'#6B5B4B',s:'#E8DDD0',a:'#B87333',n:'Warm Copper'}],
      'food':     [{p:'#E8590C',s:'#FFF5E6',a:'#8B4513',n:'Warm Orange'},{p:'#5C3D2E',s:'#FFF8F0',a:'#D4A843',n:'Brown-Cream'},{p:'#1A472A',s:'#F0F7E6',a:'#D4A843',n:'Green-Gold'}],
      'health':   [{p:'#0891B2',s:'#F0FDFA',a:'#5EEAD4',n:'Blue-Mint'},{p:'#FFFFFF',s:'#14B8A6',a:'#F0FDFA',n:'White-Teal'},{p:'#8B5CF6',s:'#FAF5FF',a:'#C4B5FD',n:'Soft Purple'}],
      'legal':    [{p:'#1B2838',s:'#D4A843',a:'#F4F7FB',n:'Navy-Gold'},{p:'#374151',s:'#D1D5DB',a:'#F9FAFB',n:'Grey-Silver'},{p:'#1E3A5F',s:'#FFFFFF',a:'#60A5FA',n:'Deep Blue'}],
      'fashion':  [{p:'#EC4899',s:'#0F0F0F',a:'#FDF2F8',n:'Pink-Black'},{p:'#FFFFFF',s:'#F43F5E',a:'#FFF1F2',n:'White-Rose'},{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF',n:'Bold Red'}],
      'fitness':  [{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF',n:'Red-Black'},{p:'#EA580C',s:'#1C1917',a:'#FFF7ED',n:'Dark Orange'},{p:'#16A34A',s:'#0F0F0F',a:'#F0FDF4',n:'Green-Black'}],
    };
    var palKey='tech';
    if(/interior|real.estate|furniture|home/i.test(industry)) palKey='interior';
    else if(/food|restaurant|cafe|bakery|catering/i.test(industry)) palKey='food';
    else if(/health|medical|dental|clinic|pharma/i.test(industry)) palKey='health';
    else if(/law|legal|finance|accounting|bank/i.test(industry)) palKey='legal';
    else if(/fashion|retail|clothing|beauty|cosmetic/i.test(industry)) palKey='fashion';
    else if(/fitness|gym|sport|yoga|wellness/i.test(industry)) palKey='fitness';
    var pals=palettes[palKey]||palettes.tech;
    var selPal=parseInt(_ob.palette_idx)||0;
    area.innerHTML='<h2 style="font-family:var(--fh);font-size:22px;font-weight:800;color:var(--t1);margin:0 0 6px">Choose Your Brand Style</h2>'+
      '<p style="color:var(--t3);font-size:13px;margin:0 0 20px">Colors and fonts for your entire website.</p>'+
      '<div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Color Palette</div>'+
      '<div style="display:flex;gap:10px;margin-bottom:20px">'+
      pals.map(function(p,i){return '<div onclick="_obSelectPalette('+i+')" style="flex:1;padding:14px;border:2px solid '+(i===selPal?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;background:'+(i===selPal?'rgba(108,92,231,.08)':'var(--s2)')+'"><div style="display:flex;gap:4px;margin-bottom:8px"><div style="width:32px;height:32px;border-radius:6px;background:'+p.p+'"></div><div style="width:32px;height:32px;border-radius:6px;background:'+p.s+'"></div><div style="width:32px;height:32px;border-radius:6px;background:'+p.a+';border:1px solid var(--bd)"></div></div><div style="font-size:11px;font-weight:600;color:var(--t1)">'+p.n+'</div></div>';}).join('')+
      '</div>'+
      '<div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Font Pairing</div>'+
      '<div style="display:flex;gap:10px;margin-bottom:24px">'+
      '<div onclick="_obSelectFont(0)" style="flex:1;padding:12px;border:2px solid '+(parseInt(_ob.font_idx||0)===0?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;text-align:center"><div style="font-family:Syne;font-size:16px;font-weight:700;color:var(--t1)">Syne</div><div style="font-size:11px;color:var(--t3)">+ DM Sans</div></div>'+
      '<div onclick="_obSelectFont(1)" style="flex:1;padding:12px;border:2px solid '+(parseInt(_ob.font_idx||0)===1?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;text-align:center"><div style="font-family:Georgia;font-size:16px;font-weight:700;color:var(--t1)">Playfair</div><div style="font-size:11px;color:var(--t3)">+ Inter</div></div>'+
      '<div onclick="_obSelectFont(2)" style="flex:1;padding:12px;border:2px solid '+(parseInt(_ob.font_idx||0)===2?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;text-align:center"><div style="font-family:monospace;font-size:16px;font-weight:700;color:var(--t1)">Grotesk</div><div style="font-size:11px;color:var(--t3)">+ Manrope</div></div>'+
      '</div>'+
      '<div style="display:flex;gap:10px"><button class="btn btn-outline" onclick="_obRenderStep(2)">\u2190 Back</button><button class="btn btn-primary" style="flex:1;justify-content:center" onclick="_obNext3()">Generate My Website \u2192</button></div>';

  } else if (step === 4) {
    area.innerHTML = `
      <div style="text-align:center;padding:20px 0">
        <div style="font-size:48px;margin-bottom:16px">${window.icon('ai',14)}</div>
        <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 8px">Building your website…</h2>
        <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">Your AI team is generating pages for <strong style="color:var(--t1)">${_luEsc(_ob.name)}</strong></p>
        <div style="background:var(--s2,#1E2230);border-radius:10px;padding:20px">
          <div id="ob-progress" style="font-size:13px;color:var(--p,#6C5CE7);font-weight:600">Starting generation…</div>
          <div style="height:4px;background:var(--bd,#2a2d3e);border-radius:2px;margin-top:12px;overflow:hidden"><div id="ob-prog-bar" style="height:100%;background:linear-gradient(90deg,var(--p,#6C5CE7),var(--ac,#00E5A8));border-radius:2px;width:5%;transition:width .5s"></div></div>
        </div>
      </div>`;
    _obGenerate();
  } else if (step === 5) {
    area.innerHTML = `
      <div style="text-align:center;padding:20px 0">
        <div style="font-size:56px;margin-bottom:16px">${window.icon('star',14)}</div>
        <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:24px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 8px">Your workspace is ready.</h2>
        <p style="color:var(--t2,#aaa);font-size:14px;margin:0 0 28px">Website generated for <strong style="color:var(--t1)">${_luEsc(_ob.name)}</strong>. Your AI marketing team is standing by.</p>
        <div style="background:var(--s2,#1E2230);border-radius:10px;padding:16px;margin-bottom:24px;text-align:left">
          <div style="font-size:12px;color:var(--t3,#777);margin-bottom:8px">WHAT'S READY</div>
          <div style="font-size:13px;color:var(--t1,#e0e0e0);line-height:1.8">${window.icon('check',14)} Website generated<br>${window.icon('check',14)} AI agents briefed<br>${window.icon('check',14)} Trial credits active (50 credits)</div>
        </div>
        <button class="btn btn-primary" style="width:100%;justify-content:center;font-size:15px;padding:14px" onclick="_obComplete()">Enter Dashboard →</button>
      </div>`;
  }
}

function _obSelectGoal(g) {
  _obSave('goal', g);
  _obRenderStep(2); // re-render to show selection
}

window._obSelectPalette=function(idx){
  _obSave('palette_idx',idx);
  _obRenderStep(3);
};
window._obSelectFont=function(idx){
  _obSave('font_idx',idx);
  _obRenderStep(3);
};
function _obNext3(){
  // Brand step done → go to generation (step 4)
  // Store selected palette + font
  var industry=(_ob.industry||'').toLowerCase();
  var palettes={tech:[{p:'#6C5CE7',s:'#00CEC9',a:'#F4F7FB'},{p:'#0D1B2A',s:'#00E5A8',a:'#E8EDF5'},{p:'#3B82F6',s:'#EFF6FF',a:'#1E293B'}],interior:[{p:'#2D5016',s:'#F5F0E8',a:'#8B7355'},{p:'#1B2838',s:'#D4A843',a:'#F4F7FB'},{p:'#6B5B4B',s:'#E8DDD0',a:'#B87333'}],food:[{p:'#E8590C',s:'#FFF5E6',a:'#8B4513'},{p:'#5C3D2E',s:'#FFF8F0',a:'#D4A843'},{p:'#1A472A',s:'#F0F7E6',a:'#D4A843'}],health:[{p:'#0891B2',s:'#F0FDFA',a:'#5EEAD4'},{p:'#FFFFFF',s:'#14B8A6',a:'#F0FDFA'},{p:'#8B5CF6',s:'#FAF5FF',a:'#C4B5FD'}],legal:[{p:'#1B2838',s:'#D4A843',a:'#F4F7FB'},{p:'#374151',s:'#D1D5DB',a:'#F9FAFB'},{p:'#1E3A5F',s:'#FFFFFF',a:'#60A5FA'}],fashion:[{p:'#EC4899',s:'#0F0F0F',a:'#FDF2F8'},{p:'#FFFFFF',s:'#F43F5E',a:'#FFF1F2'},{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF'}],fitness:[{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF'},{p:'#EA580C',s:'#1C1917',a:'#FFF7ED'},{p:'#16A34A',s:'#0F0F0F',a:'#F0FDF4'}]};
  var palKey='tech';
  if(/interior|real.estate|furniture|home/i.test(industry))palKey='interior';
  else if(/food|restaurant|cafe|bakery/i.test(industry))palKey='food';
  else if(/health|medical|dental|clinic/i.test(industry))palKey='health';
  else if(/law|legal|finance|accounting/i.test(industry))palKey='legal';
  else if(/fashion|retail|clothing|beauty/i.test(industry))palKey='fashion';
  else if(/fitness|gym|sport|yoga/i.test(industry))palKey='fitness';
  var pals=palettes[palKey]||palettes.tech;
  var sel=pals[_ob.palette_idx||0]||pals[0];
  _obSave('primary_color',sel.p);
  _obSave('secondary_color',sel.s);
  _obSave('accent_color',sel.a);
  var fonts=[['Syne','DM Sans'],['Playfair Display','Inter'],['Space Grotesk','Manrope']];
  var f=fonts[_ob.font_idx||0]||fonts[0];
  _obSave('font_heading',f[0]);
  _obSave('font_body',f[1]);
  _obRenderStep(4);
}
function _obNext0() {
  var name = (document.getElementById('ob-name')||{}).value||'';
  if (!name.trim()) { if(typeof showToast==='function') showToast('Enter your business name.','error'); return; }
  _obSave('name', name.trim());
  _obSave('location', (document.getElementById('ob-loc')||{}).value||'');
  _obRenderStep(1);
}

function _obNext1() {
  var ind = (document.getElementById('ob-ind')||{}).value||'';
  if (!ind.trim()) { if(typeof showToast==='function') showToast('Enter your industry.','error'); return; }
  _obSave('industry', ind.trim());
  var svc = (document.getElementById('ob-svc')||{}).value||'';
  _obSave('services', svc.split(',').map(function(s){ return s.trim(); }).filter(Boolean));
  _obRenderStep(2);
}

function _obNext2() {
  if (!_ob.goal) { if(typeof showToast==='function') showToast('Select a goal.','error'); return; }
  _obRenderStep(3);
}

async function _obGenerate() {
  try {
    // Save workspace settings (existing endpoint)
    await _luFetch('POST', '/workspace/settings', {
      business_name: _ob.name,
      industry:      _ob.industry,
      services:      Array.isArray(_ob.services) ? _ob.services.join(', ') : _ob.services,
      business_desc: _ob.goal,
    });

    // Page map by goal (task 2.2)
    var pageMap = {
      leads:     ['home','services','about','contact'],
      brand:     ['home','about','services','contact'],
      ecommerce: ['home','products','about','contact'],
      portfolio: ['home','portfolio','about','contact'],
    };

    // TASK 2.2: use existing POST api/websites/create
    var r = await _luFetch('POST', '/builder/wizard', {
      wizard_mode:   true,
      business_name: _ob.name,
      industry:      _ob.industry,
      goal:          _ob.goal,
      location:      _ob.location,
      services:      _ob.services,
      pages:         pageMap[_ob.goal] || ['home','about','services','contact'],
      primary_color: _ob.primary_color || '#6C5CE7',
      secondary_color: _ob.secondary_color || '#00E5A8',
      accent_color:  _ob.accent_color || '#F4F7FB',
      font_heading:  _ob.font_heading || 'Syne',
      font_body:     _ob.font_body || 'DM Sans',
    });
    var d = await safeJson(r);
    if (!d) throw new Error('Server error \u2014 could not start website generation. Please try again.');
    if (!d.website_id) throw new Error(d.error || d.message || 'Website creation failed');
    _obSave('website_id', d.website_id);

    // Poll until complete (task 2.2)
    var pct = 10;
    for (var i = 0; i < 30; i++) {
      await new Promise(function(res){ setTimeout(res, 2000); });
      var sr = await _luFetch('GET', '/builder/websites/' + d.website_id);
      var sd = await safeJson(sr);
      if (!sd) continue; // skip this poll cycle on error
      var pagesTotal = sd.pages_total || 4;
      var pagesDone  = sd.pages_generated || sd.pages_done || 0;
      pct = Math.max(pct, Math.round(10 + (pagesDone / pagesTotal) * 80));
      var bar = document.getElementById('ob-prog-bar');
      var lbl = document.getElementById('ob-progress');
      if (bar) bar.style.width = pct + '%';
      if (lbl) lbl.textContent = 'Generating… (' + pagesDone + '/' + pagesTotal + ' pages)';
      if (sd.status === 'complete' || sd.status === 'published') {
        var bar2 = document.getElementById('ob-prog-bar');
        if (bar2) bar2.style.width = '100%';
        _obRenderStep(5);
        return;
      }
    }
    // Timeout fallback — still show done
    _obRenderStep(5);
  } catch(e) {
    var lbl = document.getElementById('ob-progress');
    if (lbl) lbl.textContent = 'Generation issue: ' + e.message + ' — continuing anyway.';
    setTimeout(function(){ _obRenderStep(5); }, 2000);
  }
}

// TASK 2.3
function _obComplete() {
  localStorage.setItem('lu_onboarded', '1');
  // Clear onboarding state
  ['step','name','industry','services','goal','location','website_id'].forEach(function(k){
    localStorage.removeItem('lu_ob_' + k);
  });
  _appEnterDashboard();
  // Navigate to command center
  setTimeout(function(){ if (typeof nav === 'function') nav('command'); }, 300);
}

// ── Trial credit warning ──────────────────────────────────────────────────────
async function _checkTrialStatus() {
  try {
    var r = await _luFetch('GET', '/workspace/status');
    if (!r.ok) return;
    var s = await r.json();
    var balance = s.credit_balance || 0;
    var limit = s.monthly_credit_limit || 0;
    var plan = (s.plan && s.plan.plan_slug) ? s.plan.plan_slug : 'free';

    // Show credit widget in sidebar
    var widget = document.getElementById('sb-credit-widget');
    if (widget) {
      widget.style.display = 'block';
      var valEl = document.getElementById('sb-credit-val');
      var barEl = document.getElementById('sb-credit-bar');
      var subEl = document.getElementById('sb-credit-sub');
      var badgeEl = document.getElementById('sb-plan-badge');
      if (valEl) valEl.textContent = Math.round(balance);
      if (subEl) subEl.textContent = limit > 0 ? 'of ' + limit + ' monthly limit' : 'credits available';
      if (barEl) {
        var pct = limit > 0 ? Math.min(100, (balance / limit) * 100) : (balance > 0 ? 100 : 0);
        barEl.style.width = pct + '%';
        barEl.style.background = pct < 20 ? 'var(--rd)' : pct < 50 ? 'var(--am)' : 'var(--ac)';
      }
      if (badgeEl) badgeEl.textContent = plan.charAt(0).toUpperCase() + plan.slice(1);
    }

    // Low credit warning (once per session)
    if (!sessionStorage.getItem('lu_trial_warned') && balance < 15 && balance > 0) {
      sessionStorage.setItem('lu_trial_warned', '1');
      if (typeof showToast === 'function') showToast(Math.round(balance) + ' credits remaining.', 'warning');
    }

    // ── NAV GATING: show/hide nav items based on plan features ──
    var features = (s.plan && s.plan.features) ? s.plan.features : {};
    document.querySelectorAll('[data-feature]').forEach(function(el) {
      var feat = el.getAttribute('data-feature');
      el.style.display = features[feat] ? '' : 'none';
    });

    // ── ADMIN GATING: check cached user data (no extra API call) ──
    try {
      var cachedUser = localStorage.getItem('lu_user');
      var isAdmin = false;
      if (cachedUser) {
        try { isAdmin = JSON.parse(cachedUser).is_platform_admin; } catch(_) {}
      }
      if (!isAdmin) {
        // Fallback: check from auth/me only if not cached
        var meR = await _luFetch('GET', '/auth/me');
        if (meR.ok) {
          var meD = await meR.json();
          isAdmin = meD.user && meD.user.is_platform_admin;
          if (meD.user) localStorage.setItem('lu_user', JSON.stringify(meD.user));
        }
      }
      document.querySelectorAll('[data-admin-only]').forEach(function(el) {
        el.style.display = isAdmin ? '' : 'none';
      });
      window._luIsAdmin = isAdmin;
    } catch(_adminErr) {}

  } catch(_) {}
}

// ── TASK 4.4: Notification bell ───────────────────────────────────────────────
var _notifPollTimer = null;

function _notifStartPolling() {
  if (_notifPollTimer) return;
  _notifPoll();
  _notifPollTimer = setInterval(_notifPoll, 30000);
}

async function _notifPoll() {
  try {
    // PATCH 10 Fix 4 — Use the dedicated unread-count endpoint (returns
    // {count: N}). Previous version called /notifications?unread=true&limit=5
    // and read d.total_unread, which the controller never returns — bell
    // perpetually showed zero regardless of real unread state.
    // Backend: app/Http/Controllers/Api/NotificationController.php:43 unreadCount()
    var r = await _luFetch('GET', '/notifications/unread-count');
    if (!r.ok) return;
    var d = await r.json();
    var count = (typeof d.count === 'number') ? d.count : (d.total_unread || 0);
    var bell = document.getElementById('lu-notif-bell');
    if (!bell) return;
    if (count > 0) {
      bell.innerHTML = ''+window.icon("info",14)+' <span style="background:var(--rd,#F87171);color:#fff;border-radius:10px;padding:1px 5px;font-size:10px;font-weight:700;margin-left:2px">' + count + '</span>';
      bell.title = count + ' unread notification' + (count > 1 ? 's' : '');
    } else {
      bell.innerHTML = ''+window.icon("info",14)+'';
      bell.title = 'No new notifications';
    }
  } catch(_) {}
}

async function _openNotifications() {
  try {
    var r = await _luFetch('GET', '/notifications?limit=20');
    var d = await r.json();
    var items = d.notifications || [];
    var bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = function(e){ if(e.target===bd) bd.remove(); };
    bd.innerHTML = '<div class="modal" style="max-width:400px">'
      + '<div class="modal-header"><h3>Notifications</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div>'
      + '<div class="modal-body" style="padding:0;max-height:400px;overflow-y:auto">'
      + (items.length === 0
          ? '<div style="padding:32px;text-align:center;color:var(--t3);font-size:13px">No notifications</div>'
          : items.map(function(n){
              var unread = !n.read_at;
              return '<div style="display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--bd);background:' + (unread?'rgba(108,92,231,.06)':'') + ';cursor:pointer" onclick="_markNotifRead(' + n.id + ',this)">'
                + '<div style="width:8px;height:8px;border-radius:50%;background:' + (unread?'var(--p,#6C5CE7)':'transparent') + ';flex-shrink:0;margin-top:4px"></div>'
                + '<div style="flex:1"><div style="font-size:13px;font-weight:' + (unread?'600':'400') + ';color:var(--t1)">' + _luEsc(n.title) + '</div>'
                + '<div style="font-size:11px;color:var(--t3);margin-top:2px">' + new Date(n.created_at).toLocaleString() + '</div></div></div>';
            }).join(''))
      + '</div></div>';
    document.body.appendChild(bd);
    bd.style.opacity = '1'; bd.style.pointerEvents = 'all';
    // Mark all visible unread notifications
    _notifPoll();
  } catch(e) { if(typeof showToast==='function') showToast('Could not load notifications','error'); }
}

async function _markNotifRead(id, el) {
  try {
    await _luFetch('POST', '/notifications/' + id + '/read');
    if (el) { el.style.background = ''; el.querySelector('div[style*="border-radius:50%"]').style.background = 'transparent'; }
    _notifPoll();
  } catch(_) {}
}


// ── REVIEW QUEUE v5.5.1 ────────────────────────────────────────────────────
// Dedicated page for managing pending approvals. All data from /api/approvals.
// Reuses cmd-nav-badge-approvals counter + styles from cmd-* classes.

var _aqState = {
  status: 'pending',
  page: 1,
  perPage: 20,
  selected: new Set(),
  stats: null,
  pollTimer: null,
};

window.loadApprovals = async function loadApprovals() {
  _aqState.selected = new Set();
  _aqBulkClear();
  _aqStartPolling();
  await Promise.all([_aqFetchStats(), _aqFetchList()]);
};

function _aqStartPolling() {
  if (_aqState.pollTimer) return;
  _aqState.pollTimer = setInterval(function() {
    if (document.hidden) return;
    if (!_aqIsView()) { _aqStopPolling(); return; }
    _aqFetchStats();
    if (_aqState.status === 'pending') _aqFetchList(true);
  }, 60000);
}
function _aqStopPolling() {
  if (_aqState.pollTimer) { clearInterval(_aqState.pollTimer); _aqState.pollTimer = null; }
}
function _aqIsView() {
  var v = document.getElementById('view-approvals');
  return v && v.classList.contains('active');
}
function _aqReload() { _aqFetchStats(); _aqFetchList(); }

async function _aqFetchStats() {
  try {
    var r = await _luFetch('GET', '/approvals/stats');
    if (!r.ok) return;
    var s = await r.json();
    _aqState.stats = s;
    _aqRenderStats(s);
    // Nav badges
    var nav = document.getElementById('nav-approval-badge');
    if (nav) { if (s.pending > 0) { nav.textContent = s.pending > 99 ? '99+' : s.pending; nav.classList.add('show'); } else nav.classList.remove('show'); }
    _cmdcSetNavBadge && _cmdcSetNavBadge(s.pending || 0);
  } catch(_) {}
}

function _aqRenderStats(s) {
  var byId = function(id){ return document.getElementById(id); };
  var pending = byId('aq-stat-pending'); if (pending) pending.textContent = s.pending;
  var tabc    = byId('aq-tabc-pending'); if (tabc)    tabc.textContent = s.pending;
  var approved = byId('aq-stat-approved'); if (approved) approved.textContent = s.approved_today;
  var approvedMeta = byId('aq-stat-approved-meta'); if (approvedMeta) approvedMeta.textContent = 'this week: ' + s.approved_this_week;
  var avg = byId('aq-stat-avg'); if (avg) avg.textContent = (s.avg_response_hours != null) ? s.avg_response_hours + ' hrs' : '—';
  var oldest = byId('aq-stat-oldest');
  var oldestMeta = byId('aq-stat-oldest-meta');
  var oldestCard = byId('aq-stat-oldest-card');
  if (oldest) {
    if (s.oldest_pending_hours > 0) {
      var h = s.oldest_pending_hours;
      var txt = h >= 24 ? Math.floor(h / 24) + 'd' : h + 'h';
      oldest.textContent = txt;
      if (oldestMeta) oldestMeta.textContent = h > 24 ? 'overdue' : 'within SLA';
    } else { oldest.textContent = '—'; if (oldestMeta) oldestMeta.textContent = 'no pending'; }
  }
  if (oldestCard) {
    if (s.is_overdue) oldestCard.classList.add('aq-overdue');
    else oldestCard.classList.remove('aq-overdue');
  }
  // Show Expire-stale button only if any pending >= 30 days
  var expBtn = byId('aq-expire-btn');
  if (expBtn) expBtn.style.display = (s.oldest_pending_hours >= 24 * 30) ? 'inline-block' : 'none';

  // Subhead summary
  var sub = byId('aq-subhead');
  if (sub) {
    var overCount = s.is_overdue ? (' · <strong style="color:var(--rd)">' + Math.floor(s.oldest_pending_hours / 24) + 'd overdue</strong>') : '';
    sub.innerHTML = (s.pending || 0) + ' pending' + overCount;
  }
}

async function _aqFetchList(silent) {
  var list = document.getElementById('aq-list');
  if (!silent && list) list.innerHTML = '<div class="cmd-empty">Loading…</div>';
  try {
    var qs = '?status=' + encodeURIComponent(_aqState.status) + '&page=' + _aqState.page + '&per_page=' + _aqState.perPage;
    var r = await _luFetch('GET', '/approvals' + qs);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var d = await r.json();
    _aqRenderList(d);
  } catch(e) {
    if (list) list.innerHTML = '<div class="cmd-empty">Could not load approvals: ' + _cmdcEsc(e.message || '') + '</div>';
  }
}

function _aqRenderList(d) {
  var list = document.getElementById('aq-list');
  var pager = document.getElementById('aq-pager');
  if (!list) return;
  if (!d.items.length) {
    list.innerHTML = _aqEmptyStateFor(_aqState.status);
    if (pager) pager.style.display = 'none';
    return;
  }
  list.innerHTML = d.items.map(_aqCardHtml).join('');
  // Pagination
  if (pager) {
    if (d.pages > 1) {
      pager.style.display = 'flex';
      document.getElementById('aq-page-label').textContent = 'Page ' + d.page + ' of ' + d.pages;
      document.getElementById('aq-prev').disabled = d.page <= 1;
      document.getElementById('aq-next').disabled = d.page >= d.pages;
    } else pager.style.display = 'none';
  }
}

function _aqEmptyStateFor(status) {
  if (status === 'pending') {
    return '<div class="cmd-empty" style="padding:48px 16px">' +
      '<div style="font-size:28px;margin-bottom:8px;color:var(--ac)">✓</div>' +
      "<div style=\"color:var(--t1);font-weight:600;margin-bottom:4px\">You're all caught up.</div>" +
      '<div>Sarah will notify you when new approvals need your attention.</div></div>';
  }
  return '<div class="cmd-empty" style="padding:40px 16px">No ' + _cmdcEsc(status) + ' approvals in this workspace.</div>';
}

function _aqCardHtml(it) {
  var isPending = it.status === 'pending';
  var task = it.task;
  var label = task ? _cmdcEsc(task.label) : 'Orphan approval (no attached task)';
  var desc  = task && task.description ? '<div class="aq-card-desc">' + _cmdcEsc(task.description) + '</div>' : '';
  var agent = task ? task.agent : { name: 'Sarah', slug: 'sarah', color: '#F59E0B' };
  var engineBadge = task ? task.engine_badge : { name: 'system', color: '#8B97B0' };
  var orb = _cmdcOrbHtml(agent);
  var ageCls = it.age_hours < 1 ? 'aq-age-fresh' : (it.age_hours < 24 ? 'aq-age-warn' : 'aq-age-old');
  var ageTxt = _cmdcEsc(it.time_ago) + (it.is_overdue ? ' · overdue' : '');
  var orphanChip = it.is_orphan ? '<span class="aq-orphan-chip">Stale</span>' : '';
  var engineChip = '<span class="aq-engine-badge" style="background:' + engineBadge.color + '">' + _cmdcEsc(engineBadge.name) + '</span>';
  var agentLine  = '<span class="aq-agent-line"><span style="width:6px;height:6px;border-radius:50%;background:' + agent.color + '"></span>' + _cmdcEsc(agent.name) + '</span>';
  var creditLine = task && task.credit_cost ? '<span style="font-size:11px;color:var(--t3)">· ' + task.credit_cost + ' credits</span>' : '';

  var payloadBits = '';
  if (task && Array.isArray(task.payload_keys) && task.payload_keys.length) {
    payloadBits = '<div class="aq-card-payload">' + task.payload_keys.map(function(k){ return '<code>' + _cmdcEsc(k) + '</code>'; }).join('') + '</div>';
  }

  var actions = '';
  if (isPending) {
    actions = '<div class="aq-card-actions">' +
      '<button class="aq-btn aq-btn-reject" onclick="_aqRejectToggle(' + it.id + ')">Reject</button>' +
      '<button class="aq-btn aq-btn-approve" onclick="_aqApprove(' + it.id + ')"' + (it.is_orphan ? ' disabled title="Orphan approvals cannot be approved — reject or expire"' : '') + '>Approve →</button>' +
      '</div>';
  } else {
    // Read-only footer
    var decidedAgo = it.decided_at ? ' · ' + _cmdcEsc(new Date(it.decided_at).toLocaleString()) : '';
    actions = '<div style="font-size:11.5px;color:var(--t3)">' + _cmdcEsc(it.status) + decidedAgo + '</div>';
  }

  var selCheckbox = isPending && !it.is_orphan ? '<input type="checkbox" class="aq-checkbox" data-id="' + it.id + '" onchange="_aqToggleSelect(' + it.id + ',this.checked)"' + (_aqState.selected.has(it.id) ? ' checked' : '') + '>' : '<span style="width:16px;flex-shrink:0"></span>';

  var noteBlock = it.decision_note ? '<div class="aq-decision-note"><strong>Note:</strong> ' + _cmdcEsc(it.decision_note) + '</div>' : '';

  return '<div class="aq-card" data-id="' + it.id + '">' +
    '<div class="aq-card-head">' +
      selCheckbox +
      orb +
      '<div class="aq-card-meta">' +
        '<div class="aq-card-title">' + label + orphanChip + '</div>' +
        '<div class="aq-card-sub">' + engineChip + agentLine + creditLine + '</div>' +
      '</div>' +
    '</div>' +
    desc +
    payloadBits +
    noteBlock +
    '<div class="aq-card-foot">' +
      '<span class="aq-age ' + ageCls + '">' + ageTxt + '</span>' +
      actions +
    '</div>' +
    '<div class="aq-reject-form" id="aq-reject-' + it.id + '" style="display:none">' +
      '<textarea id="aq-reject-text-' + it.id + '" placeholder="Why are you rejecting this? (required)"></textarea>' +
      '<div class="aq-reject-actions">' +
        '<button class="aq-btn aq-btn-reject" onclick="_aqRejectToggle(' + it.id + ')">Cancel</button>' +
        '<button class="aq-btn aq-btn-approve" style="background:var(--rd);border-color:var(--rd)" onclick="_aqReject(' + it.id + ')">Confirm Reject</button>' +
      '</div>' +
    '</div>' +
  '</div>';
}

// ── Actions ─────────────────────────────────────────────────────────────
function _aqSwitchTab(status) {
  _aqState.status = status;
  _aqState.page = 1;
  _aqState.selected = new Set();
  _aqBulkClear();
  // Visual
  document.querySelectorAll('.aq-tab').forEach(function(el){
    if (el.dataset.status === status) el.classList.add('active'); else el.classList.remove('active');
  });
  _aqFetchList();
}

async function _aqApprove(id) {
  var card = document.querySelector('.aq-card[data-id="' + id + '"]');
  if (card) { card.classList.add('aq-done'); card.style.transition = 'all 260ms'; card.style.borderColor = 'rgba(0,229,168,.3)'; card.style.background = 'rgba(0,229,168,.05)'; }
  try {
    var r = await _luFetch('POST', '/approvals/' + id + '/approve');
    var d = await r.json();
    if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
    _aqRemoveCard(id);
    if (typeof _luToast === 'function') _luToast(d.message || 'Approved.');
    _aqFetchStats();
  } catch(e) {
    if (card) { card.classList.remove('aq-done'); card.style.background = ''; card.style.borderColor = ''; }
    if (typeof _luToast === 'function') _luToast('Approve failed: ' + (e.message || 'unknown'));
  }
}

function _aqRejectToggle(id) {
  var form = document.getElementById('aq-reject-' + id);
  if (!form) return;
  var showing = form.style.display === 'block';
  form.style.display = showing ? 'none' : 'block';
  if (!showing) { var ta = document.getElementById('aq-reject-text-' + id); if (ta) ta.focus(); }
}

async function _aqReject(id) {
  var ta = document.getElementById('aq-reject-text-' + id);
  var reason = ta ? ta.value.trim() : '';
  if (!reason) { if (typeof _luToast === 'function') _luToast('A rejection reason is required.'); ta && ta.focus(); return; }
  var card = document.querySelector('.aq-card[data-id="' + id + '"]');
  if (card) { card.classList.add('aq-done'); card.style.transition = 'all 260ms'; card.style.borderColor = 'rgba(248,113,113,.3)'; card.style.background = 'rgba(248,113,113,.05)'; }
  try {
    var r = await _luFetch('POST', '/approvals/' + id + '/reject', { reason: reason });
    var d = await r.json();
    if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
    _aqRemoveCard(id);
    if (typeof _luToast === 'function') _luToast(d.message || 'Rejected.');
    _aqFetchStats();
  } catch(e) {
    if (card) { card.classList.remove('aq-done'); card.style.background = ''; card.style.borderColor = ''; }
    if (typeof _luToast === 'function') _luToast('Reject failed: ' + (e.message || 'unknown'));
  }
}

function _aqRemoveCard(id) {
  var card = document.querySelector('.aq-card[data-id="' + id + '"]');
  if (!card) return;
  card.style.maxHeight = card.offsetHeight + 'px';
  setTimeout(function(){ card.style.maxHeight = '0'; card.style.padding = '0 16px'; card.style.margin = '0'; card.style.opacity = '0'; }, 20);
  setTimeout(function(){ card.remove(); }, 300);
  _aqState.selected.delete(id);
  _aqRenderBulkBar();
}

// ── Bulk ─────────────────────────────────────────────────────────────────
function _aqToggleSelect(id, on) {
  if (on) _aqState.selected.add(id); else _aqState.selected.delete(id);
  _aqRenderBulkBar();
}
function _aqRenderBulkBar() {
  var bar = document.getElementById('aq-bulk');
  if (!bar) return;
  var n = _aqState.selected.size;
  if (n > 0) {
    bar.style.display = 'flex';
    document.getElementById('aq-bulk-count').textContent = n;
  } else bar.style.display = 'none';
}
function _aqBulkClear() {
  _aqState.selected = new Set();
  document.querySelectorAll('.aq-checkbox').forEach(function(el){ el.checked = false; });
  _aqRenderBulkBar();
}
async function _aqBulkApprove() {
  var ids = Array.from(_aqState.selected);
  if (!ids.length) return;
  if (!confirm('Approve ' + ids.length + ' selected item' + (ids.length === 1 ? '' : 's') + '?')) return;
  try {
    var r = await _luFetch('POST', '/approvals/bulk-approve', { ids: ids });
    var d = await r.json();
    if (typeof _luToast === 'function') _luToast('Approved ' + d.approved + (d.failed ? ' · failed ' + d.failed : '') + (d.skipped ? ' · skipped ' + d.skipped : ''));
    ids.forEach(function(id){ _aqRemoveCard(id); });
    _aqFetchStats();
  } catch(e) {
    if (typeof _luToast === 'function') _luToast('Bulk approve failed: ' + (e.message || 'unknown'));
  }
}
function _aqBulkRejectPrompt() {
  var ids = Array.from(_aqState.selected);
  if (!ids.length) return;
  var reason = prompt('Reason for rejecting ' + ids.length + ' items?');
  if (!reason) return;
  _aqBulkReject(ids, reason);
}
async function _aqBulkReject(ids, reason) {
  try {
    var r = await _luFetch('POST', '/approvals/bulk-reject', { ids: ids, reason: reason });
    var d = await r.json();
    if (typeof _luToast === 'function') _luToast('Rejected ' + d.rejected + (d.failed ? ' · failed ' + d.failed : ''));
    ids.forEach(function(id){ _aqRemoveCard(id); });
    _aqFetchStats();
  } catch(e) {
    if (typeof _luToast === 'function') _luToast('Bulk reject failed: ' + (e.message || 'unknown'));
  }
}

async function _aqExpireStalePrompt() {
  if (!confirm('Expire all pending approvals older than 30 days in this workspace?')) return;
  try {
    var r = await _luFetch('POST', '/approvals/expire-stale', { older_than_days: 30 });
    var d = await r.json();
    if (typeof _luToast === 'function') _luToast('Expired ' + (d.expired || 0) + ' stale approval' + (d.expired === 1 ? '' : 's') + '.');
    _aqReload();
  } catch(e) {
    if (typeof _luToast === 'function') _luToast('Expire failed: ' + (e.message || 'unknown'));
  }
}

function _aqPrev() { if (_aqState.page > 1) { _aqState.page--; _aqFetchList(); } }
function _aqNext() { _aqState.page++; _aqFetchList(); }

// Pause polling on tab hide
document.addEventListener('visibilitychange', function() {
  if (!document.hidden && _aqIsView()) _aqReload();
});

// ── COMMAND CENTER v5.5.0 — REAL DATA ONLY ──────────────────────────────────
// Replaces the placeholder loadCommandCenter. Every number from /api/dashboard/overview.
// Polls every 30s (feed + stats), 60s (approval count). Pauses when tab hidden.

var _cmdcPollTimer      = null;
var _cmdcBadgeTimer     = null;
var _cmdcLastFeedIds    = new Set();  // timestamps we've seen (so we can animate new ones)
var _cmdcLastApprovalN  = -1;

window.loadCommandCenter = async function loadCommandCenter() {
  _cmdcLastFeedIds = new Set(); // reset on full reload so nothing animates on first paint
  await _cmdcFetchAndRender();
  _cmdcStartPolling();
  _notifStartPolling();
};

async function _cmdcFetchAndRender(isPoll) {
  try {
    var r = await _luFetch('GET', '/dashboard/overview');
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var d = await r.json();
    _cmdcRenderAll(d, !!isPoll);
  } catch(e) {
    if (!isPoll) {
      var root = document.getElementById('cmd-root');
      if (root) {
        var sub = document.getElementById('cmd-subhead');
        if (sub) sub.textContent = 'Could not load dashboard. ' + (e.message || '');
      }
    }
    // Silent on poll failures.
  }
}

function _cmdcRenderAll(d, isPoll) {
  // Greeting
  var greet = document.getElementById('cmd-greeting');
  var sub   = document.getElementById('cmd-subhead');
  if (greet) {
    var hour = new Date().getHours();
    var salutation = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');
    var name = d.first_name ? (', ' + d.first_name) : '';
    greet.textContent = salutation + name + '. Sarah is working.';
  }
  if (sub) {
    var when = new Date().toLocaleDateString(undefined, { weekday:'long', month:'long', day:'numeric' });
    var agentsN = (d.stats && d.stats.active_agents) || (d.agents || []).length;
    sub.textContent = when + ' · ' + agentsN + ' agent' + (agentsN === 1 ? '' : 's') + ' on your workspace';
  }

  // KPIs
  _cmdcKpi('tasks',    d.stats.tasks_completed, (d.stats.tasks_today > 0 ? '<strong>+' + d.stats.tasks_today + '</strong> today' : (d.stats.tasks_this_week > 0 ? '+' + d.stats.tasks_this_week + ' this week' : 'No activity yet')));
  _cmdcKpi('content',  d.stats.articles_published, (d.stats.articles_total > d.stats.articles_published ? (d.stats.articles_total - d.stats.articles_published) + ' in draft' : (d.stats.articles_total === 0 ? 'Priya hasn\'t written yet' : 'All published')));
  _cmdcKpi('leads',    d.stats.leads_captured, (d.stats.leads_this_week > 0 ? '<strong>+' + d.stats.leads_this_week + '</strong> this week' : (d.stats.leads_captured === 0 ? 'No leads captured yet' : 'No new this week')));
  _cmdcKpi('keywords', d.stats.keywords_tracked, (d.stats.keywords_tracked === 0 ? 'James hasn\'t tracked any yet' : 'Tracked by James daily'));

  // Feed
  _cmdcRenderFeed(d.activity_feed || [], isPoll);

  // Strategy
  _cmdcRenderStrategy(d.latest_strategy, d.strategy_proposals_total, d.strategy_proposals_global);

  // Approvals
  _cmdcRenderApprovals(d.pending_approvals || [], d.approvals_pending_total || 0);
  _cmdcSetNavBadge(d.approvals_pending_total || 0);

  // Agents
  _cmdcRenderAgents(d.agents || []);

  // Websites + Meetings
  _cmdcRenderWebsites(d.websites || []);
  _cmdcRenderMeetings(d.recent_meetings || []);
}

function _cmdcKpi(key, val, metaHtml) {
  var v = document.getElementById('cmd-kpi-' + key);
  var m = document.getElementById('cmd-kpi-' + key + '-meta');
  if (v) v.textContent = (val === 0 || val == null) ? '0' : String(val);
  if (m) m.innerHTML   = metaHtml || '&nbsp;';
}

function _cmdcRenderFeed(items, isPoll) {
  var el = document.getElementById('cmd-feed');
  var countEl = document.getElementById('cmd-feed-count');
  if (!el) return;
  if (countEl) countEl.textContent = items.length ? (items.length + ' events') : '';
  if (!items.length) {
    el.innerHTML = '<div class="cmd-empty">Your team is warming up.<br>Sarah runs her first analysis within 24 hours of signup.</div>';
    return;
  }
  var prevIds = _cmdcLastFeedIds;
  var newIds  = new Set(items.map(function(i){ return i.timestamp; }));
  var html = items.map(function(it) {
    var orb = _cmdcOrbHtml(it.agent);
    var isNew = isPoll && !prevIds.has(it.timestamp) && prevIds.size > 0;
    var cls = 'cmd-feed-row' + (isNew ? ' cmd-feed-new' : '');
    return '<div class="' + cls + '">' + orb +
      '<div class="cmd-feed-body">' +
        '<div class="cmd-feed-label">' + _cmdcEsc(it.label) + '</div>' +
        '<div class="cmd-feed-time">' + _cmdcEsc(it.time_ago) + '</div>' +
      '</div></div>';
  }).join('');
  el.innerHTML = html;
  _cmdcLastFeedIds = newIds;
}

function _cmdcRenderStrategy(p, totalWs, totalGlobal) {
  var el = document.getElementById('cmd-strategy');
  var footer = document.getElementById('cmd-strategy-footer');
  if (!el) return;
  if (!p) {
    el.innerHTML = '<div class="cmd-empty">Sarah will present her first strategy within 24 hours of onboarding.</div>';
    if (footer) footer.textContent = (totalGlobal || 0) + ' strategies generated platform-wide';
    return;
  }
  var statusClass = p.status === 'approved' ? 'cmd-chip-approved' : (p.status === 'pending' ? 'cmd-chip-pending' : 'cmd-chip-default');
  var desc = p.description ? (p.description.length > 140 ? p.description.substr(0, 137) + '…' : p.description) : '';
  var chips = '<span class="cmd-chip ' + statusClass + '">' + _cmdcEsc(p.status || 'draft') + '</span>' +
              (p.total_credits ? '<span class="cmd-chip cmd-chip-default">' + p.total_credits + ' credits</span>' : '');
  var btn = p.meeting_id
    ? '<button class="cmd-btn cmd-btn-approve" style="width:100%" onclick="nav(\'meeting\');if(typeof _meetingOpen===\'function\')_meetingOpen(' + p.meeting_id + ')">Review strategy →</button>'
    : '<button class="cmd-btn cmd-btn-approve" style="width:100%" onclick="nav(\'meeting\')">Open Strategy Room →</button>';
  el.innerHTML =
    '<div class="cmd-strategy-title">' + _cmdcEsc(p.title || 'Untitled strategy') + '</div>' +
    (desc ? '<div class="cmd-strategy-desc">' + _cmdcEsc(desc) + '</div>' : '') +
    '<div class="cmd-strategy-chips">' + chips + '</div>' +
    btn;
  if (footer) {
    var txt = (totalWs || 0) + ' on this workspace · ' + (totalGlobal || 0) + ' platform-wide · ' + (p.time_ago || '');
    footer.textContent = txt;
  }
}

function _cmdcRenderApprovals(list, total) {
  var el = document.getElementById('cmd-approvals');
  var badge = document.getElementById('cmd-approval-badge');
  if (badge) {
    if (total > 0) { badge.style.display = 'inline-block'; badge.textContent = total; }
    else { badge.style.display = 'none'; }
  }
  if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty" style="color:var(--ac)">✓ All clear — no actions waiting</div>';
    return;
  }
  el.innerHTML = list.map(function(a) {
    var orb = _cmdcOrbHtml(a.agent);
    return '<div class="cmd-approval-row" data-id="' + a.id + '">' +
      '<div class="cmd-approval-head">' + orb + '<div class="cmd-approval-label">' + _cmdcEsc(a.label) + '</div></div>' +
      '<div class="cmd-approval-meta">' + _cmdcEsc(a.time_ago) + (a.credit_cost ? ' · ' + a.credit_cost + ' credits' : '') + '</div>' +
      '<div class="cmd-approval-actions">' +
        (a.task_id
          ? '<button class="cmd-btn cmd-btn-approve" onclick="_cmdcApprovalAction(' + a.id + ',\'approve\')">Approve</button>'
          : '<button class="cmd-btn cmd-btn-approve" disabled title="Task no longer exists" style="opacity:0.4;cursor:not-allowed">Approve</button>'
        ) +
        (a.task_id
          ? '<button class="cmd-btn cmd-btn-reject"  onclick="_cmdcApprovalAction(' + a.id + ',\'reject\')">Reject</button>'
          : '<button class="cmd-btn cmd-btn-reject" disabled title="Task no longer exists" style="opacity:0.4;cursor:not-allowed">Reject</button>'
        ) +
      '</div>' +
    '</div>';
  }).join('');
}

async function _cmdcApprovalAction(id, which) {
  var row = document.querySelector('.cmd-approval-row[data-id="' + id + '"]');
  if (row) { row.style.transition = 'opacity 200ms,max-height 300ms'; row.style.opacity = '.3'; row.style.pointerEvents = 'none'; }
  try {
    var r = await _luFetch('POST', '/approvals/' + id + '/' + which);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    if (row) { row.style.maxHeight = '0'; row.style.padding = '0'; row.style.margin = '0'; setTimeout(function(){ row.remove(); }, 280); }
    var toast = (which === 'approve') ? 'Approved — the task will proceed.' : 'Rejected — the task was cancelled.';
    if (typeof _luToast === 'function') _luToast(toast);
    setTimeout(_cmdcFetchAndRender, 400);
  } catch(e) {
    if (row) { row.style.opacity = '1'; row.style.pointerEvents = ''; }
    if (typeof _luToast === 'function') _luToast('Action failed: ' + (e.message || 'unknown'));
  }
}

function _cmdcRenderAgents(list) {
  var el = document.getElementById('cmd-agents');
  var count = document.getElementById('cmd-agents-count');
  if (!el) return;
  if (count) count.textContent = list.length ? (list.length + ' active') : '';
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty">Your team activates after onboarding. <a href="javascript:void(0)" onclick="nav(\'agents\')" style="color:var(--p)">Complete setup →</a></div>';
    return;
  }
  el.innerHTML = list.map(function(a) {
    var orb = _cmdcOrbHtml({ name: a.name, slug: a.slug, color: a.color }, true);
    var titleLine = a.title || (a.is_dmm ? 'Digital Marketing Manager' : (a.category || 'Specialist'));
    var metaBits = [];
    metaBits.push('<span class="cmd-agent-dot"></span>' + (a.status === 'active' ? 'Active' : (a.status || 'idle')));
    metaBits.push(a.tasks_this_week + ' task' + (a.tasks_this_week === 1 ? '' : 's') + ' this week');
    if (a.last_action_ago) metaBits.push('Last: ' + a.last_action_ago);
    var nav = _cmdcAgentNav(a);
    return '<div class="cmd-agent-card" onclick="' + nav + '">' + orb +
      '<div class="cmd-agent-body">' +
        '<div class="cmd-agent-name">' + _cmdcEsc(a.name) + (a.is_dmm ? ' <span style="color:var(--am);font-size:10px;font-weight:800">DMM</span>' : '') + '</div>' +
        '<div class="cmd-agent-title">' + _cmdcEsc(titleLine) + '</div>' +
        '<div class="cmd-agent-meta">' + metaBits.join(' · ') + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

function _cmdcAgentNav(a) {
  // Map agent category/slug → engine nav target
  if (a.is_dmm || a.slug === 'sarah') return "nav('meeting')";
  var category = (a.category || '').toLowerCase();
  if (category === 'seo')     return "nav('seo')";
  if (category === 'content') return "nav('write')";
  if (category === 'social')  return "nav('social')";
  if (category === 'crm')     return "nav('crm')";
  return "nav('agents')";
}

function _cmdcRenderWebsites(list) {
  var el = document.getElementById('cmd-websites');
  if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty">No websites yet. <a href="javascript:void(0)" onclick="nav(\'websites\')" style="color:var(--p)">Build your first site →</a></div>';
    return;
  }
  el.innerHTML = list.map(function(w) {
    var statusClass = w.status === 'published' ? 'cmd-status-published' : 'cmd-status-draft';
    var hostLine = w.host ? w.host : (w.status || 'draft');
    var viewBtn = w.status === 'published' && w.host
      ? '<button class="cmd-site-btn" onclick="window.open(\'https://' + w.host + '\',\'_blank\')">View live</button>'
      : '';
    return '<div class="cmd-site-row">' +
      '<span class="cmd-status-dot ' + statusClass + '"></span>' +
      '<div class="cmd-site-body">' +
        '<div class="cmd-site-name">' + _cmdcEsc(w.name || 'Untitled site') + '</div>' +
        '<div class="cmd-site-host">' + _cmdcEsc(hostLine) + (w.time_ago ? ' · ' + _cmdcEsc(w.time_ago) : '') + '</div>' +
      '</div>' +
      '<div class="cmd-site-actions">' +
        '<button class="cmd-site-btn" onclick="nav(\'websites\');if(typeof _wsOpen===\'function\')_wsOpen(' + w.id + ')">Edit</button>' +
        viewBtn +
      '</div>' +
    '</div>';
  }).join('');
}

function _cmdcRenderMeetings(list) {
  var el = document.getElementById('cmd-meetings');
  if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty">No strategy meetings yet. <a href="javascript:void(0)" onclick="nav(\'meeting\')" style="color:var(--p)">Open Strategy Room →</a></div>';
    return;
  }
  var sarahOrb = _cmdcOrbHtml({ name: 'Sarah', slug: 'sarah', color: '#F59E0B' });
  el.innerHTML = list.map(function(m) {
    var statusChipCls = m.status === 'completed' ? 'cmd-chip-approved' : (m.status === 'in_progress' ? 'cmd-chip-pending' : 'cmd-chip-default');
    return '<div class="cmd-site-row" style="cursor:pointer" onclick="nav(\'reports\')">' +
      sarahOrb +
      '<div class="cmd-site-body">' +
        '<div class="cmd-site-name">' + _cmdcEsc(m.title || 'Strategy meeting') + '</div>' +
        '<div class="cmd-site-host"><span class="cmd-chip ' + statusChipCls + '" style="margin-right:6px">' + _cmdcEsc(m.status || 'draft') + '</span>' + _cmdcEsc(m.time_ago || '') + (m.credits_used ? ' · ' + m.credits_used + ' credits' : '') + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// ── Polling + visibility ─────────────────────────────────────────────────────
function _cmdcStartPolling() {
  _cmdcStopPolling();
  // Full feed + stats every 30s
  _cmdcPollTimer = setInterval(function(){
    if (document.hidden) return;
    if (!_cmdcIsCommandView()) return;
    _cmdcFetchAndRender(true);
  }, 30000);
  // Lightweight approval count every 60s (for nav badge when viewing other engines)
  _cmdcBadgeTimer = setInterval(function(){
    if (document.hidden) return;
    _cmdcRefreshApprovalCount();
  }, 60000);
  // Kick off badge immediately so nav badge is right even on pages other than command
  _cmdcRefreshApprovalCount();
}
function _cmdcStopPolling() {
  if (_cmdcPollTimer)  { clearInterval(_cmdcPollTimer);  _cmdcPollTimer = null; }
  if (_cmdcBadgeTimer) { clearInterval(_cmdcBadgeTimer); _cmdcBadgeTimer = null; }
}
function _cmdcIsCommandView() {
  var v = document.getElementById('view-command');
  return v && v.classList.contains('active');
}
async function _cmdcRefreshApprovalCount() {
  try {
    var r = await _luFetch('GET', '/approvals/count');
    if (!r.ok) return;
    var d = await r.json();
    _cmdcSetNavBadge(d.pending || 0);
  } catch(_) {}
}
function _cmdcSetNavBadge(n) {
  var b = document.getElementById('cmd-nav-badge-approvals');
  if (!b) return;
  if (n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.classList.add('show'); }
  else       { b.classList.remove('show'); }
  _cmdcLastApprovalN = n;
}

// Pause/resume on tab visibility
document.addEventListener('visibilitychange', function() {
  if (!document.hidden && _cmdcIsCommandView()) {
    _cmdcFetchAndRender(true);
  }
});

// Utility
function _cmdcOrbHtml(agent, big) {
  var a = agent || { name: '?', color: '#6C5CE7' };
  var initials = (a.name || '?').substr(0, 1).toUpperCase();
  var color = a.color || '#6C5CE7';
  var cls = 'cmd-orb' + (big ? ' cmd-orb-lg' : '');
  return '<span class="' + cls + '" style="background:' + color + ';color:#fff" title="' + _cmdcEsc(a.name || '') + '">' + _cmdcEsc(initials) + '</span>';
}
function _cmdcEsc(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
}

// Kick off approval badge on page load (even before Command Center opened)
document.addEventListener('DOMContentLoaded', function() {
  setTimeout(function(){
    if (localStorage.getItem('lu_token')) _cmdcRefreshApprovalCount();
  }, 1200);
});


// ── Inject auth overlay + notification bell into WP admin SPA ────────────────
// Only injects on standalone (non-WP-admin) usage; no-op in WP admin.
document.addEventListener('DOMContentLoaded', function() {
  // Inject auth root overlay if not present (standalone SPA)
  if (!document.getElementById('lu-auth-root')) {
    var overlay = document.createElement('div');
    overlay.id = 'lu-auth-root';
    overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:var(--bg,#0F1117);align-items:center;justify-content:center';
    document.body.appendChild(overlay);
  }

  // Inject insights panel into command center if missing
  var cmdView = document.getElementById('view-command');
  if (cmdView && !document.getElementById('cmd-insights-panel')) {
    var insPanel = document.createElement('div');
    insPanel.id = 'cmd-insights-panel';
    insPanel.style.cssText = 'margin-top:16px;max-width:1400px;width:100%;margin-left:auto;margin-right:auto';
    var header = cmdView.querySelector('.page-header, [style*="margin-bottom:24px"]');
    if (header && header.parentNode) {
      header.parentNode.insertBefore(insPanel, header.nextSibling);
    } else {
      var innerDiv = cmdView.querySelector('div');
      if (innerDiv) innerDiv.appendChild(insPanel);
    }
  }

  // Inject notification bell into top nav if not present
  var topActions = document.querySelector('.top-nav, .page-header-actions, #lu-top-bar');
  if (topActions && !document.getElementById('lu-notif-bell')) {
    var bell = document.createElement('button');
    bell.id = 'lu-notif-bell';
    bell.style.cssText = 'background:none;border:none;font-size:16px;cursor:pointer;color:var(--t2,#aaa);padding:6px 8px;border-radius:6px;position:relative';
    bell.innerHTML = ''+window.icon("info",14)+'';
    bell.onclick = _openNotifications;
    topActions.appendChild(bell);
  }

  // Run bootstrap — will short-circuit if not on standalone SPA
  _appBootstrap();
});

console.log('[LevelUp] v3.3.0 — auth, onboarding, notifications, analytics loaded');

// ── BILLING v5.5.4 ─────────────────────────────────────────────────────────
window.loadBilling = async function loadBilling() {
  _billCheckReturnFlags();
  await _billFetchAndRender();
};

async function _billFetchAndRender() {
  var root = document.getElementById('bill-root');
  if (!root) return;
  try {
    var [statusR, plansR] = await Promise.all([
      _luFetch('GET', '/billing/status').then(r => r.json()),
      _luFetch('GET', '/billing/plans').then(r => r.json()),
    ]);
    _billRender(statusR || {}, (plansR && plansR.plans) || []);
  } catch (e) {
    root.querySelector('#bill-current').innerHTML = '<div class="cmd-empty">Could not load billing status: ' + _cmdcEsc(e.message || '') + '</div>';
  }
}

function _billRender(status, plans) {
  var curEl = document.getElementById('bill-current');
  var plansEl = document.getElementById('bill-plans');
  var subEl = document.getElementById('bill-sub');

  // Subhead
  if (subEl) {
    if (status.stripe_configured === false) {
      subEl.innerHTML = '<span style="color:var(--am)">Stripe is not configured on this workspace yet.</span>';
    } else {
      subEl.textContent = status.has_subscription ? ('Current plan: ' + (status.plan || 'Free')) : 'No active paid plan — you are on the Free tier.';
    }
  }

  // Current plan card
  if (curEl) {
    var statusClass = status.status === 'trialing' ? 'cmd-chip-pending' : (status.status === 'active' ? 'cmd-chip-approved' : 'cmd-chip-default');
    var renewalLine = '';
    if (status.trial_ends_at) {
      renewalLine = '<span style="color:var(--am)">Trial ends ' + new Date(status.trial_ends_at).toLocaleDateString() + '</span>';
    } else if (status.cancel_at_period_end) {
      renewalLine = '<span style="color:var(--rd)">Cancels on ' + (status.current_period_end ? new Date(status.current_period_end).toLocaleDateString() : '—') + '</span>';
    } else if (status.current_period_end) {
      renewalLine = 'Renews ' + new Date(status.current_period_end).toLocaleDateString();
    } else if (status.ends_at) {
      renewalLine = 'Ends ' + new Date(status.ends_at).toLocaleDateString();
    }
    var credits = Math.max(0, (status.credit_available || 0));
    var creditLimit = status.monthly_credit_limit || 0;
    var pct = creditLimit > 0 ? Math.min(100, Math.round(credits / creditLimit * 100)) : 0;

    curEl.innerHTML =
      '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
        '<div style="flex:1;min-width:220px">' +
          '<div style="font-family:var(--fh);font-size:18px;font-weight:700;color:var(--t1);letter-spacing:-0.01em">' + _cmdcEsc(status.plan || 'Free') +
          ' <span class="cmd-chip ' + statusClass + '" style="margin-left:6px">' + _cmdcEsc(status.status || 'active') + '</span></div>' +
          '<div style="font-size:13px;color:var(--t2);margin-top:4px">' +
            (status.plan_price ? '$' + status.plan_price + '/month · ' : '') +
            (creditLimit ? creditLimit + ' credits/month' : 'Free tier') +
          '</div>' +
          (renewalLine ? '<div style="font-size:12px;color:var(--t3);margin-top:6px">' + renewalLine + '</div>' : '') +
        '</div>' +
        '<div>' +
          (status.stripe_customer_id
            ? '<button class="aq-btn aq-btn-approve" onclick="_billOpenPortal()">Manage billing →</button>'
            : '<span style="font-size:11px;color:var(--t3)">No Stripe customer yet</span>') +
        '</div>' +
      '</div>' +
      (creditLimit > 0 ? (
        '<div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--bd)">' +
          '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">' +
            '<span style="font-size:12px;color:var(--t2)">Credits this month</span>' +
            '<span style="font-size:12px;font-weight:700;color:var(--t1)">' + credits + ' / ' + creditLimit + '</span>' +
          '</div>' +
          '<div style="height:6px;background:var(--s2);border-radius:3px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + (pct < 20 ? 'var(--rd)' : pct < 50 ? 'var(--am)' : 'var(--ac)') + '"></div></div>' +
        '</div>'
      ) : '');
  }

  // Upgrade plan cards — show every plan except current
  if (plansEl) {
    var currentSlug = status.plan_slug || 'free';
    var paid = (plans || []).filter(function(p) { return p.slug !== currentSlug; });
    if (!paid.length) {
      plansEl.innerHTML = '<div class="cmd-empty">You are on the highest plan.</div>';
    } else {
      plansEl.innerHTML = paid.map(function(p) {
        var features = _billPlanFeatures(p);
        var isFree = p.slug === 'free';
        var canStripe = !!p.stripe_price_id && !isFree;
        var btn = isFree
          ? '<button class="aq-btn aq-btn-reject" onclick="_billDowngradeToFree()">Downgrade to Free</button>'
          : (canStripe
            ? '<button class="aq-btn aq-btn-approve" onclick="_billCheckout(' + p.id + ',\'' + _cmdcEsc(p.slug) + '\')">Upgrade to ' + _cmdcEsc(p.name) + ' →</button>'
            : '<button class="aq-btn aq-btn-approve" disabled style="opacity:.5;cursor:not-allowed">Not yet configured</button>');
        return '<div class="cmd-panel" style="padding:16px 18px">' +
          '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">' +
            '<div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1)">' + _cmdcEsc(p.name) + '</div>' +
            '<div style="font-size:16px;font-weight:800;color:var(--t1)">$' + p.price + '<span style="font-size:11px;color:var(--t3);font-weight:400">/mo</span></div>' +
          '</div>' +
          '<ul style="list-style:none;padding:0;margin:10px 0;font-size:12.5px;color:var(--t2)">' + features + '</ul>' +
          '<div style="margin-top:10px">' + btn + '</div>' +
        '</div>';
      }).join('');
    }
  }
}

function _billPlanFeatures(p) {
  var items = [];
  if (+p.credit_limit > 0) items.push((+p.credit_limit) + ' AI credits/month');
  if (+p.max_websites)    items.push((+p.max_websites) + ' website' + (+p.max_websites === 1 ? '' : 's'));
  if (+p.agent_count)     items.push((+p.agent_count) + ' specialist agent' + (+p.agent_count === 1 ? '' : 's'));
  if (p.companion_app)    items.push('Mobile companion app');
  if (p.white_label)      items.push('White-label');
  if (p.priority_processing) items.push('Priority processing');
  if (!items.length)      items.push('Free tier');
  return items.map(function(t) { return '<li style="padding:3px 0;position:relative;padding-left:16px"><span style="position:absolute;left:0;color:var(--ac)">✓</span>' + _cmdcEsc(t) + '</li>'; }).join('');
}

async function _billCheckout(planId, planSlug) {
  try {
    showToast('Opening checkout…', 'info');
    var r = await _luFetch('POST', '/billing/checkout', { plan_id: planId });
    var d = await r.json();
    if (d.checkout_url) {
      window.location.href = d.checkout_url;
      return;
    }
    if (d.success && d.dev_mode) {
      showToast('Dev mode — plan activated without Stripe.', 'success');
      _billFetchAndRender();
      return;
    }
    showToast('Checkout failed: ' + (d.error || 'Unknown error'), 'error');
  } catch (e) {
    showToast('Checkout error: ' + (e.message || 'Unknown'), 'error');
  }
}

async function _billOpenPortal() {
  try {
    var r = await _luFetch('GET', '/billing/portal');
    var d = await r.json();
    if (d.portal_url) { window.open(d.portal_url, '_blank'); return; }
    showToast('Portal failed: ' + (d.error || 'Unknown error'), 'error');
  } catch (e) {
    showToast('Portal error: ' + (e.message || 'Unknown'), 'error');
  }
}

async function _billDowngradeToFree() {
  if (!confirm('Cancel your current plan? You will be downgraded to Free at the end of the current period.')) return;
  try {
    var r = await _luFetch('POST', '/billing/cancel');
    var d = await r.json();
    if (d.success) { showToast('Cancellation scheduled.', 'success'); _billFetchAndRender(); }
    else showToast('Cancel failed: ' + (d.error || 'Unknown'), 'error');
  } catch (e) {
    showToast('Cancel error: ' + (e.message || 'Unknown'), 'error');
  }
}

function _billCheckReturnFlags() {
  // Handle return from Stripe Checkout via hash query params.
  // URL shape: /app/#billing?success=1&session=cs_test_abc
  var hash = window.location.hash || '';
  var qIx = hash.indexOf('?');
  if (qIx < 0) return;
  var params = new URLSearchParams(hash.substring(qIx + 1));
  if (params.get('success') === '1') {
    showToast('Payment successful! Your plan has been upgraded.', 'success');
  } else if (params.get('cancelled') === '1') {
    showToast('Checkout cancelled.', 'info');
  }
  // Clean the URL so a refresh doesn't re-toast
  history.replaceState(null, '', '#billing');
}



// ═══════════════════════════════════════════════════════════════════════════
// Settings: API Keys + WordPress Sites renderers (2026-05-11)
// These are designed to be called against any container element (e.g.
// document.getElementById('view-settings-apikeys')). The HTML wiring of
// new tabs in the settings view is a separate UI task — these globals
// stand on their own and can be invoked programmatically.
// ═══════════════════════════════════════════════════════════════════════════
window._renderApiKeys = async function _renderApiKeys(el) {
  if (!el) return;
  el.innerHTML = '<div style="padding:24px"><div id="apk-wrap"><div class="lu-loading">Loading…</div></div></div>';
  var wrap = el.querySelector('#apk-wrap');
  try {
    var r = await _luFetch('GET', '/settings/api-keys');
    var d = await r.json();
    if (!d.success) { wrap.innerHTML = '<div style="color:#EF4444">Failed to load keys.</div>'; return; }
    var keys = d.keys || [];
    var html =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
        '<h3 style="font:700 18px Manrope,sans-serif;color:#fff;margin:0">API Keys</h3>' +
        '<button onclick="_generateApiKey()" class="btn btn-primary" style="font-size:13px;padding:8px 16px">+ Generate Key</button>' +
      '</div>' +
      '<p style="font:400 13px Inter,sans-serif;color:#9CA3AF;margin-bottom:20px">' +
        'Use these keys to connect your WordPress site with the LevelUp Growth SEO Connector plugin.' +
      '</p>';
    if (keys.length === 0) {
      html += '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:32px;text-align:center;color:#6B7280">No API keys yet. Generate one to connect your WordPress site.</div>';
    } else {
      html += '<div style="border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden">';
      keys.forEach(function (k) {
        html +=
          '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.05)">' +
            '<div>' +
              '<div style="font:600 14px Inter,sans-serif;color:#fff">' + (k.name || 'API Key') + '</div>' +
              '<div style="font:400 12px Inter,sans-serif;color:#6B7280;margin-top:2px">' +
                '<code style="background:rgba(255,255,255,0.06);padding:2px 6px;border-radius:4px;font-size:11px">' + k.key_preview + '</code>' +
                ' · Created ' + (k.created_at ? String(k.created_at).split(' ')[0] : '—') +
                (k.last_used_at ? ' · Last used ' + String(k.last_used_at).split(' ')[0] : ' · Never used') +
              '</div>' +
            '</div>' +
            '<button onclick="_revokeApiKey(' + k.id + ')" style="background:rgba(239,68,68,0.1);color:#EF4444;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:6px 12px;font:500 12px Inter,sans-serif;cursor:pointer">Revoke</button>' +
          '</div>';
      });
      html += '</div>';
    }
    wrap.innerHTML = html;
  } catch (e) { wrap.innerHTML = '<div style="color:#EF4444">Error: ' + (e.message || e) + '</div>'; }
};

window._generateApiKey = async function _generateApiKey() {
  var name = prompt('Key name (e.g. "shukranuae.com connector"):', 'WP Connector');
  if (!name) return;
  try {
    var r = await _luFetch('POST', '/settings/api-keys', { name: name, type: 'connector' });
    var d = await r.json();
    if (!d.success || !d.key) {
      if (typeof showToast === 'function') showToast('Failed to generate key', 'error');
      return;
    }
    // Modal — display key ONCE
    var modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px';
    modal.innerHTML =
      '<div style="background:#121826;border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:32px;max-width:520px;width:100%">' +
        '<h3 style="font:700 18px Manrope,sans-serif;color:#fff;margin:0 0 8px">API Key Generated</h3>' +
        '<p style="font:400 13px Inter,sans-serif;color:#F59E0B;margin:0 0 16px">⚠ Copy this key now — it will not be shown again.</p>' +
        '<div id="apk-new-value" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:12px 16px;font:500 13px/1.5 monospace;color:#fff;word-break:break-all;margin-bottom:16px">' + d.key + '</div>' +
        '<div style="display:flex;gap:8px">' +
          '<button id="apk-copy" class="btn btn-primary" style="flex:1">Copy Key</button>' +
          '<button id="apk-done" style="background:rgba(255,255,255,0.06);color:#fff;border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:10px 16px;cursor:pointer;flex:1">Done</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.querySelector('#apk-copy').addEventListener('click', function () {
      navigator.clipboard.writeText(d.key);
      if (typeof showToast === 'function') showToast('Copied', 'info');
    });
    modal.querySelector('#apk-done').addEventListener('click', function () {
      modal.remove();
      // Re-render the current API Keys container if visible
      var wrap = document.querySelector('#apk-wrap');
      if (wrap) window._renderApiKeys(wrap.parentElement);
    });
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};

window._revokeApiKey = async function _revokeApiKey(id) {
  if (!confirm('Revoke this key? Any connected sites using it will stop working.')) return;
  try {
    var r = await _luFetch('DELETE', '/settings/api-keys/' + id);
    var d = await r.json();
    if (d.success) {
      if (typeof showToast === 'function') showToast('Key revoked', 'info');
      var wrap = document.querySelector('#apk-wrap');
      if (wrap) window._renderApiKeys(wrap.parentElement);
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};

window._renderWpSites = async function _renderWpSites(el) {
  if (!el) return;
  el.innerHTML = '<div style="padding:24px"><div id="wps-wrap"><div class="lu-loading">Loading…</div></div></div>';
  var wrap = el.querySelector('#wps-wrap');
  try {
    var r = await _luFetch('GET', '/settings/wp-sites');
    var d = await r.json();
    if (!d.success) { wrap.innerHTML = '<div style="color:#EF4444">Failed to load sites.</div>'; return; }
    var sites = d.sites || [];
    var html =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
        '<h3 style="font:700 18px Manrope,sans-serif;color:#fff;margin:0">WordPress Sites</h3>' +
      '</div>' +
      '<p style="font:400 13px Inter,sans-serif;color:#9CA3AF;margin-bottom:20px">' +
        'Connect your WordPress site by installing the LevelUp SEO Connector plugin and entering your API key + webhook secret.' +
      '</p>';
    if (sites.length === 0) {
      html +=
        '<div style="background:rgba(255,255,255,0.03);border:1px dashed rgba(255,255,255,0.12);border-radius:12px;padding:32px;text-align:center">' +
          '<div style="font-size:32px;margin-bottom:12px">🔗</div>' +
          '<div style="font:600 15px Manrope,sans-serif;color:#fff;margin-bottom:8px">No WordPress site connected</div>' +
          '<div style="font:400 13px Inter,sans-serif;color:#6B7280;max-width:340px;margin:0 auto;line-height:1.6">' +
            '1. Install the LevelUp SEO Connector plugin on your WP site<br>' +
            '2. Generate an API key above<br>' +
            '3. Paste the key + your webhook secret into the plugin settings' +
          '</div>' +
        '</div>';
    } else {
      sites.forEach(function (s) {
        html +=
          '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;margin-bottom:12px">' +
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">' +
              '<div>' +
                '<div style="font:600 15px Manrope,sans-serif;color:#fff">' + (s.name || s.url) + '</div>' +
                '<div style="font:400 12px Inter,sans-serif;color:#6B7280;margin-top:4px">' + s.url + '</div>' +
                '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">' +
                  '<span style="background:rgba(0,229,168,0.1);color:#00E5A8;border:1px solid rgba(0,229,168,0.2);border-radius:6px;padding:3px 8px;font:500 11px Inter,sans-serif">✓ Connected</span>' +
                  '<span style="background:rgba(255,255,255,0.05);color:#9CA3AF;border-radius:6px;padding:3px 8px;font:500 11px Inter,sans-serif">' + s.pages_indexed + ' pages indexed</span>' +
                '</div>' +
              '</div>' +
              '<button onclick="_disconnectWpSite()" style="background:rgba(239,68,68,0.1);color:#EF4444;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:6px 12px;font:500 12px Inter,sans-serif;cursor:pointer;flex-shrink:0">Disconnect</button>' +
            '</div>' +
            '<div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.05)">' +
              '<div style="font:500 12px Inter,sans-serif;color:#6B7280;margin-bottom:6px">Webhook Secret</div>' +
              '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">' +
                '<code style="background:rgba(255,255,255,0.05);padding:6px 10px;border-radius:6px;font-size:11px;color:#9CA3AF;flex:1;min-width:200px;word-break:break-all">' + (s.webhook_secret || '—') + '</code>' +
                '<button onclick="navigator.clipboard.writeText(\'' + (s.webhook_secret || '') + '\');if(typeof showToast===\'function\')showToast(\'Copied\',\'info\')" style="background:rgba(255,255,255,0.06);color:#fff;border:1px solid rgba(255,255,255,0.1);border-radius:6px;padding:6px 10px;font:500 11px Inter,sans-serif;cursor:pointer">Copy</button>' +
                '<button onclick="_rotateWebhookSecret()" style="background:rgba(255,255,255,0.06);color:#9CA3AF;border:1px solid rgba(255,255,255,0.1);border-radius:6px;padding:6px 10px;font:500 11px Inter,sans-serif;cursor:pointer">Rotate</button>' +
              '</div>' +
            '</div>' +
          '</div>';
      });
    }
    wrap.innerHTML = html;
  } catch (e) { wrap.innerHTML = '<div style="color:#EF4444">Error: ' + (e.message || e) + '</div>'; }
};

window._disconnectWpSite = async function _disconnectWpSite() {
  if (!confirm('Disconnect this WordPress site? SEO data will be kept but the site will stop syncing.')) return;
  try {
    var r = await _luFetch('DELETE', '/settings/wp-sites');
    var d = await r.json();
    if (d.success) {
      if (typeof showToast === 'function') showToast('Site disconnected', 'info');
      var wrap = document.querySelector('#wps-wrap');
      if (wrap) window._renderWpSites(wrap.parentElement);
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};

window._rotateWebhookSecret = async function _rotateWebhookSecret() {
  if (!confirm('Rotate webhook secret? You will need to update the secret in your WP plugin settings.')) return;
  try {
    var r = await _luFetch('POST', '/settings/wp-sites/rotate-secret');
    var d = await r.json();
    if (d.success) {
      if (typeof showToast === 'function') showToast('Secret rotated — update your WP plugin settings', 'info');
      var wrap = document.querySelector('#wps-wrap');
      if (wrap) window._renderWpSites(wrap.parentElement);
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};
