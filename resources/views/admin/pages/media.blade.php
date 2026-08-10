{{-- Media Library — /admin/media
     Renderer for the 'assets' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        if (!window._adminMedia) {
          window._adminMedia = {
            tab: 'library', page: 1, per_page: 20,
            filters: { search:'', category:'', industry:'', type:'', source:'' },
            view: 'grid',
            selected: [],        // bulk-delete selection (ids)
            selectMode: false,   // selection UI on/off
          };
        }
        setContent('<div class="loading">Loading...</div>');
        await _mediaRender();
      }).bind(window.pages);
</script>
