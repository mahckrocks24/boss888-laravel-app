{{--
    Engineer888 - Decisions.  /admin/engineer888/decisions

    THE CANONICAL ANSWER TO "WHAT IS WAITING ON ME".

    Everything on this page comes from GET /engineer888/decisions, which reads
    DecisionProjection and nothing else. The page computes no state, counts
    nothing itself, and decides nothing about what is executable. It draws what
    the server sends.

    That is not fastidiousness. Before the projection existed, four surfaces
    each worked the answer out separately and disagreed: 52 live cards, a header
    saying 25, a per-task view saying 24, and the model telling Boss "30". A
    fifth opinion, computed in a browser, is the last thing this needs.

    WHY THERE ARE NO APPROVE BUTTONS HERE.
    Approving is a named human making a statement about exact bytes, and that
    flow -- the diff, the file hashes, the typed sentence -- already exists in
    the Command Center. Rebuilding it here would create a second authority
    surface to keep in step with the first. So this page is canonical for WHAT
    is waiting and links to WHERE the decision is taken. A card uuid arrives
    with each item and is deliberately not turned into a button.

    An APPROVAL_EXPIRED item carries an explanation instead of any control at
    all, because the domain has no way to honour one: the ledger refuses
    execution, approve() refuses any row that is not PENDING, and renew and
    extend do not exist. A control that looks available implies an authority
    that is not there.

    ASCII-only. This file is shipped over SSH and a UTF-8 re-encode in transit
    has corrupted a sibling once already.
--}}

