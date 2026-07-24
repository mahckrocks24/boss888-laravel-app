/**
 * Content & SEO Pipeline + Calendar engine (v1.0.0)
 *
 * Visible in:
 *   - Embed mode (WP plugin iframe with ?lgsc_key=...&embed=1)
 *   - Direct Laravel SPA (growth+ plan)
 *
 * Endpoints (both routed through window._luFetch so X-API-KEY works in embed):
 *   GET  /connector/content/pipeline           — buckets by status
 *   GET  /connector/content/calendar?month=…   — month-grouped items
 *
 * Tabs: Pipeline | Calendar (split inside the same engine).
 */
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['pipeline'] = true;
console.log('[Pipeline] engine slot claimed');

(function () {
    'use strict';

    var _state = {
        tab:       'pipeline',     // 'pipeline' | 'calendar'
        statusTab: 'all',          // 'all' | 'queued' | 'running' | 'completed' | 'failed'
        pollTimer: null,
        rootEl:    null,
        pipeline:  null,
        calendar:  null,
        month:     null,           // 'YYYY-MM'
    };

    function _esc(s) {
        return (s == null ? '' : String(s))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function _badgeColor(action) {
        var map = {
            generate_article:   '#7C3AED',
            write_article:      '#7C3AED',
            optimize_article:   '#8B5CF6',
            improve_draft:      '#8B5CF6',
            deep_audit:         '#3B82F6',
            serp_analysis:      '#06B6D4',
            keyword_research:   '#06B6D4',
            bulk_generate_meta: '#F59E0B',
            generate_meta:      '#F59E0B',
            generate_image:     '#EC4899',
            autonomous_goal:    '#00E5A8',
            agent_goal:         '#F59E0B',
            link_suggestions:   '#10B981',
        };
        return map[action] || '#6B7280';
    }

    function _statusColor(status) {
        if (status === 'completed') return '#10B981';
        if (status === 'running' || status === 'verifying') return '#3B82F6';
        if (status === 'failed' || status === 'degraded' || status === 'blocked') return '#EF4444';
        if (status === 'cancelled') return '#6B7280';
        return '#F59E0B'; // queued/pending/awaiting_approval
    }

    function _fmtAction(action) {
        return String(action || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function _fmtDate(iso) {
        if (!iso) return '';
        try {
            var d = new Date(iso.replace(' ', 'T'));
            return d.toLocaleString();
        } catch (_) { return String(iso); }
    }

    // ── Fetch helpers ────────────────────────────────────────────────────
    async function _fetchPipeline() {
        try {
            if (typeof window._luFetch === 'function') {
                var r = await window._luFetch('GET', '/connector/content/pipeline', null);
                return await r.json();
            }
            var r2 = await fetch('/api/connector/content/pipeline', {
                headers: { 'Accept': 'application/json',
                           'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
            });
            return await r2.json();
        } catch (e) { return { success: false, error: 'fetch_failed' }; }
    }

    async function _fetchCalendar(month) {
        try {
            if (typeof window._luFetch === 'function') {
                var r = await window._luFetch('GET', '/connector/content/calendar?month=' + encodeURIComponent(month), null);
                return await r.json();
            }
            var r2 = await fetch('/api/connector/content/calendar?month=' + encodeURIComponent(month), {
                headers: { 'Accept': 'application/json',
                           'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
            });
            return await r2.json();
        } catch (e) { return { success: false, error: 'fetch_failed' }; }
    }

    // ── Pipeline tab render ──────────────────────────────────────────────
    function _renderPipeline() {
        var p = _state.pipeline;
        if (!p) {
            return '<div style="padding:32px;text-align:center;color:var(--t3)">Loading pipeline…</div>';
        }
        if (!p.success) {
            return '<div style="padding:32px;text-align:center;color:#EF4444">Failed to load pipeline.</div>';
        }
        var counts = p.counts || {};
        var html = '';

        // Stat cards
        html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">';
        var cards = [
            { label: 'Queued',    val: counts.queued || 0,    color: '#F59E0B' },
            { label: 'Running',   val: counts.running || 0,   color: '#3B82F6' },
            { label: 'Completed', val: counts.completed || 0, color: '#10B981' },
            { label: 'Failed',    val: counts.failed || 0,    color: '#EF4444' },
        ];
        cards.forEach(function (c) {
            html += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px 16px">'
                  +   '<div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">' + c.label + '</div>'
                  +   '<div style="font-size:24px;font-weight:700;color:' + c.color + ';margin-top:4px">' + c.val + '</div>'
                  + '</div>';
        });
        html += '</div>';

        // Status tabs — 2026-05-25 'blocked' is its own tab now (was folded into failed).
        var tabs = ['all', 'queued', 'running', 'blocked', 'completed', 'failed'];
        html += '<div style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--bd);padding-bottom:0">';
        tabs.forEach(function (t) {
            var ac = t === _state.statusTab;
            html += '<button onclick="window._pipeSetStatus(\'' + t + '\')" '
                  + 'style="padding:8px 14px;background:' + (ac ? 'var(--p)' : 'transparent') + ';'
                  + 'color:' + (ac ? '#fff' : 'var(--t2)') + ';border:none;border-radius:6px 6px 0 0;'
                  + 'cursor:pointer;font-size:13px;font-weight:600">'
                  + t.charAt(0).toUpperCase() + t.slice(1)
                  + '</button>';
        });
        html += '</div>';

        // 2026-05-27 — Phase 2: category filter pills (client-side filter)
        var catPills = [
            { slug: 'all',        label: 'All',        color: 'var(--p)' },
            { slug: 'research',   label: 'Research',   color: '#3B82F6' },
            { slug: 'create',     label: 'Create',     color: '#7C3AED' },
            { slug: 'optimize',   label: 'Optimize',   color: '#00E5A8' },
            { slug: 'publish',    label: 'Publish',    color: '#F59E0B' },
            { slug: 'crm',        label: 'CRM',        color: '#EC4899' },
            { slug: 'campaign',   label: 'Campaign',   color: '#F97316' },
            { slug: 'operations', label: 'Operations', color: '#6B7280' },
        ];
        _state.categoryTab = _state.categoryTab || 'all';
        html += '<div style="display:flex;gap:5px;margin-bottom:14px;flex-wrap:wrap">';
        catPills.forEach(function (c) {
            var ac = c.slug === _state.categoryTab;
            var bg = ac ? c.color : 'var(--s2)';
            var fg = ac ? '#fff' : 'var(--t2)';
            var border = ac ? c.color : 'var(--bd)';
            var dotMarkup = ac ? '' : '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' + c.color + ';margin-right:5px;vertical-align:middle"></span>';
            html += '<button onclick="window._pipeSetCategory(\'' + c.slug + '\')" '
                  + 'style="padding:5px 11px;background:' + bg + ';color:' + fg + ';'
                  + 'border:1px solid ' + border + ';border-radius:99px;cursor:pointer;'
                  + 'font-size:11px;font-weight:600">' + dotMarkup + c.label + '</button>';
        });
        html += '</div>';

        // Task table
        var rows = [];
        if (_state.statusTab === 'all') {
            ['running', 'queued', 'blocked', 'completed', 'failed', 'cancelled'].forEach(function (b) {
                rows = rows.concat(p.pipeline[b] || []);
            });
        } else {
            rows = p.pipeline[_state.statusTab] || [];
        }

        // Apply category filter client-side
        if (_state.categoryTab && _state.categoryTab !== 'all') {
            rows = rows.filter(function (t) { return (t.category || 'operations') === _state.categoryTab; });
        }

        // 2026-05-25 — when viewing blocked tab, show a batch-retry button.
        if (_state.statusTab === 'blocked' && rows.length > 0) {
            html += '<div style="margin-bottom:12px;display:flex;gap:8px;align-items:center">'
                  +   '<button onclick="window._pipeRetryAllBlocked()" style="padding:8px 14px;background:var(--p);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">'
                  +     '↻ Retry all blocked'
                  +   '</button>'
                  +   '<span style="font-size:11px;color:var(--t3)">Duplicates are auto-skipped. Only legitimately-stuck work is requeued.</span>'
                  + '</div>';
        }

        if (rows.length === 0) {
            html += '<div style="padding:48px;text-align:center;color:var(--t3);background:var(--s1);border:1px dashed var(--bd);border-radius:10px">'
                  + 'No tasks in this view yet.<br>'
                  + '<span style="font-size:12px">When you trigger article generation or audits, they show up here.</span>'
                  + '</div>';
            return html;
        }

        html += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        html += '<thead style="background:var(--s2)"><tr>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Category</th>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Type</th>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Task</th>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Status</th>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Progress</th>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Created</th>'
              +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Action</th>'
              + '</tr></thead><tbody>';
        rows.forEach(function (t) {
            var badgeC = _badgeColor(t.task_type);
            var statC  = _statusColor(t.status);
            var prog   = (t.progress >= 0 ? t.progress : 0);
            // 2026-05-25 — per-row retry button for blocked tasks.
            var actionCell = '';
            if (t.status === 'blocked') {
                actionCell = '<button onclick="window._pipeRetryOne(' + t.id + ')" style="padding:4px 10px;background:var(--p);color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600">↻ Retry</button>';
            }
            // 2026-05-27 — Phase 2 category cell
            var catColor = t.category_color || '#6B7280';
            var catLabel = t.category_label || (t.category || 'Ops');
            var catCell = '<span style="background:' + catColor + '15;color:' + catColor + ';padding:3px 8px;border-radius:99px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:4px">'
                        + '<span style="width:6px;height:6px;border-radius:50%;background:' + catColor + '"></span>'
                        + _esc(catLabel)
                        + '</span>';
            html += '<tr style="border-top:1px solid var(--bd)">'
                  +   '<td style="padding:10px 14px">' + catCell + '</td>'
                  +   '<td style="padding:10px 14px"><span style="background:' + badgeC + '15;color:' + badgeC + ';padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600">' + _fmtAction(t.task_type) + '</span></td>'
                  +   '<td style="padding:10px 14px;color:var(--t1)">' + _esc(t.result_summary || _fmtAction(t.task_type)) + '</td>'
                  +   '<td style="padding:10px 14px"><span style="color:' + statC + ';font-weight:600">' + _esc(t.status) + '</span></td>'
                  +   '<td style="padding:10px 14px"><div style="background:var(--s2);border-radius:3px;height:6px;width:100px;overflow:hidden"><div style="background:' + statC + ';height:100%;width:' + prog + '%"></div></div></td>'
                  +   '<td style="padding:10px 14px;color:var(--t3);font-size:11px">' + _esc(_fmtDate(t.created_at)) + '</td>'
                  +   '<td style="padding:10px 14px">' + actionCell + '</td>'
                  + '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    // ── Calendar tab render ──────────────────────────────────────────────
    function _renderCalendar() {
        var c = _state.calendar;
        if (!c) {
            return '<div style="padding:32px;text-align:center;color:var(--t3)">Loading calendar…</div>';
        }
        if (!c.success) {
            return '<div style="padding:32px;text-align:center;color:#EF4444">Failed to load calendar.</div>';
        }
        var month = c.month || _state.month;
        var parts = month.split('-');
        var year = parseInt(parts[0], 10);
        var mo   = parseInt(parts[1], 10);
        var label = new Date(year, mo - 1, 1).toLocaleString('default', { month: 'long', year: 'numeric' });
        var firstDay = new Date(year, mo - 1, 1);
        var lastDay  = new Date(year, mo, 0).getDate();
        var startDow = firstDay.getDay(); // 0=Sun

        var prevM = mo - 1, prevY = year; if (prevM < 1) { prevM = 12; prevY--; }
        var nextM = mo + 1, nextY = year; if (nextM > 12) { nextM = 1; nextY++; }
        var prevMonth = prevY + '-' + String(prevM).padStart(2, '0');
        var nextMonth = nextY + '-' + String(nextM).padStart(2, '0');

        var html = '';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">';
        html += '<button onclick="window._pipeSetMonth(\'' + prevMonth + '\')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px">← Prev</button>';
        html += '<h2 style="margin:0;font-size:18px;font-weight:700">' + _esc(label) + '</h2>';
        html += '<button onclick="window._pipeSetMonth(\'' + nextMonth + '\')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px">Next →</button>';
        html += '</div>';

        // Legend
        html += '<div style="display:flex;gap:14px;margin-bottom:14px;font-size:11px;color:var(--t3)">'
              + '<span><span style="background:#10B981;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Completed</span>'
              + '<span><span style="background:#3B82F6;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Running</span>'
              + '<span><span style="background:#F59E0B;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Queued</span>'
              + '<span><span style="background:#7C3AED;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Article</span>'
              + '</div>';

        // Grid
        html += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden">';
        html += '<div style="display:grid;grid-template-columns:repeat(7,1fr);background:var(--s2);font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">';
        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function (d) {
            html += '<div style="padding:8px 10px;border-right:1px solid var(--bd);text-align:center">' + d + '</div>';
        });
        html += '</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(7,1fr)">';
        // empty cells before day 1
        for (var i = 0; i < startDow; i++) {
            html += '<div style="min-height:80px;background:var(--s1);border-right:1px solid var(--bd);border-top:1px solid var(--bd)"></div>';
        }
        var days = c.days || {};
        for (var d = 1; d <= lastDay; d++) {
            var key = year + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            var items = days[key] || [];
            html += '<div style="min-height:80px;background:var(--s1);border-right:1px solid var(--bd);border-top:1px solid var(--bd);padding:6px 8px;font-size:11px">';
            html += '<div style="color:var(--t3);font-weight:600;margin-bottom:4px">' + d + '</div>';
            var maxShow = 3;
            for (var i = 0; i < Math.min(items.length, maxShow); i++) {
                var item = items[i];
                var col = item.type === 'article' ? '#7C3AED' : _statusColor(item.status);
                var titleTrim = String(item.title || '').slice(0, 22) + (String(item.title || '').length > 22 ? '…' : '');
                html += '<div style="background:' + col + '20;color:' + col + ';padding:2px 5px;border-radius:3px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + _esc(item.title || '') + '">' + _esc(titleTrim) + '</div>';
            }
            if (items.length > maxShow) {
                html += '<div style="color:var(--t3);font-size:10px">+' + (items.length - maxShow) + ' more</div>';
            }
            html += '</div>';
        }
        html += '</div></div>';
        return html;
    }

    // ── Main render ──────────────────────────────────────────────────────
    function _render() {
        var html = '';
        html += '<div style="padding:24px;max-width:1200px;margin:0 auto">';

        // Header + Tab strip
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">';
        html += '<h1 style="margin:0;font-size:22px;font-weight:700">📊 Content & SEO Pipeline</h1>';
        html += '<div style="display:flex;gap:4px;background:var(--s2);padding:4px;border-radius:8px">';
        ['pipeline','calendar'].forEach(function (t) {
            var ac = t === _state.tab;
            html += '<button onclick="window._pipeSetTab(\'' + t + '\')" '
                  + 'style="padding:7px 16px;background:' + (ac ? 'var(--p)' : 'transparent') + ';'
                  + 'color:' + (ac ? '#fff' : 'var(--t2)') + ';border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">'
                  + t.charAt(0).toUpperCase() + t.slice(1)
                  + '</button>';
        });
        html += '</div></div>';

        if (_state.tab === 'pipeline') html += _renderPipeline();
        else html += _renderCalendar();

        html += '</div>';

        if (_state.rootEl) _state.rootEl.innerHTML = html;
    }

    function _initMonth() {
        if (_state.month) return;
        var d = new Date();
        _state.month = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }

    // ── Public window hooks ─────────────────────────────────────────────
    window._pipeSetTab = function (t) {
        _state.tab = t;
        if (t === 'calendar' && !_state.calendar) {
            _fetchCalendar(_state.month).then(function (d) { _state.calendar = d; _render(); });
        }
        _render();
    };
    window._pipeSetStatus = function (s) { _state.statusTab = s; _render(); };
    window._pipeSetCategory = function (c) { _state.categoryTab = c; _render(); };

    // 2026-05-25 — retry handlers. Idempotency-checked Laravel-side, so
    // duplicate retries are silently skipped. UI shows a brief toast.
    window._pipeRetryOne = async function (taskId) {
        try {
            var r = await window._luFetch('POST', '/tasks/' + taskId + '/retry', {});
            var d = await r.json();
            var verdict = (d.result && d.result.action) || 'unknown';
            var msg = verdict === 'requeued'           ? 'Task ' + taskId + ' requeued.'
                    : verdict === 'skipped_duplicate'  ? 'Task ' + taskId + ' skipped — work already done on the target.'
                    : verdict === 'not_blocked'        ? 'Task ' + taskId + ' is not blocked (current status: ' + (d.result.status || '?') + ').'
                    :                                    'Retry result: ' + verdict;
            if (typeof window.toast === 'function') window.toast(msg);
            else alert(msg);
            // Refetch pipeline
            setTimeout(function(){ window._pipeRefresh && window._pipeRefresh(); }, 800);
        } catch (e) {
            alert('Retry failed: ' + e.message);
        }
    };

    window._pipeRetryAllBlocked = async function () {
        if (!confirm('Retry all blocked tasks?\n\nDuplicates (work already done) will be auto-skipped — only legitimately-stuck tasks will requeue.')) return;
        try {
            var r = await window._luFetch('POST', '/tasks/retry-blocked', {});
            var d = await r.json();
            var s = d.summary || {};
            var msg = 'Retry batch complete:\n'
                    + (s.requeued || 0) + ' requeued\n'
                    + (s.skipped_duplicate || 0) + ' skipped (duplicates)\n'
                    + (s.errors || 0) + ' errors';
            alert(msg);
            setTimeout(function(){ window._pipeRefresh && window._pipeRefresh(); }, 800);
        } catch (e) {
            alert('Batch retry failed: ' + e.message);
        }
    };

    // Expose a refresh hook so the retry handlers can re-fetch after a retry.
    window._pipeRefresh = function () {
        if (typeof _fetchPipeline === 'function') _fetchPipeline();
    };
    window._pipeSetMonth = function (m) {
        _state.month = m;
        _state.calendar = null;
        _render();
        _fetchCalendar(m).then(function (d) { _state.calendar = d; _render(); });
    };

    // ── Auto-poll ────────────────────────────────────────────────────────
    function _startPoll() {
        if (_state.pollTimer) return;
        _state.pollTimer = setInterval(function () {
            if (_state.tab !== 'pipeline') return;
            _fetchPipeline().then(function (d) {
                _state.pipeline = d;
                _render();
                // Stop polling if nothing running
                if (d.success && (!d.counts || d.counts.running === 0)) {
                    clearInterval(_state.pollTimer);
                    _state.pollTimer = null;
                }
            });
        }, 8000);
    }

    function _stopPoll() {
        if (_state.pollTimer) { clearInterval(_state.pollTimer); _state.pollTimer = null; }
    }

    // ── Entry point — called by core.js view router ─────────────────────
    window.pipelineLoad = async function (el) {
        _state.rootEl = el || document.getElementById('pipeline-root');
        if (!_state.rootEl) return;
        _initMonth();
        _render();
        // initial fetch
        _fetchPipeline().then(function (d) {
            _state.pipeline = d;
            _render();
            if (d.success && d.counts && d.counts.running > 0) _startPoll();
        });
    };

    // Stop polling when navigating away
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) _stopPoll();
    });
})();
