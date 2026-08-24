{{--
    Admin sidebar — generated from config/admin_pages.php.

    This markup used to be maintained by hand alongside an inline title map and
    the `pages` object. The three drifted, and the 2026-07-29 forensic audit
    found the results: a House Account menu item with no page behind it, and a
    title entry for a page that no longer existed. There is now one list.

    Items are real anchors, so middle-click and open-in-new-tab work.

    @param array  $pages       the registry
    @param string $currentKey  page key being rendered
--}}
<aside class="sidebar">
  <div class="sidebar-logo" style="display:flex;align-items:center;gap:10px">
    <img src="/img/logo-icon-40.png" alt="" style="width:32px;height:32px;object-fit:contain;flex-shrink:0">
    <div><div class="logo-text" style="font-size:13px">LevelUp Growth</div><div class="logo-sub">Admin Console</div></div>
  </div>
  <nav>
    @php $lastGroup = null; @endphp
    @foreach ($pages as $key => $p)
      @if ($p['group'] !== $lastGroup)
        <div class="nav-group">{{ $p['group'] }}</div>
        @php $lastGroup = $p['group']; @endphp
      @endif
      {{-- kept on one line: .nav-item is display:flex, and stray whitespace
           text nodes would change the gap between the icon and the label --}}
      <a class="nav-item{{ $key === $currentKey ? ' active' : '' }}" href="/admin/{{ $p['slug'] }}"><span class="nav-icon">{!! $p['icon'] !!}</span> @if (! empty($p['indent'])){!! '&nbsp;' !!}@endif{{ $p['label'] }}</a>
    @endforeach
  </nav>
  <div class="sidebar-footer">
    <div class="admin-badge" id="admin-name">Admin</div>
    <button class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%" onclick="logout()">Sign out</button>
  </div>
</aside>