<style>
  .e8d{max-width:920px}
  .e8d-scope{display:flex;align-items:baseline;justify-content:space-between;gap:12px;
             margin-bottom:18px;flex-wrap:wrap}
  .e8d-count{font-size:22px;font-weight:600}
  .e8d-sub{font-size:12.5px;color:var(--muted)}
  .e8d-sec{margin:26px 0 10px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;
           color:var(--muted);font-weight:700}
  .e8d-grp{margin-bottom:22px}
  .e8d-grp-h{font-size:13px;font-weight:600;margin:0 0 8px;display:flex;align-items:center;gap:8px}
  .e8d-item{border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:10px;
            background:var(--s2)}
  .e8d-t{font-size:14.5px;font-weight:600;line-height:1.4}
  .e8d-m{font-size:12px;color:var(--muted);margin-top:4px}
  .e8d-why{font-size:12.5px;line-height:1.6;margin-top:10px;padding:10px 12px;border-radius:8px;
           background:rgba(251,191,36,.09);border:1px solid rgba(251,191,36,.28)}
  .e8d-act{margin-top:11px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .e8d-go{font-size:12.5px;text-decoration:none;padding:5px 11px;border-radius:7px;
          border:1px solid var(--border);color:inherit;background:transparent}
  .e8d-go:hover{background:rgba(255,255,255,.05)}
  .e8d-none{padding:26px;text-align:center;color:var(--muted);font-size:13px;
            border:1px dashed var(--border);border-radius:10px}
  .e8d-line{font-size:12.5px;padding:7px 0;border-bottom:1px solid var(--border);
            display:flex;gap:10px;align-items:baseline}
  .e8d-line:last-child{border-bottom:none}
  .e8d-h summary{cursor:pointer;font-size:12.5px;color:var(--muted);padding:6px 0}
</style>

<script>
  window.page = async function () {
    var esc = function (s) {
      return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    var COLOUR = {
      REVIEW_REQUIRED:  '#60A5FA',
      READY_TO_EXECUTE: '#34D399',
      APPROVAL_EXPIRED: '#FBBF24',
      COMPLETED:        '#34D399',
      REJECTED:         '#F87171',
      REVOKED:          '#F87171',
      SUPERSEDED:       '#9CA3AF',
      BLOCKED:          '#9CA3AF'
    };

    var pill = function (state) {
      var c = COLOUR[state] || '#6B7280';
      return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;'
           + 'font-weight:700;letter-spacing:.03em;background:' + c + '22;color:' + c
           + ';border:1px solid ' + c + '55">' + esc(String(state).replace(/_/g, ' ')) + '</span>';
    };

    var qs = new URLSearchParams(location.search);
    var scopeParam = qs.get('project');

    content().innerHTML = '<div style="text-align:center;padding:44px;color:#6B7280">Loading decisions...</div>';

    var d = await api('/engineer888/decisions' + (scopeParam ? '?project=' + encodeURIComponent(scopeParam) : ''));
    if (!d) {
      content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load decisions.</div>';
      return;
    }

    // ---- one decision -------------------------------------------------
    // The server said what this is and what may be offered for it. The only
    // thing decided here is layout.
    var item = function (x) {
      var h = '<div class="e8d-item">';
      h += '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">';
      h += '<div class="e8d-t">' + esc(x.title) + '</div>' + pill(x.state);
      h += '</div>';

      var meta = [];
      if (x.project) { meta.push(esc(x.project)); }
      if (x.file_count !== null && x.file_count !== undefined) { meta.push(esc(x.file_count) + ' files'); }
      if (x.confidence) { meta.push(esc(x.confidence) + ' confidence'); }
      if (x.earlier) { meta.push(esc(x.earlier) + ' earlier attempts'); }
      if (x.approved_by) { meta.push('approved by ' + esc(x.approved_by)); }
      h += '<div class="e8d-m">' + meta.join('  .  ') + '</div>';

      // The server's own words for why this is in the state it is in.
      if (x.explanation) { h += '<div class="e8d-why">' + esc(x.explanation) + '</div>'; }

      h += '<div class="e8d-act">';
      if (x.task_uuid) {
        h += '<a class="e8d-go" href="/admin/engineer888?task=' + encodeURIComponent(x.task_uuid) + '">'
           + (x.state === 'REVIEW_REQUIRED' ? 'Review the candidate' : 'Open in Command Center') + '</a>';
      }
      h += '<a class="e8d-go" href="/admin/engineer888/chat">Ask Engineer888</a>';

      // Said plainly rather than shown as a disabled button. A greyed-out
      // control still reads as "this could work if I had permission", and for
      // an expired approval nothing would make it work.
      if (x.state === 'APPROVAL_EXPIRED') {
        h += '<span class="e8d-sub">No action available on this decision.</span>';
      } else if (x.stale) {
        h += '<span class="e8d-sub">Waiting for a fresh action card.</span>';
      }
      h += '</div></div>';

      return h;
    };

    var group = function (title, rows) {
      if (!rows || !rows.length) { return ''; }
      var h = '<div class="e8d-grp"><div class="e8d-grp-h">' + esc(title)
            + ' <span class="e8d-sub">(' + rows.length + ')</span></div>';
      rows.forEach(function (r) { h += item(r); });
      return h + '</div>';
    };

    // ---- page ---------------------------------------------------------
    var a = d.needs_attention || {};
    var total = a.total || 0;

    var h = '<div class="e8d">';

    h += '<div class="e8d-scope">';
    h += '<div><div class="e8d-count">'
       + (total === 0 ? 'Nothing is waiting on you'
                      : (total === 1 ? '1 decision waiting' : total + ' decisions waiting'))
       + '</div>'
       + '<div class="e8d-sub">' + esc(d.scope && d.scope.project ? d.scope.project : 'All projects')
       + (d.scope && d.scope.project_id ? ' . <a href="?project=all" style="color:inherit">see all projects</a>' : '')
       + '</div></div>';
    h += '</div>';

    h += '<div class="e8d-sec">Needs attention</div>';
    if (total === 0) {
      h += '<div class="e8d-none">No candidate is waiting for your judgement and nothing approved is waiting to run.</div>';
    } else {
      h += group('Awaiting your review', a.review_required);
      h += group('Ready to execute', a.ready_to_execute);
      h += group('Approval expired', a.approval_expired);
    }

    var prog = d.in_progress || [];
    h += '<div class="e8d-sec">In progress</div>';
    if (!prog.length) {
      h += '<div class="e8d-none">Nothing is running.</div>';
    } else {
      h += '<div class="e8d-item">';
      prog.forEach(function (p) {
        h += '<div class="e8d-line">' + pill(String(p.status).toUpperCase())
           + '<div><div>' + esc(p.title) + '</div>'
           + '<div class="e8d-sub">' + esc(p.project) + ' . stage ' + esc(p.stage || 'none')
           + ' . since ' + esc(p.since) + '</div></div></div>';
      });
      h += '</div>';
    }

    var hist = d.history || [];
    h += '<div class="e8d-sec">History</div>';
    if (!hist.length) {
      h += '<div class="e8d-none">No decision has been taken yet.</div>';
    } else {
      h += '<details class="e8d-h"><summary>' + hist.length
         + ' decision' + (hist.length === 1 ? '' : 's') + ' already taken. Nothing here is ever deleted.</summary>';
      h += '<div class="e8d-item" style="margin-top:8px">';
      hist.forEach(function (x) {
        h += '<div class="e8d-line">' + pill(x.state)
           + '<div><div>' + esc(x.title) + '</div>'
           + '<div class="e8d-sub">' + esc(x.project)
           + (x.approved_by ? ' . ' + esc(x.approved_by) : '')
           + (x.decided_at ? ' . ' + esc(x.decided_at) : '') + '</div></div></div>';
      });
      h += '</div></details>';
    }

    h += '</div>';

    content().innerHTML = h;

    // The shell ships "Loading..." in the topbar and every page is responsible
    // for replacing it. Left alone it says the page is still loading forever,
    // which is a small lie in the one place on screen whose whole job is to
    // report state.
    var tb = document.getElementById('topbar-status');
    if (tb) {
      tb.textContent = (total === 0 ? 'Nothing waiting' : total + ' waiting')
        + ' . ' + (d.scope && d.scope.project ? d.scope.project : 'All projects');
    }
  };
</script>
