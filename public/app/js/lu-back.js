/* BACK-1 (Owner 2026-09-23): "On mobile when back button on phone's browser is clicked it should never trigger exit from
   Level Up Growth instead the same confirmation modal should open."
   A sentinel history entry sits under the app: backing onto it never leaves the page. In the editor, back opens the
   editor's own exit confirmation (EXIT-1) - the same dialog as the toolbar's Back. Anywhere else, back asks before
   leaving; Leave steps past the sentinel to wherever the visitor came from, Stay keeps them here. The router's own
   back/forward between app views is untouched: the sentinel is only ever reached at the bottom of the app's history. */
(function () {
  if (window.__luBackGuard) return; window.__luBackGuard = true;
  var ROOT = { lu: 'root' }, GUARD = { lu: 'guard' }, EDITOR = { lu: 'editor' };
  var leaving = false, asking = false;
  function here() { return location.pathname + location.search + location.hash; }
  function push(st) { try { history.pushState(st, '', here()); } catch (e) {} }
  function editorOpen() { return !!document.getElementById('template-editor-view'); }

  function arm() {
    try {
      var st = history.state;
      if (st && st.lu === 'root') { push(GUARD); return; }   // reloaded on the sentinel itself
      if (st && st.lu) return;
      history.replaceState(ROOT, '', here());
      push(st || GUARD);
    } catch (e) {}
  }

  function onPop() {
    if (leaving) return;
    var st = history.state || {};
    if (editorOpen()) {   // the editor: back = the toolbar's Back, with its confirmation
      if (st.lu !== 'editor') push(EDITOR);
      if (asking) return; asking = true;
      var done = function () { asking = false; };
      try { Promise.resolve(typeof wsCloseTemplateEditor === 'function' ? wsCloseTemplateEditor() : null).then(done, done); } catch (e) { done(); }
      return;
    }
    if (st.lu !== 'root') return;   // an ordinary app view: the router handles it
    push(GUARD);
    if (asking) return; asking = true;
    var ask = (typeof window.luConfirm === 'function')
      ? window.luConfirm('Leave LevelUpGrowth?', 'You are about to leave the app. Your work here is saved.', { okLabel: 'Leave', cancelLabel: 'Stay' })
      : Promise.resolve(window.confirm('Leave LevelUpGrowth?'));
    Promise.resolve(ask).then(function (ok) {
      asking = false;
      if (!ok) return;
      leaving = true; setTimeout(function () { leaving = false; }, 1500);
      try { history.go(-2); } catch (e) {}   // past the guard and the sentinel; nothing happens when the app was the first page
    }, function () { asking = false; });
  }

  // the editor gets its own entry when it opens, so the first back inside it lands on the same address
  function wrapEditor() {
    var orig = window._wsShowTemplateEditor;
    if (typeof orig !== 'function' || orig.__luBack) return;
    var w = function (site) { var r = orig.apply(this, arguments); try { if (!(history.state && history.state.lu === 'editor')) push(EDITOR); } catch (e) {} return r; };
    w.__luBack = true; window._wsShowTemplateEditor = w;
  }

  window.addEventListener('popstate', onPop);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { arm(); wrapEditor(); });
  else { arm(); wrapEditor(); }
  setTimeout(wrapEditor, 1500);   // in case the editor script registers later
})();
