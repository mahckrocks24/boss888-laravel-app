{{-- Shared helpers for the /admin Partners pages (RFC-0026). --}}
<script>
  window.pAdmin = window.pAdmin || {};
  pAdmin.req = async function (path, method, body) {
    var t = (typeof token !== 'undefined' && token) ? token : (localStorage.getItem('lu_admin_token') || localStorage.getItem('lu_token') || '');
    var r = await fetch('/api/admin/partners' + path, { method: method || 'GET', headers: { 'Authorization': 'Bearer ' + t, 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    var j = {}; try { j = await r.json(); } catch (e) {}
    return { ok: r.ok, status: r.status, j: j };
  };
  pAdmin.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  pAdmin.usd = function (m) { var n = (m || 0) / 100; return (n < 0 ? '-$' : '$') + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  pAdmin.pct = function (b) { return (b / 100) + '%'; };
  pAdmin.st = function (s) { var m = { pending: 'amber', approved: 'green', rejected: 'red', suspended: 'red', closed: 'purple', active: 'green', paused: 'amber', archived: 'purple', payable: 'blue', paid: 'green', void: 'red', open: 'amber', resolved: 'green', dismissed: 'purple', signed_up: 'blue', paying: 'green', ended: 'purple' }; return '<span class="badge badge-' + (m[s] || 'blue') + '">' + pAdmin.esc(s) + '</span>'; };
  pAdmin.err = function (x, fallback) { var j = x.j || {}; return j.error || (j.errors ? j.errors[Object.keys(j.errors)[0]][0] : '') || j.message || fallback || ('HTTP ' + x.status); };
</script>
