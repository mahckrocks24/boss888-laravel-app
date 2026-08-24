{{-- Engineer888 — Command Center — /admin/engineer888
     Task list, task detail, candidate review, exact-content approval and
     execution, in one master-detail page. The detail view is addressed by
     ?task=<uuid>, so a task is linkable, bookmarkable and survives a refresh
     without adding a sidebar entry nobody would click.

     Data comes from /api/admin/engineer888/*. Nothing here decides anything:
     approval, enforcement and execution all happen server-side and this page
     shows what happened. --}}
<script>
  window.page = async function () {
    // ── local helpers ─────────────────────────────────────────────────
    // Deliberately local rather than reaching into another page's shared
    // helpers: this screen should not break because the Engineering Ops pages
    // are refactored.
    const esc = function (s) {
      return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    const short = function (s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n) + '…' : s; };
    const when = function (t) { return t ? String(t).replace('T', ' ').slice(0, 19) : '—'; };
    const ms = function (n) {
      if (n === null || n === undefined) { return '—'; }
      return n < 1000 ? n + 'ms' : (n / 1000).toFixed(1) + 's';
    };

    const STATE_COLOUR = {
      PENDING: '#6B7280', RUNNING: '#60A5FA', PASSED: '#10B981', FAILED: '#F87171',
      BLOCKED: '#F59E0B', SKIPPED: '#6B7280', AWAITING_APPROVAL: '#A78BFA',
      APPROVED: '#10B981', REJECTED: '#F87171', SUPERSEDED: '#F59E0B',
      EXPIRED: '#F59E0B', REVOKED: '#F87171', NONE: '#6B7280',
      completed: '#10B981', blocked: '#F59E0B', failed: '#F87171', running: '#60A5FA',
      received: '#6B7280',
    };
    const pill = function (text, colour) {
      const c = colour || STATE_COLOUR[text] || '#6B7280';
      return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;'
           + 'font-weight:600;letter-spacing:.02em;background:' + c + '22;color:' + c + ';border:1px solid '
           + c + '55">' + esc(text) + '</span>';
    };

    const qs = new URLSearchParams(location.search);
    let boot = null;
    let poll = null;

    const stop = function () { if (poll) { clearInterval(poll); poll = null; } };
    const go = function (uuid) {
      stop();
      const url = uuid ? '/admin/engineer888?task=' + encodeURIComponent(uuid) : '/admin/engineer888';
      history.pushState({}, '', url);
      uuid ? renderTask(uuid) : renderList();
    };
    window.addEventListener('popstate', function () { stop(); window.page(); });

    // ── list ──────────────────────────────────────────────────────────
    async function renderList() {
      const filters = {
        project: document.getElementById('f-project') ? document.getElementById('f-project').value : '',
        status: document.getElementById('f-status') ? document.getElementById('f-status').value : '',
        priority: document.getElementById('f-priority') ? document.getElementById('f-priority').value : '',
        stage: document.getElementById('f-stage') ? document.getElementById('f-stage').value : '',
        provider: document.getElementById('f-provider') ? document.getElementById('f-provider').value : '',
        awaiting: document.getElementById('f-awaiting') && document.getElementById('f-awaiting').checked ? '1' : '',
      };
      const query = Object.keys(filters).filter(function (k) { return filters[k]; })
        .map(function (k) { return k + '=' + encodeURIComponent(filters[k]); }).join('&');

      const d = await api('/engineer888/tasks' + (query ? '?' + query : ''));
      if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load tasks.</div>'; return; }

      let h = '';

      h += '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px">';
      h += '<div class="stat-card"><div class="stat-value">' + esc(boot.counts.total) + '</div><div class="stat-label">Tasks</div></div>';
      h += '<div class="stat-card"><div class="stat-value" style="color:#A78BFA">' + esc(boot.counts.awaiting) + '</div><div class="stat-label">Awaiting approval</div></div>';
      h += '<div class="stat-card"><div class="stat-value" style="color:#F59E0B">' + esc(boot.counts.blocked) + '</div><div class="stat-label">Blocked</div></div>';
      h += '<div class="stat-card"><div class="stat-value" style="color:#F87171">' + esc(boot.counts.failed) + '</div><div class="stat-label">Failed</div></div>';
      h += '<div class="stat-card"><div class="stat-value" style="color:#10B981">' + esc(boot.counts.completed) + '</div><div class="stat-label">Completed</div></div>';
      h += '</div>';

      h += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">';
      h += '<select id="f-project" class="input" style="width:auto" onchange="e888.list()"><option value="">All projects</option>';
      boot.projects.forEach(function (p) {
        h += '<option value="' + esc(p.key) + '"' + (filters.project === p.key ? ' selected' : '') + '>' + esc(p.name) + '</option>';
      });
      h += '</select>';
      const sel = function (id, label, options, current) {
        let s = '<select id="' + id + '" class="input" style="width:auto" onchange="e888.list()"><option value="">' + label + '</option>';
        options.forEach(function (o) { s += '<option value="' + esc(o) + '"' + (current === o ? ' selected' : '') + '>' + esc(o) + '</option>'; });
        return s + '</select>';
      };
      h += sel('f-status', 'Any status', ['received', 'running', 'completed', 'blocked', 'failed'], filters.status);
      h += sel('f-priority', 'Any priority', ['low', 'normal', 'high', 'urgent'], filters.priority);
      h += sel('f-stage', 'Any stage', boot.lifecycle, filters.stage);
      h += sel('f-provider', 'Any provider', boot.providers.map(function (p) { return p.name; }), filters.provider);
      h += '<label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="f-awaiting" onchange="e888.list()"'
         + (filters.awaiting ? ' checked' : '') + '> Awaiting approval</label>';
      h += '<div style="flex:1"></div>';
      h += '<button class="btn btn-primary" onclick="e888.newTask()">New engineering task</button>';
      h += '</div>';

      if (!d.tasks.length) {
        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:40px;text-align:center;color:var(--muted)">'
           + 'No tasks match these filters.</div>';
      } else {
        h += '<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">';
        h += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        h += '<thead><tr style="background:var(--s2);text-align:left">'
           + ['Task', 'Project', 'Stage', 'Status', 'Approval', 'Priority', 'Provider', 'Last activity']
             .map(function (c) { return '<th style="padding:9px 12px;font-weight:600">' + c + '</th>'; }).join('')
           + '</tr></thead><tbody>';
        d.tasks.forEach(function (t) {
          h += '<tr style="border-top:1px solid var(--border);cursor:pointer" onclick="e888.open(\'' + esc(t.uuid) + '\')">';
          h += '<td style="padding:9px 12px"><div style="font-weight:600">' + esc(short(t.title, 58)) + '</div>'
             + '<div style="font-size:11px;color:var(--muted);font-family:monospace">' + esc(t.uuid.slice(0, 8)) + '</div>'
             + (t.blocker ? '<div style="font-size:11px;color:#F59E0B;margin-top:3px">' + esc(short(t.blocker, 80)) + '</div>' : '')
             + '</td>';
          h += '<td style="padding:9px 12px">' + esc(t.project || '—') + '</td>';
          h += '<td style="padding:9px 12px;font-family:monospace;font-size:11px">' + esc(t.stage || '—') + '</td>';
          h += '<td style="padding:9px 12px">' + pill(t.status) + '</td>';
          h += '<td style="padding:9px 12px">' + pill(t.approval_state) + '</td>';
          h += '<td style="padding:9px 12px">' + esc(t.priority) + '</td>';
          h += '<td style="padding:9px 12px;font-size:12px">' + esc(t.provider || '—') + '</td>';
          h += '<td style="padding:9px 12px;font-size:12px;color:var(--muted)">' + esc(when(t.last_activity)) + '</td>';
          h += '</tr>';
        });
        h += '</tbody></table></div>';
      }

      content().innerHTML = h;
    }

    // ── create ────────────────────────────────────────────────────────
    function renderCreate() {
      let h = '<div style="max-width:820px">';
      h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">'
         + '<button class="btn btn-ghost btn-sm" onclick="e888.back()">&larr; Tasks</button>'
         + '<h2 style="margin:0;font-size:18px">New engineering task</h2></div>';

      h += '<div style="background:rgba(167,139,250,.10);border:1px solid rgba(167,139,250,.35);border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;line-height:1.6">'
         + 'Engineer888 will analyse the repository, reason about this task and propose an implementation. '
         + '<strong>Nothing is written until you approve the exact file contents.</strong></div>';

      const field = function (id, label, hint, input) {
        return '<div style="margin-bottom:14px"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px">'
             + label + '</label>' + input
             + (hint ? '<div style="font-size:11px;color:var(--muted);margin-top:3px">' + hint + '</div>' : '') + '</div>';
      };

      let projectOptions = '';
      boot.projects.forEach(function (p) {
        projectOptions += '<option value="' + esc(p.key) + '"' + (p.key === boot.default_project ? ' selected' : '') + '>'
                        + esc(p.company) + ' / ' + esc(p.name) + '</option>';
      });

      h += field('t-project', 'Project', 'Repository, ownership manifest and test database come from the project row.',
        '<select id="t-project" class="input" style="width:100%">' + projectOptions + '</select>');
      h += field('t-title', 'Title', '', '<input id="t-title" class="input" style="width:100%" maxlength="255" placeholder="Add a readiness report for reasoning providers">');
      h += field('t-desc', 'Description', 'Be specific about which classes and methods to use. Vagueness here is what a provider fills in with invention.',
        '<textarea id="t-desc" class="input" style="width:100%;min-height:150px" maxlength="20000"></textarea>');
      h += field('t-criteria', 'Acceptance criteria', 'One per line. These become part of the reasoning context.',
        '<textarea id="t-criteria" class="input" style="width:100%;min-height:80px"></textarea>');
      h += field('t-constraints', 'Known constraints', 'One per line.',
        '<textarea id="t-constraints" class="input" style="width:100%;min-height:60px"></textarea>');
      h += field('t-modules', 'Related modules', 'One per line — subsystem names, used to select relevant knowledge.',
        '<textarea id="t-modules" class="input" style="width:100%;min-height:50px"></textarea>');

      h += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">';
      h += field('t-kind', 'Kind', '', '<select id="t-kind" class="input" style="width:100%">'
        + ['feature', 'fix', 'docs', 'refactor', 'test', 'chore'].map(function (k) { return '<option>' + k + '</option>'; }).join('')
        + '</select>');
      h += field('t-priority', 'Priority', '', '<select id="t-priority" class="input" style="width:100%">'
        + ['low', 'normal', 'high', 'urgent'].map(function (k) { return '<option' + (k === 'normal' ? ' selected' : '') + '>' + k + '</option>'; }).join('')
        + '</select>');
      h += field('t-env', 'Affected environment', '', '<input id="t-env" class="input" style="width:100%" value="staging = production (single tree)">');
      h += '</div>';

      h += field('t-notes', 'Supporting notes', 'Optional. Carried as a constraint so the provider sees it.',
        '<textarea id="t-notes" class="input" style="width:100%;min-height:60px"></textarea>');

      h += '<div style="font-size:12px;color:var(--muted);margin-bottom:14px">Requested by <strong>' + esc(boot.actor) + '</strong></div>';

      h += '<div style="display:flex;gap:8px">'
         + '<button class="btn btn-primary" onclick="e888.submitTask()">Create task</button>'
         + '<button class="btn btn-ghost" onclick="e888.back()">Cancel</button></div>';
      h += '</div>';

      content().innerHTML = h;
    }

    async function submitTask() {
      const lines = function (id) {
        const v = document.getElementById(id).value || '';
        return v.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      };
      const body = {
        project: document.getElementById('t-project').value,
        title: document.getElementById('t-title').value.trim(),
        description: document.getElementById('t-desc').value.trim(),
        kind: document.getElementById('t-kind').value,
        priority: document.getElementById('t-priority').value,
        environment: document.getElementById('t-env').value.trim(),
        notes: document.getElementById('t-notes').value.trim(),
        acceptance_criteria: lines('t-criteria'),
        constraints: lines('t-constraints'),
        modules: lines('t-modules'),
      };
      if (!body.title || !body.description) { showAdminToast('Title and description are required', 'error'); return; }

      const r = await api('/engineer888/tasks', 'POST', body);
      if (!r || !r.uuid) { return; }
      showAdminToast('Task created', 'success');
      go(r.uuid);
    }

    // ── task detail ───────────────────────────────────────────────────
    async function renderTask(uuid) {
      const d = await api('/engineer888/tasks/' + encodeURIComponent(uuid));
      if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load this task.</div>'; return; }

      let h = '';
      h += '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px">';
      h += '<button class="btn btn-ghost btn-sm" onclick="e888.back()">&larr; Tasks</button>';
      h += '<div style="flex:1"><h2 style="margin:0 0 3px;font-size:18px">' + esc(d.task.title) + '</h2>'
         + '<div style="font-size:12px;color:var(--muted);font-family:monospace">' + esc(d.task.uuid) + '</div></div>';
      h += '<div style="text-align:right">' + pill(d.task.status) + ' <span style="font-size:12px;color:var(--muted);margin-left:6px">'
         + esc(d.project ? d.project.company + ' / ' + d.project.name : '—') + '</span></div>';
      h += '</div>';

      h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:14px;background:var(--s2);font-size:13px;line-height:1.7">';
      h += '<div style="white-space:pre-wrap">' + esc(d.task.description) + '</div>';
      if (d.task.acceptance_criteria.length) {
        h += '<div style="margin-top:10px;font-weight:600">Acceptance criteria</div><ul style="margin:4px 0 0;padding-left:18px">';
        d.task.acceptance_criteria.forEach(function (c) { h += '<li>' + esc(c) + '</li>'; });
        h += '</ul>';
      }
      h += '<div style="margin-top:10px;font-size:12px;color:var(--muted)">'
         + esc(d.task.kind) + ' · ' + esc(d.task.priority) + ' · requested by ' + esc(d.task.requested_by || '—')
         + ' · created ' + esc(when(d.task.created_at)) + '</div>';
      h += '</div>';

      // Actions
      h += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">';
      h += '<button class="btn btn-primary btn-sm" onclick="e888.reason(\'' + esc(uuid) + '\')">Reason about this task</button>';
      h += '<button class="btn btn-sm" onclick="e888.execute(\'' + esc(uuid) + '\',false)">Execute workflow</button>';
      h += '<button class="btn btn-ghost btn-sm" onclick="e888.execute(\'' + esc(uuid) + '\',true)">Dry run</button>';
      h += '<div style="flex:1"></div>';
      h += '<button class="btn btn-ghost btn-sm" onclick="e888.open(\'' + esc(uuid) + '\')">Refresh</button>';
      h += '</div>';

      // Timeline
      h += '<h3 style="font-size:15px;margin:0 0 8px">Workflow</h3>';
      h += '<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:18px">';
      d.stages.forEach(function (s, i) {
        const c = STATE_COLOUR[s.state] || '#6B7280';
        h += '<div style="border-top:' + (i ? '1px solid var(--border)' : 'none') + ';padding:9px 14px;display:flex;gap:12px;align-items:flex-start">';
        h += '<div style="width:22px;color:var(--muted);font-size:11px;padding-top:2px">' + s.position + '</div>';
        h += '<div style="width:150px;font-family:monospace;font-size:12px;font-weight:600">' + esc(s.stage) + '</div>';
        h += '<div style="width:150px">' + pill(s.state, c) + '</div>';
        h += '<div style="flex:1;font-size:12.5px;line-height:1.6">';
        h += esc(s.summary || '<span style="color:var(--muted)">not run</span>');
        if (s.failure) {
          h += '<div style="margin-top:5px;color:' + c + ';font-size:12px;white-space:pre-wrap">' + esc(s.failure) + '</div>';
        }
        if (s.purpose) {
          h += '<details style="margin-top:5px"><summary style="cursor:pointer;font-size:11px;color:var(--muted)">contract &amp; evidence</summary>'
             + '<div style="font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.7">'
             + '<div><strong>Purpose:</strong> ' + esc(s.purpose) + '</div>'
             + '<div><strong>Inputs:</strong> ' + esc((s.inputs || []).join(', ')) + '</div>'
             + '<div><strong>Verification:</strong> ' + esc(s.verification) + '</div>'
             + '<div><strong>Recovery:</strong> ' + esc(s.recovery) + '</div>'
             + (Object.keys(s.outputs || {}).length ? '<div style="margin-top:4px"><strong>Outputs:</strong> <pre style="white-space:pre-wrap;font-size:11px;margin:3px 0">'
                + esc(JSON.stringify(s.outputs, null, 1).slice(0, 1800)) + '</pre></div>' : '')
             + '</div></details>';
        }
        h += '</div>';
        h += '<div style="width:70px;text-align:right;font-size:11.5px;color:var(--muted)">' + esc(ms(s.duration_ms)) + '</div>';
        h += '</div>';
      });
      h += '</div>';

      // Candidates
      h += '<h3 style="font-size:15px;margin:0 0 8px">Reasoning candidates <span style="font-weight:400;font-size:12px;color:var(--muted)">'
         + '· ' + esc(d.revisions.used) + ' of ' + esc(d.revisions.allowed) + ' bounded revision(s) used</span></h3>';
      if (!d.candidates.length) {
        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:20px;text-align:center;color:var(--muted);font-size:13px;margin-bottom:18px">'
           + 'No candidate yet. Reason about this task to produce one.</div>';
      } else {
        h += '<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:18px">';
        d.candidates.forEach(function (c, i) {
          h += '<div style="border-top:' + (i ? '1px solid var(--border)' : 'none') + ';padding:10px 14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">';
          h += '<div style="font-family:monospace;font-size:12px;width:90px">' + esc(c.uuid.slice(0, 8)) + '</div>';
          h += '<div style="width:100px">' + pill(c.status, c.status === 'VALIDATED' ? '#10B981' : '#F87171') + '</div>';
          h += '<div style="width:220px;font-size:12.5px">' + esc(c.provider) + ' / ' + esc(short(c.model, 22)) + '</div>';
          h += '<div style="width:70px;font-size:12px">' + esc(c.file_count) + ' file(s)</div>';
          h += '<div style="width:80px;font-size:12px">' + esc(c.confidence || '—') + '</div>';
          h += '<div style="width:130px">' + (c.approval ? pill(c.approval.state) : '<span style="font-size:12px;color:var(--muted)">no record</span>') + '</div>';
          h += '<div style="flex:1"></div>';
          if (c.status === 'VALIDATED') {
            h += '<button class="btn btn-sm" onclick="e888.candidate(\'' + esc(c.uuid) + '\')">Review</button>';
          }
          h += '</div>';
          if (c.revision_of) {
            h += '<div style="padding:0 14px 8px 116px;font-size:11.5px;color:var(--muted)">revision of candidate #' + esc(c.revision_of) + '</div>';
          }
          (c.violations || []).forEach(function (v) {
            h += '<div style="padding:0 14px 6px 116px;font-size:11.5px;color:#F87171">✘ [' + esc(v.rule) + '] ' + esc(v.detail) + '</div>';
          });
          if (c.error) {
            h += '<div style="padding:0 14px 6px 116px;font-size:11.5px;color:#F87171">' + esc(c.error) + '</div>';
          }
        });
        h += '</div>';
      }

      // Evidence
      if (d.task.status === 'completed' || (d.evidence.files_changed || []).length) {
        const e = d.evidence;
        h += '<h3 style="font-size:15px;margin:0 0 8px">Completion evidence</h3>';
        h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:18px;font-size:13px;line-height:1.9;background:var(--s2)">';
        h += '<div><strong>Status:</strong> ' + pill(e.status) + '</div>';
        h += '<div><strong>Files changed:</strong> ' + ((e.files_changed || []).length
          ? esc(e.files_changed.join(', ')) : '<span style="color:var(--muted)">none</span>') + '</div>';
        h += '<div><strong>Origin:</strong> ' + esc(e.origin || '—') + '</div>';
        h += '<div><strong>Tests:</strong> ' + (e.suite ? esc(e.suite) : '<span style="color:var(--muted)">not recorded</span>') + '</div>';
        if (e.verification) {
          h += '<div><strong>Verification:</strong> ' + pill(e.verification.adequacy || 'UNKNOWN') + '</div>';
          h += '<details style="margin:4px 0"><summary style="cursor:pointer;font-size:12px;color:var(--muted)">'
             + 'checks selected and excluded, with reasons</summary><div style="font-size:12px;line-height:1.7">';
          (e.verification.selected || []).forEach(function (s) { h += '<div>+ ' + esc(s) + '</div>'; });
          (e.verification.excluded || []).forEach(function (x) {
            h += '<div style="color:var(--muted)">− ' + esc(x.target) + ' — ' + esc(x.why) + '</div>'; });
          (e.verification.policy || []).forEach(function (p) {
            h += '<div style="color:var(--muted)">policy: ' + esc(p) + '</div>'; });
          h += '</div></details>';
        }
        h += '<div><strong>Deployment verification:</strong> ' + esc(e.deploy_verdict || '—') + '</div>';
        h += '<div><strong>Rollback path:</strong> ' + ((e.backups || []).length
          ? esc(e.backups.join(', ')) : '<span style="color:var(--muted)">nothing was overwritten</span>') + '</div>';
        h += '<div><strong>Assets promoted:</strong> ' + ((e.assets || []).length
          ? esc(e.assets.map(function (a) { return a.kind + '/' + a.name; }).join(', ')) : 'none') + '</div>';
        h += '<div><strong>Total duration:</strong> ' + esc(ms(e.duration_ms)) + '</div>';
        if (e.enforcement) {
          h += '<div style="margin-top:6px"><strong>Approval enforcement:</strong> ' + esc(e.enforcement.summary) + '</div>';
        }
        h += '</div>';
      }

      // Recovery — what was restored, or why it refused.
      if ((d.recoveries || []).length) {
        h += '<h3 style="font-size:15px;margin:0 0 8px">Recovery</h3>';
        d.recoveries.forEach(function (r) {
          var ev = r.evidence || {};
          var blocked = r.status === 'FAILED_RECOVERY_BLOCKED';
          h += '<div style="border:1px solid ' + (blocked ? 'rgba(248,113,113,.45)' : 'var(--border)')
             + ';border-radius:8px;padding:14px 16px;margin-bottom:12px;font-size:13px;line-height:1.85;'
             + 'background:' + (blocked ? 'rgba(248,113,113,.08)' : 'var(--s2)') + '">';
          h += '<div>' + pill(r.status) + ' <span style="color:var(--muted)">by ' + esc(r.actor || '—')
             + ' at ' + esc(when(r.at)) + '</span></div>';
          h += '<div style="margin-top:4px">' + esc(ev.summary || '') + '</div>';
          h += '<div style="font-family:monospace;font-size:11px;color:var(--muted)">manifest '
             + esc((r.fingerprint || '').slice(0, 32)) + '…</div>';
          (ev.performed || []).forEach(function (p) {
            h += '<div style="font-size:12.5px">' + esc(p.action) + ' <span style="font-family:monospace">'
               + esc(p.path) + '</span>' + (p.hash_after ? ' → ' + esc(p.hash_after.slice(0, 16)) + '…' : '')
               + (p.verified ? ' <span style="color:#10B981">verified</span>' : '') + '</div>';
          });
          (ev.problems || []).forEach(function (c) {
            h += '<div style="font-size:12.5px;color:#F87171">conflict — ' + esc(c.reason || c.error) + '</div>';
          });
          if (r.status === 'FAILED_RECOVERY_BLOCKED' || r.status === 'FAILED_RECOVERY_INCOMPLETE') {
            h += '<div style="margin-top:10px"><button class="btn btn-sm" onclick="e888.recovery('
               + r.id + ')">Review and approve recovery</button></div>';
          }
          if ((ev.manual || []).length) {
            h += '<div style="margin-top:8px;font-weight:600">Manual recovery</div>';
            h += '<ol style="margin:4px 0 0;padding-left:20px;font-size:12.5px;line-height:1.7">';
            ev.manual.forEach(function (s) { h += '<li>' + esc(s) + '</li>'; });
            h += '</ol>';
            h += '<div style="margin-top:8px;font-size:12px;color:var(--muted)">Recovery is blocked '
               + 'because another change arrived after this workflow wrote. Engineer888 will not '
               + 'overwrite it; resolve the file by hand using the steps above.</div>';
          }
          h += '</div>';
        });
      }

      // Approval history
      if (d.approvals.length) {
        h += '<h3 style="font-size:15px;margin:0 0 8px">Approval ledger</h3>';
        h += '<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">';
        d.approvals.forEach(function (a, i) {
          h += '<div style="border-top:' + (i ? '1px solid var(--border)' : 'none') + ';padding:9px 14px;font-size:12.5px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">';
          h += '<div style="font-family:monospace;width:90px">' + esc(a.candidate_uuid.slice(0, 8)) + '</div>';
          h += '<div style="width:120px">' + pill(a.state) + '</div>';
          h += '<div style="width:200px">' + esc(a.approver || '—') + '</div>';
          h += '<div style="flex:1;font-family:monospace;font-size:11px;color:var(--muted)">' + esc(a.fingerprint.slice(0, 24)) + '…</div>';
          h += '<div style="color:var(--muted)">' + esc(when(a.approved_at || a.expires_at)) + '</div>';
          h += '</div>';
          if (a.comment) { h += '<div style="padding:0 14px 8px 116px;font-size:12px;color:var(--muted)">' + esc(a.comment) + '</div>'; }
        });
        h += '</div>';
      }

      content().innerHTML = h;

      // Poll while the workflow is on the queue.
      stop();
      if (d.task.status === 'running') {
        poll = setInterval(function () { renderTask(uuid); }, 5000);
      }
    }

    // ── candidate review ──────────────────────────────────────────────
    async function renderCandidate(uuid) {
      stop();
      const d = await api('/engineer888/candidates/' + encodeURIComponent(uuid));
      if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load this candidate.</div>'; return; }

      const c = d.candidate;
      const fp = d.binding ? d.binding.fingerprint : null;

      let h = '';
      h += '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px">';
      h += '<button class="btn btn-ghost btn-sm" onclick="e888.open(\'' + esc(d.task_uuid) + '\')">&larr; Task</button>';
      h += '<div style="flex:1"><h2 style="margin:0 0 3px;font-size:18px">Candidate review</h2>'
         + '<div style="font-size:12px;color:var(--muted);font-family:monospace">' + esc(c.uuid) + '</div></div>';
      h += '<div>' + pill(c.status, c.status === 'VALIDATED' ? '#10B981' : '#F87171') + '</div>';
      h += '</div>';

      // Provenance
      h += '<div style="border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:14px;background:var(--s2);font-size:12.5px;line-height:1.9">';
      h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">';
      h += '<div><span style="color:var(--muted)">Provider</span><br><strong>' + esc(c.provider) + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Model returned</span><br><strong>' + esc(c.model) + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Provider requested</span><br><strong>' + esc(c.requested_model) + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Fallback used</span><br><strong>' + (c.fallback_used ? 'yes' : 'no') + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Generated</span><br><strong>' + esc(when(c.generated_at)) + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Latency</span><br><strong>' + esc(ms(c.latency_ms)) + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Confidence</span><br><strong>' + esc(c.confidence || '—') + '</strong></div>';
      h += '<div><span style="color:var(--muted)">Context sent</span><br><strong>' + esc(d.context.bytes || 0) + ' bytes</strong></div>';
      h += '<div><span style="color:var(--muted)">Knowledge excluded</span><br><strong>' + esc((d.context.excluded || []).length) + ' item(s)</strong></div>';
      h += '</div>';
      if (c.revision_instruction) {
        h += '<div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)"><strong>Revision requested:</strong> '
           + esc(c.revision_instruction) + '</div>';
      }
      h += '</div>';

      // Reasoning
      const r = d.reasoning;
      const block = function (title, body) {
        return '<div style="margin-bottom:10px"><div style="font-weight:600;font-size:13px;margin-bottom:3px">' + title + '</div>'
             + '<div style="font-size:12.5px;line-height:1.7;color:var(--muted)">' + body + '</div></div>';
      };
      h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:14px">';
      h += block('Problem understanding', esc(r.problem_understanding || '—'));
      h += block('Implementation strategy', esc(r.implementation_strategy || '—'));
      if ((r.assumptions || []).length) {
        h += block('Assumptions', '<ul style="margin:0;padding-left:18px">'
          + r.assumptions.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>');
      }
      if ((r.unknowns || []).length) {
        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:6px;padding:10px 12px;margin-bottom:10px">';
        h += '<div style="font-weight:600;font-size:13px;margin-bottom:4px">Unresolved unknowns — ' + r.unknowns.length + '</div>';
        h += '<ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.7">';
        r.unknowns.forEach(function (u) {
          h += '<li>' + esc(u.question) + '<div style="color:var(--muted);font-size:11.5px">matters: ' + esc(u.why_it_matters)
             + ' · resolve: ' + esc(u.how_to_resolve) + '</div></li>';
        });
        h += '</ul></div>';
      }
      if ((r.risks || []).length) {
        h += block('Risks declared by the provider', '<ul style="margin:0;padding-left:18px">'
          + r.risks.map(function (x) { return '<li>' + esc(x.risk) + ' — breaks: ' + esc(x.breaks) + '</li>'; }).join('') + '</ul>');
      }
      h += block('Migrations', esc(r.migrations ? JSON.stringify(r.migrations) : '—'));
      h += block('Testing strategy', esc(r.testing_strategy || '—'));
      h += block('Rollback', esc(r.rollback || '—'));
      h += '</div>';

      // Validation
      if ((d.validation || []).length) {
        h += '<div style="background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.4);border-radius:8px;padding:12px 14px;margin-bottom:14px">';
        h += '<div style="font-weight:600;margin-bottom:5px">Engineer888 refused this candidate</div><ul style="margin:0;padding-left:18px;font-size:12.5px">';
        d.validation.forEach(function (v) { h += '<li>[' + esc(v.rule) + '] ' + esc(v.detail) + '</li>'; });
        h += '</ul></div>';
      }

      // Files and diffs
      h += '<h3 style="font-size:15px;margin:0 0 8px">Proposed changes — ' + esc(d.totals.files) + ' file(s), '
         + '<span style="color:#10B981">+' + esc(d.totals.additions) + '</span> '
         + '<span style="color:#F87171">−' + esc(d.totals.deletions) + '</span></h3>';

      d.files.forEach(function (f, idx) {
        const ownColour = f.committable ? '#10B981' : '#F87171';
        h += '<div style="border:1px solid var(--border);border-radius:8px;margin-bottom:12px;overflow:hidden">';
        h += '<div style="padding:9px 14px;background:var(--s2);display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
        h += '<span style="font-family:monospace;font-size:12.5px;font-weight:600">' + esc(f.path) + '</span>';
        h += pill(f.action, f.action === 'CREATE' ? '#10B981' : '#60A5FA');
        h += '<span style="font-size:11.5px;color:' + ownColour + '">ownership: ' + esc(f.ownership) + '</span>';
        if (f.governed) { h += pill('GOVERNED', '#F59E0B'); }
        h += '<div style="flex:1"></div>';
        h += '<span style="font-size:11.5px;color:var(--muted)">' + esc(f.bytes) + ' bytes · '
           + '<span style="color:#10B981">+' + esc(f.diff.additions) + '</span> '
           + '<span style="color:#F87171">−' + esc(f.diff.deletions) + '</span></span>';
        h += '</div>';

        if (!f.committable) {
          h += '<div style="padding:8px 14px;font-size:12px;color:#F87171;border-bottom:1px solid var(--border)">'
             + 'This sprint cannot prove it owns this file: ' + esc(f.ownership_why) + '</div>';
        }

        h += '<div style="max-height:420px;overflow:auto;font-family:monospace;font-size:11.5px;line-height:1.55">';
        if (f.diff.binary) {
          h += '<div style="padding:12px 14px;color:var(--muted)">binary content — not shown</div>';
        } else if (!f.diff.hunks.length) {
          h += '<div style="padding:12px 14px;color:var(--muted)">no textual change</div>';
        } else {
          f.diff.hunks.forEach(function (line) {
            let bg = 'transparent', mark = ' ', col = 'inherit';
            if (line.type === 'add') { bg = 'rgba(16,185,129,.12)'; mark = '+'; col = '#6EE7B7'; }
            if (line.type === 'del') { bg = 'rgba(248,113,113,.12)'; mark = '−'; col = '#FCA5A5'; }
            if (line.type === 'gap') { bg = 'var(--s2)'; mark = ' '; col = '#6B7280'; }
            h += '<div style="display:flex;background:' + bg + '">'
               + '<span style="width:44px;text-align:right;padding-right:8px;color:#6B7280;flex-shrink:0">'
               + (line.old === null || line.old === undefined ? '' : line.old) + '</span>'
               + '<span style="width:44px;text-align:right;padding-right:8px;color:#6B7280;flex-shrink:0">'
               + (line.new === null || line.new === undefined ? '' : line.new) + '</span>'
               + '<span style="width:14px;color:' + col + ';flex-shrink:0">' + mark + '</span>'
               + '<span style="white-space:pre-wrap;color:' + col + '">' + esc(line.text) + '</span></div>';
          });
          if (f.diff.truncated) {
            h += '<div style="padding:8px 14px;color:#F59E0B">Display truncated at ' + esc(f.diff.hunks.length)
               + ' of ' + esc(f.diff.total_lines) + ' lines. The approval covers the whole file regardless.</div>';
          }
        }
        h += '</div></div>';
      });

      // Approval
      const a = d.approval;
      h += '<h3 style="font-size:15px;margin:18px 0 8px">Approval</h3>';

      // A migration whose rehearsal gate refuses is not approvable, and the
      // control is REMOVED rather than left clickable. Allowing the click and
      // relying on the server to say no trains an operator to treat refusals as
      // noise. The server gate remains authoritative either way.
      var gate = (d.rehearsal_gate || null);
      var gateBlocked = gate && gate.required && !gate.permitted;

      if (gateBlocked) {
        h += '<div style="border:1px solid rgba(248,113,113,.45);background:rgba(248,113,113,.08);'
          + 'border-radius:8px;padding:14px 16px;font-size:13px;line-height:1.8">';
        h += '<div style="font-weight:600;margin-bottom:6px">Approval unavailable &mdash; migration rehearsal gate</div>';
        gate.refusals.forEach(function (r) {
          h += '<div><span style="font-family:monospace;color:#F87171">' + esc(r.reason) + '</span> &mdash; '
            + esc(r.detail) + '</div>';
        });
        h += '<div style="margin-top:8px;color:var(--muted);font-size:12px">Rehearse the exact bytes '
          + 'against the assigned isolated database, then reopen this candidate.</div>';
        h += '</div>';
      } else if (!fp) {
        h += '<div style="border:1px solid var(--border);border-radius:8px;padding:16px;color:var(--muted);font-size:13px">'
           + 'This candidate has no binding, so it cannot be approved.</div>';
      } else if (a && a.state !== 'PENDING') {
        h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;font-size:13px;line-height:1.9;background:var(--s2)">';
        h += '<div>' + pill(a.state) + ' by <strong>' + esc(a.approver || '—') + '</strong> at ' + esc(when(a.approved_at)) + '</div>';
        if (a.statement) { h += '<div style="margin-top:6px;font-style:italic">“' + esc(a.statement) + '”</div>'; }
        if (a.comment) { h += '<div style="margin-top:4px;color:var(--muted)">' + esc(a.comment) + '</div>'; }
        if (a.expires_at) { h += '<div style="margin-top:4px;color:var(--muted)">expires ' + esc(when(a.expires_at)) + '</div>'; }
        if (a.state === 'APPROVED') {
          h += '<div style="margin-top:10px"><button class="btn btn-ghost btn-sm" onclick="e888.revoke(\'' + esc(c.uuid) + '\')">Revoke approval</button></div>';
        }
        h += '</div>';
      } else {
        const statement = 'I approve candidate ' + c.uuid + ' with fingerprint ' + fp + ' and the exact file changes shown.';
        h += '<div style="border:1px solid rgba(167,139,250,.4);background:rgba(167,139,250,.08);border-radius:8px;padding:14px 16px">';
        h += '<div style="font-size:12.5px;line-height:1.9;margin-bottom:10px">';
        h += '<div><span style="color:var(--muted)">Candidate</span> <span style="font-family:monospace">' + esc(c.uuid) + '</span></div>';
        h += '<div><span style="color:var(--muted)">Fingerprint</span> <span style="font-family:monospace;word-break:break-all">' + esc(fp) + '</span></div>';
        h += '<div><span style="color:var(--muted)">Files</span> ' + esc(d.totals.files) + ' · '
           + '<span style="color:#10B981">+' + esc(d.totals.additions) + '</span> / '
           + '<span style="color:#F87171">−' + esc(d.totals.deletions) + '</span></div>';
        h += '<div><span style="color:var(--muted)">Paths</span> <span style="font-family:monospace">' + esc((d.binding.file_hashes ? Object.keys(d.binding.file_hashes) : []).join(', ')) + '</span></div>';
        h += '<div><span style="color:var(--muted)">Provider</span> ' + esc(c.provider) + ' / ' + esc(c.model) + '</div>';
        h += '<div><span style="color:var(--muted)">Unresolved unknowns</span> ' + esc((r.unknowns || []).length) + '</div>';
        h += '<div><span style="color:var(--muted)">Validation findings</span> ' + esc((d.validation || []).length) + '</div>';
        h += '</div>';

        h += '<label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;line-height:1.6;margin-bottom:10px;cursor:pointer">'
           + '<input type="checkbox" id="ap-confirm" style="margin-top:3px">'
           + '<span>' + esc(statement) + '</span></label>';
        h += '<input id="ap-comment" class="input" style="width:100%;margin-bottom:10px" placeholder="Optional note for the record">';
        h += '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        h += '<button class="btn btn-primary btn-sm" onclick="e888.approve(\'' + esc(c.uuid) + '\',\'' + esc(fp) + '\')">Approve these exact bytes</button>';
        h += '<button class="btn btn-sm" onclick="e888.reject(\'' + esc(c.uuid) + '\',true)">Reject &amp; request one revision</button>';
        h += '<button class="btn btn-ghost btn-sm" onclick="e888.reject(\'' + esc(c.uuid) + '\',false)">Reject</button>';
        h += '</div></div>';
      }

      // Context transparency
      h += '<details style="margin-top:16px"><summary style="cursor:pointer;font-size:13px;font-weight:600">'
         + 'Knowledge sent to the provider, and what was withheld</summary>';
      h += '<div style="border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-top:8px;font-size:12.5px;line-height:1.8">';
      h += '<div style="font-weight:600;margin-bottom:4px">Included</div>';
      Object.keys(d.context.sections || {}).forEach(function (k) {
        const s = d.context.sections[k];
        h += '<div style="font-family:monospace;font-size:11.5px">' + esc(k) + ' — ' + esc(s.items) + ' item(s), ' + esc(s.bytes) + ' bytes</div>';
      });
      h += '<div style="font-weight:600;margin:8px 0 4px">Excluded</div>';
      (d.context.excluded || []).slice(0, 40).forEach(function (x) {
        h += '<div style="font-size:11.5px;color:var(--muted)">' + esc(x.item) + ' — ' + esc(x.reason) + '</div>';
      });
      h += '</div></details>';

      content().innerHTML = h;
    }

    // ── actions ───────────────────────────────────────────────────────
    window.e888 = {
      list: renderList,
      back: function () { go(null); },
      open: function (uuid) { go(uuid); },
      newTask: renderCreate,
      submitTask: submitTask,
      candidate: function (uuid) { stop(); renderCandidate(uuid); },

      reason: async function (uuid) {
        showAdminToast('Reasoning — this calls a real provider and can take a minute', 'info');
        const r = await api('/engineer888/tasks/' + encodeURIComponent(uuid) + '/reason', 'POST', {});
        if (r && r.candidate_uuid) {
          showAdminToast(r.status === 'VALIDATED' ? 'Candidate ready for review' : ('Refused: ' + r.status), r.status === 'VALIDATED' ? 'success' : 'error');
          renderCandidate(r.candidate_uuid);
        } else {
          renderTask(uuid);
        }
      },

      execute: async function (uuid, dry) {
        const ok = await adminConfirm(dry
          ? 'Run the workflow as a dry run? Nothing will be written.'
          : 'Execute the approved candidate? Files will be installed and the test suite will run.',
          dry ? 'Dry run' : 'Execute workflow');
        if (!ok) { return; }
        const r = await api('/engineer888/tasks/' + encodeURIComponent(uuid) + '/execute', 'POST', { dry_run: !!dry });
        if (r && r.queued) { showAdminToast('Workflow queued', 'success'); renderTask(uuid); }
      },

      approve: async function (uuid, fingerprint) {
        const box = document.getElementById('ap-confirm');
        if (!box || !box.checked) { showAdminToast('Tick the approval statement first', 'error'); return; }
        const statement = 'I approve candidate ' + uuid + ' with fingerprint ' + fingerprint + ' and the exact file changes shown.';
        const r = await api('/engineer888/candidates/' + encodeURIComponent(uuid) + '/approve', 'POST', {
          fingerprint: fingerprint,
          statement: statement,
          comment: (document.getElementById('ap-comment') || {}).value || null,
        });
        if (r && r.approval) { showAdminToast('Approved — bound to fingerprint', 'success'); renderCandidate(uuid); }
      },

      reject: async function (uuid, revise) {
        const instruction = await adminPrompt(revise
          ? 'What should the revision do differently? This is the one revision allowed.'
          : 'Why is this candidate rejected?', revise ? 'Reject and revise' : 'Reject');
        if (!instruction) { return; }
        const r = await api('/engineer888/candidates/' + encodeURIComponent(uuid) + '/reject', 'POST', {
          instruction: instruction, revise: !!revise,
        });
        if (!r) { return; }
        if (r.revision && r.revision.candidate_uuid) {
          showAdminToast('Revision produced — review it', 'success');
          renderCandidate(r.revision.candidate_uuid);
        } else if (r.revision && r.revision.blocked) {
          showAdminToast('Revision refused: ' + r.revision.reason, 'error');
          renderCandidate(uuid);
        } else {
          showAdminToast('Rejected', 'success');
          renderCandidate(uuid);
        }
      },

      recovery: async function (id) {
        var d = await api('/engineer888/recoveries/' + id);
        if (!d) { return; }
        var c = d.candidate;

        var h = '<div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:14px">'
          + '<button class="btn btn-ghost btn-sm" onclick="e888.open(\'' + esc(c.task_uuid) + '\')">&larr; Task</button>'
          + '<div><h2 style="margin:0;font-size:18px">Recovery review</h2>'
          + '<div style="font-size:12px;color:var(--muted);font-family:monospace">' + esc(c.uuid) + '</div></div></div>';

        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.4);border-radius:8px;'
          + 'padding:12px 14px;margin-bottom:14px;font-size:13px;line-height:1.7">'
          + '<strong>Automatic recovery refused.</strong> Nothing has been restored and no other '
          + 'engineer&rsquo;s work has been touched. Approving below runs <em>only</em> the actions shown.';
        (c.warnings || []).forEach(function (w) { h += '<div style="margin-top:4px">&#9888; ' + esc(w) + '</div>'; });
        h += '</div>';

        c.files.forEach(function (f) {
          var executable = ['REMOVE_CREATED_FILE','RESTORE_UPDATED_FILE','RESTORE_DELETED_FILE'].indexOf(f.action) >= 0;
          h += '<div style="border:1px solid var(--border);border-radius:8px;margin-bottom:12px;overflow:hidden">';
          h += '<div style="padding:9px 14px;background:var(--s2);display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
            + '<span style="font-family:monospace;font-size:12.5px;font-weight:600">' + esc(f.path) + '</span>'
            + pill(f.action, executable ? '#10B981' : '#F59E0B')
            + (f.drift ? pill('DRIFT', '#F87171') : '')
            + '<span style="font-size:11.5px;color:var(--muted)">ownership ' + esc(f.ownership) + '</span></div>';
          if (f.conflict) { h += '<div style="padding:8px 14px;color:#F87171;font-size:12.5px">' + esc(f.conflict) + '</div>'; }
          h += '<div style="padding:8px 14px;font-size:12px;font-family:monospace;line-height:1.8;color:var(--muted)">'
            + '<div>current  ' + esc((f.current_hash || 'absent').slice(0, 24)) + '</div>'
            + '<div>pre-task ' + esc((f.pre_hash || 'absent').slice(0, 24)) + '</div>'
            + '<div>backup   ' + esc(f.backup_path || 'none') + ' ' + esc((f.backup_hash || '').slice(0, 16)) + '</div>'
            + '</div>';
          if (f.diff && f.diff.hunks && f.diff.hunks.length) {
            h += '<div style="max-height:300px;overflow:auto;font-family:monospace;font-size:11.5px;line-height:1.55">';
            f.diff.hunks.forEach(function (line) {
              var bg = 'transparent', mark = ' ', col = 'inherit';
              if (line.type === 'add') { bg = 'rgba(16,185,129,.12)'; mark = '+'; col = '#6EE7B7'; }
              if (line.type === 'del') { bg = 'rgba(248,113,113,.12)'; mark = '-'; col = '#FCA5A5'; }
              h += '<div style="display:flex;background:' + bg + '"><span style="width:14px;color:' + col + '">'
                + mark + '</span><span style="white-space:pre-wrap;color:' + col + '">' + esc(line.text) + '</span></div>';
            });
            h += '</div>';
          }
          h += '</div>';
        });

        if (d.approval.status === 'PENDING') {
          h += '<div style="border:1px solid rgba(167,139,250,.4);background:rgba(167,139,250,.08);'
            + 'border-radius:8px;padding:14px 16px">';
          h += '<div style="font-family:monospace;font-size:11.5px;word-break:break-all;margin-bottom:10px">'
            + 'fingerprint ' + esc(c.fingerprint) + '</div>';
          h += '<label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;line-height:1.6;'
            + 'margin-bottom:10px;cursor:pointer"><input type="checkbox" id="rc-confirm" style="margin-top:3px">'
            + '<span>' + esc(c.statement) + '</span></label>';
          h += '<input id="rc-comment" class="input" style="width:100%;margin-bottom:10px" placeholder="Optional note">';
          h += '<div style="display:flex;gap:8px">'
            + '<button class="btn btn-primary btn-sm" onclick="e888.approveRecovery(\'' + esc(c.uuid)
            + '\',\'' + esc(c.fingerprint) + '\',' + id + ')">Approve and execute</button>'
            + '<button class="btn btn-ghost btn-sm" onclick="e888.rejectRecovery(\'' + esc(c.uuid) + '\','
            + id + ')">Reject</button></div>';
          if (!c.executable) {
            h += '<div style="margin-top:10px;font-size:12px;color:#F59E0B">No file can be restored '
              + 'automatically. Approving records the decision; the files above must be resolved by hand.</div>';
          }
          h += '</div>';
        } else {
          h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;font-size:13px">'
            + pill(d.approval.status) + '</div>';
        }

        content().innerHTML = h;
      },

      approveRecovery: async function (uuid, fingerprint, id) {
        var box = document.getElementById('rc-confirm');
        if (!box || !box.checked) { showAdminToast('Tick the approval statement first', 'error'); return; }
        var statement = 'I approve recovery ' + uuid + ' with fingerprint ' + fingerprint
          + ' and the exact actions shown.';
        var r = await api('/engineer888/recoveries/' + encodeURIComponent(uuid) + '/approve', 'POST', {
          fingerprint: fingerprint, statement: statement,
          comment: (document.getElementById('rc-comment') || {}).value || null,
        });
        if (!r || !r.status) { return; }
        var x = await api('/engineer888/recoveries/' + encodeURIComponent(uuid) + '/execute', 'POST', {});
        showAdminToast(x && x.status === 'EXECUTED' ? 'Recovery executed' : ('Recovery ' + ((x && x.status) || 'refused')),
          x && x.status === 'EXECUTED' ? 'success' : 'error');
        e888.recovery(id);
      },

      rejectRecovery: async function (uuid, id) {
        var why = await adminPrompt('Why is this recovery rejected?', 'Reject recovery');
        if (!why) { return; }
        await api('/engineer888/recoveries/' + encodeURIComponent(uuid) + '/reject', 'POST', { reason: why });
        showAdminToast('Recovery rejected', 'success');
        e888.recovery(id);
      },

      revoke: async function (uuid) {
        const why = await adminPrompt('Why is this approval being withdrawn?', 'Revoke approval');
        if (!why) { return; }
        const r = await api('/engineer888/candidates/' + encodeURIComponent(uuid) + '/revoke', 'POST', { reason: why });
        if (r) { showAdminToast('Approval revoked', 'success'); renderCandidate(uuid); }
      },
    };

    // ── boot ──────────────────────────────────────────────────────────
    content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading Engineer888…</div>';
    boot = await api('/engineer888/bootstrap');
    if (!boot) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not reach Engineer888.</div>'; return; }

    const target = qs.get('task');
    target ? renderTask(target) : renderList();
  };
</script>
