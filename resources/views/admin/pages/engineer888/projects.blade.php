{{-- Engineer888 — Projects — /admin/engineer888/projects
     Proof that the project abstraction is real: repository, ownership manifest,
     test database and phpunit config come from a row, not from code. This page
     is deliberately small. It exists to show multi-project support, not to
     manage a portfolio. --}}
<script>
  window.page = async function () {
    const esc = function (s) {
      return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading projects…</div>';
    const d = await api('/engineer888/projects');
    if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load projects.</div>'; return; }

    let h = '<div style="background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.35);border-radius:8px;'
          + 'padding:12px 14px;margin-bottom:16px;font-size:13px;line-height:1.6">'
          + 'Engineer888 is not tied to one codebase. Every project below is a database row carrying its own '
          + 'repository, ownership manifest, test database and test configuration. Adding a project is an insert.'
          + '</div>';

    d.projects.forEach(function (p) {
      h += '<div style="border:1px solid var(--border);border-radius:8px;margin-bottom:14px;overflow:hidden">';
      h += '<div style="padding:12px 16px;background:var(--s2)">'
         + '<div style="font-weight:600;font-size:15px">' + esc(p.name) + '</div>'
         + '<div style="font-size:12px;color:var(--muted)">' + esc(p.company) + ' · <span style="font-family:monospace">'
         + esc(p.key) + '</span></div></div>';

      h += '<div style="padding:12px 16px;font-size:12.5px;line-height:1.9">';
      h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px">';
      [['Tasks', p.tasks.total], ['Completed', p.tasks.completed], ['Blocked', p.tasks.blocked], ['Failed', p.tasks.failed]]
        .forEach(function (pair) {
          h += '<div class="stat-card"><div class="stat-value">' + esc(pair[1]) + '</div><div class="stat-label">' + pair[0] + '</div></div>';
        });
      h += '</div>';

      h += '<div><span style="color:var(--muted)">Repository</span> <span style="font-family:monospace">' + esc(p.repository_path) + '</span></div>';
      h += '<div><span style="color:var(--muted)">Ownership manifest</span> <span style="font-family:monospace">' + esc(p.ownership_manifest || 'resolved from the repository') + '</span></div>';
      h += '<div><span style="color:var(--muted)">Test database</span> <span style="font-family:monospace">' + esc(p.test_database || '—') + '</span></div>';
      h += '<div><span style="color:var(--muted)">PHPUnit config</span> <span style="font-family:monospace">' + esc(p.phpunit_config || '—') + '</span></div>';

      if (p.assets.length) {
        h += '<div style="margin-top:10px;font-weight:600">Promoted assets</div>';
        p.assets.forEach(function (a) {
          h += '<div style="font-size:12px">· <span style="font-family:monospace">' + esc(a.kind) + '</span> ' + esc(a.name)
             + ' <span style="color:var(--muted)">' + (a.project_id ? 'project-scoped' : 'company-wide')
             + (a.evidence_task_id ? ', proved by task #' + esc(a.evidence_task_id) : ', no task evidence') + '</span></div>';
        });
      }

      if (p.recent_failures.length) {
        h += '<div style="margin-top:10px;font-weight:600">Recent halts</div>';
        p.recent_failures.forEach(function (f) {
          h += '<div style="font-size:12px;color:var(--muted)">· <span style="font-family:monospace">' + esc(f.stage)
             + '</span> on “' + esc(f.title) + '” — ' + esc(f.summary) + '</div>';
        });
      }

      h += '</div></div>';
    });

    content().innerHTML = h;
  };
</script>
