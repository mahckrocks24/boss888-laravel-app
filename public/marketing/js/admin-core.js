/* LevelUp Growth — admin console core.
 *
 * Auth guard, the api() helper, table/badge/date formatters, modal and toast
 * helpers, and navigation. Shared by every admin page and served as a static
 * file so it is fetched once and cached across navigations.
 *
 * Extracted from resources/views/admin/app.blade.php on 2026-07-29.
 * Cache-bust with ?v= on deploy.
 */
    // -- Admin Modal Utilities (replaces native alert/confirm/prompt) -----------
    function showAdminToast(msg, type) {
      type = type || 'info';
      var el = document.createElement('div');
      el.className = 'admin-toast ' + type;
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(function(){ el.style.opacity = '0'; setTimeout(function(){ el.remove(); }, 300); }, 3000);
    }
    function adminConfirm(msg, title) {
      return new Promise(function(resolve) {
        var ov = document.createElement('div');
        ov.className = 'admin-modal-overlay';
        ov.innerHTML = '<div class="admin-modal-box"><div class="admin-modal-title">' + (title || 'Confirm') + '</div><div class="admin-modal-msg">' + msg + '</div><div class="admin-modal-actions"><button class="admin-modal-btn admin-modal-cancel" id="_amc">Cancel</button><button class="admin-modal-btn admin-modal-ok" id="_amo">Confirm</button></div></div>';
        document.body.appendChild(ov);
        ov.querySelector('#_amo').onclick = function(){ ov.remove(); resolve(true); };
        ov.querySelector('#_amc').onclick = function(){ ov.remove(); resolve(false); };
      });
    }
    function adminPrompt(msg, title, defaultVal) {
      return new Promise(function(resolve) {
        var ov = document.createElement('div');
        ov.className = 'admin-modal-overlay';
        ov.innerHTML = '<div class="admin-modal-box"><div class="admin-modal-title">' + (title || 'Input') + '</div><div class="admin-modal-msg">' + msg + '</div><input class="admin-modal-input" id="_ami" value="' + (defaultVal || '') + '"><div class="admin-modal-actions"><button class="admin-modal-btn admin-modal-cancel" id="_amc">Cancel</button><button class="admin-modal-btn admin-modal-ok" id="_amo">OK</button></div></div>';
        document.body.appendChild(ov);
        var inp = ov.querySelector('#_ami');
        setTimeout(function(){ inp.focus(); }, 50);
        ov.querySelector('#_amo').onclick = function(){ ov.remove(); resolve(inp.value); };
        ov.querySelector('#_amc').onclick = function(){ ov.remove(); resolve(null); };
        inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ ov.remove(); resolve(inp.value); } });
      });
    }
    // -- Auth guard ----------------------------------------------------------------
    const token = localStorage.getItem('lu_admin_token');
    const user  = JSON.parse(localStorage.getItem('lu_admin_user') || '{}');
    if (!token || !user.is_platform_admin) {
      window.location.href = '/admin/login';
    }
    document.getElementById('admin-name').textContent = user.name || 'Admin';

    // -- API helper ----------------------------------------------------------------
    async function api(path, method = 'GET', body = null) {
      const opts = {
        method,
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
      };
      if (body) opts.body = JSON.stringify(body);
      let res;
      try {
        res = await fetch('/api/admin' + path, opts);
      } catch (netErr) {
        console.error('api() network error', path, netErr);
        return null;
      }
      if (res.status === 401) { logout(); return null; }
      if (!res.ok) {
        console.error('api() HTTP ' + res.status, path);
        try { console.error(await res.text()); } catch (_) {}
        return null;
      }
      try {
        return await res.json();
      } catch (parseErr) {
        console.error('api() JSON parse error', path, parseErr);
        return null;
      }
    }

    // Friendly error helper used in place of silent `if (!data) return;` bails.
    // Renders a generic failure card with a Try Again link that re-runs the current page.
    // Pass an optional label; if omitted, derives one from the current page id.
    // Friendly error card in place of a silent `if (!data) return;` bail.
    //
    // The old version's "Try again" link called window[currentPage]() — but page
    // functions never lived on window, so the link did nothing. In the
    // multi-page model the page IS the URL, so a reload is both correct and
    // honest about what it does.
    function renderApiError(label) {
      label = label || (currentPage || 'data');
      setContent(
        '<div class="card" style="text-align:center;padding:40px;color:var(--muted)">' +
          '<div style="font-size:14px;margin-bottom:8px">Failed to load ' + label + '.</div>' +
          '<div style="font-size:12px;margin-bottom:16px">Check your connection or refresh the page. ' +
            'If this keeps happening, the API may be temporarily unavailable — see the browser console for details.</div>' +
          '<a href="#" onclick="window.location.reload();return false" style="color:var(--p);text-decoration:none;font-weight:500">Try again</a>' +
        '</div>'
      );
    }

    // Sign out.
    //
    // Removing the two localStorage keys ends nothing. Page access is granted by
    // the HttpOnly lu_admin_at cookie, which JavaScript cannot touch: until
    // 2026-08-04 a "signed out" browser kept receiving the full admin shell for
    // the remaining 12 hours of the token's life.
    //
    // The server must be asked to end the session, and the local keys are only
    // cleared once it has answered. Clearing them first would make every failure
    // look like a successful sign-out.
    async function logout() {
      var ended = false;

      // A) The API contract, when this client can satisfy it. It revokes the
      //    refresh-token session as well as clearing the cookie. The admin
      //    console does not currently store a refresh token — it keeps only the
      //    access token — so this is attempted, not assumed.
      var bearer = localStorage.getItem('lu_admin_token');
      var refresh = localStorage.getItem('lu_admin_refresh');
      if (bearer && refresh) {
        try {
          var r = await fetch('/api/auth/logout', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + bearer, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ refresh_token: refresh }),
            credentials: 'same-origin'
          });
          ended = r.ok;
        } catch (e) { ended = false; }
      }

      // B) The server-side fallback, which needs nothing but the cookie the
      //    browser already holds. This is what actually ends the session today.
      if (!ended) {
        try {
          var xsrf = decodeURIComponent((document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '');
          var f = await fetch('/admin/logout', {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
            redirect: 'manual'
          });
          // A redirect is the success case here; fetch reports it as opaque.
          ended = f.ok || f.type === 'opaqueredirect' || f.status === 302 || f.status === 0;
        } catch (e) { ended = false; }
      }

      // Only now, and regardless of the outcome — a browser that cannot reach
      // the server must not be left displaying a signed-in console. If the
      // server was not reached the cookie survives, and the next page request
      // is refused by AdminSessionIdentity anyway.
      localStorage.removeItem('lu_admin_token');
      localStorage.removeItem('lu_admin_user');

      if (!ended) {
        console.warn('[admin] sign-out could not be confirmed by the server; local state cleared');
      }

      window.location.href = '/admin/login';
    }

    // -- Navigation ----------------------------------------------------------------
    //
    // Each admin page is now its own URL, served by Laravel from the registry in
    // config/admin_pages.php. The server injects that registry as ADMIN_PAGES
    // (key -> slug) and the current page as ADMIN_PAGE_KEY.
    //
    // nav() is kept as the entry point so all 54 existing onclick="nav('x')"
    // call sites keep working unchanged — it navigates instead of swapping
    // innerHTML.
    const currentPage = window.ADMIN_PAGE_KEY || '';

    /** Page key -> absolute URL. Returns null for an unknown key. */
    function adminUrl(key) {
      const slug = (window.ADMIN_PAGES || {})[key];
      return slug ? '/admin/' + slug : null;
    }

    function nav(key) {
      const url = adminUrl(key);
      if (!url) {
        console.warn('nav(): unknown page key "' + key + '" — not in config/admin_pages.php');
        return;
      }
      if (key === currentPage) { window.location.reload(); return; }
      window.location.href = url;
    }

    // Compatibility shim.
    //
    // ~32 call sites do `pages.someOtherPage()` to jump between pages. Once the
    // pages object is split into per-page files (Stage B), most of those keys
    // will not exist in the current document. Rather than let them fail the way
    // `pages[page]?.()` used to — silently, which is exactly how the dead
    // houseAccount menu item survived in production — an unknown key becomes a
    // navigation and says so in the console.
    //
    // `page()` is the current page's own render function; a same-page call
    // re-renders in place with no round trip.
    window.pages = new Proxy({}, {
      get(target, key) {
        if (typeof key !== 'string') { return undefined; }
        if (key in target) { return target[key]; }
        if (key === currentPage && typeof window.page === 'function') { return window.page; }
        if (adminUrl(key)) {
          return function () {
            console.warn('pages.' + key + '() called from ' + currentPage + ' — navigating instead');
            nav(key);
          };
        }
        return undefined;
      },
      set(target, key, value) { target[key] = value; return true; },
      has(target, key) { return key in target || !!adminUrl(key); },
    });

    // -- Content helpers -----------------------------------------------------------
    const el = id => document.getElementById(id);
    const content = () => el('content');
    function setContent(html) { content().innerHTML = html; }
    function badge(status) {
      const map = { active:'green', suspended:'red', trialing:'blue', free:'amber', growth:'purple', pro:'blue', agency:'green', completed:'green', failed:'red', pending:'amber', running:'blue', expired:'red', revoked:'amber' };
      return '<span class="badge badge-' + (map[status]||'blue') + '">' + status + '</span>';
    }
    function ts(str) { return str ? new Date(str).toLocaleDateString() : '\u2014'; }
    function tsTime(str) { return str ? new Date(str).toLocaleString() : '\u2014'; }
    function truncate(str, len) { if (!str) return '\u2014'; return str.length > len ? str.substring(0, len) + '...' : str; }

    // 2026-05-25 \u2014 Agent Web Activity: detail-view modal opener.
    function _waShowDetail(id) {
      const r = (window._waRows || {})[id];
      const m = document.getElementById('wa-detail-modal');
      if (!r || !m) return;
      let body = '<div style="background:var(--s1);border:1px solid var(--border);border-radius:14px;max-width:780px;width:90%;max-height:80vh;overflow-y:auto;padding:24px">';
      body += '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:16px"><h3 style="font-size:18px;font-weight:700">Activity #' + r.id + '</h3><button onclick="document.getElementById(\'wa-detail-modal\').style.display=\'none\'" style="background:transparent;border:none;color:var(--muted);font-size:24px;cursor:pointer">&times;</button></div>';
      body += '<div style="display:grid;grid-template-columns:140px 1fr;gap:8px 16px;font-size:13px">';
      const f = (k, v) => '<div style="color:var(--muted)">' + k + '</div><div>' + (v == null ? '\u2014' : v) + '</div>';
      body += f('Time', tsTime(r.created_at));
      body += f('Workspace', (r.workspace_name || '\u2014') + ' (id=' + r.workspace_id + ')');
      body += f('Agent', r.agent_slug);
      body += f('User ID', r.user_id || '(autonomous)');
      body += f('Action', r.action);
      body += f('Status', r.status);
      body += f('Cost (credits)', r.cost_credits);
      body += f('Duration (ms)', r.duration_ms);
      body += f('Content length', r.content_length);
      body += '</div>';
      body += '<div style="margin-top:16px"><div style="color:var(--muted);font-size:12px;margin-bottom:6px">Target (URL or query)</div><div style="background:var(--s2);padding:10px;border-radius:6px;font-family:monospace;font-size:12px;word-break:break-all">' + (r.url_or_query || '\u2014') + '</div></div>';
      if (r.title) body += '<div style="margin-top:12px"><div style="color:var(--muted);font-size:12px;margin-bottom:6px">Title</div><div style="background:var(--s2);padding:10px;border-radius:6px;font-size:13px">' + r.title + '</div></div>';
      if (r.response_preview) body += '<div style="margin-top:12px"><div style="color:var(--muted);font-size:12px;margin-bottom:6px">Response preview (first 500 chars)</div><pre style="background:var(--s2);padding:10px;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-word;max-height:240px;overflow-y:auto;margin:0">' + (r.response_preview || '').replace(/</g, '&lt;') + '</pre></div>';
      if (r.error) body += '<div style="margin-top:12px"><div style="color:var(--rd);font-size:12px;margin-bottom:6px">Error</div><pre style="background:rgba(248,113,113,.1);border:1px solid var(--rd);padding:10px;border-radius:6px;font-size:12px;white-space:pre-wrap;margin:0;color:var(--rd)">' + r.error + '</pre></div>';
      body += '</div>';
      m.innerHTML = body;
      m.style.display = 'flex';
    }

    // ── Admin Table Utilities: Search, Sort, Filter ─────────────────
    window._adminTables = {};
    window._adminTableTimers = {};

    function adminTable(config) {
      window._adminTables[config.id] = {
        ...config,
        _search: '',
        _sort: config.defaultSort || null,
        _filters: {},
        _originalData: config.data
      };
      return _adminTableBuild(config.id);
    }

    function adminTableSearch(tableId, query) {
      clearTimeout(window._adminTableTimers[tableId]);
      window._adminTableTimers[tableId] = setTimeout(function() {
        var t = window._adminTables[tableId];
        if (!t) return;
        t._search = query.toLowerCase();
        _adminTableRerender(tableId);
      }, 300);
    }

    function adminTableSort(tableId, column) {
      var t = window._adminTables[tableId];
      if (!t) return;
      if (t._sort && t._sort.key === column) {
        t._sort.dir = t._sort.dir === 'asc' ? 'desc' : 'asc';
      } else {
        t._sort = { key: column, dir: 'asc' };
      }
      _adminTableRerender(tableId);
    }

    function adminTableFilter(tableId, key, value) {
      var t = window._adminTables[tableId];
      if (!t) return;
      if (value === '' || value === undefined) {
        delete t._filters[key];
      } else {
        t._filters[key] = value;
      }
      _adminTableRerender(tableId);
    }

    function _adminTableGetVal(row, key) {
      if (key.indexOf('.') !== -1) {
        var parts = key.split('.');
        var v = row;
        for (var i = 0; i < parts.length; i++) {
          v = v ? v[parts[i]] : undefined;
        }
        return v;
      }
      return row[key];
    }

    function _adminTableGetFiltered(tableId) {
      var t = window._adminTables[tableId];
      if (!t) return [];
      var data = t._originalData.slice();

      // Apply search
      if (t._search && t.searchFields && t.searchFields.length > 0) {
        var q = t._search;
        data = data.filter(function(row) {
          return t.searchFields.some(function(f) {
            var val = _adminTableGetVal(row, f);
            return val && String(val).toLowerCase().indexOf(q) !== -1;
          });
        });
      }

      // Apply filters
      var filterKeys = Object.keys(t._filters);
      filterKeys.forEach(function(key) {
        var filterVal = t._filters[key];
        if (filterVal === '' || filterVal === undefined) return;
        data = data.filter(function(row) {
          if (key.indexOf('_text_') === 0) {
            var colKey = key.substring(6);
            var cellVal = String(_adminTableGetVal(row, colKey) || '').toLowerCase();
            return cellVal.indexOf(String(filterVal).toLowerCase()) !== -1;
          }
          var rowVal = _adminTableGetVal(row, key);
          return String(rowVal || '').toLowerCase() === String(filterVal).toLowerCase();
        });
      });

      // Apply sort
      if (t._sort) {
        var sortKey = t._sort.key;
        var dir = t._sort.dir === 'desc' ? -1 : 1;
        data.sort(function(a, b) {
          var va = _adminTableGetVal(a, sortKey);
          var vb = _adminTableGetVal(b, sortKey);
          if (sortKey.indexOf('_at') !== -1 || sortKey === 'date') {
            va = va ? new Date(va).getTime() : 0;
            vb = vb ? new Date(vb).getTime() : 0;
            return (va - vb) * dir;
          }
          if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
          }
          var na = parseFloat(va), nb = parseFloat(vb);
          if (!isNaN(na) && !isNaN(nb)) {
            return (na - nb) * dir;
          }
          va = String(va || '').toLowerCase();
          vb = String(vb || '').toLowerCase();
          return va < vb ? -dir : va > vb ? dir : 0;
        });
      }

      return data;
    }

    function _adminTableBuild(tableId) {
      var t = window._adminTables[tableId];
      if (!t) return '';
      var filtered = _adminTableGetFiltered(tableId);
      var total = t._originalData.length;
      var shown = filtered.length;

      // Build filter options map from data
      var filterOptions = {};
      if (t.filters) {
        t.filters.forEach(function(f) { filterOptions[f.key] = f.options; });
      }

      // Top toolbar: just search + result count
      var toolbar = '<div style="display:flex;gap:10px;margin-bottom:12px;align-items:center">';
      if (t.searchFields && t.searchFields.length > 0) {
        toolbar += '<input type="text" class="search-bar" style="flex:1;max-width:300px" placeholder="Search..." value="' + (t._search || '').replace(/"/g, '&quot;') + '" oninput="adminTableSearch(\'' + tableId + '\', this.value)">';
      }
      toolbar += '<span style="font-size:12px;color:var(--muted);margin-left:auto">Showing ' + shown + ' of ' + total + '</span>';
      toolbar += '</div>';

      // Table header row 1: sortable labels
      var thead = '<thead>';
      thead += '<tr>';
      t.columns.forEach(function(col) {
        var arrow = '';
        if (col.sortable && t._sort && t._sort.key === col.key) {
          arrow = t._sort.dir === 'asc' ? ' <span style="color:var(--ac)">&#9650;</span>' : ' <span style="color:var(--ac)">&#9660;</span>';
        }
        var clickAttr = col.sortable ? ' onclick="adminTableSort(\'' + tableId + '\',\'' + col.key + '\')" style="cursor:pointer;user-select:none;white-space:nowrap' + (col.width ? ';width:' + col.width : '') + '"' : (col.width ? ' style="width:' + col.width + '"' : '');
        thead += '<th' + clickAttr + '>' + col.label + arrow + '</th>';
      });
      thead += '</tr>';

      // Table header row 2: per-column filter inputs
      thead += '<tr style="background:var(--s2)">';
      t.columns.forEach(function(col) {
        thead += '<td style="padding:4px 6px">';
        if (filterOptions[col.key]) {
          // Dropdown filter
          var currentVal = t._filters[col.key] || '';
          thead += '<select style="width:100%;padding:4px 6px;background:var(--s3,#252A3A);border:1px solid var(--bd);border-radius:4px;color:var(--t1);font-size:11px" onchange="adminTableFilter(\'' + tableId + '\',\'' + col.key + '\',this.value)">';
          filterOptions[col.key].forEach(function(opt) {
            thead += '<option value="' + opt.value + '"' + (currentVal === String(opt.value) ? ' selected' : '') + '>' + opt.label + '</option>';
          });
          thead += '</select>';
        } else if (col.sortable && col.key !== '_actions') {
          // Text filter for sortable columns
          var currentTxt = t._filters['_text_' + col.key] || '';
          thead += '<input type="text" placeholder="Filter..." value="' + currentTxt.replace(/"/g, '&quot;') + '" style="width:100%;padding:4px 6px;background:var(--s3,#252A3A);border:1px solid var(--bd);border-radius:4px;color:var(--t1);font-size:11px;box-sizing:border-box" oninput="adminTableFilter(\'' + tableId + '\',\'_text_' + col.key + '\',this.value)">';
        } else {
          thead += '&nbsp;';
        }
        thead += '</td>';
      });
      thead += '</tr>';
      thead += '</thead>';

      // Table body
      var tbody = '<tbody id="at-body-' + tableId + '">';
      if (filtered.length === 0) {
        tbody += '<tr><td colspan="' + t.columns.length + '" style="text-align:center;color:var(--muted);padding:24px">No matching records</td></tr>';
      } else {
        filtered.forEach(function(row) {
          tbody += '<tr>';
          t.columns.forEach(function(col) {
            var raw = _adminTableGetVal(row, col.key);
            var display = col.render ? col.render(raw, row) : (raw !== null && raw !== undefined ? String(raw) : '\u2014');
            tbody += '<td>' + display + '</td>';
          });
          tbody += '</tr>';
        });
      }
      tbody += '</tbody>';

      var extra = t.extraHtml || '';
      return extra + '<div class="admin-table-wrap card" id="at-wrap-' + tableId + '">' + toolbar + '<table>' + thead + tbody + '</table></div>';
    }

    function _adminTableRerender(tableId) {
      var wrap = document.getElementById('at-wrap-' + tableId);
      if (!wrap) return;
      var t = window._adminTables[tableId];
      if (!t) return;
      var filtered = _adminTableGetFiltered(tableId);
      var total = t._originalData.length;
      var shown = filtered.length;

      var spans = wrap.querySelectorAll('span');
      for (var i = 0; i < spans.length; i++) {
        if (spans[i].textContent.indexOf('Showing') === 0) {
          spans[i].textContent = 'Showing ' + shown + ' of ' + total;
          break;
        }
      }

      var tbodyHtml = '';
      if (filtered.length === 0) {
        tbodyHtml = '<tr><td colspan="' + t.columns.length + '" style="text-align:center;color:var(--muted);padding:24px">No matching records</td></tr>';
      } else {
        filtered.forEach(function(row) {
          tbodyHtml += '<tr>';
          t.columns.forEach(function(col) {
            var raw = _adminTableGetVal(row, col.key);
            var display;
            if (col.render) {
              display = col.render(raw, row);
            } else {
              display = raw !== null && raw !== undefined ? String(raw) : '\u2014';
            }
            tbodyHtml += '<td>' + display + '</td>';
          });
          tbodyHtml += '</tr>';
        });
      }

      var tbodyEl = wrap.querySelector('tbody');
      if (tbodyEl) tbodyEl.innerHTML = tbodyHtml;

      var ths = wrap.querySelectorAll('thead th');
      t.columns.forEach(function(col, idx) {
        if (col.sortable && ths[idx]) {
          var arrow = '';
          if (t._sort && t._sort.key === col.key) {
            arrow = t._sort.dir === 'asc' ? ' \u2191' : ' \u2193';
          }
          ths[idx].textContent = col.label + arrow;
          ths[idx].onclick = function() { adminTableSort(tableId, col.key); };
        }
      });
    }



    // -- Pages ---------------------------------------------------------------------
